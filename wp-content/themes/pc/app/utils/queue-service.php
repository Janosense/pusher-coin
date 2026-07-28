<?php

namespace PC;

use WP_Error;

/**
 * The room queue and the turn it confers.
 *
 * Model (implied by `POST /rooms/{id}/queue/join { coins }` in
 * API-CONTRACT.md): a player joins declaring how many coins they intend
 * to play. The queue is FIFO on `joined_at`; whoever sits at the head
 * holds the turn and is the only one `POST /rooms/{id}/play` will accept.
 * A turn ends when the declared coins run out, when the player leaves,
 * or when they go quiet for `pc_queue_idle_timeout_seconds` (the SPA's
 * queue poll doubles as the heartbeat).
 *
 * Every read goes through `state()`, which prunes stale entries first.
 * That means the queue heals on traffic alone — no cron, no daemon. A
 * room nobody is looking at may hold a stale head, but nothing can
 * happen in it either: the next request to touch the room fixes it
 * before answering.
 *
 * The head's `wp_pc_bet_sessions` row is what makes a machine event
 * attributable — see `resolve_player_for_machine()`, which closes the
 * Phase 5 §3 gap where bonus payouts logged as `unattributed`.
 */
final class Queue_Service {

	private const DEFAULT_IDLE_TIMEOUT = 60;

	public static function queue_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_room_queues';
	}

	public static function session_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_bet_sessions';
	}

	public static function idle_timeout(): int {
		return max( 10, (int) get_option( 'pc_queue_idle_timeout_seconds', self::DEFAULT_IDLE_TIMEOUT ) );
	}

	/* ---------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------- */

	/**
	 * Full queue state for a room, freshest first pruned.
	 *
	 * `$viewer_id` (optional) touches that player's heartbeat, so simply
	 * watching the queue keeps you in it.
	 */
	public static function state( int $room_id, ?int $viewer_id = null ): array {
		self::prune_stale( $room_id );

		if ( $viewer_id ) {
			self::touch( $room_id, $viewer_id );
		}

		self::sync_turn( $room_id );

		global $wpdb;
		$table = self::queue_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE room_id = %d ORDER BY joined_at ASC, id ASC",
				$room_id
			),
			ARRAY_A
		);
		$rows = $rows ?: [];

		$queue = array_map( static function ( array $r ): array {
			$user = get_userdata( (int) $r['user_id'] );
			return [
				'user_id'  => (int) $r['user_id'],
				'nickname' => $user ? $user->display_name : 'Player',
				'coins'    => (int) $r['coins_remaining'],
			];
		}, $rows );

		$head    = $rows[0] ?? null;
		$session = $head && $head['session_id'] ? self::get_session( (int) $head['session_id'] ) : null;

		return [
			'queue'                 => $queue,
			'current_turn_user_id'  => $head ? (int) $head['user_id'] : null,
			'online_count'          => count( $queue ),
			'session'               => $session,
			'idle_timeout_seconds'  => self::idle_timeout(),
		];
	}

	public static function entry( int $room_id, int $user_id ): ?array {
		global $wpdb;
		$table = self::queue_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE room_id = %d AND user_id = %d", $room_id, $user_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * The entry currently holding the turn, or null for an empty queue.
	 * Prunes first so a vanished player never blocks the queue.
	 */
	public static function head( int $room_id ): ?array {
		self::prune_stale( $room_id );

		global $wpdb;
		$table = self::queue_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE room_id = %d ORDER BY joined_at ASC, id ASC LIMIT 1",
				$room_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function holds_turn( int $room_id, int $user_id ): bool {
		$head = self::head( $room_id );
		return $head && (int) $head['user_id'] === $user_id;
	}

	/* ---------------------------------------------------------------
	 * Mutations
	 * ------------------------------------------------------------- */

	/**
	 * Join (or re-declare) with `$coins` coins.
	 *
	 * The declaration is capped at the wallet balance at join time — it
	 * is an intent, not a reservation. Coins are debited one at a time by
	 * `play`, so a player who tops up mid-turn is not penalised and one
	 * who spends elsewhere simply runs out early.
	 */
	public static function join( int $room_id, int $user_id, int $coins ) {
		if ( $coins < 1 ) {
			return new WP_Error( 'invalid_coin_qty', 'Declare at least one coin.', [ 'status' => 400 ] );
		}

		$balance = Wallet_Service::get_wallet( $user_id )['balance_coins'];
		if ( $balance < 1 ) {
			return new WP_Error( 'insufficient_balance', 'Top up before joining the queue.', [ 'status' => 409 ] );
		}
		$coins = min( $coins, $balance );

		global $wpdb;
		$now      = current_time( 'mysql' );
		$existing = self::entry( $room_id, $user_id );

		if ( $existing ) {
			// Re-declaring keeps the original `joined_at`: a player must
			// not be able to jump their own place in the queue by
			// changing their mind about the coin count.
			$wpdb->update(
				self::queue_table(),
				[ 'coins_declared' => $coins, 'coins_remaining' => $coins, 'last_seen_at' => $now ],
				[ 'id' => (int) $existing['id'] ],
				[ '%d', '%d', '%s' ],
				[ '%d' ]
			);
		} else {
			$wpdb->insert(
				self::queue_table(),
				[
					'room_id'         => $room_id,
					'user_id'         => $user_id,
					'coins_declared'  => $coins,
					'coins_remaining' => $coins,
					'joined_at'       => $now,
					'last_seen_at'    => $now,
				],
				[ '%d', '%d', '%d', '%d', '%s', '%s' ]
			);
		}

		return self::state( $room_id, $user_id );
	}

	/**
	 * Leave the queue. Closes the bet session if they held the turn, so
	 * the next player starts a clean one.
	 */
	public static function leave( int $room_id, int $user_id ): array {
		$entry = self::entry( $room_id, $user_id );
		if ( $entry ) {
			self::remove_entry( $entry );
		}
		return self::state( $room_id );
	}

	public static function touch( int $room_id, int $user_id ): void {
		global $wpdb;
		$wpdb->update(
			self::queue_table(),
			[ 'last_seen_at' => current_time( 'mysql' ) ],
			[ 'room_id' => $room_id, 'user_id' => $user_id ],
			[ '%s' ],
			[ '%d', '%d' ]
		);
	}

	/**
	 * Book one coin against the turn holder's declaration. Ends the turn
	 * when the declaration is exhausted.
	 *
	 * Called by `play` *after* the machine acknowledged the toss.
	 */
	public static function consume_coin( int $room_id, int $user_id ): int {
		$entry = self::entry( $room_id, $user_id );
		if ( ! $entry ) {
			return 0;
		}

		$remaining = max( 0, (int) $entry['coins_remaining'] - 1 );

		global $wpdb;
		$wpdb->update(
			self::queue_table(),
			[ 'coins_remaining' => $remaining, 'last_seen_at' => current_time( 'mysql' ) ],
			[ 'id' => (int) $entry['id'] ],
			[ '%d', '%s' ],
			[ '%d' ]
		);

		if ( $entry['session_id'] ) {
			self::increment_session( (int) $entry['session_id'], [ 'coins_played' => 1 ] );
		}

		if ( 0 === $remaining ) {
			// Declaration spent — hand the machine to the next player.
			self::remove_entry( array_merge( $entry, [ 'coins_remaining' => 0 ] ) );
		}

		return $remaining;
	}

	/* ---------------------------------------------------------------
	 * Sessions
	 * ------------------------------------------------------------- */

	/**
	 * Make the head's session match reality: open one for a new head,
	 * close any session belonging to someone who no longer leads.
	 */
	public static function sync_turn( int $room_id ): void {
		$head = self::head( $room_id );

		$open = self::open_session( $room_id );
		if ( $open && ( ! $head || (int) $open['user_id'] !== (int) $head['user_id'] ) ) {
			self::close_session( (int) $open['id'] );
			$open = null;
		}

		if ( ! $head ) {
			return;
		}

		if ( ! $head['session_id'] || ! $open ) {
			$session_id = $open ? (int) $open['id'] : self::open_new_session( $room_id, (int) $head['user_id'] );
			global $wpdb;
			$wpdb->update(
				self::queue_table(),
				[ 'session_id' => $session_id ],
				[ 'id' => (int) $head['id'] ],
				[ '%d' ],
				[ '%d' ]
			);
		}
	}

	public static function open_session( int $room_id ): ?array {
		global $wpdb;
		$table = self::session_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE room_id = %d AND ended_at IS NULL ORDER BY started_at DESC, id DESC LIMIT 1",
				$room_id
			),
			ARRAY_A
		);
		return $row ? self::serialize_session( $row ) : null;
	}

	public static function get_session( int $session_id ): ?array {
		global $wpdb;
		$table = self::session_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $session_id ), ARRAY_A );
		return $row ? self::serialize_session( $row ) : null;
	}

	private static function open_new_session( int $room_id, int $user_id ): int {
		global $wpdb;
		$wpdb->insert(
			self::session_table(),
			[
				'user_id'    => $user_id,
				'room_id'    => $room_id,
				'started_at' => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	public static function close_session( int $session_id ): void {
		global $wpdb;
		$wpdb->update(
			self::session_table(),
			[ 'ended_at' => current_time( 'mysql' ) ],
			[ 'id' => $session_id, 'ended_at' => null ],
			[ '%s' ],
			[ '%d', '%s' ]
		);
	}

	/**
	 * Add to a live session's counters. Used by `play` (coins_played)
	 * and by machine-event settlement (coins_won / money_won).
	 */
	public static function increment_session( int $session_id, array $deltas ): void {
		global $wpdb;
		$table = self::session_table();

		$sets   = [];
		$params = [];
		foreach ( [ 'coins_played', 'coins_won' ] as $col ) {
			if ( ! empty( $deltas[ $col ] ) ) {
				$sets[]   = "$col = $col + %d";
				$params[] = (int) $deltas[ $col ];
			}
		}
		if ( ! empty( $deltas['money_won'] ) ) {
			$sets[]   = 'money_won = money_won + %f';
			$params[] = (float) $deltas['money_won'];
		}
		if ( ! $sets ) {
			return;
		}

		$params[] = $session_id;
		$wpdb->query(
			$wpdb->prepare( "UPDATE $table SET " . implode( ', ', $sets ) . ' WHERE id = %d', ...$params )
		);
	}

	/**
	 * Latest sessions for a player — the data behind a future
	 * session-history view (ROADMAP §4.7 keeps these out of the money
	 * ledger deliberately).
	 */
	public static function sessions_for_user( int $user_id, int $limit = 20 ): array {
		global $wpdb;
		$table = self::session_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE user_id = %d ORDER BY started_at DESC, id DESC LIMIT %d",
				$user_id,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);
		return array_map( [ self::class, 'serialize_session' ], $rows ?: [] );
	}

	/* ---------------------------------------------------------------
	 * Machine-event attribution (closes the Phase 5 §3 gap)
	 * ------------------------------------------------------------- */

	/**
	 * Resolve the player a machine event belongs to, from the machine id
	 * the event carries.
	 *
	 * `pc_room_machine_id` post meta maps a machine to its room; the
	 * room's open session names the player. No session ⇒ nobody was
	 * playing ⇒ the event stays unattributed, which is the correct
	 * outcome for coins the machine drops while idle.
	 */
	public static function resolve_player_for_machine( $user_id, array $context ) {
		if ( $user_id ) {
			return $user_id;
		}

		$machine_id = (string) ( $context['machine_id'] ?? '' );
		if ( '' === $machine_id ) {
			return null;
		}

		$room_id = self::room_id_for_machine( $machine_id );
		if ( ! $room_id ) {
			return null;
		}

		self::prune_stale( $room_id );
		self::sync_turn( $room_id );

		$session = self::open_session( $room_id );
		return $session ? (int) $session['user_id'] : null;
	}

	/**
	 * Bank a settled machine win against the open session, so the
	 * in-room "winnings" counter reflects this turn rather than the
	 * player's lifetime.
	 *
	 * Hooked to `pc_machine_event_credited`; a credit that lands with no
	 * open session (an admin replay, say) simply moves the wallet and
	 * books nothing here.
	 */
	public static function record_win( int $user_id, int $coins, string $unit_price, array $context ): void {
		$machine_id = (string) ( $context['machine_id'] ?? '' );
		$room_id    = '' === $machine_id ? null : self::room_id_for_machine( $machine_id );
		if ( ! $room_id ) {
			return;
		}

		$session = self::open_session( $room_id );
		if ( ! $session || (int) $session['user_id'] !== $user_id ) {
			return;
		}

		self::increment_session( (int) $session['id'], [
			'coins_won' => $coins,
			'money_won' => $coins * (float) $unit_price,
		] );
	}

	public static function room_id_for_machine( string $machine_id ): ?int {
		$posts = get_posts( [
			'post_type'      => 'pc_room',
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => Post_Meta_Keys::ROOM_MACHINE_ID,
			'meta_value'     => $machine_id,
		] );

		return $posts ? (int) $posts[0] : null;
	}

	/* ---------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------- */

	/**
	 * Drop entries whose player stopped polling. Their session (if they
	 * held the turn) closes with them.
	 */
	public static function prune_stale( int $room_id ): void {
		global $wpdb;
		$table  = self::queue_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::idle_timeout() );

		$stale = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE room_id = %d AND last_seen_at < %s", $room_id, $cutoff ),
			ARRAY_A
		);

		foreach ( $stale ?: [] as $entry ) {
			self::remove_entry( $entry );
		}
	}

	private static function remove_entry( array $entry ): void {
		global $wpdb;

		if ( ! empty( $entry['session_id'] ) ) {
			self::close_session( (int) $entry['session_id'] );
		}

		$wpdb->delete( self::queue_table(), [ 'id' => (int) $entry['id'] ], [ '%d' ] );
	}

	private static function serialize_session( array $row ): array {
		return [
			'id'           => (int) $row['id'],
			'user_id'      => (int) $row['user_id'],
			'room_id'      => (int) $row['room_id'],
			'started_at'   => $row['started_at'],
			'ended_at'     => $row['ended_at'],
			'coins_played' => (int) $row['coins_played'],
			'coins_won'    => (int) $row['coins_won'],
			'money_won'    => $row['money_won'],
		];
	}
}

add_filter( 'pc_machine_event_player', [ Queue_Service::class, 'resolve_player_for_machine' ], 10, 2 );
add_action( 'pc_machine_event_credited', [ Queue_Service::class, 'record_win' ], 10, 4 );
