<?php

namespace PC;

use WP_Error;
use WP_Post;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin CRUD for the `pc_room` CPT and atomic-replace for the schedule
 * rules table. All endpoints are gated by `Permissions::require_admin`.
 *
 *   GET    /pc/v1/admin/rooms
 *   POST   /pc/v1/admin/rooms
 *   GET    /pc/v1/admin/rooms/{id}
 *   PUT    /pc/v1/admin/rooms/{id}
 *   DELETE /pc/v1/admin/rooms/{id}            (trashes; not permanent)
 *   PUT    /pc/v1/admin/rooms/{id}/schedule   (atomic replace)
 *
 * The public read controller is `RoomController.php`. This controller
 * intentionally returns the same `serialize_room` shape so admin and
 * player SPAs can share a single mapping function.
 */
class AdminRoomController extends WP_REST_Controller {

	private const NAME_MAX_LENGTH = 200;

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'admin/rooms';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base", [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_rooms' ],
				'permission_callback' => [ Permissions::class, 'require_admin' ],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_room' ],
				'permission_callback' => [ Permissions::class, 'require_admin' ],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/(?P<id>\\d+)", [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_room' ],
				'permission_callback' => [ Permissions::class, 'require_admin' ],
			],
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_room' ],
				'permission_callback' => [ Permissions::class, 'require_admin' ],
			],
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_room' ],
				'permission_callback' => [ Permissions::class, 'require_admin' ],
			],
		] );

		register_rest_route( $this->namespace, "/$this->rest_base/(?P<id>\\d+)/schedule", [
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => [ $this, 'replace_schedule' ],
			'permission_callback' => [ Permissions::class, 'require_admin' ],
		] );
	}

	public function list_rooms( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );

		$query = new \WP_Query( [
			'post_type'      => 'pc_room',
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
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

	public function create_room( WP_REST_Request $request ) {
		$validated = $this->validate_room_input( $request, true );
		if ( $validated instanceof WP_Error ) {
			return $validated;
		}

		$post_id = wp_insert_post( [
			'post_type'   => 'pc_room',
			'post_status' => 'publish',
			'post_title'  => $validated['name'],
		], true );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'room_create_failed', $post_id->get_error_message(), [ 'status' => 500 ] );
		}

		$this->write_room_meta( (int) $post_id, $validated );
		$post = get_post( $post_id );
		return new WP_REST_Response( $this->serialize_room( $post, [] ), 201 );
	}

	public function get_room( WP_REST_Request $request ) {
		$post = $this->require_room_post( (int) $request['id'] );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$rules = $this->load_rules_for_rooms( [ $post->ID ] )[ $post->ID ] ?? [];
		return new WP_REST_Response( $this->serialize_room( $post, $rules ), 200 );
	}

	public function update_room( WP_REST_Request $request ) {
		$post = $this->require_room_post( (int) $request['id'] );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$validated = $this->validate_room_input( $request, false );
		if ( $validated instanceof WP_Error ) {
			return $validated;
		}

		if ( array_key_exists( 'name', $validated ) ) {
			wp_update_post( [ 'ID' => $post->ID, 'post_title' => $validated['name'] ] );
		}

		$this->write_room_meta( (int) $post->ID, $validated );

		$rules = $this->load_rules_for_rooms( [ $post->ID ] )[ $post->ID ] ?? [];
		return new WP_REST_Response( $this->serialize_room( get_post( $post->ID ), $rules ), 200 );
	}

	public function delete_room( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request['id'];
		$post = $this->require_room_post( $id );
		if ( $post instanceof WP_Error ) {
			return new WP_REST_Response( $post->get_error_data(), 404 );
		}
		wp_trash_post( $id );
		return new WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	public function replace_schedule( WP_REST_Request $request ) {
		$post = $this->require_room_post( (int) $request['id'] );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$raw_rules = $request->get_param( 'rules' );
		if ( ! is_array( $raw_rules ) ) {
			return new WP_Error( 'invalid_schedule_rule', 'rules must be an array.', [ 'status' => 400 ] );
		}

		$rules = [];
		foreach ( $raw_rules as $idx => $raw ) {
			$rule = $this->validate_rule( $raw );
			if ( $rule instanceof WP_Error ) {
				return new WP_Error(
					$rule->get_error_code(),
					sprintf( 'Rule %d: %s', $idx, $rule->get_error_message() ),
					[ 'status' => 400 ]
				);
			}
			$rules[] = $rule;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'pc_room_schedules';
		$now   = current_time( 'mysql' );

		$wpdb->query( 'START TRANSACTION' );
		$wpdb->delete( $table, [ 'room_id' => $post->ID ], [ '%d' ] );
		foreach ( $rules as $rule ) {
			$inserted = $wpdb->insert(
				$table,
				[
					'room_id'    => $post->ID,
					'weekday'    => $rule['weekday'],
					'start_time' => $rule['start_time'],
					'end_time'   => $rule['end_time'],
					'recurrence' => $rule['recurrence'],
					'once_date'  => $rule['once_date'],
					'created_at' => $now,
				],
				[ '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
			);
			if ( false === $inserted ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'schedule_write_failed', 'Failed to persist schedule rule.', [ 'status' => 500 ] );
			}
		}
		$wpdb->query( 'COMMIT' );

		$fresh = $this->load_rules_for_rooms( [ $post->ID ] )[ $post->ID ] ?? [];
		$windows = Room_Schedule_Calculator::compute( $fresh );

		return new WP_REST_Response( [
			'rules'       => array_map( [ $this, 'serialize_rule' ], $fresh ),
			'next_window' => $windows['next_window'],
		], 200 );
	}

	// ---------------- validation ----------------

	/**
	 * Returns a validated payload array on success or WP_Error on failure.
	 *
	 * `$require_name` is true for create, false for update — update keeps
	 * fields the caller omitted untouched.
	 */
	private function validate_room_input( WP_REST_Request $request, bool $require_name ) {
		$out = [];

		$name = $request->get_param( 'name' );
		if ( $require_name || null !== $name ) {
			$name = is_string( $name ) ? trim( $name ) : '';
			if ( '' === $name || strlen( $name ) > self::NAME_MAX_LENGTH ) {
				return new WP_Error( 'invalid_room_name', 'Room name is required and must be 1–200 characters.', [ 'status' => 400 ] );
			}
			$out['name'] = $name;
		}

		$status = $request->get_param( 'status' );
		if ( null !== $status ) {
			if ( ! in_array( $status, Post_Meta_Keys::room_status_values(), true ) ) {
				return new WP_Error( 'invalid_room_status', 'status must be one of: available, maintenance, unavailable.', [ 'status' => 400 ] );
			}
			$out['status'] = $status;
		}

		foreach ( [ 'stream_url', 'theme_song_url' ] as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$value = is_string( $value ) ? trim( $value ) : '';
				if ( '' !== $value && ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
					return new WP_Error( 'invalid_room_url', sprintf( '%s must be a valid URL or empty.', $field ), [ 'status' => 400 ] );
				}
				$out[ $field ] = $value;
			}
		}

		$machine_id = $request->get_param( 'machine_id' );
		if ( null !== $machine_id ) {
			$machine_id = is_string( $machine_id ) ? trim( $machine_id ) : '';
			if ( '' !== $machine_id && ! preg_match( '/^[A-Za-z0-9_.\-]{1,64}$/', $machine_id ) ) {
				return new WP_Error( 'invalid_room_machine_id', 'machine_id must be 1–64 chars of A–Z, 0–9, underscore, hyphen, or dot.', [ 'status' => 400 ] );
			}
			$out['machine_id'] = $machine_id;
		}

		return $out;
	}

	/**
	 * Validates a single schedule rule input. Returns the normalised row
	 * (HH:MM:SS-padded times, etc.) or WP_Error.
	 */
	private function validate_rule( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'invalid_schedule_rule', 'rule must be an object.' );
		}

		$weekday = $raw['weekday'] ?? null;
		if ( ! is_int( $weekday ) && ! ctype_digit( (string) $weekday ) ) {
			return new WP_Error( 'invalid_schedule_rule', 'weekday must be an integer 0–6.' );
		}
		$weekday = (int) $weekday;
		if ( $weekday < 0 || $weekday > 6 ) {
			return new WP_Error( 'invalid_schedule_rule', 'weekday must be 0..6 (Mon=0).' );
		}

		$start = $this->normalize_time( $raw['start_time'] ?? '' );
		$end   = $this->normalize_time( $raw['end_time'] ?? '' );
		if ( null === $start || null === $end ) {
			return new WP_Error( 'invalid_schedule_rule', 'start_time and end_time must be HH:MM.' );
		}
		if ( strcmp( $start, $end ) >= 0 ) {
			return new WP_Error( 'invalid_schedule_rule', 'start_time must be strictly less than end_time (use two rules to cross midnight).' );
		}

		$recurrence = $raw['recurrence'] ?? 'always';
		if ( ! in_array( $recurrence, [ 'always', 'once' ], true ) ) {
			return new WP_Error( 'invalid_schedule_rule', 'recurrence must be "always" or "once".' );
		}

		$once_date = null;
		if ( 'once' === $recurrence ) {
			$candidate = $raw['once_date'] ?? '';
			if ( ! is_string( $candidate ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $candidate ) ) {
				return new WP_Error( 'invalid_schedule_rule', 'once_date must be YYYY-MM-DD when recurrence is "once".' );
			}
			$once_date = $candidate;
		}

		return [
			'weekday'    => $weekday,
			'start_time' => $start,
			'end_time'   => $end,
			'recurrence' => $recurrence,
			'once_date'  => $once_date,
		];
	}

	private function normalize_time( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( preg_match( '/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m ) ) {
			$h = (int) $m[1];
			$min = (int) $m[2];
			$s = isset( $m[3] ) ? (int) $m[3] : 0;
			if ( $h <= 23 && $min <= 59 && $s <= 59 ) {
				return sprintf( '%02d:%02d:%02d', $h, $min, $s );
			}
		}
		return null;
	}

	// ---------------- storage helpers ----------------

	private function require_room_post( int $id ) {
		$post = get_post( $id );
		if ( ! $post || 'pc_room' !== $post->post_type || 'trash' === $post->post_status ) {
			return new WP_Error( 'room_not_found', 'Room not found.', [ 'status' => 404 ] );
		}
		return $post;
	}

	private function write_room_meta( int $post_id, array $validated ): void {
		if ( array_key_exists( 'status', $validated ) ) {
			update_post_meta( $post_id, Post_Meta_Keys::ROOM_STATUS, $validated['status'] );
		} elseif ( '' === (string) get_post_meta( $post_id, Post_Meta_Keys::ROOM_STATUS, true ) ) {
			update_post_meta( $post_id, Post_Meta_Keys::ROOM_STATUS, Post_Meta_Keys::ROOM_STATUS_UNAVAILABLE );
		}
		if ( array_key_exists( 'stream_url', $validated ) ) {
			update_post_meta( $post_id, Post_Meta_Keys::ROOM_STREAM_URL, $validated['stream_url'] );
		}
		if ( array_key_exists( 'theme_song_url', $validated ) ) {
			update_post_meta( $post_id, Post_Meta_Keys::ROOM_THEME_SONG_URL, $validated['theme_song_url'] );
		}
		if ( array_key_exists( 'machine_id', $validated ) ) {
			update_post_meta( $post_id, Post_Meta_Keys::ROOM_MACHINE_ID, $validated['machine_id'] );
		}
	}

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

		$windows = Room_Schedule_Calculator::compute( $rules );

		return [
			'id'             => (int) $post->ID,
			'name'           => $post->post_title,
			'status'         => $status,
			'theme_song_url' => (string) get_post_meta( $post->ID, Post_Meta_Keys::ROOM_THEME_SONG_URL, true ) ?: null,
			'stream_url'     => (string) get_post_meta( $post->ID, Post_Meta_Keys::ROOM_STREAM_URL, true ) ?: null,
			'machine_id'     => (string) get_post_meta( $post->ID, Post_Meta_Keys::ROOM_MACHINE_ID, true ) ?: null,
			'post_status'    => $post->post_status,
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
