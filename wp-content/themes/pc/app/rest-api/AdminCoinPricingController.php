<?php

namespace PC;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin read/write of the per-coin price bounds.
 *
 *   GET /pc/v1/admin/coin-pricing
 *   PUT /pc/v1/admin/coin-pricing
 *
 * The three values are stored as WP options (`pc_coin_price_default`,
 * `pc_coin_price_min`, `pc_coin_price_max`) as decimal strings (UAH).
 * The player `GET /wallet` response mirrors them so the SPA can clamp
 * client-side; the server re-checks the same bounds on every top-up.
 *
 * Gated by `Permissions::require_admin`.
 */
class AdminCoinPricingController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'admin/coin-pricing';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base", [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_pricing' ],
				'permission_callback' => [ Permissions::class, 'require_admin' ],
			],
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'put_pricing' ],
				'permission_callback' => [ Permissions::class, 'require_admin' ],
				'args'                => [
					'default' => [ 'type' => 'string', 'required' => true ],
					'min'     => [ 'type' => 'string', 'required' => true ],
					'max'     => [ 'type' => 'string', 'required' => true ],
				],
			],
		] );
	}

	public function get_pricing(): WP_REST_Response {
		return new WP_REST_Response( $this->payload(), 200 );
	}

	public function put_pricing( WP_REST_Request $request ) {
		$default = $this->normalize( $request->get_param( 'default' ) );
		$min     = $this->normalize( $request->get_param( 'min' ) );
		$max     = $this->normalize( $request->get_param( 'max' ) );

		if ( null === $default || null === $min || null === $max ) {
			return new WP_Error( 'invalid_coin_price', 'default, min, and max must be positive decimal values.', [ 'status' => 400 ] );
		}

		if ( bccomp( $min, $max, 2 ) > 0 ) {
			return new WP_Error( 'coin_price_bounds_invalid', 'min must be less than or equal to max.', [ 'status' => 400 ] );
		}
		if ( bccomp( $default, $min, 2 ) < 0 || bccomp( $default, $max, 2 ) > 0 ) {
			return new WP_Error( 'coin_price_bounds_invalid', 'default must be between min and max.', [ 'status' => 400 ] );
		}

		update_option( 'pc_coin_price_default', $default );
		update_option( 'pc_coin_price_min', $min );
		update_option( 'pc_coin_price_max', $max );

		return new WP_REST_Response( $this->payload(), 200 );
	}

	private function payload(): array {
		return [
			'default' => (string) get_option( 'pc_coin_price_default', '40.00' ),
			'min'     => (string) get_option( 'pc_coin_price_min', '10.00' ),
			'max'     => (string) get_option( 'pc_coin_price_max', '500.00' ),
		];
	}

	/**
	 * Normalises a price input to a 2-decimal string > 0. Returns null
	 * on anything non-numeric or non-positive.
	 */
	private function normalize( $raw ): ?string {
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
