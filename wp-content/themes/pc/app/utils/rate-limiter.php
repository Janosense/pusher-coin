<?php

namespace PC;

use WP_Error;

/**
 * Transient-based rate limiter. Stateless across processes (transients
 * persist in the options table when no object cache is configured).
 *
 * Usage:
 *
 *   $check = Rate_Limiter::check( 'verify:' . self::client_ip(), 5, 15 * MINUTE_IN_SECONDS );
 *   if ( is_wp_error( $check ) ) { return $check; }
 *
 * The first call sets the counter to 1 with the given window TTL; subsequent
 * calls inside the window increment without resetting the TTL.
 */
final class Rate_Limiter {

	public static function check( string $key, int $max, int $window_seconds ) {
		$transient_key = 'pc_rl_' . md5( $key );
		$current       = (int) get_transient( $transient_key );

		if ( $current >= $max ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests. Please wait and try again.' ),
				array( 'status' => 429 )
			);
		}

		if ( $current === 0 ) {
			set_transient( $transient_key, 1, $window_seconds );
		} else {
			set_transient( $transient_key, $current + 1, $window_seconds );
		}

		return true;
	}

	public static function client_ip(): string {
		$candidates = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = explode( ',', (string) $_SERVER[ $key ] )[0];
				return trim( $ip );
			}
		}
		return '0.0.0.0';
	}
}
