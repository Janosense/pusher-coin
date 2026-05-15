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
