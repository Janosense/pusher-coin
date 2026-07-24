<?php

namespace PC;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Player-facing support surface.
 *
 *   GET  /pc/v1/support/subjects
 *   POST /pc/v1/support/tickets
 *
 * Both are public routes — a guest with a problem signing in is exactly
 * who needs support most. The two paths differ in what they demand:
 *
 *   - **Guest** — supplies an email and solves a captcha (when one is
 *     configured; see `Captcha_Verifier`). `email_verified` is recorded
 *     false, which is the signal support uses to weigh the claim.
 *   - **Logged in** — the account email is used verbatim and the
 *     submitted one ignored, so a ticket can't be filed under someone
 *     else's address. The account must be email-verified, matching the
 *     rule ROADMAP §7.1 sets; no captcha, the session is the proof.
 *
 * Rate-limited per IP either way: 5 tickets per hour.
 */
class SupportController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'support';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base/subjects", [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_subjects' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/tickets", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create_ticket' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'subject_id'    => [ 'type' => 'integer', 'required' => true ],
				'description'   => [ 'type' => 'string', 'required' => true ],
				'email'         => [ 'type' => 'string', 'required' => false ],
				'captcha_token' => [ 'type' => 'string', 'required' => false ],
			],
		] );
	}

	/**
	 * The dropdown's options plus the captcha the guest path will demand.
	 * Both travel together so the SPA can render the whole form after one
	 * request — and so it never has to guess whether a widget is needed.
	 */
	public function get_subjects(): WP_REST_Response {
		return new WP_REST_Response( [
			'items'   => array_map(
				static fn( array $s ) => [ 'id' => $s['id'], 'label' => $s['label'] ],
				Support_Service::subjects()
			),
			'captcha' => Captcha_Verifier::public_config(),
		], 200 );
	}

	public function create_ticket( WP_REST_Request $request ) {
		$rate_check = Rate_Limiter::check( 'support_ticket:' . Rate_Limiter::client_ip(), 5, HOUR_IN_SECONDS );
		if ( is_wp_error( $rate_check ) ) {
			Audit_Log::record( 'rate_limited', [ 'metadata' => [ 'endpoint' => 'support/tickets' ] ] );
			return $rate_check;
		}

		$is_logged_in = is_user_logged_in();

		if ( $is_logged_in ) {
			$user = wp_get_current_user();

			$verified_at = (int) get_user_meta( $user->ID, User_Meta_Keys::EMAIL_VERIFIED_AT, true );
			if ( $verified_at <= 0 ) {
				return new WP_Error(
					'email_not_verified',
					__( 'Verify your email address before contacting support.' ),
					[ 'status' => 403 ]
				);
			}

			$email          = $user->user_email;
			$user_id        = (int) $user->ID;
			$email_verified = true;
		} else {
			$captcha = Captcha_Verifier::verify( $request->get_param( 'captcha_token' ) );
			if ( is_wp_error( $captcha ) ) {
				return $captcha;
			}

			$email          = (string) $request->get_param( 'email' );
			$user_id        = null;
			$email_verified = false;
		}

		$ticket_id = Support_Service::create_ticket( [
			'user_id'        => $user_id,
			'email'          => $email,
			'subject_id'     => (int) $request->get_param( 'subject_id' ),
			'description'    => (string) $request->get_param( 'description' ),
			'email_verified' => $email_verified,
		] );

		if ( is_wp_error( $ticket_id ) ) {
			return $ticket_id;
		}

		Audit_Log::record( 'support_ticket_created', [
			'user_id'  => $user_id,
			'email'    => $email,
			'metadata' => [ 'ticket_id' => $ticket_id, 'guest' => ! $is_logged_in ],
		] );

		return new WP_REST_Response( [ 'ticket_id' => $ticket_id ], 201 );
	}
}
