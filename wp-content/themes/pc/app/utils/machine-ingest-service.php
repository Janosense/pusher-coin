<?php

namespace PC;

use WP_Error;

/**
 * Turns a physical-machine event into a wallet credit.
 *
 * Deliberately transport-agnostic: it does not care whether the event
 * arrived from a Home Assistant webhook, a backend poller, or a WP-CLI
 * replay (`wp pc machine-ingest`). Phase 5 Step 6 decides the transport;
 * whatever wins calls these three entry points.
 *
 *   ingest_bonus( 7 )          — the wheel landed on 7; pay the bonus map.
 *   ingest_relay_closed()      — the relay contact closed; pay the flat rate.
 *   ingest_coins_dropped( 3 )  — the coin sensor moved by 3; pay 1:1.
 *
 * Two rules every caller inherits:
 *
 *   1. **Idempotency.** Pass `event_key` in `$context` — a stable id
 *      derived from the source event (HA `context.id`, or
 *      `sensor:reading-timestamp` for a poller). A repeat key is
 *      recorded once and never credited twice. Callers that omit it get
 *      at-least-once delivery, which for a payout means double credits.
 *   2. **Attribution.** Coins go to whoever holds the turn. That is a
 *      Phase 6 concept (`wp_pc_bet_sessions`), so until it lands the
 *      `pc_machine_event_player` filter returns null and the event is
 *      logged `unattributed` with no wallet movement. Phase 6 hooks the
 *      filter; nothing else in this file changes.
 *
 * Credits create a coin lot + wallet delta but no `wp_pc_transactions`
 * row: the ledger is the money trail (top-ups / withdrawals), and
 * ROADMAP §4.7 keeps machine wins out of the player's history view.
 * `wp_pc_machine_events` is the audit trail for this side.
 */
final class Machine_Ingest_Service {

	/**
	 * Pay out the bonus map entry for `$bonus_number` (1..12).
	 */
	public static function ingest_bonus( int $bonus_number, array $context = [] ) {
		if ( $bonus_number < 1 || $bonus_number > 12 ) {
			return new WP_Error(
				'invalid_bonus_number',
				'Bonus number must be between 1 and 12.',
				[ 'status' => 400 ]
			);
		}

		$coins = self::bonus_map()[ (string) $bonus_number ] ?? 0;

		return self::settle(
			Machine_Event_Log::TYPE_BONUS,
			$coins,
			$context,
			[ 'bonus_number' => $bonus_number, 'coins_for_bonus' => $coins ]
		);
	}

	/**
	 * Pay out the flat relay-closure rate.
	 */
	public static function ingest_relay_closed( array $context = [] ) {
		$coins = self::relay_coin_count();

		return self::settle(
			Machine_Event_Log::TYPE_RELAY_CLOSED,
			$coins,
			$context,
			[ 'coins_for_relay' => $coins ]
		);
	}

	/**
	 * Credit coins the machine physically dropped, 1:1. `$coins` is the
	 * delta the sensor moved by, not its cumulative reading.
	 */
	public static function ingest_coins_dropped( int $coins, array $context = [] ) {
		if ( $coins < 1 ) {
			return new WP_Error(
				'invalid_coin_count',
				'Coin count must be a positive integer.',
				[ 'status' => 400 ]
			);
		}

		return self::settle(
			Machine_Event_Log::TYPE_COINS_DROPPED,
			$coins,
			$context,
			[ 'coins_dropped' => $coins ]
		);
	}

	/**
	 * Log an event that never pays out — a toss acknowledgement or an
	 * offline blip. Same idempotency contract, no wallet involvement.
	 */
	public static function log_event( string $event_type, array $context = [], array $payload = [] ): array {
		$event_id = Machine_Event_Log::record( [
			'machine_id' => $context['machine_id'] ?? '',
			'event_type' => $event_type,
			'event_key'  => $context['event_key'] ?? null,
			'user_id'    => $context['user_id'] ?? null,
			'status'     => Machine_Event_Log::STATUS_RECORDED,
			'payload'    => $payload ?: null,
		] );

		return [
			'event_id'  => $event_id,
			'duplicate' => 0 === $event_id,
			'status'    => Machine_Event_Log::STATUS_RECORDED,
			'coins'     => 0,
			'user_id'   => null,
		];
	}

	/**
	 * Record the event, then credit it if we know who earned it.
	 *
	 * The row is written *before* the wallet moves so a crash mid-credit
	 * leaves evidence rather than silence; `mark()` then reflects what
	 * actually happened.
	 */
	private static function settle( string $event_type, int $coins, array $context, array $payload ): array {
		$machine_id = (string) ( $context['machine_id'] ?? '' );

		$event_id = Machine_Event_Log::record( [
			'machine_id' => $machine_id,
			'event_type' => $event_type,
			'event_key'  => $context['event_key'] ?? null,
			'status'     => Machine_Event_Log::STATUS_RECORDED,
			'payload'    => $payload,
		] );

		if ( 0 === $event_id ) {
			return [
				'event_id'  => 0,
				'duplicate' => true,
				'status'    => Machine_Event_Log::STATUS_RECORDED,
				'coins'     => 0,
				'user_id'   => null,
			];
		}

		$user_id = self::resolve_player( $context );

		if ( ! $user_id || $coins < 1 ) {
			// Either nobody held the turn, or this bonus is mapped to 0
			// coins. Both are normal; the row records that we saw it.
			$status = $user_id ? Machine_Event_Log::STATUS_RECORDED : Machine_Event_Log::STATUS_UNATTRIBUTED;
			Machine_Event_Log::mark( $event_id, $status, [ 'user_id' => $user_id ?: null ] );

			return [
				'event_id'  => $event_id,
				'duplicate' => false,
				'status'    => $status,
				'coins'     => 0,
				'user_id'   => $user_id ?: null,
			];
		}

		$unit_price = self::payout_unit_price( $user_id );
		$credited   = Wallet_Service::credit_lot( $user_id, $coins, $unit_price );

		$status = $credited ? Machine_Event_Log::STATUS_CREDITED : Machine_Event_Log::STATUS_FAILED;
		Machine_Event_Log::mark( $event_id, $status, [
			'user_id'        => $user_id,
			'coins_credited' => $credited ? $coins : 0,
			'unit_price'     => $unit_price,
			'correlation_id' => $context['correlation_id'] ?? null,
		] );

		return [
			'event_id'   => $event_id,
			'duplicate'  => false,
			'status'     => $status,
			'coins'      => $credited ? $coins : 0,
			'user_id'    => $user_id,
			'unit_price' => $unit_price,
		];
	}

	/**
	 * Who owns this event's payout.
	 *
	 * An explicit `user_id` in `$context` wins (CLI replay, admin manual
	 * settlement). Otherwise Phase 6 answers through the filter; today
	 * nothing hooks it and the event stays unattributed.
	 */
	public static function resolve_player( array $context = [] ): ?int {
		if ( ! empty( $context['user_id'] ) ) {
			return (int) $context['user_id'];
		}

		/**
		 * Filters the player a machine event pays out to.
		 *
		 * @param int|null $user_id Resolved player, or null when no turn is held.
		 * @param array    $context Ingest context (machine_id, room_id, event_key…).
		 */
		$user_id = apply_filters( 'pc_machine_event_player', null, $context );

		return $user_id ? (int) $user_id : null;
	}

	/**
	 * Price a payout coin.
	 *
	 * ROADMAP §6.4: a win pays back at the price the player bought the
	 * coin at. The FIFO head is that price — it is the next coin the
	 * player would spend. With an empty wallet (every coin already
	 * tossed) we fall back to the operator's default.
	 */
	public static function payout_unit_price( int $user_id ): string {
		$lots = Wallet_Service::get_lots( $user_id );
		if ( $lots ) {
			return (string) $lots[0]['unit_price'];
		}

		return (string) get_option( 'pc_coin_price_default', '40.00' );
	}

	/**
	 * Coins-per-bonus-number, keys '1'..'12', missing entries 0.
	 * Written by `PUT /admin/machine/bonus-map`.
	 */
	public static function bonus_map(): array {
		$stored  = get_option( 'pc_machine_bonus_map', '' );
		$decoded = is_string( $stored ) ? json_decode( $stored, true ) : null;

		$map = [];
		for ( $i = 1; $i <= 12; $i++ ) {
			$key         = (string) $i;
			$map[ $key ] = isset( $decoded[ $key ] ) ? max( 0, (int) $decoded[ $key ] ) : 0;
		}

		return $map;
	}

	public static function relay_coin_count(): int {
		return max( 0, (int) get_option( 'pc_machine_relay_coin_count', 0 ) );
	}
}
