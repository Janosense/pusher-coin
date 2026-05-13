<?php

namespace PC;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Application_Passwords;

class UserController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'user';
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base/sign-up/", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create_user' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => $this->get_endpoint_args_for_item_schema(),
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/request-verification/", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'request_verification_code' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/verify-code/", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'verify_code' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/set-nickname", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'set_nickname' ],
			'permission_callback' => [ Permissions::class, 'require_logged_in' ],
			'args'                => [
				'nickname' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/accept-terms", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'accept_terms' ],
			'permission_callback' => [ Permissions::class, 'require_logged_in' ],
			'args'                => [
				'version' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/me", [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_me' ],
				'permission_callback' => [ Permissions::class, 'require_logged_in' ],
			],
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'patch_me' ],
				'permission_callback' => [ Permissions::class, 'require_logged_in' ],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/request-email-confirmation", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'request_email_confirmation' ],
			'permission_callback' => [ Permissions::class, 'require_logged_in' ],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/confirm-email", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'confirm_email' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'token' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/request-password-change", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'request_password_change' ],
			'permission_callback' => [ Permissions::class, 'require_logged_in' ],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/confirm-password-change", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'confirm_password_change' ],
			'permission_callback' => [ Permissions::class, 'require_logged_in' ],
			'args'                => [
				'current_password' => [ 'type' => 'string', 'required' => true ],
				'new_password'     => [ 'type' => 'string', 'required' => true ],
				'code'             => [ 'type' => 'string', 'required' => true ],
			],
		] );
	}

	public function check_permission( WP_REST_Request $request ) {
		return true;
	}

	/**
	 * Create a new user
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_user( WP_REST_Request $request ) {
		$rate_check = Rate_Limiter::check( 'signup:' . Rate_Limiter::client_ip(), 10, DAY_IN_SECONDS );
		if ( is_wp_error( $rate_check ) ) {
			Audit_Log::record( 'rate_limited', array( 'metadata' => array( 'endpoint' => 'sign-up' ) ) );
			return $rate_check;
		}

		$email           = $request->get_param( 'email' );
		$nickname        = $request->get_param( 'nickname' );
		$phone           = $request->get_param( 'phone' );
		$password        = $request->get_param( 'password' );
		$terms_accepted  = (bool) $request->get_param( 'terms_accepted' );

		// Validate required fields
		if ( empty( $email ) || empty( $nickname ) || empty( $password ) ) {
			return new WP_Error(
				'missing_required_fields',
				__( 'Email, nickname, and password are required fields.' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $terms_accepted ) {
			return new WP_Error(
				'terms_not_accepted',
				__( 'Terms and conditions must be accepted.' ),
				array( 'status' => 403 )
			);
		}

		// Validate email format
		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'Please provide a valid email address.' ),
				array( 'status' => 400 )
			);
		}

		// Check if email already exists
		if ( email_exists( $email ) ) {
			return new WP_Error(
				'email_exists',
				__( 'An account with this email address already exists.' ),
				array( 'status' => 409 )
			);
		}

		// Check if username already exists
		if ( username_exists( $nickname ) ) {
			return new WP_Error(
				'username_exists',
				__( 'This nickname is already taken.' ),
				array( 'status' => 409 )
			);
		}

		// Validate password strength (minimum 6 characters)
		if ( strlen( $password ) < 6 ) {
			return new WP_Error(
				'weak_password',
				__( 'Password must be at least 6 characters long.' ),
				array( 'status' => 400 )
			);
		}

		// Prepare user data
		$user_data = array(
			'user_login'   => $nickname,
			'user_email'   => $email,
			'user_pass'    => $password,
			'nickname'     => $nickname,
			'display_name' => $nickname,
			'role'         => 'player', // Default role
		);

		// Create the user
		$user_id = wp_insert_user( $user_data );

		// Check for errors
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error(
				'user_creation_failed',
				$user_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Save phone number as user meta if provided
		if ( ! empty( $phone ) ) {
			update_user_meta( $user_id, User_Meta_Keys::PHONE, sanitize_text_field( $phone ) );
		}

		// Email signup means the user picked their own nickname.
		update_user_meta( $user_id, User_Meta_Keys::NICKNAME_CHOSEN, '1' );

		// Persist terms acceptance.
		$terms_version = get_option( 'pc_terms_current_version', '2026-05' );
		update_user_meta( $user_id, User_Meta_Keys::TERMS_ACCEPTED_AT, time() );
		update_user_meta( $user_id, User_Meta_Keys::TERMS_ACCEPTED_VERSION, $terms_version );

		Audit_Log::record( 'signup', array( 'user_id' => $user_id, 'email' => $email ) );

		// Prepare response data
		$user          = get_user_by( 'id', $user_id );
		$response_data = array(
			'id'       => $user_id,
			'email'    => $user->user_email,
			'nickname' => $user->nickname,
			'phone'    => get_user_meta( $user_id, User_Meta_Keys::PHONE, true ),
		);

		return new WP_REST_Response( $response_data, 201 );
	}

	/**
	 * Get the query params for collections
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return array();
	}

	/**
	 * Get the User schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema(): array {
		$schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'user',
			'type'       => 'object',
			'properties' => [
				'email'    => [
					'description' => __( 'User email address.' ),
					'type'        => 'string',
					'format'      => 'email',
					'required'    => true,
				],
				'nickname' => [
					'description' => __( 'User nickname.' ),
					'type'        => 'string',
					'required'    => true,
				],
				'phone'    => [
					'description' => __( 'User phone number.' ),
					'type'        => 'string',
					'required'    => false,
				],
				'password' => [
					'description' => __( 'User password.' ),
					'type'        => 'string',
					'required'    => true,
				],
			],
		];

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Request verification code
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function request_verification_code( WP_REST_Request $request ) {
		$rate_check = Rate_Limiter::check( 'request_verification:' . Rate_Limiter::client_ip(), 5, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $rate_check ) ) {
			Audit_Log::record( 'rate_limited', array( 'metadata' => array( 'endpoint' => 'request-verification' ) ) );
			return $rate_check;
		}

		$login    = $request->get_param( 'login' );
		$password = $request->get_param( 'password' );

		// Validate required fields
		if ( empty( $login ) || empty( $password ) ) {
			return new WP_Error(
				'missing_required_fields',
				__( 'Login/email and password are required fields.' ),
				array( 'status' => 400 )
			);
		}

		// Authenticate the user
		$user = wp_authenticate( $login, $password );

		// If the authentication fails return an error
		if ( is_wp_error( $user ) ) {
			Audit_Log::record( 'request_verification_failed', array( 'email' => $login ) );
			return new WP_Error(
				'authentication_failed',
				__( 'Invalid login credentials.' ),
				array( 'status' => 401 )
			);
		}

		Audit_Log::record( 'request_verification', array( 'user_id' => $user->ID, 'email' => $user->user_email ) );

		// Generate 6-digit verification code
		$verification_code = sprintf( '%06d', mt_rand( 0, 999999 ) );

		// Store verification code in user meta with expiration (15 minutes)
		update_user_meta( $user->ID, User_Meta_Keys::VERIFICATION_CODE, $verification_code );
		update_user_meta( $user->ID, User_Meta_Keys::VERIFICATION_CODE_EXPIRY, time() + ( 15 * 60 ) );

		// Send verification code via email
		$to      = $user->user_email;
		$subject = __( 'Your Verification Code' );
		$message = sprintf(
			__( "Hello %s,\n\nYour verification code is: %s\n\nThis code will expire in 15 minutes.\n\nIf you did not request this code, please ignore this email." ),
			$user->display_name,
			$verification_code
		);
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$email_sent = wp_mail( $to, $subject, $message, $headers );

		// Check if email was sent successfully
		if ( ! $email_sent ) {
			// Clean up stored verification code if email failed
			delete_user_meta( $user->ID, User_Meta_Keys::VERIFICATION_CODE );
			delete_user_meta( $user->ID, User_Meta_Keys::VERIFICATION_CODE_EXPIRY );

			return new WP_Error(
				'email_send_failed',
				__( 'Failed to send verification code email. Please try again later.' ),
				array( 'status' => 500 )
			);
		}

		// Return success response
		return new WP_REST_Response( array(
			'success' => true,
			'message' => __( 'Verification code has been sent to your email address.' )
		), 200 );
	}

	/**
	 * Verify code and return JWT token
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function verify_code( WP_REST_Request $request ): WP_Error|WP_REST_Response {
		$login             = $request->get_param( 'login' );
		$password          = $request->get_param( 'password' );
		$verification_code = $request->get_param( 'code' );

		// Validate required fields
		if ( empty( $login ) || empty( $password ) || empty( $verification_code ) ) {
			return new WP_Error(
				'missing_required_fields',
				__( 'Login/email, password, and verification code are required fields.' ),
				array( 'status' => 400 )
			);
		}

		// Authenticate the user
		$user = wp_authenticate( $login, $password );

		// If the authentication fails return an error
		if ( is_wp_error( $user ) ) {
			return new WP_Error(
				'authentication_failed',
				__( 'Invalid login credentials.' ),
				array( 'status' => 401 )
			);
		}

		// Get stored verification code
		$stored_code   = get_user_meta( $user->ID, User_Meta_Keys::VERIFICATION_CODE, true );
		$code_expiry   = get_user_meta( $user->ID, User_Meta_Keys::VERIFICATION_CODE_EXPIRY, true );

		// Check if verification code exists
		if ( empty( $stored_code ) ) {
			return new WP_Error(
				'no_verification_code',
				__( 'No verification code found. Please request a new one.' ),
				array( 'status' => 404 )
			);
		}

		// Check if verification code is expired
		if ( $code_expiry < time() ) {
			delete_user_meta( $user->ID, User_Meta_Keys::VERIFICATION_CODE );
			delete_user_meta( $user->ID, User_Meta_Keys::VERIFICATION_CODE_EXPIRY );
			return new WP_Error(
				'verification_code_expired',
				__( 'Verification code has expired. Please request a new one.' ),
				array( 'status' => 401 )
			);
		}

		// Verify the code
		if ( $stored_code !== $verification_code ) {
			Audit_Log::record( 'verify_failure', array( 'user_id' => $user->ID, 'metadata' => array( 'reason' => 'invalid_code' ) ) );
			return new WP_Error(
				'invalid_verification_code',
				__( 'Invalid verification code.' ),
				array( 'status' => 401 )
			);
		}

		// Clear verification code after successful verification
		delete_user_meta( $user->ID, User_Meta_Keys::VERIFICATION_CODE );
		delete_user_meta( $user->ID, User_Meta_Keys::VERIFICATION_CODE_EXPIRY );

		$envelope = AuthController::issue_token_pair( $user );
		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}

		Audit_Log::record( 'verify_success', array( 'user_id' => $user->ID, 'metadata' => array( 'method' => 'email' ) ) );

		return new WP_REST_Response( $envelope, 200 );
	}

	public function set_nickname( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$nickname = trim( (string) $request->get_param( 'nickname' ) );

		if ( ! preg_match( '/^[A-Za-z0-9_]{3,20}$/', $nickname ) ) {
			return new WP_Error(
				'invalid_nickname',
				__( 'Nickname must be 3–20 characters of letters, digits, or underscores.' ),
				array( 'status' => 400 )
			);
		}

		// Reject if any other user already uses it as user_login or nickname meta.
		$existing_login = get_user_by( 'login', $nickname );
		if ( $existing_login && (int) $existing_login->ID !== $user_id ) {
			return new WP_Error( 'nickname_taken', __( 'Nickname already in use.' ), array( 'status' => 409 ) );
		}
		$nickname_match = get_users( array(
			'meta_key'   => 'nickname',
			'meta_value' => $nickname,
			'exclude'    => array( $user_id ),
			'number'     => 1,
			'fields'     => 'ID',
		) );
		if ( ! empty( $nickname_match ) ) {
			return new WP_Error( 'nickname_taken', __( 'Nickname already in use.' ), array( 'status' => 409 ) );
		}

		$user = get_user_by( 'id', $user_id );
		wp_update_user( array(
			'ID'           => $user_id,
			'nickname'     => $nickname,
			'display_name' => $nickname,
		) );

		// Only rewrite user_login when the existing one is the auto-generated email-prefix
		// pattern (e.g. set by GoogleAuthController::generate_username_from_email).
		if ( $user && $user->user_login !== $nickname ) {
			global $wpdb;
			$wpdb->update( $wpdb->users, array( 'user_login' => $nickname ), array( 'ID' => $user_id ) );
			clean_user_cache( $user_id );
		}

		update_user_meta( $user_id, User_Meta_Keys::NICKNAME_CHOSEN, '1' );

		Audit_Log::record( 'set_nickname', array( 'user_id' => $user_id ) );

		return new WP_REST_Response( array( 'nickname' => $nickname ), 200 );
	}

	public function accept_terms( WP_REST_Request $request ) {
		$user_id          = get_current_user_id();
		$version          = (string) $request->get_param( 'version' );
		$current_version  = get_option( 'pc_terms_current_version', '2026-05' );

		if ( $version !== $current_version ) {
			return new WP_Error(
				'invalid_terms_version',
				__( 'Terms version is out of date.' ),
				array( 'status' => 400 )
			);
		}

		$now = time();
		update_user_meta( $user_id, User_Meta_Keys::TERMS_ACCEPTED_AT, $now );
		update_user_meta( $user_id, User_Meta_Keys::TERMS_ACCEPTED_VERSION, $current_version );

		Audit_Log::record( 'accept_terms', array( 'user_id' => $user_id, 'metadata' => array( 'version' => $current_version ) ) );

		return new WP_REST_Response( array(
			'terms_accepted_at'      => $now,
			'terms_accepted_version' => $current_version,
		), 200 );
	}

	public function get_me( WP_REST_Request $request ) {
		$user = wp_get_current_user();
		return new WP_REST_Response( $this->build_me_payload( $user ), 200 );
	}

	public function patch_me( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		if ( $request->has_param( 'phone' ) ) {
			$phone = trim( (string) $request->get_param( 'phone' ) );
			if ( $phone !== '' && ! preg_match( '/^[0-9 +()\-]{6,20}$/', $phone ) ) {
				return new WP_Error(
					'invalid_phone',
					__( 'Phone number is invalid.' ),
					array( 'status' => 400 )
				);
			}
			if ( $phone === '' ) {
				delete_user_meta( $user_id, User_Meta_Keys::PHONE );
			} else {
				update_user_meta( $user_id, User_Meta_Keys::PHONE, sanitize_text_field( $phone ) );
			}
		}

		return new WP_REST_Response( $this->build_me_payload( $user ), 200 );
	}

	private function build_me_payload( \WP_User $user ): array {
		$verified_at = (int) get_user_meta( $user->ID, User_Meta_Keys::EMAIL_VERIFIED_AT, true );
		$accepted_at = (int) get_user_meta( $user->ID, User_Meta_Keys::TERMS_ACCEPTED_AT, true );
		$accepted_version = (string) get_user_meta( $user->ID, User_Meta_Keys::TERMS_ACCEPTED_VERSION, true );
		$current_version = (string) get_option( 'pc_terms_current_version', '2026-05' );
		$wallet          = Wallet_Service::get_wallet( $user->ID );

		return array(
			'id'                     => $user->ID,
			'email'                  => $user->user_email,
			'email_verified'         => $verified_at > 0,
			'email_verified_at'      => $verified_at ?: null,
			'nickname'               => $user->nickname ?: $user->display_name,
			'phone'                  => (string) get_user_meta( $user->ID, User_Meta_Keys::PHONE, true ),
			'phone_verified'         => false,
			'terms_accepted'         => $accepted_at > 0 && $accepted_version === $current_version,
			'terms_accepted_version' => $accepted_version,
			'google_linked'          => ! empty( get_user_meta( $user->ID, User_Meta_Keys::GOOGLE_ID, true ) ),
			'apple_linked'           => ! empty( get_user_meta( $user->ID, User_Meta_Keys::APPLE_ID, true ) ),
			'balance_money'          => $wallet['balance_money'],
			'balance_coins'          => $wallet['balance_coins'],
		);
	}

	public function request_email_confirmation( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$user      = wp_get_current_user();
		$rate_check = Rate_Limiter::check( 'request_email_confirmation:' . $user_id, 5, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $rate_check ) ) {
			Audit_Log::record( 'rate_limited', array( 'user_id' => $user_id, 'metadata' => array( 'endpoint' => 'request-email-confirmation' ) ) );
			return $rate_check;
		}

		$token  = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$ttl    = (int) get_option( 'pc_email_confirmation_ttl_seconds', 86400 );
		$expiry = time() + $ttl;

		update_user_meta( $user_id, User_Meta_Keys::EMAIL_CONFIRMATION_TOKEN, $token );
		update_user_meta( $user_id, User_Meta_Keys::EMAIL_CONFIRMATION_EXPIRY, $expiry );

		$base_url     = (string) get_option( 'pc_spa_base_url', home_url() );
		$confirm_link = rtrim( $base_url, '/' ) . '/confirm-email?token=' . rawurlencode( $token );

		$message = sprintf(
			__( "Hello %s,\n\nConfirm your email by visiting the link below within 24 hours:\n\n%s\n\nIf you did not request this, you can ignore this message." ),
			$user->display_name,
			$confirm_link
		);

		$sent = wp_mail( $user->user_email, __( 'Confirm your email address' ), $message, array( 'Content-Type: text/plain; charset=UTF-8' ) );

		if ( ! $sent ) {
			delete_user_meta( $user_id, User_Meta_Keys::EMAIL_CONFIRMATION_TOKEN );
			delete_user_meta( $user_id, User_Meta_Keys::EMAIL_CONFIRMATION_EXPIRY );
			return new WP_Error(
				'email_send_failed',
				__( 'Failed to send confirmation email. Please try again later.' ),
				array( 'status' => 500 )
			);
		}

		Audit_Log::record( 'request_email_confirmation', array( 'user_id' => $user_id, 'email' => $user->user_email ) );

		return new WP_REST_Response( array(
			'success' => true,
			'message' => __( 'A confirmation link has been sent to your email address.' )
		), 200 );
	}

	public function confirm_email( WP_REST_Request $request ) {
		$token = trim( (string) $request->get_param( 'token' ) );
		if ( $token === '' ) {
			return new WP_Error( 'token_invalid', __( 'Confirmation token is invalid.' ), array( 'status' => 401 ) );
		}

		$users = get_users( array(
			'meta_key'   => User_Meta_Keys::EMAIL_CONFIRMATION_TOKEN,
			'meta_value' => $token,
			'number'     => 1,
		) );
		$user = ! empty( $users ) ? $users[0] : null;

		if ( ! $user ) {
			return new WP_Error( 'token_invalid', __( 'Confirmation token is invalid.' ), array( 'status' => 401 ) );
		}

		$expiry = (int) get_user_meta( $user->ID, User_Meta_Keys::EMAIL_CONFIRMATION_EXPIRY, true );
		if ( $expiry <= 0 || $expiry < time() ) {
			delete_user_meta( $user->ID, User_Meta_Keys::EMAIL_CONFIRMATION_TOKEN );
			delete_user_meta( $user->ID, User_Meta_Keys::EMAIL_CONFIRMATION_EXPIRY );
			return new WP_Error( 'token_expired', __( 'Confirmation token has expired.' ), array( 'status' => 401 ) );
		}

		update_user_meta( $user->ID, User_Meta_Keys::EMAIL_VERIFIED_AT, time() );
		delete_user_meta( $user->ID, User_Meta_Keys::EMAIL_CONFIRMATION_TOKEN );
		delete_user_meta( $user->ID, User_Meta_Keys::EMAIL_CONFIRMATION_EXPIRY );

		Audit_Log::record( 'confirm_email', array( 'user_id' => $user->ID, 'email' => $user->user_email ) );

		return new WP_REST_Response( array(
			'success'           => true,
			'email_verified_at' => time(),
		), 200 );
	}

	public function request_password_change( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		$rate_check = Rate_Limiter::check( 'request_password_change:' . $user_id, 5, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $rate_check ) ) {
			Audit_Log::record( 'rate_limited', array( 'user_id' => $user_id, 'metadata' => array( 'endpoint' => 'request-password-change' ) ) );
			return $rate_check;
		}

		$code   = sprintf( '%06d', mt_rand( 0, 999999 ) );
		$ttl    = (int) get_option( 'pc_password_change_ttl_seconds', 900 );
		$expiry = time() + $ttl;

		update_user_meta( $user_id, User_Meta_Keys::PASSWORD_CHANGE_CODE, $code );
		update_user_meta( $user_id, User_Meta_Keys::PASSWORD_CHANGE_CODE_EXPIRY, $expiry );

		$message = sprintf(
			__( "Hello %s,\n\nYour password change code is: %s\n\nThe code is valid for 15 minutes." ),
			$user->display_name,
			$code
		);
		$sent = wp_mail( $user->user_email, __( 'Password change code' ), $message, array( 'Content-Type: text/plain; charset=UTF-8' ) );

		if ( ! $sent ) {
			delete_user_meta( $user_id, User_Meta_Keys::PASSWORD_CHANGE_CODE );
			delete_user_meta( $user_id, User_Meta_Keys::PASSWORD_CHANGE_CODE_EXPIRY );
			return new WP_Error(
				'email_send_failed',
				__( 'Failed to send password change code. Please try again later.' ),
				array( 'status' => 500 )
			);
		}

		Audit_Log::record( 'request_password_change', array( 'user_id' => $user_id ) );

		return new WP_REST_Response( array(
			'success' => true,
			'message' => __( 'A 6-digit code has been sent to your email address.' )
		), 200 );
	}

	public function confirm_password_change( WP_REST_Request $request ) {
		$user_id          = get_current_user_id();
		$user             = wp_get_current_user();
		$current_password = (string) $request->get_param( 'current_password' );
		$new_password     = (string) $request->get_param( 'new_password' );
		$code             = (string) $request->get_param( 'code' );

		if ( $current_password === '' || $new_password === '' || $code === '' ) {
			return new WP_Error( 'missing_required_fields', __( 'All fields are required.' ), array( 'status' => 400 ) );
		}
		if ( strlen( $new_password ) < 6 ) {
			return new WP_Error( 'weak_password', __( 'Password must be at least 6 characters long.' ), array( 'status' => 400 ) );
		}

		$auth = wp_authenticate( $user->user_login, $current_password );
		if ( is_wp_error( $auth ) ) {
			Audit_Log::record( 'password_change_failure', array( 'user_id' => $user_id, 'metadata' => array( 'reason' => 'wrong_current_password' ) ) );
			return new WP_Error( 'authentication_failed', __( 'Current password is incorrect.' ), array( 'status' => 401 ) );
		}

		$stored_code = (string) get_user_meta( $user_id, User_Meta_Keys::PASSWORD_CHANGE_CODE, true );
		$expiry      = (int) get_user_meta( $user_id, User_Meta_Keys::PASSWORD_CHANGE_CODE_EXPIRY, true );

		if ( $stored_code === '' ) {
			return new WP_Error( 'no_verification_code', __( 'No password change code on file.' ), array( 'status' => 404 ) );
		}
		if ( $expiry < time() ) {
			delete_user_meta( $user_id, User_Meta_Keys::PASSWORD_CHANGE_CODE );
			delete_user_meta( $user_id, User_Meta_Keys::PASSWORD_CHANGE_CODE_EXPIRY );
			return new WP_Error( 'verification_code_expired', __( 'Password change code has expired.' ), array( 'status' => 401 ) );
		}
		if ( ! hash_equals( $stored_code, $code ) ) {
			Audit_Log::record( 'password_change_failure', array( 'user_id' => $user_id, 'metadata' => array( 'reason' => 'invalid_code' ) ) );
			return new WP_Error( 'invalid_verification_code', __( 'Invalid password change code.' ), array( 'status' => 401 ) );
		}

		wp_set_password( $new_password, $user_id );
		delete_user_meta( $user_id, User_Meta_Keys::PASSWORD_CHANGE_CODE );
		delete_user_meta( $user_id, User_Meta_Keys::PASSWORD_CHANGE_CODE_EXPIRY );
		Refresh_Tokens::revoke_all_for_user( $user_id );

		// Re-fetch the user to pick up the updated password hash, then issue a fresh pair.
		$fresh_user = get_user_by( 'id', $user_id );
		$envelope   = AuthController::issue_token_pair( $fresh_user );
		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}

		Audit_Log::record( 'password_change', array( 'user_id' => $user_id ) );

		return new WP_REST_Response( $envelope, 200 );
	}
}