<?php

namespace PC;

use Google_Client;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Exception;

class GoogleAuthController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'api/v1';
		$this->rest_base = 'google-auth';
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base", [
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
	}

	/**
	 * Authenticate user with Google ID token
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function authenticate_with_google( WP_REST_Request $request ) {
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
			$existing_google_id = get_user_meta( $user_id, 'google_id', true );
			if ( empty( $existing_google_id ) ) {
				update_user_meta( $user_id, 'google_id', sanitize_text_field( $google_id ) );
			}
		} else {
			// User doesn't exist - create new user
			$user_id = $this->create_user_from_google( $email, $name, $google_id );

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			$user = get_user_by( 'id', $user_id );
		}

		// Generate JWT token for the authenticated user
		$jwt_token = $this->generate_jwt_token( $user );

		if ( is_wp_error( $jwt_token ) ) {
			return $jwt_token;
		}

		// Prepare successful response
		$response_data = array(
			'token'             => $jwt_token,
			'user_id'           => $user->ID,
			'user_email'        => $user->user_email,
			'user_nicename'     => $user->user_nicename,
			'user_display_name' => $user->display_name,
		);

		return new WP_REST_Response( $response_data, 200 );
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
			$client = new Google_Client();

			// Get Google Client ID from WordPress options or define
			$google_client_id = defined( 'GOOGLE_CLIENT_ID' ) ? GOOGLE_CLIENT_ID : get_option( 'google_client_id' );

			if ( empty( $google_client_id ) ) {
				return new WP_Error(
					'google_not_configured',
					__( 'Google authentication is not properly configured. Please contact the administrator.' ),
					array( 'status' => 500 )
				);
			}

			$client->setClientId( $google_client_id );

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
		update_user_meta( $user_id, 'google_id', sanitize_text_field( $google_id ) );

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

	/**
	 * Generate JWT token for authenticated user
	 *
	 * @param \WP_User $user The WordPress user object.
	 *
	 * @return string|WP_Error JWT token on success, WP_Error on failure.
	 */
	private function generate_jwt_token( \WP_User $user ) {
		// Check if JWT secret key is configured
		$secret_key = defined( 'JWT_AUTH_SECRET_KEY' ) ? JWT_AUTH_SECRET_KEY : false;

		if ( ! $secret_key ) {
			return new WP_Error(
				'jwt_not_configured',
				__( 'JWT is not configured properly. Please contact the administrator.' ),
				array( 'status' => 500 )
			);
		}

		// Import JWT library
		if ( ! class_exists( 'Tmeister\Firebase\JWT\JWT' ) ) {
			return new WP_Error(
				'jwt_library_missing',
				__( 'JWT library is not available.' ),
				array( 'status' => 500 )
			);
		}

		// Use WordPress JWT class
		$jwt_class = new \Tmeister\Firebase\JWT\JWT();

		// Build token data
		$issuedAt  = time();
		$notBefore = apply_filters( 'jwt_auth_not_before', $issuedAt, $issuedAt );
		$expire    = apply_filters( 'jwt_auth_expire', $issuedAt + ( DAY_IN_SECONDS * 7 ), $issuedAt );

		$token = array(
			'iss'  => get_bloginfo( 'url' ),
			'iat'  => $issuedAt,
			'nbf'  => $notBefore,
			'exp'  => $expire,
			'data' => array(
				'user' => array(
					'id' => $user->ID,
				),
			),
		);

		// Get algorithm (default HS256)
		$algorithm = apply_filters( 'jwt_auth_algorithm', 'HS256' );

		// Allow modification of token before signing
		$token = apply_filters( 'jwt_auth_token_before_sign', $token, $user );

		// Encode and return the token
		try {
			$jwt_token = $jwt_class::encode( $token, $secret_key, $algorithm );

			return $jwt_token;
		} catch ( Exception $e ) {
			return new WP_Error(
				'jwt_encoding_failed',
				sprintf( __( 'Failed to generate JWT token: %s' ), $e->getMessage() ),
				array( 'status' => 500 )
			);
		}
	}
}
