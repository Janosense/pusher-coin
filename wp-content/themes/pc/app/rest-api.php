<?php

use PC\AdminController;
use PC\AdminRoomController;
use PC\AdminWithdrawalController;
use PC\AppleAuthController;
use PC\AuthController;
use PC\GoogleAuthController;
use PC\PaymentController;
use PC\RoomController;
use PC\UserController;
use PC\WalletController;

require_once TEMPLATE_DIR . '/app/rest-api/AuthController.php';
require_once TEMPLATE_DIR . '/app/rest-api/UserController.php';
require_once TEMPLATE_DIR . '/app/rest-api/GoogleAuthController.php';
require_once TEMPLATE_DIR . '/app/rest-api/AppleAuthController.php';
require_once TEMPLATE_DIR . '/app/rest-api/RoomController.php';
require_once TEMPLATE_DIR . '/app/rest-api/AdminController.php';
require_once TEMPLATE_DIR . '/app/rest-api/AdminRoomController.php';
require_once TEMPLATE_DIR . '/app/rest-api/AdminWithdrawalController.php';
require_once TEMPLATE_DIR . '/app/rest-api/WalletController.php';
require_once TEMPLATE_DIR . '/app/rest-api/PaymentController.php';

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

	$admin_withdrawal_controller = new AdminWithdrawalController();
	$admin_withdrawal_controller->register_routes();

	$wallet_controller = new WalletController();
	$wallet_controller->register_routes();

	$payment_controller = new PaymentController();
	$payment_controller->register_routes();
} );
