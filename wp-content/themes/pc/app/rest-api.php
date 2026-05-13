<?php

use PC\AdminController;
use PC\AdminRoomController;
use PC\AppleAuthController;
use PC\AuthController;
use PC\GoogleAuthController;
use PC\RoomController;
use PC\UserController;

require_once TEMPLATE_DIR . '/app/rest-api/AuthController.php';
require_once TEMPLATE_DIR . '/app/rest-api/UserController.php';
require_once TEMPLATE_DIR . '/app/rest-api/GoogleAuthController.php';
require_once TEMPLATE_DIR . '/app/rest-api/AppleAuthController.php';
require_once TEMPLATE_DIR . '/app/rest-api/RoomController.php';
require_once TEMPLATE_DIR . '/app/rest-api/AdminController.php';
require_once TEMPLATE_DIR . '/app/rest-api/AdminRoomController.php';

add_action( 'rest_api_init', function () {

	$auth_controller = new AuthController();
	$auth_controller->register_routes();

	$user_controller = new UserController();
	$user_controller->register_routes();

	$google_auth_controller = new GoogleAuthController();
	$google_auth_controller->register_routes();

	$apple_auth_controller = new AppleAuthController();
	$apple_auth_controller->register_routes();

	$room_controller = new RoomController();
	$room_controller->register_routes();

	$admin_controller = new AdminController();
	$admin_controller->register_routes();

	$admin_room_controller = new AdminRoomController();
	$admin_room_controller->register_routes();
} );
