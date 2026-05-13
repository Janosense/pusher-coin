<?php

namespace PC;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Player-facing wallet endpoints.
 *
 *   GET /pc/v1/wallet — balance + outstanding coin lots.
 *
 * Top-up and withdrawal endpoints land in Step 2 and Step 4.
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
		], 200 );
	}
}
