<?php

namespace PC;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin queue for player withdrawals.
 *
 *   GET  /pc/v1/admin/withdrawals
 *   POST /pc/v1/admin/withdrawals/{id}/approve
 *   POST /pc/v1/admin/withdrawals/{id}/reject
 *
 * The actual payout is out-of-band (bank transfer). `approve` just
 * flips the row to `completed`; `reject` re-credits the originally-
 * consumed coin lots and flips the row to `refunded`.
 *
 * Gated by `Permissions::require_admin`.
 */
class AdminWithdrawalController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'admin/withdrawals';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base", [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_withdrawals' ],
			'permission_callback' => [ Permissions::class, 'require_admin' ],
			'args'                => [
				'status'   => [ 'type' => 'string', 'default' => 'pending' ],
				'page'     => [ 'type' => 'integer', 'default' => 1 ],
				'per_page' => [ 'type' => 'integer', 'default' => 50 ],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/(?P<id>\\d+)/approve", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'approve' ],
			'permission_callback' => [ Permissions::class, 'require_admin' ],
			'args'                => [
				'notes' => [ 'type' => 'string', 'required' => false ],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/(?P<id>\\d+)/reject", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'reject' ],
			'permission_callback' => [ Permissions::class, 'require_admin' ],
			'args'                => [
				'notes' => [ 'type' => 'string', 'required' => false ],
			],
		] );
	}

	public function list_withdrawals( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$status   = (string) $request->get_param( 'status' );
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 50 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $wpdb->prefix . 'pc_transactions';

		$valid_statuses = [ 'pending', 'completed', 'refunded', 'failed', 'all' ];
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			$status = 'pending';
		}

		if ( 'all' === $status ) {
			$where = $wpdb->prepare( 'WHERE type = %s', Wallet_Service::TYPE_WITHDRAW );
		} else {
			$where = $wpdb->prepare( 'WHERE type = %s AND status = %s', Wallet_Service::TYPE_WITHDRAW, $status );
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$items = array_map( [ $this, 'serialize_row' ], $rows ?: [] );

		return new WP_REST_Response( [
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		], 200 );
	}

	public function approve( WP_REST_Request $request ) {
		$txn = $this->require_pending( (int) $request['id'] );
		if ( $txn instanceof WP_Error ) {
			return $txn;
		}
		Wallet_Service::approve_withdrawal( (int) $txn['id'], $this->extract_notes( $request ) );
		Audit_Log::record( 'withdrawal_approved', [
			'user_id'  => (int) $txn['user_id'],
			'metadata' => [ 'txn_id' => (int) $txn['id'], 'admin_id' => get_current_user_id() ],
		] );
		return new WP_REST_Response( $this->serialize_row( $this->refetch( (int) $txn['id'] ) ), 200 );
	}

	public function reject( WP_REST_Request $request ) {
		$txn = $this->require_pending( (int) $request['id'] );
		if ( $txn instanceof WP_Error ) {
			return $txn;
		}
		$result = Wallet_Service::reject_withdrawal( (int) $txn['id'], $this->extract_notes( $request ) );
		if ( $result instanceof WP_Error ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 500 ] );
		}
		Audit_Log::record( 'withdrawal_rejected', [
			'user_id'  => (int) $txn['user_id'],
			'metadata' => [ 'txn_id' => (int) $txn['id'], 'admin_id' => get_current_user_id() ],
		] );
		return new WP_REST_Response( $this->serialize_row( $this->refetch( (int) $txn['id'] ) ), 200 );
	}

	private function require_pending( int $id ) {
		$row = Wallet_Service::get_transaction( $id );
		if ( ! $row || Wallet_Service::TYPE_WITHDRAW !== $row['type'] ) {
			return new WP_Error( 'withdrawal_not_found', 'Withdrawal not found.', [ 'status' => 404 ] );
		}
		if ( Wallet_Service::STATUS_PENDING !== $row['status'] ) {
			return new WP_Error( 'withdrawal_not_pending', 'Withdrawal is not pending.', [ 'status' => 409 ] );
		}
		return $row;
	}

	private function extract_notes( WP_REST_Request $request ): ?string {
		$notes = $request->get_param( 'notes' );
		if ( ! is_string( $notes ) ) {
			return null;
		}
		$notes = trim( $notes );
		return '' === $notes ? null : substr( $notes, 0, 2000 );
	}

	private function refetch( int $id ): array {
		return (array) Wallet_Service::get_transaction( $id );
	}

	private function serialize_row( array $row ): array {
		$user = get_userdata( (int) $row['user_id'] );
		$consumed = $row['consumed_lots'] ? json_decode( $row['consumed_lots'], true ) : [];
		return [
			'id'             => (int) $row['id'],
			'user_id'        => (int) $row['user_id'],
			'user_email'     => $user ? $user->user_email : null,
			'user_nickname'  => $user ? ( $user->nickname ?: $user->display_name ) : null,
			'amount_money'   => $row['amount_money'],
			'amount_coins'   => (int) $row['amount_coins'],
			'status'         => $row['status'],
			'notes'          => $row['notes'],
			'created_at'     => $row['created_at'],
			'settled_at'     => $row['settled_at'],
			'consumed_lots'  => is_array( $consumed ) ? $consumed : [],
		];
	}
}
