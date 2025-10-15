<?php
/**
 * #ddev-generated: Automatically generated WordPress settings file.
 * ddev manages this file and may delete or overwrite the file unless this comment is removed.
 *
 * @package ddevapp
 */

if ( getenv( 'IS_DDEV_PROJECT' ) == 'true' ) {
	/** The name of the database for WordPress */
	defined( 'DB_NAME' ) || define( 'DB_NAME', 'db' );

	/** MySQL database username */
	defined( 'DB_USER' ) || define( 'DB_USER', 'db' );

	/** MySQL database password */
	defined( 'DB_PASSWORD' ) || define( 'DB_PASSWORD', 'db' );

	/** MySQL hostname */
	defined( 'DB_HOST' ) || define( 'DB_HOST', 'ddev-pusher-coin-db' );

	/** WP_HOME URL */
	defined( 'WP_HOME' ) || define( 'WP_HOME', 'https://pusher-coin.ddev.site' );

	/** WP_SITEURL location */
	defined( 'WP_SITEURL' ) || define( 'WP_SITEURL', WP_HOME . '/' );

	/** Enable debug */
	defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', true );


	define('JWT_AUTH_SECRET_KEY', 'Yb{fx*o56Ih!a<{irR:=f(p&JhJk<xbB<jS0[uLY4)72.Jw-]]OiwxG;sak.7MnO');
	define('JWT_AUTH_CORS_ENABLE', true);

	define('GOOGLE_CLIENT_ID', '412386896288-lis1ed3aqti03j53o8lmu6cbj8bk81ka.apps.googleusercontent.com');


	/**
	 * Set WordPress Database Table prefix if not already set.
	 *
	 * @global string $table_prefix
	 */
	if ( ! isset( $table_prefix ) || empty( $table_prefix ) ) {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$table_prefix = 'wp_';
		// phpcs:enable
	}
}
