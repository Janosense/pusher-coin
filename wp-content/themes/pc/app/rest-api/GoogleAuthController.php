<?php

namespace PC;

use Exception;
use Google_Client;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class GoogleAuthController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'google-auth';
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base/authentication", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'authenticate_with_google' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'id_token' => [
					'description' => __( 'Google ID token from Sign-In.' ),
					'type'        => 'string',
					'required'    => true,
				],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/verify-code", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'verify_google_code' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'id_token' => [
					'description' => __( 'Google ID token from Sign-In.' ),
					'type'        => 'string',
					'required'    => true,
				],
				'verification_code' => [
					'description' => __( 'Verification code sent to email.' ),
					'type'        => 'string',
					'required'    => true,
				],
			],
		] );
	}

	/**
	 * Authenticate user with Google ID token and send verification code
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function authenticate_with_google( WP_REST_Request $request ) {
		$rate_check = Rate_Limiter::check( 'google_auth:' . Rate_Limiter::client_ip(), 5, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $rate_check ) ) {
			Audit_Log::record( 'rate_limited', array( 'metadata' => array( 'endpoint' => 'google-auth/authentication' ) ) );
			return $rate_check;
		}

		$id_token = $request->get_param( 'id_token' );

		// Validate that ID token is provided
		if ( empty( $id_token ) ) {
			return new WP_Error(
				'missing_id_token',
				__( 'Google ID token is required.' ),
				array( 'status' => 400 )
			);
		}

		// Verify the Google ID token
		$payload = $this->verify_google_token( $id_token );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Extract user information from the token payload
		$google_id = $payload['sub'] ?? '';
		$email     = $payload['email'] ?? '';
		$name      = $payload['name'] ?? '';
		$verified  = $payload['email_verified'] ?? false;

		// Validate required fields from Google
		if ( empty( $google_id ) || empty( $email ) ) {
			return new WP_Error(
				'invalid_token_data',
				__( 'Invalid Google token data: missing required fields.' ),
				array( 'status' => 400 )
			);
		}

		// Check if email is verified
		if ( ! $verified ) {
			return new WP_Error(
				'email_not_verified',
				__( 'Email address is not verified by Google.' ),
				array( 'status' => 403 )
			);
		}

		// Check if user exists by email
		$user = get_user_by( 'email', $email );

		if ( $user ) {
			// User exists - authenticate them
			$user_id = $user->ID;

			// Update Google ID if not already set
			$existing_google_id = get_user_meta( $user_id, User_Meta_Keys::GOOGLE_ID, true );
			if ( empty( $existing_google_id ) ) {
				update_user_meta( $user_id, User_Meta_Keys::GOOGLE_ID, sanitize_text_field( $google_id ) );
			}
		} else {
			// User doesn't exist - create new user
			$user_id = $this->create_user_from_google( $email, $name, $google_id );

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			$user = get_user_by( 'id', $user_id );
		}

		// Generate 6-digit verification code
		$verification_code = sprintf( '%06d', mt_rand( 0, 999999 ) );

		// Store verification code in user meta with expiration (15 minutes)
		update_user_meta( $user->ID, User_Meta_Keys::GOOGLE_VERIFICATION_CODE, $verification_code );
		update_user_meta( $user->ID, User_Meta_Keys::GOOGLE_VERIFICATION_CODE_EXPIRY, time() + ( 15 * 60 ) );

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
			delete_user_meta( $user->ID, User_Meta_Keys::GOOGLE_VERIFICATION_CODE );
			delete_user_meta( $user->ID, User_Meta_Keys::GOOGLE_VERIFICATION_CODE_EXPIRY );

			return new WP_Error(
				'email_send_failed',
				__( 'Failed to send verification code email. Please try again later.' ),
				array( 'status' => 500 )
			);
		}

		// Return success response
		return new WP_REST_Response( array(
			'requires_verification' => true,
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
	public function verify_google_code( WP_REST_Request $request ) {
		$id_token          = $request->get_param( 'id_token' );
		$verification_code = $request->get_param( 'verification_code' );

		// Validate required fields
		if ( empty( $id_token ) || empty( $verification_code ) ) {
			return new WP_Error(
				'missing_required_fields',
				__( 'Google ID token and verification code are required fields.' ),
				array( 'status' => 400 )
			);
		}

		// Verify the Google ID token
		$payload = $this->verify_google_token( $id_token );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Extract user information from the token payload
		$email = $payload['email'] ?? '';

		if ( empty( $email ) ) {
			return new WP_Error(
				'invalid_token_data',
				__( 'Invalid Google token data: missing email.' ),
				array( 'status' => 400 )
			);
		}

		// Get user by email
		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found. Please authenticate first.' ),
				array( 'status' => 404 )
			);
		}

		// Get stored verification code
		$stored_code = get_user_meta( $user->ID, User_Meta_Keys::GOOGLE_VERIFICATION_CODE, true );
		$code_expiry = get_user_meta( $user->ID, User_Meta_Keys::GOOGLE_VERIFICATION_CODE_EXPIRY, true );

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
			delete_user_meta( $user->ID, User_Meta_Keys::GOOGLE_VERIFICATION_CODE );
			delete_user_meta( $user->ID, User_Meta_Keys::GOOGLE_VERIFICATION_CODE_EXPIRY );
			return new WP_Error(
				'verification_code_expired',
				__( 'Verification code has expired. Please request a new one.' ),
				array( 'status' => 401 )
			);
		}

		// Verify the code
		if ( $stored_code !== $verification_code ) {
			Audit_Log::record( 'verify_failure', array( 'user_id' => $user->ID, 'metadata' => array( 'reason' => 'invalid_code', 'method' => 'google' ) ) );
			return new WP_Error(
				'invalid_verification_code',
				__( 'Invalid verification code.' ),
				array( 'status' => 401 )
			);
		}

		// Clear verification code after successful verification
		delete_user_meta( $user->ID, User_Meta_Keys::GOOGLE_VERIFICATION_CODE );
		delete_user_meta( $user->ID, User_Meta_Keys::GOOGLE_VERIFICATION_CODE_EXPIRY );

		$envelope = AuthController::issue_token_pair( $user );
		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}

		Audit_Log::record( 'verify_success', array( 'user_id' => $user->ID, 'metadata' => array( 'method' => 'google' ) ) );

		return new WP_REST_Response( $envelope, 200 );
	}

	/**
	 * Verify Google ID token
	 *
	 * @param string $id_token The Google ID token to verify.
	 *
	 * @return array|WP_Error Token payload on success, WP_Error on failure.
	 */
	private function verify_google_token( string $id_token ) {
		try {

			// Get Google Client ID from WordPress options or define
			$google_client_id = defined( 'GOOGLE_CLIENT_ID' ) ? GOOGLE_CLIENT_ID : get_option( 'google_client_id' );

			if ( empty( $google_client_id ) ) {
				return new WP_Error(
					'google_not_configured',
					__( 'Google authentication is not properly configured. Please contact the administrator.' ),
					array( 'status' => 500 )
				);
			}

			$client = new Google_Client( [ 'client_id' => $google_client_id ] );

			// Verify the ID token
			$payload = $client->verifyIdToken( $id_token );

			if ( ! $payload ) {
				return new WP_Error(
					'invalid_id_token',
					__( 'Invalid Google ID token.' ),
					array( 'status' => 401 )
				);
			}

			return $payload;

		} catch ( Exception $e ) {
			return new WP_Error(
				'token_verification_failed',
				sprintf( __( 'Token verification failed: %s' ), $e->getMessage() ),
				array( 'status' => 401 )
			);
		}
	}

	/**
	 * Create a new WordPress user from Google data
	 *
	 * @param string $email The user's email address.
	 * @param string $name The user's full name from Google.
	 * @param string $google_id The user's Google ID.
	 *
	 * @return int|WP_Error User ID on success, WP_Error on failure.
	 */
	private function create_user_from_google( string $email, string $name, string $google_id ) {
		// Generate username from email
		$username = $this->generate_username_from_email( $email );

		// Generate a random password (user won't need it for Google Sign-In)
		$password = wp_generate_password( 20, true, true );

		// Prepare user data
		$user_data = array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => $password,
			'display_name' => $name,
			'nickname'     => $username,
			'role'         => 'player', // Set role to "Player" as per requirements
		);

		// Create the user
		$user_id = wp_insert_user( $user_data );

		// Check for errors
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error(
				'user_creation_failed',
				sprintf( __( 'Failed to create user: %s' ), $user_id->get_error_message() ),
				array( 'status' => 500 )
			);
		}

		// Store Google ID in user meta
		update_user_meta( $user_id, User_Meta_Keys::GOOGLE_ID, sanitize_text_field( $google_id ) );

		return $user_id;
	}

	/**
	 * Generate a unique username from email
	 *
	 * @param string $email The email address.
	 *
	 * @return string A unique username.
	 */
	private function generate_username_from_email( string $email ): string {
		// Get the part before @ in email
		$base_username = sanitize_user( substr( $email, 0, strpos( $email, '@' ) ) );

		// Ensure username is unique
		$username = $base_username;
		$counter  = 1;

		while ( username_exists( $username ) ) {
			$username = $base_username . $counter;
			$counter ++;
		}

		return $username;
	}

}
