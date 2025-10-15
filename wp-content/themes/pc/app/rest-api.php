<?php

use PC\UserController;
use PC\GoogleAuthController;

require_once TEMPLATE_DIR . '/app/rest-api/UserController.php';
require_once TEMPLATE_DIR . '/app/rest-api/GoogleAuthController.php';

add_action( 'rest_api_init', function () {

	$user_controller = new UserController();
	$user_controller->register_routes();

	$google_auth_controller = new GoogleAuthController();
	$google_auth_controller->register_routes();
} );
