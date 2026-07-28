<?php

namespace PC;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Queue and play — the command side of the game loop.
 *
 *   GET  /pc/v1/rooms/{id}/queue
 *   POST /pc/v1/rooms/{id}/queue/join
 *   POST /pc/v1/rooms/{id}/queue/leave
 *   POST /pc/v1/rooms/{id}/play
 *
 * All Bearer + `require_play_ready` (logged in, terms accepted, nickname
 * chosen, email verified) — the same gate the wallet uses, because every
 * one of these can move coins.
 *
 * Phase 5 Step 7 will add a push channel so the SPA stops polling
 * `GET queue`. Until then the poll is also the heartbeat that keeps a
 * player in the queue, so it is deliberately cheap: prune, touch, read.
 */
class RoomQueueController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'rooms';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base/(?P<id>\\d+)/queue", [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_queue' ],
			'permission_callback' => [ Permissions::class, 'require_play_ready' ],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/(?P<id>\\d+)/queue/join", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'join_queue' ],
			'permission_callback' => [ Permissions::class, 'require_play_ready' ],
			'args'                => [
				'coins' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/(?P<id>\\d+)/queue/leave", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'leave_queue' ],
			'permission_callback' => [ Permissions::class, 'require_play_ready' ],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/(?P<id>\\d+)/play", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'play' ],
			'permission_callback' => [ Permissions::class, 'require_play_ready' ],
		] );
	}

	public function get_queue( WP_REST_Request $request ) {
		$room_id = $this->require_room( $request );
		if ( $room_id instanceof WP_Error ) {
			return $room_id;
		}

		return new WP_REST_Response( $this->envelope( $room_id ), 200 );
	}

	public function join_queue( WP_REST_Request $request ) {
		$room_id = $this->require_room( $request );
		if ( $room_id instanceof WP_Error ) {
			return $room_id;
		}

		$state = Queue_Service::join( $room_id, get_current_user_id(), (int) $request->get_param( 'coins' ) );
		if ( $state instanceof WP_Error ) {
			return $state;
		}

		return new WP_REST_Response( $this->envelope( $room_id ), 200 );
	}

	public function leave_queue( WP_REST_Request $request ) {
		$room_id = $this->require_room( $request );
		if ( $room_id instanceof WP_Error ) {
			return $room_id;
		}

		Queue_Service::leave( $room_id, get_current_user_id() );

		return new WP_REST_Response( $this->envelope( $room_id ), 200 );
	}

	/**
	 * Toss one coin.
	 *
	 * Order matters here, and it is the whole point of Phase 5 Step 4:
	 *
	 *   1. Refuse unless the caller holds the turn.
	 *   2. Refuse while the relay is closed — the machine is mid-payout
	 *      and a toss now would be swallowed.
	 *   3. Debit one coin (FIFO, row-locked).
	 *   4. Ask the machine to toss. **Only a 200 counts.**
	 *   5. On any machine failure, re-credit the exact lot price we just
	 *      consumed and return the machine's error. The player must never
	 *      pay for a toss that did not happen.
	 *
	 * Debiting before the toss (rather than after) is deliberate: it
	 * closes the window where two concurrent requests both see a
	 * sufficient balance and both toss. The compensating credit runs on
	 * the failure path, which is rare, rather than the success path,
	 * which is every toss.
	 */
	public function play( WP_REST_Request $request ) {
		$room_id = $this->require_room( $request );
		if ( $room_id instanceof WP_Error ) {
			return $room_id;
		}

		$user_id = get_current_user_id();

		if ( ! Queue_Service::holds_turn( $room_id, $user_id ) ) {
			return new WP_Error( 'not_player_turn', 'It is not your turn.', [ 'status' => 403 ] );
		}

		$relay_closed = Machine_Service::get_relay_closed();
		if ( $relay_closed instanceof WP_Error ) {
			return $this->machine_error_response( $relay_closed );
		}
		if ( $relay_closed ) {
			return new WP_Error( 'relay_closed', 'The machine is settling a payout. Try again in a moment.', [ 'status' => 423 ] );
		}

		$consumed = Wallet_Service::debit_fifo( $user_id, 1 );
		if ( $consumed instanceof WP_Error ) {
			$code = $consumed->get_error_code();
			if ( 'insufficient_balance' === $code ) {
				return new WP_Error( 'insufficient_balance', 'Not enough coins.', [ 'status' => 409 ] );
			}
			return $consumed;
		}

		$toss = Machine_Service::toss_coin();
		if ( $toss instanceof WP_Error ) {
			$this->refund( $user_id, $consumed );
			return $this->machine_error_response( $toss );
		}

		$entry      = Queue_Service::entry( $room_id, $user_id );
		$session_id = $entry && $entry['session_id'] ? (int) $entry['session_id'] : null;

		$event = Machine_Ingest_Service::log_event(
			Machine_Event_Log::TYPE_TOSS,
			[
				'machine_id' => $this->machine_id_for_room( $room_id ),
				'user_id'    => $user_id,
			],
			[ 'room_id' => $room_id, 'session_id' => $session_id ]
		);

		$coins_remaining = Queue_Service::consume_coin( $room_id, $user_id );

		return new WP_REST_Response( [
			'toss_id'         => $event['event_id'],
			'coins_remaining' => $coins_remaining,
			'balance_coins'   => Wallet_Service::get_wallet( $user_id )['balance_coins'],
			'queue'           => $this->envelope( $room_id ),
		], 200 );
	}

	/**
	 * Put back exactly what the FIFO debit took, lot price by lot price.
	 * A flat re-credit at the default price would quietly re-price the
	 * player's coins on every machine hiccup.
	 *
	 * Known nuance: the refunded coin returns as a *new* lot, so it goes
	 * to the back of the FIFO stack rather than the position it came
	 * from. Total value is preserved; only the consumption order shifts,
	 * and only for a player holding lots bought at different prices who
	 * also hit a machine failure. Fixing it properly means threading
	 * `acquired_at` through `Wallet_Service::credit_lot`, which is more
	 * churn than the case warrants today.
	 */
	private function refund( int $user_id, array $consumed ): void {
		foreach ( $consumed as $slice ) {
			Wallet_Service::credit_lot( $user_id, (int) $slice['qty'], (string) $slice['unit_price'] );
		}
	}

	private function envelope( int $room_id ): array {
		return Queue_Service::state( $room_id, get_current_user_id() );
	}

	private function machine_id_for_room( int $room_id ): string {
		return (string) get_post_meta( $room_id, Post_Meta_Keys::ROOM_MACHINE_ID, true );
	}

	/**
	 * A room must exist and be published before anyone queues in it.
	 */
	private function require_room( WP_REST_Request $request ) {
		$room_id = (int) $request->get_param( 'id' );
		$post    = get_post( $room_id );

		if ( ! $post || 'pc_room' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'room_not_found', 'Room not found.', [ 'status' => 404 ] );
		}

		$status = get_post_meta( $room_id, Post_Meta_Keys::ROOM_STATUS, true );
		if ( Post_Meta_Keys::ROOM_STATUS_AVAILABLE !== $status ) {
			return new WP_Error( 'room_unavailable', 'This room is not accepting players right now.', [ 'status' => 409 ] );
		}

		return $room_id;
	}

	/**
	 * Same mapping as AdminMachineController: an upstream HA failure is
	 * a gateway problem, never a 401 — a 401 here would trip the SPA's
	 * token-refresh interceptor and log the player out mid-turn.
	 */
	private function machine_error_response( WP_Error $err ): WP_Error {
		$map = [
			'machine_not_configured'    => 500,
			'machine_offline'           => 503,
			'machine_unauthorized'      => 502,
			'machine_call_failed'       => 502,
			'machine_unavailable_state' => 503,
		];
		$code = $err->get_error_code();

		return new WP_Error( $code, $err->get_error_message(), [ 'status' => $map[ $code ] ?? 502 ] );
	}
}
