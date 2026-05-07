<?php

namespace PC;

/**
 * Append-only audit log for auth events. Backed by `wp_pc_auth_audit_log`
 * (see Install_Schema).
 *
 * Event types in use (Phase 1):
 *   - signup, signup_failed
 *   - request_verification, request_verification_failed
 *   - verify_success, verify_failure
 *   - refresh, refresh_reuse
 *   - logout
 *   - accept_terms
 *   - set_nickname
 *   - rate_limited
 */
final class Audit_Log {

	public static function record( string $event_type, array $context = array() ): void {
		global $wpdb;

		$user_id = $context['user_id'] ?? null;
		$email   = $context['email'] ?? null;
		$meta    = $context['metadata'] ?? null;

		$wpdb->insert(
			$wpdb->prefix . 'pc_auth_audit_log',
			array(
				'event_type' => substr( $event_type, 0, 64 ),
				'user_id'    => $user_id ? (int) $user_id : null,
				'email'      => $email ? substr( $email, 0, 255 ) : null,
				'ip'         => self::ip_binary(),
				'user_agent' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 512 ),
				'metadata'   => $meta ? wp_json_encode( $meta ) : null,
				'created_at' => current_time( 'mysql', true ) . substr( microtime(), 1, 7 ),
			)
		);
	}

	private static function ip_binary(): ?string {
		$ip = Rate_Limiter::client_ip();
		$packed = @inet_pton( $ip );
		return $packed === false ? null : $packed;
	}
}
