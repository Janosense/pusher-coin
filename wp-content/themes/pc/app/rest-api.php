<?php

use PC\UserController;

require_once TEMPLATE_DIR . '/app/rest-api/UserController.php';

add_action( 'rest_api_init', function () {

	$user_controller = new UserController();

	$user_controller->register_routes();
} );
