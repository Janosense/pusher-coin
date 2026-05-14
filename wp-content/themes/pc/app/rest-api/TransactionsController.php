<?php

namespace PC;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Player-facing transaction history.
 *
 *   GET /pc/v1/transactions
 *
 * Scoped to the current user — no cross-user reads. Returns top-ups
 * and withdrawals only; game results never land here (they'll live on
 * `wp_pc_bet_sessions`, Phase 6, and stay out of the player history).
 *
 * Bearer auth (`require_logged_in`). Note: not `require_play_ready`
 * because a player whose email isn't verified can still inspect their
 * (likely empty) history.
 */
class TransactionsController extends WP_REST_Controller {

	private const ALLOWED_TYPES = [ 'topup', 'withdraw' ];

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'transactions';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base", [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_transactions' ],
			'permission_callback' => [ Permissions::class, 'require_logged_in' ],
			'args'                => [
				'page'     => [ 'type' => 'integer', 'default' => 1 ],
				'per_page' => [ 'type' => 'integer', 'default' => 20 ],
				'type'     => [ 'type' => 'string', 'required' => false ],
				'from'     => [ 'type' => 'string', 'required' => false ],
				'to'       => [ 'type' => 'string', 'required' => false ],
			],
		] );
	}

	public function list_transactions( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );

		$type = $request->get_param( 'type' );
		if ( null !== $type && '' !== $type && ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return new WP_Error( 'invalid_transaction_type', 'type must be one of: topup, withdraw.', [ 'status' => 400 ] );
		}

		$from = $this->normalize_date( $request->get_param( 'from' ), false );
		$to   = $this->normalize_date( $request->get_param( 'to' ), true );
		if ( is_string( $from ) === false && null !== $from ) {
			return new WP_Error( 'invalid_date', 'from must be YYYY-MM-DD.', [ 'status' => 400 ] );
		}
		if ( is_string( $to ) === false && null !== $to ) {
			return new WP_Error( 'invalid_date', 'to must be YYYY-MM-DD.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'pc_transactions';
		$user_id = get_current_user_id();

		$where      = [ '%d' ];
		$where_vals = [ $user_id ];
		$where_sql  = 'user_id = %d AND type IN (' . implode( ',', array_fill( 0, count( self::ALLOWED_TYPES ), '%s' ) ) . ')';
		foreach ( self::ALLOWED_TYPES as $t ) {
			$where_vals[] = $t;
		}

		if ( null !== $type && '' !== $type ) {
			$where_sql   .= ' AND type = %s';
			$where_vals[] = $type;
		}
		if ( $from ) {
			$where_sql   .= ' AND created_at >= %s';
			$where_vals[] = $from;
		}
		if ( $to ) {
			$where_sql   .= ' AND created_at <= %s';
			$where_vals[] = $to;
		}

		$offset = ( $page - 1 ) * $per_page;
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where_sql", ...$where_vals ) );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, amount_money, amount_coins, unit_price, status, created_at, settled_at
				 FROM $table
				 WHERE $where_sql
				 ORDER BY created_at DESC, id DESC
				 LIMIT %d OFFSET %d",
				...array_merge( $where_vals, [ $per_page, $offset ] )
			),
			ARRAY_A
		);

		return new WP_REST_Response( [
			'items'    => array_map( [ $this, 'serialize_row' ], $rows ?: [] ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		], 200 );
	}

	/**
	 * Normalise `from` / `to` query params to a MySQL DATETIME boundary
	 * string. `is_end_of_day` true → 23:59:59. Returns null when the
	 * input is absent, or `false` if it's present but malformed (so
	 * the caller can 400).
	 */
	private function normalize_date( $raw, bool $is_end_of_day ) {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		if ( ! is_string( $raw ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return false;
		}
		return $is_end_of_day ? $raw . ' 23:59:59' : $raw . ' 00:00:00';
	}

	private function serialize_row( array $row ): array {
		return [
			'id'           => (int) $row['id'],
			'type'         => $row['type'],
			'amount_money' => $row['amount_money'],
			'amount_coins' => (int) $row['amount_coins'],
			'unit_price'   => $row['unit_price'],
			'status'       => $row['status'],
			'created_at'   => $row['created_at'],
			'settled_at'   => $row['settled_at'],
		];
	}
}
