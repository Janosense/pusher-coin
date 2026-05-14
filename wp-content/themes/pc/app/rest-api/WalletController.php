<?php

namespace PC;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Player-facing wallet endpoints.
 *
 *   GET  /pc/v1/wallet           — balance + outstanding coin lots.
 *   POST /pc/v1/wallet/topup     — build a LiqPay checkout envelope.
 *
 * Withdrawals land in Step 4.
 */
class WalletController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'wallet';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base", [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_wallet' ],
			'permission_callback' => [ Permissions::class, 'require_logged_in' ],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/topup", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'topup' ],
			'permission_callback' => [ Permissions::class, 'require_play_ready' ],
			'args'                => [
				'coin_qty'   => [ 'type' => 'integer', 'required' => true ],
				'unit_price' => [ 'type' => 'string', 'required' => true ],
			],
		] );
	}

	public function get_wallet( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$wallet  = Wallet_Service::get_wallet( $user_id );
		$lots    = Wallet_Service::get_lots( $user_id );

		return new WP_REST_Response( [
			'balance_money' => $wallet['balance_money'],
			'balance_coins' => $wallet['balance_coins'],
			'lots'          => array_map( static fn( $lot ) => [
				'qty'        => $lot['qty'],
				'unit_price' => $lot['unit_price'],
			], $lots ),
			'coin_pricing'  => [
				'default' => (string) get_option( 'pc_coin_price_default', '40.00' ),
				'min'     => (string) get_option( 'pc_coin_price_min', '10.00' ),
				'max'     => (string) get_option( 'pc_coin_price_max', '500.00' ),
			],
		], 200 );
	}

	/**
	 * Start a top-up: validate inputs, reserve a pending transaction
	 * row, and hand the SPA a signed LiqPay envelope to POST to
	 * `https://www.liqpay.ua/api/3/checkout`.
	 *
	 * Settlement happens on the server via
	 * `POST /pc/v1/payments/liqpay/callback` — the SPA returning to
	 * `result_url` is a UX cue only, never a source of truth for the
	 * wallet state.
	 */
	public function topup( WP_REST_Request $request ) {
		if ( ! LiqPay_Client::is_configured() ) {
			return new WP_Error( 'liqpay_not_configured', 'LiqPay keys are not configured on the server.', [ 'status' => 500 ] );
		}

		$qty        = (int) $request->get_param( 'coin_qty' );
		$unit_price = $this->normalize_price( $request->get_param( 'unit_price' ) );

		if ( $qty <= 0 ) {
			return new WP_Error( 'invalid_coin_qty', 'coin_qty must be a positive integer.', [ 'status' => 400 ] );
		}
		if ( null === $unit_price ) {
			return new WP_Error( 'coin_price_out_of_bounds', 'unit_price must be a positive decimal.', [ 'status' => 400 ] );
		}

		$min = (string) get_option( 'pc_coin_price_min', '10.00' );
		$max = (string) get_option( 'pc_coin_price_max', '500.00' );
		if ( bccomp( $unit_price, $min, 2 ) < 0 || bccomp( $unit_price, $max, 2 ) > 0 ) {
			return new WP_Error(
				'coin_price_out_of_bounds',
				sprintf( 'unit_price must be between %s and %s.', $min, $max ),
				[ 'status' => 400 ]
			);
		}

		$user_id = get_current_user_id();
		$amount  = bcmul( (string) $qty, $unit_price, 2 );

		$txn_id = Wallet_Service::record_transaction(
			$user_id,
			Wallet_Service::TYPE_TOPUP,
			$amount,
			$qty,
			$unit_price,
			Wallet_Service::STATUS_PENDING,
			null,
			null
		);

		// `external_ref = order_id` lets the callback look up the row in
		// one indexed query and keeps the LiqPay-side identifier
		// readable in the merchant console.
		$order_id = sprintf( 'pc-topup-%d', $txn_id );
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'pc_transactions',
			[ 'external_ref' => $order_id ],
			[ 'id' => $txn_id ],
			[ '%s' ],
			[ '%d' ]
		);

		$envelope = LiqPay_Client::build_payment_envelope( [
			'action'      => 'pay',
			'amount'      => $amount,
			'currency'    => 'UAH',
			'description' => sprintf( 'Pusher Coin top-up — %d coin(s) @ %s UAH', $qty, $unit_price ),
			'order_id'    => $order_id,
			'server_url'  => rest_url( 'pc/v1/payments/liqpay/callback' ),
			'result_url'  => trailingslashit( (string) get_option( 'pc_spa_base_url', home_url() ) ) . 'account?topup=success',
		] );

		return new WP_REST_Response( [
			'transaction_id' => $txn_id,
			'order_id'       => $order_id,
			'amount'         => $amount,
			'checkout_url'   => LiqPay_Client::CHECKOUT_URL,
			'liqpay'         => $envelope,
		], 200 );
	}

	private function normalize_price( $raw ): ?string {
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
		}
		if ( ! is_numeric( $raw ) ) {
			return null;
		}
		$value = number_format( (float) $raw, 2, '.', '' );
		return bccomp( $value, '0.00', 2 ) > 0 ? $value : null;
	}
}
