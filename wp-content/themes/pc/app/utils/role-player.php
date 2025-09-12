<?php

/**
 * Adds the 'player' role to the system if it does not already exist.
 *
 * The 'player' role will have the capability 'read' set to false by default.
 *
 * @return void
 */
function add_player_role(): void {
	if ( ! get_role( 'player' ) ) {
		add_role( 'player', 'Player', [
				'read' => false,
			]
		);
	}
}

add_action( 'init', 'add_player_role' );
