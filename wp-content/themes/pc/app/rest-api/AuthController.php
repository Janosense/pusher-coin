<?php

namespace PC;

use Exception;
use Tmeister\Firebase\JWT\JWT;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Logout / refresh endpoints, plus the shared token-pair helpers used by
 * UserController, GoogleAuthController, and AppleAuthController.
 */
class AuthController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'auth';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base/logout", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'logout' ],
			'permission_callback' => [ Permissions::class, 'require_logged_in' ],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/refresh", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'refresh' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function logout( WP_REST_Request $request ) {
		$refresh_token = (string) $request->get_param( 'refresh_token' );
		$user_id       = get_current_user_id();

		if ( $refresh_token !== '' ) {
			Refresh_Tokens::revoke( $refresh_token );
		}

		Audit_Log::record( 'logout', array( 'user_id' => $user_id ) );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	public function refresh( WP_REST_Request $request ) {
		$refresh_token = (string) $request->get_param( 'refresh_token' );
		if ( $refresh_token === '' ) {
			return new WP_Error(
				'missing_required_fields',
				__( 'Refresh token is required.' ),
				array( 'status' => 400 )
			);
		}

		$redeemed = Refresh_Tokens::redeem( $refresh_token );
		if ( is_wp_error( $redeemed ) ) {
			return $redeemed;
		}

		$user = get_user_by( 'id', $redeemed['user_id'] );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', __( 'User not found.' ), array( 'status' => 404 ) );
		}

		$access_token = self::issue_access_token( $user );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		Audit_Log::record( 'refresh', array( 'user_id' => $user->ID ) );

		return new WP_REST_Response(
			self::build_envelope( $user, $access_token, $redeemed['plaintext'] ),
			200
		);
	}

	/**
	 * Issue a fresh access JWT and refresh token, return the full envelope.
	 *
	 * @return array|WP_Error
	 */
	public static function issue_token_pair( \WP_User $user ) {
		$access_token = self::issue_access_token( $user );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}
		$refresh_token = Refresh_Tokens::issue( $user->ID );
		return self::build_envelope( $user, $access_token, $refresh_token );
	}

	/**
	 * Mint a short-lived JWT bound to the user.
	 *
	 * @return string|WP_Error
	 */
	public static function issue_access_token( \WP_User $user ) {
		$secret_key = defined( 'JWT_AUTH_SECRET_KEY' ) ? JWT_AUTH_SECRET_KEY : false;
		if ( ! $secret_key ) {
			return new WP_Error(
				'jwt_not_configured',
				__( 'JWT is not configured properly. Please contact the administrator.' ),
				array( 'status' => 500 )
			);
		}
		if ( ! class_exists( 'Tmeister\\Firebase\\JWT\\JWT' ) ) {
			return new WP_Error(
				'jwt_library_missing',
				__( 'JWT library is not available.' ),
				array( 'status' => 500 )
			);
		}

		$access_ttl = (int) get_option( 'pc_access_token_ttl_seconds', 900 );
		$issued_at  = time();

		$payload = array(
			'iss'  => get_bloginfo( 'url' ),
			'iat'  => $issued_at,
			'nbf'  => apply_filters( 'jwt_auth_not_before', $issued_at, $issued_at ),
			'exp'  => $issued_at + $access_ttl,
			'data' => array(
				'user' => array( 'id' => $user->ID ),
			),
		);

		try {
			return JWT::encode(
				apply_filters( 'jwt_auth_token_before_sign', $payload, $user ),
				$secret_key,
				apply_filters( 'jwt_auth_algorithm', 'HS256' )
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'jwt_encoding_failed',
				sprintf( __( 'Failed to generate JWT token: %s' ), $e->getMessage() ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Build the canonical auth response envelope around an existing token pair.
	 */
	public static function build_envelope( \WP_User $user, string $access_token, string $refresh_token ): array {
		$access_ttl  = (int) get_option( 'pc_access_token_ttl_seconds', 900 );
		$refresh_ttl = (int) get_option( 'pc_refresh_token_ttl_seconds', 604800 );

		$current_version  = get_option( 'pc_terms_current_version', '2026-05' );
		$accepted_at      = (int) get_user_meta( $user->ID, User_Meta_Keys::TERMS_ACCEPTED_AT, true );
		$accepted_version = get_user_meta( $user->ID, User_Meta_Keys::TERMS_ACCEPTED_VERSION, true );
		$nickname_chosen  = get_user_meta( $user->ID, User_Meta_Keys::NICKNAME_CHOSEN, true ) === '1';
		$email_verified   = (int) get_user_meta( $user->ID, User_Meta_Keys::EMAIL_VERIFIED_AT, true ) > 0;

		$envelope = array(
			'access_token'             => $access_token,
			'access_token_expires_in'  => $access_ttl,
			'refresh_token'            => $refresh_token,
			'refresh_token_expires_in' => $refresh_ttl,
			'user_id'                  => $user->ID,
			'user_email'               => $user->user_email,
			'user_nicename'            => $user->user_nicename,
			'user_display_name'        => $user->display_name,
			'terms_accepted'           => $accepted_at > 0 && $accepted_version === $current_version,
			'nickname_required'        => ! $nickname_chosen,
			'email_verified'           => $email_verified,
		);

		return apply_filters( 'jwt_auth_token_before_dispatch', $envelope, $user );
	}
}
