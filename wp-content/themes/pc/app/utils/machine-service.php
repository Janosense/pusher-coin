<?php

namespace PC;

use WP_Error;

/**
 * Thin wrapper around the Home Assistant REST API that drives the
 * physical pusher machine. Single instance per request — every other
 * Phase 5+ component (admin power switch, bonus crediting, Phase 6
 * toss flow) goes through this class so we never sprinkle HA URLs or
 * bearer tokens across the codebase.
 *
 * Configuration:
 *
 *   - Bearer token comes from the `PC_MACHINE_TOKEN` constant in
 *     `wp-config.php`. NEVER persist it to the DB — keeping it out of
 *     options means it stays out of admin UI dumps, backups, and
 *     `wp db export`. Rotation is a wp-config edit.
 *   - The HA base URL and the per-entity IDs are operator-tunable WP
 *     options with sensible defaults (see `default_options()`). Phase
 *     5 ships with a single physical machine; multi-machine support
 *     refactors these into a per-machine_id JSON map.
 *
 * All calls have a 2-second timeout and translate HA errors into
 * typed WP_Error codes the controllers can map to HTTP statuses.
 */
final class Machine_Service {

	private const HTTP_TIMEOUT = 2;

	public static function is_configured(): bool {
		return '' !== self::token() && '' !== self::endpoint();
	}

	// ---------------- actions (POST /services/...) ----------------

	public static function power_on() {
		return self::call_service( 'switch/turn_on', self::option( 'pc_machine_power_switch_entity', 'switch.sonoff_10024fb618' ) );
	}

	public static function power_off() {
		return self::call_service( 'switch/turn_off', self::option( 'pc_machine_power_switch_entity', 'switch.sonoff_10024fb618' ) );
	}

	public static function toss_coin() {
		return self::press_button( self::option( 'pc_machine_toss_button_entity', 'input_button.toss_a_coin' ) );
	}

	public static function relay_close() {
		return self::press_button( self::option( 'pc_machine_relay_close_entity', 'input_button.relay_on' ) );
	}

	public static function relay_open() {
		return self::press_button( self::option( 'pc_machine_relay_open_entity', 'input_button.relay_off' ) );
	}

	// ---------------- state reads (GET /states/...) ----------------

	/** Cumulative coin count from `sensor.coin`. Returns int or WP_Error. */
	public static function get_coin_count() {
		return self::read_int( self::option( 'pc_machine_coin_sensor_entity', 'sensor.coin' ) );
	}

	/** Bonus number 1..12 from `sensor.lc01_12`. */
	public static function get_bonus_number() {
		return self::read_int( self::option( 'pc_machine_bonus_sensor_entity', 'sensor.lc01_12' ) );
	}

	/** Light state bitfield 0..15 from `sensor.light_b_t`. */
	public static function get_light_state() {
		return self::read_int( self::option( 'pc_machine_light_sensor_entity', 'sensor.light_b_t' ) );
	}

	/** Relay-contact closed state from `sensor.relay_on`. True iff closed. */
	public static function get_relay_closed() {
		$state = self::read_raw_state( self::option( 'pc_machine_relay_sensor_entity', 'sensor.relay_on' ) );
		if ( $state instanceof WP_Error ) {
			return $state;
		}
		return self::normalise_truthy( $state );
	}

	/** Power switch on/off state. True iff on. */
	public static function get_power_on() {
		$state = self::read_raw_state( self::option( 'pc_machine_power_switch_entity', 'switch.sonoff_10024fb618' ) );
		if ( $state instanceof WP_Error ) {
			return $state;
		}
		return self::normalise_truthy( $state );
	}

	/**
	 * Batched snapshot for the admin SPA — one struct, five reads.
	 * Soft-fails per field: if `sensor.coin` is offline but the power
	 * switch responds, the response is `{power_on: true, coin_count:
	 * null, ...}` rather than 503-ing the whole call.
	 */
	public static function get_state_snapshot(): array {
		return [
			'online'            => self::is_online(),
			'power_on'          => self::soften( self::get_power_on() ),
			'coin_count'        => self::soften( self::get_coin_count() ),
			'last_bonus_number' => self::soften( self::get_bonus_number() ),
			'light_state'       => self::soften( self::get_light_state() ),
			'relay_closed'      => self::soften( self::get_relay_closed() ),
		];
	}

	/**
	 * Cheap connectivity probe — used by ops checks (Phase 7). Hits
	 * the HA root `/api/` which returns `{ "message": "API running." }`
	 * when the service is up and the token is valid.
	 */
	public static function is_online(): bool {
		if ( ! self::is_configured() ) {
			return false;
		}
		$response = wp_remote_get( self::endpoint() . '/', [
			'headers' => self::auth_headers(),
			'timeout' => self::HTTP_TIMEOUT,
		] );
		if ( is_wp_error( $response ) ) {
			return false;
		}
		return 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	// ---------------- HTTP helpers ----------------

	private static function call_service( string $service_path, string $entity_id ) {
		$guard = self::guard_configured();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}
		$response = wp_remote_post( self::endpoint() . '/services/' . ltrim( $service_path, '/' ), [
			'headers' => self::auth_headers(),
			'body'    => wp_json_encode( [ 'entity_id' => $entity_id ] ),
			'timeout' => self::HTTP_TIMEOUT,
		] );
		return self::interpret_action_response( $response );
	}

	private static function press_button( string $entity_id ) {
		return self::call_service( 'input_button/press', $entity_id );
	}

	private static function read_raw_state( string $entity_id ) {
		$guard = self::guard_configured();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}
		$response = wp_remote_get( self::endpoint() . '/states/' . $entity_id, [
			'headers' => self::auth_headers(),
			'timeout' => self::HTTP_TIMEOUT,
		] );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'machine_offline', $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status ) {
			return new WP_Error( 'machine_unauthorized', 'Home Assistant rejected the bearer token.' );
		}
		if ( 404 === $status ) {
			return new WP_Error( 'machine_call_failed', sprintf( 'Entity %s not found on Home Assistant.', $entity_id ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'machine_call_failed', sprintf( 'HA returned HTTP %d for %s.', $status, $entity_id ) );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! array_key_exists( 'state', $body ) ) {
			return new WP_Error( 'machine_call_failed', 'HA response missing `state`.' );
		}
		$state = (string) $body['state'];
		// HA reports `unavailable` / `unknown` for sensors that lost contact.
		if ( in_array( $state, [ 'unavailable', 'unknown', '' ], true ) ) {
			return new WP_Error( 'machine_unavailable_state', sprintf( 'Sensor %s is unavailable.', $entity_id ) );
		}
		return $state;
	}

	private static function read_int( string $entity_id ) {
		$state = self::read_raw_state( $entity_id );
		if ( $state instanceof WP_Error ) {
			return $state;
		}
		if ( ! is_numeric( $state ) ) {
			return new WP_Error( 'machine_call_failed', sprintf( 'Sensor %s returned non-numeric "%s".', $entity_id, $state ) );
		}
		return (int) $state;
	}

	private static function interpret_action_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'machine_offline', $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status ) {
			return new WP_Error( 'machine_unauthorized', 'Home Assistant rejected the bearer token.' );
		}
		if ( 200 === $status ) {
			return true;
		}
		return new WP_Error( 'machine_call_failed', sprintf( 'HA returned HTTP %d.', $status ) );
	}

	// ---------------- config / utilities ----------------

	private static function token(): string {
		if ( defined( 'PC_MACHINE_TOKEN' ) ) {
			return trim( (string) constant( 'PC_MACHINE_TOKEN' ) );
		}
		return '';
	}

	private static function endpoint(): string {
		return rtrim( self::option( 'pc_machine_endpoint', 'https://developer-it.com/api' ), '/' );
	}

	private static function option( string $key, string $default ): string {
		$value = (string) get_option( $key, $default );
		return '' === $value ? $default : $value;
	}

	private static function auth_headers(): array {
		return [
			'Authorization' => 'Bearer ' . self::token(),
			'Content-Type'  => 'application/json',
		];
	}

	private static function guard_configured() {
		if ( ! self::is_configured() ) {
			return new WP_Error(
				'machine_not_configured',
				'Set PC_MACHINE_TOKEN in wp-config.php and pc_machine_endpoint via the admin SPA.'
			);
		}
		return null;
	}

	/**
	 * Maps the truthy half of HA's state vocabulary to bool. HA uses
	 * `on` / `off` for switches, sometimes `closed` / `open` for
	 * contacts, occasionally `true` / `false`. Anything we don't
	 * recognise as positive falls to `false`.
	 */
	private static function normalise_truthy( string $state ): bool {
		return in_array( strtolower( $state ), [ 'on', 'closed', 'true', '1', 'home' ], true );
	}

	/**
	 * Convert a per-field WP_Error into `null` for the batched
	 * snapshot. The snapshot caller wants partial data, not a single
	 * failure mode for the whole machine.
	 */
	private static function soften( $value ) {
		return $value instanceof WP_Error ? null : $value;
	}
}
