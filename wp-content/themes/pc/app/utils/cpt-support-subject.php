<?php

namespace PC;

/**
 * Registers the `pc_support_subject` custom post type — the admin-editable
 * list of subject lines the support form's dropdown offers.
 *
 * Storage decision lives in DATA-MODEL.md §Phase 7: a CPT rather than an
 * option array because admins author subjects one at a time and ordering
 * matters (`menu_order`), and because a ticket references a subject by
 * post ID — an option array has no stable id to point at.
 *
 * Same registration posture as `pc_room`: not public, no `show_in_rest`.
 * Subjects reach the SPA through `GET /pc/v1/support/subjects`, never
 * through `/wp-json/wp/v2/`.
 */
function register_pc_support_subject_cpt(): void {
	register_post_type( 'pc_support_subject', [
		'labels'              => [
			'name'          => 'Support subjects',
			'singular_name' => 'Support subject',
		],
		'public'              => false,
		'show_ui'             => false,
		'show_in_menu'        => false,
		'show_in_rest'        => false,
		'exclude_from_search' => true,
		'publicly_queryable'  => false,
		'has_archive'         => false,
		'rewrite'             => false,
		'supports'            => [ 'title', 'page-attributes' ],
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
	] );
}

add_action( 'init', __NAMESPACE__ . '\\register_pc_support_subject_cpt' );
