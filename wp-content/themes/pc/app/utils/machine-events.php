<?php

namespace PC;

/**
 * Append-only log of physical-machine events, backed by
 * `wp_pc_machine_events` (see Install_Schema 1.5.0).
 *
 * Every event the backend mediates lands here regardless of whether it
 * moved coins: the row is the audit trail Phase 7's ops dashboard reads,
 * and — via the unique `event_key` — the idempotency guard that stops a
 * retried webhook or an overlapping poll from crediting twice.
 *
 * Writers go through `Machine_Ingest_Service`, never straight to this
 * class; it owns the wallet side of the same event.
 */
final class Machine_Event_Log {

	public const TYPE_TOSS          = 'toss';
	public const TYPE_COINS_DROPPED = 'coins_dropped';
	public const TYPE_BONUS         = 'bonus';
	public const TYPE_RELAY_CLOSED  = 'relay_closed';
	public const TYPE_OFFLINE       = 'offline';

	/** Recorded, no coin movement expected (e.g. a toss or an offline blip). */
	public const STATUS_RECORDED = 'recorded';
	/** Coins were credited to `user_id`. */
	public const STATUS_CREDITED = 'credited';
	/** Payout owed but nobody to pay — no player held the turn. */
	public const STATUS_UNATTRIBUTED = 'unattributed';
	/** Wallet write failed; the row is the forensic record. */
	public const STATUS_FAILED = 'failed';

	public static function type_values(): array {
		return [
			self::TYPE_TOSS,
			self::TYPE_COINS_DROPPED,
			self::TYPE_BONUS,
			self::TYPE_RELAY_CLOSED,
			self::TYPE_OFFLINE,
		];
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_machine_events';
	}

	/**
	 * Insert an event row.
	 *
	 * Returns the new row id, or 0 when `event_key` collided with a row
	 * that already exists — the caller treats 0 as "already handled" and
	 * must not touch the wallet.
	 */
	public static function record( array $event ): int {
		global $wpdb;

		$event_key = isset( $event['event_key'] ) ? substr( (string) $event['event_key'], 0, 191 ) : null;
		if ( null !== $event_key && self::find_by_key( $event_key ) ) {
			return 0;
		}

		$payload = $event['payload'] ?? null;

		$inserted = $wpdb->insert(
			self::table(),
			[
				'machine_id'     => substr( (string) ( $event['machine_id'] ?? '' ), 0, 64 ),
				'event_type'     => substr( (string) $event['event_type'], 0, 32 ),
				'event_key'      => $event_key,
				'user_id'        => isset( $event['user_id'] ) ? (int) $event['user_id'] : null,
				'coins_credited' => (int) ( $event['coins_credited'] ?? 0 ),
				'unit_price'     => (string) ( $event['unit_price'] ?? '0.00' ),
				'status'         => substr( (string) ( $event['status'] ?? self::STATUS_RECORDED ), 0, 16 ),
				'payload'        => $payload ? wp_json_encode( $payload ) : null,
				'correlation_id' => isset( $event['correlation_id'] ) ? (int) $event['correlation_id'] : null,
				'created_at'     => current_time( 'mysql', true ) . substr( microtime(), 1, 7 ),
			]
		);

		// A concurrent writer can win the race between the pre-check above
		// and this insert; the unique index rejects it. Either way the
		// caller must not credit, so a failed insert reports 0 too.
		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	public static function find_by_key( string $event_key ): ?array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE event_key = %s LIMIT 1", $event_key ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Patch an event after the wallet side resolves. `$patch` accepts
	 * `user_id`, `coins_credited`, `unit_price`, and `correlation_id`.
	 */
	public static function mark( int $id, string $status, array $patch = [] ): bool {
		global $wpdb;

		$data = [ 'status' => substr( $status, 0, 16 ) ];
		foreach ( [ 'user_id', 'coins_credited', 'correlation_id' ] as $key ) {
			if ( array_key_exists( $key, $patch ) ) {
				$data[ $key ] = null === $patch[ $key ] ? null : (int) $patch[ $key ];
			}
		}
		if ( array_key_exists( 'unit_price', $patch ) ) {
			$data['unit_price'] = (string) $patch['unit_price'];
		}

		return false !== $wpdb->update( self::table(), $data, [ 'id' => $id ], null, [ '%d' ] );
	}

	/**
	 * Newest-first slice for the ops view. `$event_type` narrows to one
	 * type; `$user_id` to one player.
	 */
	public static function recent( int $limit = 50, ?string $event_type = null, ?int $user_id = null ): array {
		global $wpdb;

		$table  = self::table();
		$limit  = max( 1, min( 500, $limit ) );
		$where  = [];
		$params = [];

		if ( $event_type ) {
			$where[]  = 'event_type = %s';
			$params[] = $event_type;
		}
		if ( $user_id ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}

		$clause   = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$params[] = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table $clause ORDER BY created_at DESC, id DESC LIMIT %d", $params ),
			ARRAY_A
		);

		return array_map( static function ( array $r ): array {
			return [
				'id'             => (int) $r['id'],
				'machine_id'     => $r['machine_id'],
				'event_type'     => $r['event_type'],
				'event_key'      => $r['event_key'],
				'user_id'        => null === $r['user_id'] ? null : (int) $r['user_id'],
				'coins_credited' => (int) $r['coins_credited'],
				'unit_price'     => $r['unit_price'],
				'status'         => $r['status'],
				'payload'        => $r['payload'] ? json_decode( $r['payload'], true ) : null,
				'correlation_id' => null === $r['correlation_id'] ? null : (int) $r['correlation_id'],
				'created_at'     => $r['created_at'],
			];
		}, $rows ?: [] );
	}
}
