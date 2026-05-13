<?php

namespace PC;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Cross-cutting admin endpoints that don't belong to a specific resource.
 *
 *   GET /pc/v1/admin/me — bearer-protected probe used by the admin SPA to
 *   confirm the current session is both authenticated *and* has admin
 *   capabilities. Returns 403 for any logged-in non-admin so the SPA can
 *   bounce them at sign-in time instead of after every action.
 */
class AdminController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'admin';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base/me", [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_me' ],
			'permission_callback' => [ Permissions::class, 'require_admin' ],
		] );
	}

	public function get_me(): WP_REST_Response {
		$user = wp_get_current_user();
		return new WP_REST_Response( [
			'id'            => (int) $user->ID,
			'email'         => $user->user_email,
			'display_name'  => $user->display_name,
			'capabilities'  => [ 'manage_options' => true ],
		], 200 );
	}
}
