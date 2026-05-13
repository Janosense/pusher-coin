<?php

namespace PC;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure helper that computes broadcast windows from `wp_pc_room_schedules`
 * rules. Stateless and free of DB access — callers pass the rule rows in.
 *
 * A rule row shape (as returned by $wpdb->get_results( ARRAY_A )):
 *   [
 *     'id'         => int,
 *     'room_id'    => int,
 *     'weekday'    => int  (0=Mon .. 6=Sun, ISO),
 *     'start_time' => 'HH:MM:SS',
 *     'end_time'   => 'HH:MM:SS',
 *     'recurrence' => 'always' | 'once',
 *     'once_date'  => 'YYYY-MM-DD' | null,
 *   ]
 *
 * Windows are interpreted in the WordPress site timezone (`wp_timezone()`)
 * and returned as ISO-8601 strings.
 *
 * MVP constraint: start_time < end_time. Midnight-crossing windows would
 * need to be expressed as two rules. The admin write path enforces this.
 */
final class Room_Schedule_Calculator {

	/**
	 * Find the rule (if any) covering $now and the soonest upcoming rule.
	 *
	 * Returns:
	 *   [
	 *     'current_window' => [ 'start_at' => ISO, 'end_at' => ISO ] | null,
	 *     'next_window'    => [ 'start_at' => ISO, 'end_at' => ISO ] | null,
	 *   ]
	 *
	 * If a rule is currently live, `next_window` is the *following* window
	 * after the current one ends.
	 */
	public static function compute( array $rules, ?DateTimeImmutable $now = null ): array {
		$tz  = wp_timezone();
		$now = $now ? $now->setTimezone( $tz ) : new DateTimeImmutable( 'now', $tz );

		$current = null;
		$next    = null;

		foreach ( $rules as $rule ) {
			$windows = self::materialize_rule( $rule, $now, $tz );
			foreach ( $windows as $window ) {
				if ( $window['start_at'] <= $now && $now < $window['end_at'] ) {
					if ( null === $current || $window['end_at'] > $current['end_at'] ) {
						$current = $window;
					}
				} elseif ( $window['start_at'] > $now ) {
					if ( null === $next || $window['start_at'] < $next['start_at'] ) {
						$next = $window;
					}
				}
			}
		}

		return [
			'current_window' => self::serialize_window( $current ),
			'next_window'    => self::serialize_window( $next ),
		];
	}

	/**
	 * Expand a rule into concrete windows around $now.
	 *
	 * - `always`: returns the matching weekday's window for this week and
	 *   next week (covers the case where this week's window has already
	 *   passed).
	 * - `once`: returns the single window on `once_date` if any.
	 *
	 * @return array<int,array{start_at:DateTimeImmutable,end_at:DateTimeImmutable}>
	 */
	private static function materialize_rule( array $rule, DateTimeImmutable $now, DateTimeZone $tz ): array {
		$weekday    = (int) $rule['weekday'];
		$start_time = (string) $rule['start_time'];
		$end_time   = (string) $rule['end_time'];
		$recurrence = (string) $rule['recurrence'];

		if ( 'once' === $recurrence ) {
			if ( empty( $rule['once_date'] ) ) {
				return [];
			}
			$start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $rule['once_date'] . ' ' . $start_time, $tz );
			$end   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $rule['once_date'] . ' ' . $end_time, $tz );
			if ( ! $start || ! $end ) {
				return [];
			}
			return [ [ 'start_at' => $start, 'end_at' => $end ] ];
		}

		// 'always' — emit this week's and next week's occurrence so the
		// caller always has a future window to compare against.
		$windows  = [];
		$today_iso = (int) $now->format( 'N' ) - 1; // 0=Mon..6=Sun
		$delta     = ( $weekday - $today_iso + 7 ) % 7;

		foreach ( [ $delta - 7, $delta, $delta + 7 ] as $offset ) {
			$date  = $now->modify( sprintf( '%+d days', $offset ) )->format( 'Y-m-d' );
			$start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' ' . $start_time, $tz );
			$end   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' ' . $end_time, $tz );
			if ( $start && $end ) {
				$windows[] = [ 'start_at' => $start, 'end_at' => $end ];
			}
		}
		return $windows;
	}

	private static function serialize_window( ?array $window ): ?array {
		if ( null === $window ) {
			return null;
		}
		return [
			'start_at' => $window['start_at']->format( DATE_ATOM ),
			'end_at'   => $window['end_at']->format( DATE_ATOM ),
		];
	}
}
