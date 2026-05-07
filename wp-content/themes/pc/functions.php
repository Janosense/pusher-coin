<?php
define( 'TEMPLATE_DIR', get_template_directory() );

/**
 *  Enable Autoload
 */
require_once TEMPLATE_DIR . '/vendor/autoload.php';

/**
 *  Utils
 */
require_once TEMPLATE_DIR . '/app/utils.php';


/**
 * REST API extensions
 */
require TEMPLATE_DIR . '/app/rest-api.php';

add_filter( 'jwt_auth_expire', function ( $exp ) {
	$ttl = (int) get_option( 'pc_access_token_ttl_seconds', 900 );
	return time() + $ttl;
} );
