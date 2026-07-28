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
	public const DB_VERSION = '1.7.0';

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
		$machine_events  = $wpdb->prefix . 'pc_machine_events';
		$support_tickets = $wpdb->prefix . 'pc_support_tickets';
		$bet_sessions    = $wpdb->prefix . 'pc_bet_sessions';
		$room_queues     = $wpdb->prefix . 'pc_room_queues';

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
			consumed_lots LONGTEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			settled_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id_created (user_id, created_at),
			KEY status (status),
			KEY external_ref (external_ref)
		) $charset_collate;" );

		// Phase 5 Step 5 — every machine event the backend mediates.
		// `event_key` is the idempotency guard: a retried webhook or an
		// overlapping poll collides on the unique index instead of
		// crediting twice. NULL is allowed (MySQL lets a unique index
		// hold repeated NULLs) so events that carry no natural key still
		// log — see Machine_Ingest_Service for why callers should.
		dbDelta( "CREATE TABLE $machine_events (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			machine_id VARCHAR(64) NOT NULL DEFAULT '',
			event_type VARCHAR(32) NOT NULL,
			event_key VARCHAR(191) NULL DEFAULT NULL,
			user_id BIGINT UNSIGNED NULL DEFAULT NULL,
			coins_credited INT NOT NULL DEFAULT 0,
			unit_price DECIMAL(8,2) NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'recorded',
			payload LONGTEXT NULL DEFAULT NULL,
			correlation_id BIGINT UNSIGNED NULL DEFAULT NULL,
			created_at DATETIME(6) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY event_type_created (event_type, created_at),
			KEY user_id (user_id),
			KEY status (status)
		) $charset_collate;" );

		// Phase 7 — support tickets. `user_id` is nullable because guests
		// submit too; `email_verified` is captured at submission time so
		// support can weigh a claim without re-checking the account later
		// (and so it stays true about the moment the ticket was filed).
		dbDelta( "CREATE TABLE $support_tickets (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NULL DEFAULT NULL,
			email VARCHAR(255) NOT NULL,
			subject_id BIGINT UNSIGNED NOT NULL,
			description TEXT NOT NULL,
			ip VARBINARY(16) NULL DEFAULT NULL,
			user_agent VARCHAR(512) NOT NULL DEFAULT '',
			email_verified TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'open',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status_created (status, created_at),
			KEY user_id (user_id),
			KEY subject_id (subject_id)
		) $charset_collate;" );

		// Phase 6 — one row per turn at the head of a room's queue.
		// `ended_at IS NULL` marks the live session; there is at most one
		// per room, which is what makes "who owns this machine event?"
		// answerable.
		dbDelta( "CREATE TABLE $bet_sessions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			room_id BIGINT UNSIGNED NOT NULL,
			started_at DATETIME NOT NULL,
			ended_at DATETIME NULL DEFAULT NULL,
			coins_played INT NOT NULL DEFAULT 0,
			coins_won INT NOT NULL DEFAULT 0,
			money_won DECIMAL(12,2) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY room_open (room_id, ended_at),
			KEY user_started (user_id, started_at)
		) $charset_collate;" );

		// Phase 6 — the queue itself. DATA-MODEL left this optional
		// pending a presence-channel decision; we persist it because the
		// turn (and therefore who gets paid for a bonus) must survive a
		// page reload and a backend restart, which presence state does
		// not. `last_seen_at` is what a stale entry is pruned on.
		dbDelta( "CREATE TABLE $room_queues (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			room_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			coins_declared INT NOT NULL DEFAULT 0,
			coins_remaining INT NOT NULL DEFAULT 0,
			session_id BIGINT UNSIGNED NULL DEFAULT NULL,
			joined_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY room_user (room_id, user_id),
			KEY room_joined (room_id, joined_at)
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
		// Phase 7 — support. Empty captcha config means the guest path
		// runs without a challenge; see Captcha_Verifier.
		add_option( 'pc_support_email', get_option( 'admin_email' ) );
		add_option( 'pc_captcha_provider', 'turnstile' );
		add_option( 'pc_captcha_site_key', '' );
		// Phase 6 — how long a queue entry survives without a heartbeat
		// (the SPA polls the queue endpoint, which touches it).
		add_option( 'pc_queue_idle_timeout_seconds', 60 );
	}
}

add_action( 'after_switch_theme', [ Install_Schema::class, 'maybe_install' ] );
add_action( 'init', [ Install_Schema::class, 'maybe_install' ], 1 );
