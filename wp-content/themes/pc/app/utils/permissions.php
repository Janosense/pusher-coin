<?php

namespace PC;

use WP_Error;
use WP_REST_Request;

/**
 * Reusable REST permission_callback helpers.
 */
final class Permissions {

	public static function require_logged_in( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Authentication required.' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	public static function require_terms_accepted( WP_REST_Request $request ) {
		$logged_in = self::require_logged_in( $request );
		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}
		$user_id            = get_current_user_id();
		$accepted_at        = (int) get_user_meta( $user_id, User_Meta_Keys::TERMS_ACCEPTED_AT, true );
		$accepted_version   = get_user_meta( $user_id, User_Meta_Keys::TERMS_ACCEPTED_VERSION, true );
		$current_version    = get_option( 'pc_terms_current_version', '2026-05' );

		if ( $accepted_at <= 0 || $accepted_version !== $current_version ) {
			return new WP_Error(
				'terms_not_accepted',
				__( 'Terms and conditions must be accepted.' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	public static function require_chosen_nickname( WP_REST_Request $request ) {
		$logged_in = self::require_logged_in( $request );
		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}
		$chosen = get_user_meta( get_current_user_id(), User_Meta_Keys::NICKNAME_CHOSEN, true );
		if ( $chosen !== '1' ) {
			return new WP_Error(
				'nickname_required',
				__( 'A unique nickname must be chosen first.' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	public static function require_email_verified( WP_REST_Request $request ) {
		$logged_in = self::require_logged_in( $request );
		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}
		$verified_at = (int) get_user_meta( get_current_user_id(), User_Meta_Keys::EMAIL_VERIFIED_AT, true );
		if ( $verified_at <= 0 ) {
			return new WP_Error(
				'email_not_verified',
				__( 'Email verification required.' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	public static function require_admin( WP_REST_Request $request ) {
		$logged_in = self::require_logged_in( $request );
		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Administrator privileges required.' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	public static function require_play_ready( WP_REST_Request $request ) {
		$checks = array(
			self::require_logged_in( $request ),
			self::require_terms_accepted( $request ),
			self::require_chosen_nickname( $request ),
			self::require_email_verified( $request ),
		);
		foreach ( $checks as $check ) {
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
		return true;
	}
}
