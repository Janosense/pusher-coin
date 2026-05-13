<?php

namespace PC;

use WP_CLI;
use WP_Query;

/**
 * `wp pc seed-rooms` — populate the local DB with demo rooms + schedules.
 *
 * Idempotent: rooms are matched by title. Existing rooms with the same
 * title get their meta and schedule overwritten so reruns converge to
 * the seed state.
 *
 * Run from the WP root:
 *
 *   wp pc seed-rooms          # create / refresh the three demo rooms
 *   wp pc seed-rooms --reset  # trash everything first, then seed
 *
 * The schedule rules are designed to land "live now" most of the time
 * during local dev (early-morning + late-evening windows on every day),
 * so reviewers don't have to wait for a future window to see a stream
 * placeholder swap to live.
 */
final class Seed_Rooms_Command {

	private const SEED = [
		[
			'name'           => 'Sunset Pusher',
			'status'         => 'available',
			'stream_url'     => 'https://www.youtube-nocookie.com/embed/oXvPA6l4pts?autoplay=1&mute=1&controls=0',
			'theme_song_url' => '',
			'machine_id'     => 'demo_sunset',
			'rules'          => [
				[ 'weekday' => 0, 'start_time' => '06:00:00', 'end_time' => '23:59:00', 'recurrence' => 'always', 'once_date' => null ],
				[ 'weekday' => 1, 'start_time' => '06:00:00', 'end_time' => '23:59:00', 'recurrence' => 'always', 'once_date' => null ],
				[ 'weekday' => 2, 'start_time' => '06:00:00', 'end_time' => '23:59:00', 'recurrence' => 'always', 'once_date' => null ],
				[ 'weekday' => 3, 'start_time' => '06:00:00', 'end_time' => '23:59:00', 'recurrence' => 'always', 'once_date' => null ],
				[ 'weekday' => 4, 'start_time' => '06:00:00', 'end_time' => '23:59:00', 'recurrence' => 'always', 'once_date' => null ],
				[ 'weekday' => 5, 'start_time' => '06:00:00', 'end_time' => '23:59:00', 'recurrence' => 'always', 'once_date' => null ],
				[ 'weekday' => 6, 'start_time' => '06:00:00', 'end_time' => '23:59:00', 'recurrence' => 'always', 'once_date' => null ],
			],
		],
		[
			'name'           => 'Midnight Pusher',
			'status'         => 'available',
			'stream_url'     => '',
			'theme_song_url' => '',
			'machine_id'     => 'demo_midnight',
			// Friday + Saturday late-night window. Frontend will show a
			// countdown until the next weekend if it's mid-week.
			'rules'          => [
				[ 'weekday' => 4, 'start_time' => '20:00:00', 'end_time' => '23:59:00', 'recurrence' => 'always', 'once_date' => null ],
				[ 'weekday' => 5, 'start_time' => '20:00:00', 'end_time' => '23:59:00', 'recurrence' => 'always', 'once_date' => null ],
			],
		],
		[
			'name'           => 'Workshop',
			'status'         => 'maintenance',
			'stream_url'     => '',
			'theme_song_url' => '',
			'machine_id'     => 'demo_workshop',
			// Maintenance — no schedule rules; the frontend shows the
			// "under maintenance" placeholder.
			'rules'          => [],
		],
	];

	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['reset'] ) ) {
			$this->reset_existing();
		}

		$created = 0;
		$refreshed = 0;

		foreach ( self::SEED as $seed ) {
			$existing_id = $this->find_room_id_by_title( $seed['name'] );
			if ( $existing_id ) {
				$this->apply_to_room( $existing_id, $seed );
				$refreshed++;
				WP_CLI::log( sprintf( '  refreshed: %s (#%d)', $seed['name'], $existing_id ) );
			} else {
				$post_id = wp_insert_post( [
					'post_type'   => 'pc_room',
					'post_status' => 'publish',
					'post_title'  => $seed['name'],
				], true );
				if ( is_wp_error( $post_id ) ) {
					WP_CLI::warning( sprintf( 'failed to create %s: %s', $seed['name'], $post_id->get_error_message() ) );
					continue;
				}
				$this->apply_to_room( (int) $post_id, $seed );
				$created++;
				WP_CLI::log( sprintf( '  created: %s (#%d)', $seed['name'], $post_id ) );
			}
		}

		WP_CLI::success( sprintf( 'Seed complete — %d created, %d refreshed.', $created, $refreshed ) );
	}

	private function find_room_id_by_title( string $title ): ?int {
		$query = new WP_Query( [
			'post_type'      => 'pc_room',
			'post_status'    => [ 'publish', 'draft', 'trash' ],
			'title'          => $title,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		return $query->posts ? (int) $query->posts[0] : null;
	}

	private function apply_to_room( int $post_id, array $seed ): void {
		// Make sure a previously-trashed room of the same name is revived.
		wp_update_post( [
			'ID'          => $post_id,
			'post_status' => 'publish',
			'post_title'  => $seed['name'],
		] );

		update_post_meta( $post_id, Post_Meta_Keys::ROOM_STATUS, $seed['status'] );
		update_post_meta( $post_id, Post_Meta_Keys::ROOM_STREAM_URL, $seed['stream_url'] );
		update_post_meta( $post_id, Post_Meta_Keys::ROOM_THEME_SONG_URL, $seed['theme_song_url'] );
		update_post_meta( $post_id, Post_Meta_Keys::ROOM_MACHINE_ID, $seed['machine_id'] );

		global $wpdb;
		$table = $wpdb->prefix . 'pc_room_schedules';
		$wpdb->delete( $table, [ 'room_id' => $post_id ], [ '%d' ] );
		$now = current_time( 'mysql' );
		foreach ( $seed['rules'] as $rule ) {
			$wpdb->insert(
				$table,
				array_merge( $rule, [ 'room_id' => $post_id, 'created_at' => $now ] ),
				[ '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
			);
		}
	}

	private function reset_existing(): void {
		$query = new WP_Query( [
			'post_type'      => 'pc_room',
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		foreach ( $query->posts as $id ) {
			wp_trash_post( (int) $id );
		}
		WP_CLI::log( sprintf( 'reset: trashed %d existing room(s).', count( $query->posts ) ) );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'pc seed-rooms', Seed_Rooms_Command::class );
}
