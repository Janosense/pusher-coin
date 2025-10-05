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
		$email    = $request->get_param( 'email' );
		$nickname = $request->get_param( 'nickname' );
		$phone    = $request->get_param( 'phone' );
		$password = $request->get_param( 'password' );

		// Validate required fields
		if ( empty( $email ) || empty( $nickname ) || empty( $password ) ) {
			return new WP_Error(
				'missing_required_fields',
				__( 'Email, nickname, and password are required fields.' ),
				array( 'status' => 400 )
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
			update_user_meta( $user_id, 'phone', sanitize_text_field( $phone ) );
		}

		// Prepare response data
		$user          = get_user_by( 'id', $user_id );
		$response_data = array(
			'id'       => $user_id,
			'email'    => $user->user_email,
			'nickname' => $user->nickname,
			'phone'    => get_user_meta( $user_id, 'phone', true ),
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
}