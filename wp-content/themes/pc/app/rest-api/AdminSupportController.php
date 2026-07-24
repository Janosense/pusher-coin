<?php

namespace PC;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin support surfaces.
 *
 *   GET   /pc/v1/admin/support/tickets       — paginated, filterable
 *   PATCH /pc/v1/admin/support/tickets/{id}  — status transitions
 *   GET   /pc/v1/admin/support/subjects      — includes hidden subjects
 *   PUT   /pc/v1/admin/support/subjects      — replaces the whole list
 *
 * All admin-gated. The ticket list carries the submitter's IP, user
 * agent, and whether their email was verified at submission time —
 * everything support needs to judge a claim without a second lookup.
 *
 * Replies are deliberately out of scope: the notification mail sets
 * Reply-To to the player, so the operator answers from their mail
 * client. This panel tracks state, it is not an inbox.
 */
class AdminSupportController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'admin/support';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base/tickets", [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_tickets' ],
			'permission_callback' => [ Permissions::class, 'require_admin' ],
			'args'                => [
				'page'     => [ 'type' => 'integer', 'default' => 1 ],
				'per_page' => [ 'type' => 'integer', 'default' => 20 ],
				'status'   => [ 'type' => 'string', 'required' => false ],
				'search'   => [ 'type' => 'string', 'required' => false ],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/tickets/(?P<id>\\d+)", [
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => [ $this, 'update_ticket' ],
			'permission_callback' => [ Permissions::class, 'require_admin' ],
			'args'                => [
				'status' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/subjects", [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_subjects' ],
				'permission_callback' => [ Permissions::class, 'require_admin' ],
			],
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'put_subjects' ],
				'permission_callback' => [ Permissions::class, 'require_admin' ],
				'args'                => [
					'items' => [ 'required' => true ],
				],
			],
		] );
	}

	public function list_tickets( WP_REST_Request $request ) {
		$status = $request->get_param( 'status' );
		if ( $status && ! in_array( $status, Support_Service::status_values(), true ) ) {
			return new WP_Error(
				'invalid_ticket_status',
				sprintf( 'status must be one of: %s.', implode( ', ', Support_Service::status_values() ) ),
				[ 'status' => 400 ]
			);
		}

		return new WP_REST_Response( Support_Service::list_tickets( [
			'page'     => (int) $request->get_param( 'page' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
			'status'   => $status,
			'search'   => $request->get_param( 'search' ),
		] ), 200 );
	}

	public function update_ticket( WP_REST_Request $request ) {
		$ticket = Support_Service::update_status(
			(int) $request->get_param( 'id' ),
			(string) $request->get_param( 'status' )
		);

		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		Audit_Log::record( 'support_ticket_updated', [
			'user_id'  => get_current_user_id(),
			'metadata' => [ 'ticket_id' => $ticket['id'], 'status' => $ticket['status'] ],
		] );

		return new WP_REST_Response( $ticket, 200 );
	}

	public function get_subjects(): WP_REST_Response {
		return new WP_REST_Response( [ 'items' => Support_Service::subjects( true ) ], 200 );
	}

	public function put_subjects( WP_REST_Request $request ) {
		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			return new WP_Error( 'invalid_subject', '`items` must be an array of subjects.', [ 'status' => 400 ] );
		}

		$result = Support_Service::replace_subjects( $items );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Audit_Log::record( 'support_subjects_updated', [
			'user_id'  => get_current_user_id(),
			'metadata' => [ 'count' => count( $result ) ],
		] );

		return new WP_REST_Response( [ 'items' => $result ], 200 );
	}
}
