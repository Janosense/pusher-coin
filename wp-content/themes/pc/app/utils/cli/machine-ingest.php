<?php

namespace PC;

use WP_CLI;
use WP_Error;

/**
 * `wp pc machine-ingest` — replay a machine event by hand.
 *
 * Phase 5 Step 6 decides how events actually reach us (HA webhook vs
 * backend poller). Until then this is the only caller of
 * `Machine_Ingest_Service`, and it stays useful afterwards: it is how an
 * operator settles an event the transport dropped, and how we test the
 * bonus map against a real wallet without standing on the machine.
 *
 * Run from the WP root:
 *
 *   wp pc machine-ingest bonus --bonus=7 --player=12
 *   wp pc machine-ingest relay --player=12 --key=relay-2026-07-24T10:15:00
 *   wp pc machine-ingest coins --coins=3 --player=12
 *   wp pc machine-ingest log --type=toss --key=toss-abc123
 *
 * Without `--player`, attribution falls to the `pc_machine_event_player`
 * filter — which nothing hooks until Phase 6, so the event logs as
 * `unattributed` and no coins move. That is the honest dry run.
 *
 * `--key` is the idempotency key. Repeat a key and the event is
 * recorded once; the second call reports `duplicate` and pays nothing.
 */
final class Machine_Ingest_Command {

	/**
	 * @param array $args       Positional: bonus|relay|coins|log.
	 * @param array $assoc_args --bonus, --coins, --player, --key, --machine, --type.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';

		$context = [
			'machine_id' => (string) ( $assoc_args['machine'] ?? '' ),
			'event_key'  => isset( $assoc_args['key'] ) ? (string) $assoc_args['key'] : null,
		];
		if ( isset( $assoc_args['player'] ) ) {
			$context['user_id'] = (int) $assoc_args['player'];
		}

		switch ( $action ) {
			case 'bonus':
				$result = Machine_Ingest_Service::ingest_bonus( (int) ( $assoc_args['bonus'] ?? 0 ), $context );
				break;

			case 'relay':
				$result = Machine_Ingest_Service::ingest_relay_closed( $context );
				break;

			case 'coins':
				$result = Machine_Ingest_Service::ingest_coins_dropped( (int) ( $assoc_args['coins'] ?? 0 ), $context );
				break;

			case 'log':
				$type = (string) ( $assoc_args['type'] ?? Machine_Event_Log::TYPE_TOSS );
				if ( ! in_array( $type, Machine_Event_Log::type_values(), true ) ) {
					WP_CLI::error( sprintf( 'Unknown --type=%s. Expected one of: %s', $type, implode( ', ', Machine_Event_Log::type_values() ) ) );
				}
				$result = Machine_Ingest_Service::log_event( $type, $context );
				break;

			default:
				WP_CLI::error( 'Usage: wp pc machine-ingest <bonus|relay|coins|log> [--bonus=<1-12>] [--coins=<n>] [--player=<id>] [--key=<key>] [--machine=<id>] [--type=<event-type>]' );
				return;
		}

		if ( $result instanceof WP_Error ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( ! empty( $result['duplicate'] ) ) {
			WP_CLI::warning( sprintf( 'Duplicate event_key "%s" — already handled, nothing credited.', $context['event_key'] ) );
			return;
		}

		WP_CLI::log( sprintf( 'event #%d recorded as %s.', $result['event_id'], $result['status'] ) );

		if ( Machine_Event_Log::STATUS_CREDITED === $result['status'] ) {
			WP_CLI::success( sprintf(
				'Credited %d coin(s) to user %d at %s each.',
				$result['coins'],
				$result['user_id'],
				$result['unit_price']
			) );
			return;
		}

		if ( Machine_Event_Log::STATUS_UNATTRIBUTED === $result['status'] ) {
			WP_CLI::warning( 'No player held the turn — logged without crediting. Pass --player to attribute it.' );
			return;
		}

		if ( Machine_Event_Log::STATUS_FAILED === $result['status'] ) {
			WP_CLI::error( sprintf( 'Wallet write failed for user %d; event #%d kept for forensics.', $result['user_id'], $result['event_id'] ) );
			return;
		}

		WP_CLI::success( 'Done.' );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'pc machine-ingest', Machine_Ingest_Command::class );
}
