<?php

namespace PC;

/**
 * Schema installer for Phase 1+ custom tables.
 *
 * Runs on theme activation and on every `init` when the stored
 * `pc_db_version` option is older than the constant below.
 *
 * Adding a table: bump DB_VERSION, add the CREATE TABLE in
 * `install_schema()`, and update DATA-MODEL.md.
 */
final class Install_Schema {
	public const DB_VERSION = '1.3.0';

	public static function maybe_install(): void {
		$installed = get_option( 'pc_db_version', '0.0.0' );
		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}
		self::install_schema();
		self::install_default_options();
		update_option( 'pc_db_version', self::DB_VERSION );
	}

	private static function install_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$refresh_tokens  = $wpdb->prefix . 'pc_refresh_tokens';
		$audit_log       = $wpdb->prefix . 'pc_auth_audit_log';
		$room_schedules  = $wpdb->prefix . 'pc_room_schedules';
		$wallets         = $wpdb->prefix . 'pc_wallets';
		$coin_lots       = $wpdb->prefix . 'pc_coin_lots';
		$transactions    = $wpdb->prefix . 'pc_transactions';

		dbDelta( "CREATE TABLE $refresh_tokens (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			token_hash CHAR(64) NOT NULL,
			issued_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			revoked_at DATETIME NULL DEFAULT NULL,
			replaced_by CHAR(64) NULL DEFAULT NULL,
			user_agent VARCHAR(512) NOT NULL DEFAULT '',
			ip VARBINARY(16) NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id),
			KEY expires_at (expires_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE $audit_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NULL DEFAULT NULL,
			email VARCHAR(255) NULL DEFAULT NULL,
			ip VARBINARY(16) NULL DEFAULT NULL,
			user_agent VARCHAR(512) NOT NULL DEFAULT '',
			metadata LONGTEXT NULL DEFAULT NULL,
			created_at DATETIME(6) NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY created_at (created_at),
			KEY user_id (user_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE $room_schedules (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			room_id BIGINT UNSIGNED NOT NULL,
			weekday TINYINT UNSIGNED NOT NULL,
			start_time TIME NOT NULL,
			end_time TIME NOT NULL,
			recurrence VARCHAR(16) NOT NULL DEFAULT 'always',
			once_date DATE NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY room_id (room_id),
			KEY weekday (weekday)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE $wallets (
			user_id BIGINT UNSIGNED NOT NULL,
			balance_money DECIMAL(12,2) NOT NULL DEFAULT 0,
			balance_coins INT NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (user_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE $coin_lots (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			qty INT NOT NULL,
			unit_price DECIMAL(8,2) NOT NULL,
			acquired_at DATETIME NOT NULL,
			source_txn_id BIGINT UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id_qty (user_id, qty),
			KEY acquired_at (acquired_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE $transactions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(16) NOT NULL,
			amount_money DECIMAL(12,2) NOT NULL DEFAULT 0,
			amount_coins INT NOT NULL DEFAULT 0,
			unit_price DECIMAL(8,2) NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			external_ref VARCHAR(128) NULL DEFAULT NULL,
			notes TEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			settled_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id_created (user_id, created_at),
			KEY status (status),
			KEY external_ref (external_ref)
		) $charset_collate;" );
	}

	private static function install_default_options(): void {
		add_option( 'pc_terms_current_version', '2026-05' );
		add_option( 'pc_access_token_ttl_seconds', 900 );
		add_option( 'pc_refresh_token_ttl_seconds', 604800 );
		// Phase 2.
		add_option( 'pc_email_confirmation_ttl_seconds', 86400 );
		add_option( 'pc_password_change_ttl_seconds', 900 );
		add_option( 'pc_spa_base_url', home_url() );
		// Phase 4 — coin pricing (UAH). Operator-tunable; these are
		// starting points that approximate "1 coin = 1 USD" for an
		// operator who hasn't visited the admin SPA yet.
		add_option( 'pc_coin_price_default', '40.00' );
		add_option( 'pc_coin_price_min', '10.00' );
		add_option( 'pc_coin_price_max', '500.00' );
		add_option( 'pc_liqpay_public_key', '' );
	}
}

add_action( 'after_switch_theme', [ Install_Schema::class, 'maybe_install' ] );
add_action( 'init', [ Install_Schema::class, 'maybe_install' ], 1 );
