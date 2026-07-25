<?php

namespace PC;

use WP_Error;

/**
 * Verifies a captcha token against Cloudflare Turnstile or hCaptcha.
 *
 * Both providers expose the same shape — POST `secret` + `response` to a
 * siteverify URL, read `success` off the JSON — so one verifier covers
 * both and the operator picks with `pc_captcha_provider`. ROADMAP §7.1
 * left the choice open; this keeps it open until someone opens an
 * account, without blocking the support form.
 *
 * Configuration:
 *   - `pc_captcha_provider` option — `turnstile` (default) or `hcaptcha`.
 *   - `pc_captcha_site_key` option — public, handed to the SPA.
 *   - `PC_CAPTCHA_SECRET` constant in wp-config.php — the secret half.
 *     Same rule as `PC_LIQPAY_PRIVATE_KEY` / `PC_MACHINE_TOKEN`: secrets
 *     never live in the database.
 *
 * **Unconfigured means disabled.** With no site key or no secret,
 * `is_enabled()` is false and `verify()` passes everything. That is what
 * makes local dev and a pre-account staging environment usable; it also
 * means shipping to production without setting the keys silently leaves
 * the guest form unprotected. `GET /support/subjects` reports the live
 * state so the SPA (and a reviewer) can see which mode is in force.
 */
final class Captcha_Verifier {

	private const ENDPOINTS = [
		'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		'hcaptcha'  => 'https://hcaptcha.com/siteverify',
	];

	private const HTTP_TIMEOUT = 5;

	public static function provider(): string {
		$provider = (string) get_option( 'pc_captcha_provider', 'turnstile' );
		return isset( self::ENDPOINTS[ $provider ] ) ? $provider : 'turnstile';
	}

	public static function site_key(): string {
		return (string) get_option( 'pc_captcha_site_key', '' );
	}

	private static function secret(): string {
		return defined( 'PC_CAPTCHA_SECRET' ) ? (string) constant( 'PC_CAPTCHA_SECRET' ) : '';
	}

	/**
	 * Whether the wp-config side is done. Reports presence only — the
	 * admin API surfaces this so an operator can confirm the guest form
	 * is protected without the secret ever crossing the wire.
	 */
	public static function is_secret_configured(): bool {
		return '' !== self::secret();
	}

	public static function is_enabled(): bool {
		return '' !== self::site_key() && self::is_secret_configured();
	}

	/**
	 * Public description of the challenge for the SPA: which widget to
	 * mount and with what key. Null when captcha is off.
	 */
	public static function public_config(): ?array {
		if ( ! self::is_enabled() ) {
			return null;
		}
		return [
			'provider' => self::provider(),
			'site_key' => self::site_key(),
		];
	}

	/**
	 * Returns true when the token is good (or captcha is disabled), and a
	 * `captcha_failed` WP_Error otherwise.
	 *
	 * An unreachable provider fails closed: we cannot tell a human from a
	 * bot, and a support form is a spam target. The operator's escape
	 * hatch is clearing the site key, which is a deliberate act.
	 */
	public static function verify( ?string $token ) {
		if ( ! self::is_enabled() ) {
			return true;
		}

		if ( ! $token ) {
			return new WP_Error( 'captcha_failed', __( 'Captcha verification is required.' ), [ 'status' => 401 ] );
		}

		$response = wp_remote_post( self::ENDPOINTS[ self::provider() ], [
			'timeout' => self::HTTP_TIMEOUT,
			'body'    => [
				'secret'   => self::secret(),
				'response' => $token,
				'remoteip' => Rate_Limiter::client_ip(),
			],
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'captcha_failed', __( 'Captcha verification is unavailable. Please try again.' ), [ 'status' => 401 ] );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['success'] ) ) {
			return new WP_Error( 'captcha_failed', __( 'Captcha verification failed.' ), [ 'status' => 401 ] );
		}

		return true;
	}
}
