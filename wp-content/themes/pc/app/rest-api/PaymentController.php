<?php

namespace PC;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Payment-provider webhooks.
 *
 *   POST /pc/v1/payments/liqpay/callback
 *
 * Public route (the signature is the credential). Always returns 200
 * once the signature checks out, even when the lookup or settlement
 * fails — LiqPay treats any non-2xx as a delivery failure and will
 * retry indefinitely. We log the failure case via Audit_Log instead.
 */
class PaymentController extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'pc/v1';
		$this->rest_base = 'payments';
	}

	public function register_routes(): void {
		register_rest_route( $this->namespace, "/$this->rest_base/liqpay/callback", [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'liqpay_callback' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function liqpay_callback( WP_REST_Request $request ) {
		$data      = (string) $request->get_param( 'data' );
		$signature = (string) $request->get_param( 'signature' );

		if ( '' === $data || '' === $signature ) {
			return new WP_Error( 'missing_required_fields', 'data and signature are required.', [ 'status' => 400 ] );
		}

		if ( ! LiqPay_Client::is_configured() ) {
			Audit_Log::record( 'liqpay_callback_misconfigured' );
			return new WP_Error( 'liqpay_not_configured', 'LiqPay private key is not configured.', [ 'status' => 500 ] );
		}

		if ( ! LiqPay_Client::verify_signature( $data, $signature ) ) {
			Audit_Log::record( 'liqpay_signature_invalid' );
			return new WP_Error( 'liqpay_signature_invalid', 'Signature did not match.', [ 'status' => 401 ] );
		}

		$payload = LiqPay_Client::decode_data( $data );
		if ( null === $payload ) {
			Audit_Log::record( 'liqpay_payload_invalid' );
			return new WP_Error( 'liqpay_payload_invalid', 'Payload could not be decoded.', [ 'status' => 400 ] );
		}

		$order_id = (string) ( $payload['order_id'] ?? '' );
		$status   = (string) ( $payload['status'] ?? '' );

		$txn = $order_id ? Wallet_Service::find_transaction_by_external_ref( $order_id ) : null;
		if ( ! $txn ) {
			Audit_Log::record( 'liqpay_callback_unknown_order', [ 'metadata' => [ 'order_id' => $order_id, 'status' => $status ] ] );
			// 200 OK with a sentinel body so LiqPay stops retrying. This
			// is the right call for a permanent failure: an unknown
			// order isn't going to become known on retry.
			return new WP_REST_Response( [ 'received' => true, 'note' => 'unknown_order' ], 200 );
		}

		// Idempotency: if we've already settled this transaction we
		// short-circuit. LiqPay retries the same status repeatedly.
		if ( Wallet_Service::STATUS_PENDING !== $txn['status'] ) {
			return new WP_REST_Response( [ 'received' => true, 'note' => 'already_settled' ], 200 );
		}

		if ( in_array( $status, [ 'success', 'sandbox' ], true ) ) {
			$settled = Wallet_Service::settle_topup(
				(int) $txn['id'],
				(int) $txn['user_id'],
				(int) $txn['amount_coins'],
				(string) $txn['unit_price']
			);
			Audit_Log::record(
				$settled ? 'liqpay_topup_settled' : 'liqpay_topup_settle_failed',
				[
					'user_id'  => (int) $txn['user_id'],
					'metadata' => [ 'order_id' => $order_id, 'status' => $status, 'txn_id' => (int) $txn['id'] ],
				]
			);
		} elseif ( in_array( $status, [ 'failure', 'error', 'reversed' ], true ) ) {
			Wallet_Service::update_transaction_status(
				(int) $txn['id'],
				Wallet_Service::STATUS_FAILED,
				sprintf( 'LiqPay status: %s', $status )
			);
			Audit_Log::record( 'liqpay_topup_failed', [
				'user_id'  => (int) $txn['user_id'],
				'metadata' => [ 'order_id' => $order_id, 'status' => $status, 'txn_id' => (int) $txn['id'] ],
			] );
		}
		// Other LiqPay states (processing, wait_secure, wait_accept, …)
		// leave the row pending; a later callback flips the status.

		return new WP_REST_Response( [ 'received' => true ], 200 );
	}
}
