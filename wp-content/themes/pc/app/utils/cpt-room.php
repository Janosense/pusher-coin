<?php

namespace PC;

/**
 * Registers the `pc_room` custom post type — one post per physical pusher
 * machine / themed room exposed to players.
 *
 * Storage decisions live in DATA-MODEL.md §Phase 3. Meta keys are defined
 * in Post_Meta_Keys.
 *
 * The CPT is intentionally not `public` — rooms are surfaced through the
 * pc/v1 REST API, not through WordPress front-end archives or permalinks.
 * `show_in_rest` is left off the registration because we ship a custom
 * RoomController; we don't want the default `/wp-json/wp/v2/pc_room`
 * endpoints leaking the raw post shape.
 */
function register_pc_room_cpt(): void {
	register_post_type( 'pc_room', [
		'labels'              => [
			'name'          => 'Rooms',
			'singular_name' => 'Room',
		],
		'public'              => false,
		'show_ui'             => false,
		'show_in_menu'        => false,
		'show_in_rest'        => false,
		'exclude_from_search' => true,
		'publicly_queryable'  => false,
		'has_archive'         => false,
		'rewrite'             => false,
		'supports'            => [ 'title', 'editor', 'page-attributes' ],
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
	] );
}

add_action( 'init', __NAMESPACE__ . '\\register_pc_room_cpt' );
