<?php

namespace PC;

/**
 * LiqPay signing + decoding helper.
 *
 * LiqPay's protocol is: the merchant builds a JSON params object,
 * base64-encodes it as `data`, and signs it with
 * `base64(sha1(private_key + data + private_key))`. The same scheme
 * is used in reverse for the server-side callback — LiqPay POSTs
 * `data` + `signature`, the merchant verifies the signature against
 * the *same* private key, decodes `data`, and acts on the parsed
 * status.
 *
 * Credentials:
 *   - **Public key** — stored in WP option `pc_liqpay_public_key`
 *     (operator-editable from the admin SPA in Step 7).
 *   - **Private key** — must be defined as a `PC_LIQPAY_PRIVATE_KEY`
 *     constant in `wp-config.php`. Never persist it in the DB so it
 *     stays out of backups, admin UI, and audit log dumps.
 *
 * If either side isn't configured, `is_configured()` returns false
 * and callers should refuse with `liqpay_not_configured`.
 */
final class LiqPay_Client {

	public const CHECKOUT_URL = 'https://www.liqpay.ua/api/3/checkout';

	public static function public_key(): string {
		return (string) get_option( 'pc_liqpay_public_key', '' );
	}

	private static function private_key(): string {
		if ( defined( 'PC_LIQPAY_PRIVATE_KEY' ) ) {
			return (string) constant( 'PC_LIQPAY_PRIVATE_KEY' );
		}
		return '';
	}

	public static function is_configured(): bool {
		return '' !== self::public_key() && '' !== self::private_key();
	}

	/**
	 * Build the `{ data, signature }` envelope the SPA POSTs to
	 * LiqPay's hosted checkout. `params` should include action, amount,
	 * currency, order_id, etc. — `version` and `public_key` are
	 * injected here so callers can't forget them.
	 */
	public static function build_payment_envelope( array $params ): array {
		$params['version']    = '3';
		$params['public_key'] = self::public_key();
		$data                 = base64_encode( wp_json_encode( $params ) );
		return [
			'data'      => $data,
			'signature' => self::sign( $data ),
		];
	}

	/**
	 * Constant-time signature comparison. Returns true only when the
	 * supplied signature matches the one we'd compute for `data`.
	 */
	public static function verify_signature( string $data, string $signature ): bool {
		if ( '' === self::private_key() ) {
			return false;
		}
		return hash_equals( self::sign( $data ), $signature );
	}

	public static function decode_data( string $data ): ?array {
		$json = base64_decode( $data, true );
		if ( false === $json ) {
			return null;
		}
		$parsed = json_decode( $json, true );
		return is_array( $parsed ) ? $parsed : null;
	}

	private static function sign( string $data ): string {
		$private = self::private_key();
		return base64_encode( sha1( $private . $data . $private, true ) );
	}
}
