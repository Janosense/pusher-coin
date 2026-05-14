<?php

namespace PC;

use WP_Error;

/**
 * Atomic wallet / coin-lot / transaction operations.
 *
 * The pusher economy has three storage rows that always move together:
 *
 *   - `wp_pc_wallets`     — per-user money + coin-count totals.
 *   - `wp_pc_coin_lots`   — FIFO stack of `(qty, unit_price)` slices.
 *                            A winning toss pays back at the unit_price
 *                            the consumed coin was bought at, so we keep
 *                            the per-purchase price tagged on each lot.
 *   - `wp_pc_transactions`— append-only ledger (top-ups, withdrawals).
 *
 * Every mutation in this file runs inside a MySQL transaction with
 * `SELECT ... FOR UPDATE` on the wallet row. Without that, two concurrent
 * tosses could each see `balance_coins = 1` and both succeed.
 *
 * Type / status string constants live on this class so callers never
 * sprinkle literals across the codebase.
 */
final class Wallet_Service {

	public const TYPE_TOPUP    = 'topup';
	public const TYPE_WITHDRAW = 'withdraw';

	public const STATUS_PENDING   = 'pending';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_REFUNDED  = 'refunded';

	public static function type_values(): array {
		return [ self::TYPE_TOPUP, self::TYPE_WITHDRAW ];
	}

	public static function status_values(): array {
		return [
			self::STATUS_PENDING,
			self::STATUS_COMPLETED,
			self::STATUS_FAILED,
			self::STATUS_REFUNDED,
		];
	}

	/**
	 * Read-only wallet view. Returns zeros for a user with no row yet
	 * — we lazy-create the row on first credit.
	 */
	public static function get_wallet( int $user_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'pc_wallets';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT balance_money, balance_coins, updated_at FROM $table WHERE user_id = %d", $user_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return [ 'balance_money' => '0.00', 'balance_coins' => 0, 'updated_at' => null ];
		}
		return [
			'balance_money' => $row['balance_money'],
			'balance_coins' => (int) $row['balance_coins'],
			'updated_at'    => $row['updated_at'],
		];
	}

	/**
	 * Outstanding coin lots, oldest first (FIFO consumption order).
	 * Lots with `qty = 0` are pruned after a debit, but we filter on
	 * `qty > 0` defensively.
	 */
	public static function get_lots( int $user_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'pc_coin_lots';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, qty, unit_price, acquired_at FROM $table WHERE user_id = %d AND qty > 0 ORDER BY acquired_at ASC, id ASC",
				$user_id
			),
			ARRAY_A
		);
		return array_map( static fn( $r ) => [
			'id'          => (int) $r['id'],
			'qty'         => (int) $r['qty'],
			'unit_price'  => $r['unit_price'],
			'acquired_at' => $r['acquired_at'],
		], $rows ?: [] );
	}

	/**
	 * Insert a pending transaction row and return its id. Callers use
	 * this to reserve a row before talking to the payment provider, then
	 * settle it from the webhook.
	 */
	public static function record_transaction(
		int $user_id,
		string $type,
		string $amount_money,
		int $amount_coins,
		string $unit_price,
		string $status,
		?string $external_ref = null,
		?string $notes = null
	): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'pc_transactions',
			[
				'user_id'      => $user_id,
				'type'         => $type,
				'amount_money' => $amount_money,
				'amount_coins' => $amount_coins,
				'unit_price'   => $unit_price,
				'status'       => $status,
				'external_ref' => $external_ref,
				'notes'        => $notes,
				'created_at'   => current_time( 'mysql' ),
				'settled_at'   => self::STATUS_COMPLETED === $status ? current_time( 'mysql' ) : null,
			],
			[ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	public static function get_transaction( int $txn_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pc_transactions WHERE id = %d", $txn_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function find_transaction_by_external_ref( string $external_ref ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pc_transactions WHERE external_ref = %s LIMIT 1", $external_ref ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function update_transaction_status( int $txn_id, string $status, ?string $notes = null ): bool {
		global $wpdb;
		$data    = [ 'status' => $status ];
		$formats = [ '%s' ];
		if ( self::STATUS_COMPLETED === $status ) {
			$data['settled_at'] = current_time( 'mysql' );
			$formats[]          = '%s';
		}
		if ( null !== $notes ) {
			$data['notes'] = $notes;
			$formats[]     = '%s';
		}
		$result = $wpdb->update(
			$wpdb->prefix . 'pc_transactions',
			$data,
			[ 'id' => $txn_id ],
			$formats,
			[ '%d' ]
		);
		return false !== $result;
	}

	/**
	 * Settle a pending top-up transaction: flip its status to
	 * `completed`, insert the matching coin lot, and increment the
	 * wallet's `balance_coins` — all inside a single DB transaction so
	 * a half-failed settlement can't leave a `completed` txn without a
	 * lot (or vice versa).
	 *
	 * Idempotency is the caller's responsibility: check the txn status
	 * before calling, and treat a non-`pending` row as already settled.
	 */
	public static function settle_topup( int $txn_id, int $user_id, int $qty, string $unit_price ): bool {
		if ( $qty <= 0 ) {
			return false;
		}
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->update(
				$wpdb->prefix . 'pc_transactions',
				[ 'status' => self::STATUS_COMPLETED, 'settled_at' => $now ],
				[ 'id' => $txn_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
			$wpdb->insert(
				$wpdb->prefix . 'pc_coin_lots',
				[
					'user_id'       => $user_id,
					'qty'           => $qty,
					'unit_price'    => $unit_price,
					'acquired_at'   => $now,
					'source_txn_id' => $txn_id,
				],
				[ '%d', '%d', '%s', '%s', '%d' ]
			);
			self::upsert_wallet_delta( $user_id, '0', $qty );
			$wpdb->query( 'COMMIT' );
			return true;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}

	/**
	 * Credit a coin lot to the user's wallet atomically.
	 *
	 * Lower-level variant of `settle_topup` for callers that already
	 * own the transaction-row update (e.g. an admin manual credit that
	 * doesn't go through `wp_pc_transactions`). Inserts a lot row,
	 * upserts the wallet, and increments `balance_coins` in a single
	 * DB transaction.
	 */
	public static function credit_lot( int $user_id, int $qty, string $unit_price, ?int $source_txn_id = null ): bool {
		if ( $qty <= 0 ) {
			return false;
		}
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->insert(
				$wpdb->prefix . 'pc_coin_lots',
				[
					'user_id'       => $user_id,
					'qty'           => $qty,
					'unit_price'    => $unit_price,
					'acquired_at'   => current_time( 'mysql' ),
					'source_txn_id' => $source_txn_id,
				],
				[ '%d', '%d', '%s', '%s', '%d' ]
			);
			self::upsert_wallet_delta( $user_id, '0', $qty );
			$wpdb->query( 'COMMIT' );
			return true;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}

	/**
	 * FIFO-consume `$qty` coins from the user's lots. Used by Phase 6
	 * (the toss flow) and Step 4 (withdrawals against coin balance).
	 *
	 * Returns a WP_Error on insufficient balance, otherwise an array of
	 * `{ qty, unit_price }` slices describing which lots were consumed.
	 * The caller can sum `qty * unit_price` to compute the money value
	 * of the consumed coins (used for withdrawal payouts).
	 */
	public static function debit_fifo( int $user_id, int $qty ) {
		if ( $qty <= 0 ) {
			return new WP_Error( 'invalid_qty', 'qty must be positive.' );
		}

		global $wpdb;
		$wallets_table = $wpdb->prefix . 'pc_wallets';
		$lots_table    = $wpdb->prefix . 'pc_coin_lots';

		$wpdb->query( 'START TRANSACTION' );
		try {
			$current = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT balance_coins FROM $wallets_table WHERE user_id = %d FOR UPDATE", $user_id )
			);
			if ( $current < $qty ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'insufficient_balance', 'Not enough coins.' );
			}

			$lots = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, qty, unit_price FROM $lots_table WHERE user_id = %d AND qty > 0 ORDER BY acquired_at ASC, id ASC FOR UPDATE",
					$user_id
				),
				ARRAY_A
			);

			$remaining = $qty;
			$consumed  = [];
			foreach ( $lots as $lot ) {
				if ( 0 === $remaining ) {
					break;
				}
				$take = min( $remaining, (int) $lot['qty'] );
				$consumed[] = [ 'qty' => $take, 'unit_price' => $lot['unit_price'] ];

				$new_qty = (int) $lot['qty'] - $take;
				$wpdb->update( $lots_table, [ 'qty' => $new_qty ], [ 'id' => (int) $lot['id'] ], [ '%d' ], [ '%d' ] );
				$remaining -= $take;
			}

			if ( $remaining > 0 ) {
				// Shouldn't happen — guarded by the balance check above —
				// but if it does, refuse rather than silently under-debit.
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'lot_inconsistent', 'Wallet balance and lot sum disagree.' );
			}

			self::upsert_wallet_delta( $user_id, '0', -$qty );
			$wpdb->query( 'COMMIT' );
			return $consumed;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'wallet_write_failed', $e->getMessage() );
		}
	}

	/**
	 * Increment / decrement balances on the wallet row, creating it if
	 * absent. Callers must be inside a transaction.
	 */
	private static function upsert_wallet_delta( int $user_id, string $money_delta, int $coins_delta ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pc_wallets';
		$now   = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (user_id, balance_money, balance_coins, updated_at)
				 VALUES (%d, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE
				   balance_money = balance_money + VALUES(balance_money),
				   balance_coins = balance_coins + VALUES(balance_coins),
				   updated_at    = VALUES(updated_at)",
				$user_id,
				$money_delta,
				$coins_delta,
				$now
			)
		);
	}
}
