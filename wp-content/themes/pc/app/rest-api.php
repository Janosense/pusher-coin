<?php

use PC\AppleAuthController;
use PC\AuthController;
use PC\GoogleAuthController;
use PC\UserController;

require_once TEMPLATE_DIR . '/app/rest-api/AuthController.php';
require_once TEMPLATE_DIR . '/app/rest-api/UserController.php';
require_once TEMPLATE_DIR . '/app/rest-api/GoogleAuthController.php';
require_once TEMPLATE_DIR . '/app/rest-api/AppleAuthController.php';

add_action( 'rest_api_init', function () {

	$auth_controller = new AuthController();
	$auth_controller->register_routes();

	$user_controller = new UserController();
	$user_controller->register_routes();

	$google_auth_controller = new GoogleAuthController();
	$google_auth_controller->register_routes();

	$apple_auth_controller = new AppleAuthController();
	$apple_auth_controller->register_routes();
} );
