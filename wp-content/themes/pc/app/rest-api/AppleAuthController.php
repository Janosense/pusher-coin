<?php

namespace PC;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Apple Sign-In controller. Phase 1 ships the route surface and the
 * email-code 2FA flow that mirrors GoogleAuthController. Until the
 * APPLE_CLIENT_ID / APPLE_TEAM_ID / APPLE_KEY_ID / APPLE_PRIVATE_KEY
 * constants (or matching options) are set, every call returns
 * `apple_not_configured` (500) so the SPA can render the button without
 * the backend pretending Apple is wired up.
 *
 * Wiring Apple later is a config flip plus implementing
 * `verify_apple_token()` against `https://appleid.apple.com/auth/keys`.
 */
class AppleAuthController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'apple-auth';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base/authentication", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'authenticate_with_apple' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'id_token' => [
					'description' => __( 'Apple identity token from Sign in with Apple.' ),
					'type'        => 'string',
					'required'    => true,
				],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/verify-code", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'verify_apple_code' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'id_token'          => [ 'type' => 'string', 'required' => true ],
				'verification_code' => [ 'type' => 'string', 'required' => true ],
			],
		] );
	}

	public function authenticate_with_apple( WP_REST_Request $request ) {
		$check = Rate_Limiter::check( 'apple_auth:' . Rate_Limiter::client_ip(), 5, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $check ) ) {
			Audit_Log::record( 'rate_limited', array( 'metadata' => array( 'endpoint' => 'apple-auth/authentication' ) ) );
			return $check;
		}

		if ( ! self::is_configured() ) {
			return new WP_Error(
				'apple_not_configured',
				__( 'Apple Sign-In is not configured.' ),
				array( 'status' => 500 )
			);
		}

		// When configured, mirror GoogleAuthController::authenticate_with_google:
		//   - verify the token against Apple's keys,
		//   - upsert the user (storing User_Meta_Keys::APPLE_ID),
		//   - generate a 6-digit code into APPLE_VERIFICATION_CODE / APPLE_VERIFICATION_CODE_EXPIRY,
		//   - email it,
		//   - return { requires_verification, success, message }.
		// Implementation deferred until Apple Developer enrollment is complete.
		return new WP_Error(
			'apple_not_configured',
			__( 'Apple Sign-In is not configured.' ),
			array( 'status' => 500 )
		);
	}

	public function verify_apple_code( WP_REST_Request $request ) {
		if ( ! self::is_configured() ) {
			return new WP_Error(
				'apple_not_configured',
				__( 'Apple Sign-In is not configured.' ),
				array( 'status' => 500 )
			);
		}

		// Mirrors GoogleAuthController::verify_google_code once enabled. Will return
		// AuthController::issue_token_pair( $user ) on success.
		return new WP_Error(
			'apple_not_configured',
			__( 'Apple Sign-In is not configured.' ),
			array( 'status' => 500 )
		);
	}

	private static function is_configured(): bool {
		$client_id   = defined( 'APPLE_CLIENT_ID' ) ? APPLE_CLIENT_ID : get_option( 'apple_client_id' );
		$team_id     = defined( 'APPLE_TEAM_ID' ) ? APPLE_TEAM_ID : get_option( 'apple_team_id' );
		$key_id      = defined( 'APPLE_KEY_ID' ) ? APPLE_KEY_ID : get_option( 'apple_key_id' );
		$private_key = defined( 'APPLE_PRIVATE_KEY' ) ? APPLE_PRIVATE_KEY : get_option( 'apple_private_key' );

		return ! empty( $client_id ) && ! empty( $team_id ) && ! empty( $key_id ) && ! empty( $private_key );
	}
}
