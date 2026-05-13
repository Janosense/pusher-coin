<?php

namespace PC;

use WP_Error;
use WP_Post;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Public read endpoints for the `pc_room` CPT.
 *
 *   GET /pc/v1/rooms                       — paginated list
 *   GET /pc/v1/rooms/{id}                  — single room
 *   GET /pc/v1/rooms/{id}/schedule         — rules + next_window
 *
 * Admin write endpoints (POST/PUT/DELETE) land in Step 5 under
 * `pc/v1/admin/rooms/*`.
 */
class RoomController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'rooms';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base", [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_rooms' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'page'     => [ 'type' => 'integer', 'default' => 1 ],
				'per_page' => [ 'type' => 'integer', 'default' => 20 ],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/(?P<id>\\d+)", [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_room' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/(?P<id>\\d+)/schedule", [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_schedule' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function list_rooms( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$query = new \WP_Query( [
			'post_type'      => 'pc_room',
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'no_found_rows'  => false,
		] );

		$rules_by_room = $this->load_rules_for_rooms(
			array_map( static fn( WP_Post $p ) => (int) $p->ID, $query->posts )
		);

		$items = array_map(
			fn( WP_Post $post ) => $this->serialize_room( $post, $rules_by_room[ $post->ID ] ?? [] ),
			$query->posts
		);

		return new WP_REST_Response( [
			'items'    => $items,
			'total'    => (int) $query->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
		], 200 );
	}

	public function get_room( WP_REST_Request $request ) {
		$post = $this->get_room_post( (int) $request['id'] );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$rules = $this->load_rules_for_rooms( [ $post->ID ] )[ $post->ID ] ?? [];
		return new WP_REST_Response( $this->serialize_room( $post, $rules ), 200 );
	}

	public function get_schedule( WP_REST_Request $request ) {
		$post = $this->get_room_post( (int) $request['id'] );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$rules = $this->load_rules_for_rooms( [ $post->ID ] )[ $post->ID ] ?? [];

		$windows = Room_Schedule_Calculator::compute( $rules );

		return new WP_REST_Response( [
			'rules' => array_map( [ $this, 'serialize_rule' ], $rules ),
			'next_window' => $windows['next_window'],
		], 200 );
	}

	private function get_room_post( int $id ) {
		$post = get_post( $id );
		if ( ! $post || 'pc_room' !== $post->post_type ) {
			return new WP_Error( 'room_not_found', 'Room not found.', [ 'status' => 404 ] );
		}
		return $post;
	}

	/**
	 * Bulk-load schedule rules for a set of rooms. Returns
	 * `[ room_id => [ rule_row, ... ] ]`.
	 */
	private function load_rules_for_rooms( array $room_ids ): array {
		if ( empty( $room_ids ) ) {
			return [];
		}
		global $wpdb;
		$table        = $wpdb->prefix . 'pc_room_schedules';
		$placeholders = implode( ',', array_fill( 0, count( $room_ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, room_id, weekday, start_time, end_time, recurrence, once_date
				 FROM $table
				 WHERE room_id IN ($placeholders)
				 ORDER BY weekday ASC, start_time ASC",
				...$room_ids
			),
			ARRAY_A
		);

		$by_room = [];
		foreach ( (array) $rows as $row ) {
			$by_room[ (int) $row['room_id'] ][] = $row;
		}
		return $by_room;
	}

	private function serialize_room( WP_Post $post, array $rules ): array {
		$status = get_post_meta( $post->ID, Post_Meta_Keys::ROOM_STATUS, true );
		if ( ! in_array( $status, Post_Meta_Keys::room_status_values(), true ) ) {
			$status = Post_Meta_Keys::ROOM_STATUS_UNAVAILABLE;
		}

		$is_available   = Post_Meta_Keys::ROOM_STATUS_AVAILABLE === $status;
		$stream_url     = $is_available ? (string) get_post_meta( $post->ID, Post_Meta_Keys::ROOM_STREAM_URL, true ) : '';
		$theme_song_url = (string) get_post_meta( $post->ID, Post_Meta_Keys::ROOM_THEME_SONG_URL, true );

		$windows = Room_Schedule_Calculator::compute( $rules );

		return [
			'id'             => (int) $post->ID,
			'name'           => $post->post_title,
			'status'         => $status,
			'theme_song_url' => $theme_song_url ?: null,
			'stream_url'     => $stream_url ?: null,
			'current_window' => $windows['current_window'],
			'next_window'    => $windows['next_window'],
		];
	}

	private function serialize_rule( array $rule ): array {
		return [
			'weekday'    => (int) $rule['weekday'],
			'start_time' => substr( (string) $rule['start_time'], 0, 5 ),
			'end_time'   => substr( (string) $rule['end_time'], 0, 5 ),
			'recurrence' => (string) $rule['recurrence'],
			'once_date'  => $rule['once_date'] ?: null,
		];
	}
}
