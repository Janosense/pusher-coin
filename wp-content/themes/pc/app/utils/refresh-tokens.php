<?php

namespace PC;

use WP_Error;

/**
 * Opaque refresh-token issuance, redemption, and revocation.
 *
 * Tokens are 256-bit random strings, base64url-encoded. Only the SHA-256
 * hash is stored. Each redeem rotates the token (writes a new row, marks
 * the old `revoked_at` and `replaced_by`). Presenting an already-revoked
 * token revokes its entire descendant chain (reuse detection).
 */
final class Refresh_Tokens {

	public static function issue( int $user_id ): string {
		global $wpdb;

		$plaintext = self::generate();
		$ttl       = (int) get_option( 'pc_refresh_token_ttl_seconds', 604800 );

		$wpdb->insert(
			$wpdb->prefix . 'pc_refresh_tokens',
			array(
				'user_id'    => $user_id,
				'token_hash' => self::hash( $plaintext ),
				'issued_at'  => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
				'user_agent' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 512 ),
				'ip'         => self::ip_binary(),
			)
		);

		return $plaintext;
	}

	/**
	 * Redeem a refresh token: validates, rotates, returns the new plaintext.
	 *
	 * @return array|WP_Error { user_id, plaintext } on success.
	 */
	public static function redeem( string $plaintext ) {
		global $wpdb;

		$row = self::find_row( $plaintext );
		if ( ! $row ) {
			return new WP_Error( 'token_invalid', __( 'Refresh token not recognised.' ), array( 'status' => 401 ) );
		}

		if ( ! empty( $row->revoked_at ) ) {
			// Reuse of a revoked token — burn the entire chain.
			self::revoke_descendants( $row->token_hash );
			Audit_Log::record( 'refresh_reuse', array( 'user_id' => (int) $row->user_id ) );
			return new WP_Error( 'token_revoked', __( 'Refresh token has been revoked.' ), array( 'status' => 401 ) );
		}

		if ( strtotime( $row->expires_at . ' UTC' ) < time() ) {
			return new WP_Error( 'token_expired', __( 'Refresh token has expired.' ), array( 'status' => 401 ) );
		}

		$new_plaintext = self::generate();
		$new_hash      = self::hash( $new_plaintext );
		$ttl           = (int) get_option( 'pc_refresh_token_ttl_seconds', 604800 );

		$wpdb->insert(
			$wpdb->prefix . 'pc_refresh_tokens',
			array(
				'user_id'    => (int) $row->user_id,
				'token_hash' => $new_hash,
				'issued_at'  => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
				'user_agent' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 512 ),
				'ip'         => self::ip_binary(),
			)
		);

		$wpdb->update(
			$wpdb->prefix . 'pc_refresh_tokens',
			array(
				'revoked_at'  => gmdate( 'Y-m-d H:i:s' ),
				'replaced_by' => $new_hash,
			),
			array( 'id' => (int) $row->id )
		);

		return array(
			'user_id'   => (int) $row->user_id,
			'plaintext' => $new_plaintext,
		);
	}

	public static function revoke( string $plaintext ): bool {
		global $wpdb;

		$row = self::find_row( $plaintext );
		if ( ! $row || ! empty( $row->revoked_at ) ) {
			return false;
		}

		$wpdb->update(
			$wpdb->prefix . 'pc_refresh_tokens',
			array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => (int) $row->id )
		);
		return true;
	}

	/**
	 * Revoke every active refresh token belonging to the user. Used by
	 * password-change to invalidate other live sessions.
	 *
	 * @return int Number of rows updated.
	 */
	public static function revoke_all_for_user( int $user_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'pc_refresh_tokens';
		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE $table SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL",
			gmdate( 'Y-m-d H:i:s' ),
			$user_id
		) );
	}

	private static function revoke_descendants( string $start_hash ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pc_refresh_tokens';
		$hash  = $start_hash;
		$now   = gmdate( 'Y-m-d H:i:s' );

		// Walk the chain via replaced_by.
		while ( $hash ) {
			$next = $wpdb->get_var( $wpdb->prepare( "SELECT replaced_by FROM $table WHERE token_hash = %s", $hash ) );
			$wpdb->update( $table, array( 'revoked_at' => $now ), array( 'token_hash' => $hash ) );
			$hash = $next ?: null;
		}
	}

	private static function find_row( string $plaintext ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pc_refresh_tokens';
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE token_hash = %s LIMIT 1", self::hash( $plaintext ) )
		);
	}

	private static function generate(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	private static function hash( string $plaintext ): string {
		return hash( 'sha256', $plaintext );
	}

	private static function ip_binary(): ?string {
		$packed = @inet_pton( Rate_Limiter::client_ip() );
		return $packed === false ? null : $packed;
	}
}
