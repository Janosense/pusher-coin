<?php

namespace PC;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin endpoints that drive the physical pusher machine.
 *
 *   GET  /pc/v1/admin/machine/state
 *   POST /pc/v1/admin/machine/power
 *   GET  /pc/v1/admin/machine/bonus-map
 *   PUT  /pc/v1/admin/machine/bonus-map
 *
 * Both are gated by `Permissions::require_admin`. The actual HA calls
 * live in `Machine_Service`; this controller is just the HTTP boundary
 * — input validation, error-code → status mapping, JSON shaping.
 */
class AdminMachineController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'admin/machine';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base/state", [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_state' ],
			'permission_callback' => [ Permissions::class, 'require_admin' ],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/power", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'set_power' ],
			'permission_callback' => [ Permissions::class, 'require_admin' ],
			'args'                => [
				'on' => [ 'type' => 'boolean', 'required' => true ],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/bonus-map", [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_bonus_map' ],
				'permission_callback' => [ Permissions::class, 'require_admin' ],
			],
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'put_bonus_map' ],
				'permission_callback' => [ Permissions::class, 'require_admin' ],
				'args'                => [
					'map'              => [ 'required' => true ],
					'relay_coin_count' => [ 'required' => true ],
				],
			],
		] );
	}

	public function get_state( WP_REST_Request $request ) {
		if ( ! Machine_Service::is_configured() ) {
			return new WP_Error(
				'machine_not_configured',
				'PC_MACHINE_TOKEN is not set in wp-config.php.',
				[ 'status' => 500 ]
			);
		}
		return new WP_REST_Response( Machine_Service::get_state_snapshot(), 200 );
	}

	public function set_power( WP_REST_Request $request ) {
		$on = $request->get_param( 'on' );
		if ( ! is_bool( $on ) ) {
			return new WP_Error( 'missing_required_fields', '`on` must be boolean.', [ 'status' => 400 ] );
		}

		$result = $on ? Machine_Service::power_on() : Machine_Service::power_off();
		if ( $result instanceof WP_Error ) {
			return $this->machine_error_response( $result );
		}

		Audit_Log::record( 'machine_power_changed', [
			'user_id'  => get_current_user_id(),
			'metadata' => [ 'on' => $on ],
		] );

		return new WP_REST_Response( [ 'ok' => true, 'on' => $on ], 200 );
	}

	public function get_bonus_map(): WP_REST_Response {
		return new WP_REST_Response( $this->read_bonus_payload(), 200 );
	}

	public function put_bonus_map( WP_REST_Request $request ) {
		$raw_map = $request->get_param( 'map' );
		if ( ! is_array( $raw_map ) ) {
			return new WP_Error( 'invalid_bonus_map', '`map` must be an object with keys 1..12.', [ 'status' => 400 ] );
		}

		$normalised = [];
		for ( $i = 1; $i <= 12; $i++ ) {
			$key = (string) $i;
			if ( ! array_key_exists( $key, $raw_map ) && ! array_key_exists( $i, $raw_map ) ) {
				return new WP_Error( 'invalid_bonus_map', sprintf( 'Missing entry for bonus number %d.', $i ), [ 'status' => 400 ] );
			}
			$value = $raw_map[ $key ] ?? $raw_map[ $i ];
			if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
				return new WP_Error( 'invalid_bonus_map', sprintf( 'Bonus %d must be a non-negative integer.', $i ), [ 'status' => 400 ] );
			}
			$normalised[ $key ] = max( 0, (int) $value );
		}

		$relay_raw = $request->get_param( 'relay_coin_count' );
		if ( ! is_int( $relay_raw ) && ! ( is_string( $relay_raw ) && ctype_digit( $relay_raw ) ) ) {
			return new WP_Error( 'invalid_bonus_map', 'relay_coin_count must be a non-negative integer.', [ 'status' => 400 ] );
		}
		$relay_coin_count = max( 0, (int) $relay_raw );

		update_option( 'pc_machine_bonus_map', wp_json_encode( $normalised ) );
		update_option( 'pc_machine_relay_coin_count', $relay_coin_count );

		Audit_Log::record( 'machine_bonus_map_updated', [
			'user_id'  => get_current_user_id(),
			'metadata' => [ 'relay_coin_count' => $relay_coin_count ],
		] );

		return new WP_REST_Response( $this->read_bonus_payload(), 200 );
	}

	/**
	 * Returns the bonus map + relay coin count. Reads go through
	 * `Machine_Ingest_Service` so the admin form and the payout path can
	 * never disagree about what the stored map means; missing entries
	 * default to 0 there, so a fresh install renders a usable form.
	 */
	private function read_bonus_payload(): array {
		return [
			'map'              => Machine_Ingest_Service::bonus_map(),
			'relay_coin_count' => Machine_Ingest_Service::relay_coin_count(),
		];
	}

	/**
	 * Map Machine_Service error codes to HTTP statuses. `machine_unauthorized`
	 * deliberately becomes 502, not 401 — the client's auth is fine; it's
	 * the upstream HA that rejected us. Returning 401 here would trick the
	 * admin SPA's bearer-refresh interceptor into clearing the admin
	 * session.
	 */
	private function machine_error_response( WP_Error $err ): WP_Error {
		$code = $err->get_error_code();
		$status = match ( $code ) {
			'machine_not_configured'    => 500,
			'machine_offline'           => 503,
			'machine_unavailable_state' => 503,
			default                     => 502,
		};
		return new WP_Error( $code, $err->get_error_message(), [ 'status' => $status ] );
	}
}
