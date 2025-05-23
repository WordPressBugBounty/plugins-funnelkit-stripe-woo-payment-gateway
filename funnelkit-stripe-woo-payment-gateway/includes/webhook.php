<?php
/**
 * Stripe Webhook Class
 */

namespace FKWCS\Gateway\Stripe;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Exception as Exception;
use Stripe\Exception\SignatureVerificationException as SignatureException;
use UnexpectedValueException as UnexpectedException;

#[\AllowDynamicProperties]
class Webhook {

	private static $instance = null;
	private $mode = 'test';
	const FKWCS_LIVE_BEGAN_AT = 'fkwcs_live_webhook_began_at';
	const FKWCS_LIVE_LAST_SUCCESS_AT = 'fkwcs_live_webhook_last_success_at';
	const FKWCS_LIVE_LAST_FAILURE_AT = 'fkwcs_live_webhook_last_failure_at';
	const FKWCS_LIVE_LAST_ERROR = 'fkwcs_live_webhook_last_error';

	const FKWCS_TEST_BEGAN_AT = 'fkwcs_test_webhook_began_at';
	const FKWCS_TEST_LAST_SUCCESS_AT = 'fkwcs_test_webhook_last_success_at';
	const FKWCS_TEST_LAST_FAILURE_AT = 'fkwcs_test_webhook_last_failure_at';
	const FKWCS_TEST_LAST_ERROR = 'fkwcs_test_webhook_last_error';


	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_endpoints' ] );


	}

	/**
	 * Initiator
	 *
	 * @return Webhook
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}


	/**
	 * Returns message about interaction with Stripe webhook
	 *
	 * @param mixed $mode mode of operation.
	 *
	 * @return string
	 */
	public static function get_webhook_interaction_message( $mode = false ) {
		if ( ! $mode ) {
			$mode = Helper::get_payment_mode();
		}
		$last_success    = constant( 'self::FKWCS_' . strtoupper( $mode ) . '_LAST_SUCCESS_AT' );
		$last_success_at = get_option( $last_success );

		$last_failure    = constant( 'self::FKWCS_' . strtoupper( $mode ) . '_LAST_FAILURE_AT' );
		$last_failure_at = get_option( $last_failure );

		$began    = constant( 'self::FKWCS_' . strtoupper( $mode ) . '_BEGAN_AT' );
		$start_at = get_option( $began );

		$status = 'none';

		if ( $last_success_at && $last_failure_at ) {
			$status = ( $last_success_at >= $last_failure_at ) ? 'success' : 'failure';
		} elseif ( $last_success_at ) {
			$status = 'success';
		} elseif ( $last_failure_at ) {
			$status = 'failure';
		} elseif ( $start_at ) {
			$status = 'began';
		}

		switch ( $status ) {
			case 'success':
				/* translators: time, status */ return sprintf( __( 'Last webhook call was %1$1s. Status : %2$2s', 'funnelkit-stripe-woo-payment-gateway' ), self::time_elapsed_string( gmdate( 'Y-m-d H:i:s e', $last_success_at ) ), '<b>' . ucfirst( $status ) . '</b>' );

			case 'failure':
				$err_const = constant( 'self::FKWCS_' . strtoupper( $mode ) . '_LAST_ERROR' );
				$error     = get_option( $err_const );
				/* translators: error message */
				$reason = ( $error ) ? sprintf( __( 'Reason : %1s', 'funnelkit-stripe-woo-payment-gateway' ), '<b>' . $error . '</b>' ) : '';

				/* translators: time, status, reason */

				return sprintf( __( 'Last webhook call was %1$1s. Status : %2$2s. %3$3s', 'funnelkit-stripe-woo-payment-gateway' ), self::  time_elapsed_string( gmdate( 'Y-m-d H:i:s e', $last_failure_at ) ), '<b>' . ucfirst( $status ) . '</b>', $reason );

			case 'began':
				/* translators: timestamp */ return sprintf( __( 'No webhook call since %1s.', 'funnelkit-stripe-woo-payment-gateway' ), gmdate( 'Y-m-d H:i:s e', $start_at ) );

			default:
				$endpoint_secret = '';
				if ( 'live' === $mode ) {
					$endpoint_secret = get_option( 'fkwcs_live_webhook_secret', '' );
				} elseif ( 'test' === $mode ) {
					$endpoint_secret = get_option( 'fkwcs_test_webhook_secret', '' );
				}
				if ( ! empty( trim( $endpoint_secret ) ) ) {
					$current_time = time();
					update_option( $began, $current_time, 'no' );

					/* translators: timestamp */

					return sprintf( __( 'No webhook call since %1s.', 'funnelkit-stripe-woo-payment-gateway' ), gmdate( 'Y-m-d H:i:s e', $current_time ) );
				}

				return '';
		}
	}

	/**
	 * Registers endpoint for Stripe webhook
	 *
	 * @return void
	 */
	public function register_endpoints() {
		register_rest_route( 'fkwcs', '/v1/webhook', array(
			'methods'             => 'POST',
			'callback'            => [ $this, 'webhook_listener' ],
			'permission_callback' => function () {
				return true;
			},
		) );
	}

	/**
	 * This function listens webhook events from Stripe.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function webhook_listener() {
		if ( class_exists( 'WFOCU_Core' ) ) {
			remove_action( 'woocommerce_pre_payment_complete', [ WFOCU_Core()->public, 'maybe_setup_upsell' ], 99 );
		}

		$payload = file_get_contents( 'php://input' ); //phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsRemoteFile

		$this->mode      = $this->get_mode( $payload );
		$endpoint_secret = '';
		if ( 'live' === $this->mode ) {
			$endpoint_secret = get_option( 'fkwcs_live_webhook_secret' );
		} elseif ( 'test' === $this->mode ) {
			$endpoint_secret = get_option( 'fkwcs_test_webhook_secret' );
		}

		if ( empty( trim( $endpoint_secret ) ) ) {
			http_response_code( 400 );

			exit();
		}

		$began = constant( 'self::FKWCS_' . strtoupper( $this->mode ) . '_BEGAN_AT' );

		if ( ! get_option( $began ) ) {
			update_option( $began, time(), 'no' );
		}

		$sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? wc_clean( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) : '';

		try {
			$event = \Stripe\Webhook::constructEvent( $payload, $sig_header, $endpoint_secret );
			Helper::log( 'Webhook data: ' . wp_json_encode( $event->toArray() ) );
		} catch ( UnexpectedException|SignatureException $e ) {
			Helper::log( 'Webhook error : ' . $e->getMessage() . ' Full Payload below: ' . $payload );
			$error_at = constant( 'self::FKWCS_' . strtoupper( $this->mode ) . '_LAST_FAILURE_AT' );
			update_option( $error_at, time(), 'no' );
			$error = constant( 'self::FKWCS_' . strtoupper( $this->mode ) . '_LAST_ERROR' );
			update_option( $error, $e->getMessage(), 'no' );

			wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
			exit();

		}

		Helper::log( 'intent type: ' . $event->type );
		$object = isset( $event->data->object ) ? $event->data->object : null;

		if ( is_null( $object ) ) {

			wp_send_json_error( [ 'message' => __( 'Stripe Object found to be null in payload', 'funnelkit-stripe-woo-payment-gateway' ) ], 400 );

			exit;
		}
		Helper::set_mode( $this->mode );
		http_response_code( 200 );

		switch ( $event->type ) {
			case 'charge.captured':
				$this->charge_capture( $object );
				break;
			case 'charge.succeeded':
				$this->charge_succeeded( $object );
				break;
			case 'charge.refunded':
				$this->charge_refund( $object );
				break;
			case 'charge.dispute.created':
				$this->charge_dispute_created( $object );
				break;
			case 'charge.dispute.closed':
				$this->charge_dispute_closed( $object );
				break;
			case 'payment_intent.succeeded':
				$this->payment_intent_succeeded( $object );
				break;
			case 'charge.failed':
				$this->charge_failed( $object );
				break;
			case 'review.opened':
				$this->review_opened( $object );
				break;
			case 'review.closed':
				$this->review_closed( $object );
				break;
			case 'invoice.paid':
				do_action( 'fkwcs_invoice.paid_webhook', $object );
				break;
			case 'invoice.finalized':
				do_action( 'fkwcs_invoice.finalized_webhook', $object );
				break;
			case 'customer.subscription.deleted':
				do_action( 'fkwcs_customer.subscription.deleted_webhook', $object );
				break;
			case 'payment_intent.requires_action':
				$this->require_action( $object );
				break;
			default:
				do_action( 'fkwcs_webhook_event_' . $event->type, $event );
		}
		$success = constant( 'self::FKWCS_' . strtoupper( $this->mode ) . '_LAST_SUCCESS_AT' );
		update_option( $success, time(), 'no' );
		exit;
	}

	/**
	 * Captures charge for un-captured charges via webhook calls
	 *
	 * @param $charge
	 *
	 * @return void
	 */
	public function charge_capture( $charge ) {
		Helper::log( "Charge capture" );

		if ( ! $this->validate_site_url( $charge ) ) {
			Helper::log( 'Website url check failed ' . $charge->id );

			return;
		}
		$order_id = $this->maybe_get_order_id_from_charge( $charge );
		if ( ! $order_id ) {
			Helper::log( 'Could not find order via charge ID: ' . $charge->id );

			return;
		}

		try {
			$order = wc_get_order( $order_id );
			if ( 'fkwcs_stripe_sepa' === $order->get_payment_method() ) {
				$order->set_transaction_id( $charge->id );
				$this->make_charge( $charge, $order );
			}
			do_action( 'fkwcs_webhook_event_charge_capture', $charge, $order );
		} catch ( \WC_Data_Exception $exception ) {
			Helper::log( " Charge Failed " . $exception->getMessage() );
		}


	}

	/**
	 * Make charge via webhook call
	 *
	 * @param object $intent Stripe intent object.
	 * @param \WC_Order $order WC order object.
	 *
	 * @return void
	 */
	public function make_charge( $intent, $order ) {
		if ( $intent->amount_refunded > 0 ) {
			$partial_amount = $intent->amount_captured;
			$currency       = strtoupper( $intent->currency );
			$partial_amount = Helper::get_original_amount( $partial_amount, $currency );
			$order->set_total( $partial_amount );
			/* translators: order id */
			Helper::log( sprintf( __( 'Stripe charge partially captured with amount %1$1s Order id - %2$2s', 'funnelkit-stripe-woo-payment-gateway' ), $partial_amount, $order->get_id() ) );
			/* translators: partial captured amount */
			$order->add_order_note( sprintf( __( 'This charge was partially captured via Stripe Dashboard with the amount : %s', 'funnelkit-stripe-woo-payment-gateway' ), $partial_amount ) );
		} else {
			$order->payment_complete( $intent->id );
			/* translators: order id */
			Helper::log( sprintf( __( 'Stripe charge completely captured Order id - %1s', 'funnelkit-stripe-woo-payment-gateway' ), $order->get_id() ) );
			/* translators: transaction id */
			$order->add_order_note( sprintf( __( 'Stripe charge complete (Charge ID: %s)', 'funnelkit-stripe-woo-payment-gateway' ), $intent->id ) );
		}

		if ( isset( $intent->balance_transaction ) ) {
			Helper::update_balance( $order, $intent->balance_transaction );
		}

		if ( is_callable( [ $order, 'save' ] ) ) {
			$order->save();
		}
	}

	/**
	 * Refunds WooCommerce order via webhook call
	 *
	 * @param object $charge Stripe Charge object.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function charge_refund( $charge ) {

		Helper::log( "charge refund" );

		if ( ! $this->validate_site_url( $charge ) ) {
			Helper::log( 'Website url check failed ' . $charge->id );

			return;
		}
		$order_id = $this->maybe_get_order_id_from_charge( $charge );
		if ( ! $order_id ) {
			Helper::log( 'Could not find order via charge ID: ' . $charge->id );

			return;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( 0 === strpos( $order->get_payment_method(), 'fkwcs_' ) ) {

				$intent = $this->get_intent_from_order( $order );

				if ( empty( $intent ) ) {
					Helper::log( 'Could not find intent in the order ' . $charge->id );

					return;
				}
				if ( $intent !== $charge->payment_intent ) {
					Helper::log( 'Intent in order doesn\'t match with the payload. ' . $charge->id );

					return;
				}

				$webhook_lock = Helper::get_meta( $order, '_fkwcs_webhook_lock' );
				if ( ! empty( $webhook_lock ) ) {
					$min = ( time() - $webhook_lock ) / 60;
					if ( $min <= 1 ) {
						Helper::log( 'Refund in processing for order id ' . $order_id . ' and charge id ' . $charge->id );

						return;
					}
				}

				$transaction_id = $order->get_transaction_id();
				$captured       = $charge->captured;
				$refund_id      = Helper::get_meta( $order, '_fkwcs_refund_id' );
				$currency       = strtoupper( $charge->currency );
				$raw_amount     = $charge->refunds->data[0]->amount;

				$raw_amount = Helper::get_original_amount( $raw_amount, $currency );

				$amount = wc_price( $raw_amount, [ 'currency' => $currency ] );

				if ( ! $captured ) {
					if ( 'cancelled' !== $order->get_status() ) {
						/* translators: amount (including currency symbol) */
						$order->add_order_note( sprintf( __( 'Pre-Authorization for %s voided from the Stripe Dashboard.', 'funnelkit-stripe-woo-payment-gateway' ), $amount ) );
						$order->update_status( 'cancelled' );
					}

					return;
				}

				if ( $charge->refunds->data[0]->id === $refund_id ) {
					return;
				}

				if ( $transaction_id ) {

					$reason = __( 'Refunded via Stripe dashboard', 'funnelkit-stripe-woo-payment-gateway' );

					$refund = wc_create_refund( [
						'order_id' => $order_id,
						'amount'   => ( $charge->amount_refunded > 0 ) ? $raw_amount : false,
						'reason'   => $reason,
					] );

					if ( is_wp_error( $refund ) ) {
						Helper::log( $refund->get_error_message() );
					}

					$refund_id = $charge->refunds->data[0]->id;
					set_transient( '_fkwcs_refund_id_cache_' . $order_id, $refund_id, 60 );
					$order->update_meta_data( '_fkwcs_refund_id', $refund_id );
					$order->save_meta_data();
					if ( isset( $charge->refunds->data[0]->balance_transaction ) ) {
						Helper::update_balance( $order, $charge->refunds->data[0]->balance_transaction, true );
					}

					$status      = 'fkwcs_sepa' === $order->get_payment_method() ? __( 'Pending to Success', 'funnelkit-stripe-woo-payment-gateway' ) : __( 'Success', 'funnelkit-stripe-woo-payment-gateway' );
					$refund_time = gmdate( 'Y-m-d H:i:s', time() );
					$order->add_order_note( __( 'Reason : ', 'funnelkit-stripe-woo-payment-gateway' ) . $reason . '.<br>' . __( 'Amount : ', 'funnelkit-stripe-woo-payment-gateway' ) . $amount . '.<br>' . __( 'Status : ', 'funnelkit-stripe-woo-payment-gateway' ) . $status . ' [ ' . $refund_time . ' ] <br>' . __( 'Transaction ID : ', 'funnelkit-stripe-woo-payment-gateway' ) . $refund_id );
					Helper::log( $reason . ' : Amount: ' . get_woocommerce_currency_symbol() . str_pad( $raw_amount, 2, 0 ) . 'Transaction ID :' . $refund_id );
				}
			}
		} catch ( Exception $exception ) {
			Helper::log( $exception->getMessage() );
		}
	}

	/**
	 * Handles charge.dispute.create webhook and changes order status to 'On Hold'
	 *
	 * @param Object $dispute - Stripe webhook object.
	 *
	 * @return void
	 */
	public function charge_dispute_created( $dispute ) {
		if ( ! $this->validate_site_url( $dispute->payment_intent ) ) {
			Helper::log( 'Website url check failed ' . $dispute->payment_intent->id );

			return;
		}
		$order_id = $this->get_order_id_from_intent_query( $dispute->payment_intent );
		if ( ! $order_id ) {
			Helper::log( 'Could not find order via intent ID: ' . $dispute->payment_intent );

			return;
		}

		$order = wc_get_order( $order_id );
		$order->update_status( 'on-hold', __( 'This order is under dispute. Please respond via Stripe dashboard.', 'funnelkit-stripe-woo-payment-gateway' ) );
		$order->update_meta_data( 'fkwcs_status_before_dispute', $order->get_status() );
		self::send_failed_order_email( $order_id );
	}

	/**
	 * Handles charge.dispute.closed webhook and update order status accordingly
	 *
	 * @param object $dispute dispute object received from Stripe webhook.
	 *
	 * @return void
	 */
	public function charge_dispute_closed( $dispute ) {

		Helper::log( 'charge dispute closed' );


		if ( ! $this->validate_site_url( $dispute->payment_intent ) ) {
			Helper::log( 'Website url check failed ' . $dispute->payment_intent->id );

			return;
		}
		$order_id = $this->get_order_id_from_intent_query( $dispute->payment_intent );
		if ( ! $order_id ) {
			Helper::log( 'Could not find order for dispute ID: ' . $dispute->id );

			return;
		}

		$order   = wc_get_order( $order_id );
		$message = '';
		switch ( $dispute->status ) {
			case 'lost':
				$message = __( 'The disputed order lost or accepted.', 'funnelkit-stripe-woo-payment-gateway' );
				break;

			case 'won':
				$message = __( 'The disputed order resolved in your favour.', 'funnelkit-stripe-woo-payment-gateway' );
				break;

			case 'warning_closed':
				$message = __( 'The inquiry or retrieval closed.', 'funnelkit-stripe-woo-payment-gateway' );
				break;
		}

		$status = 'lost' === $dispute->status ? 'failed' : Helper::get_meta( $order, 'fkwcs_status_before_dispute' );
		$order->update_status( $status, $message );
	}

	/**
	 * Handles webhook call of event payment_intent.succeeded
	 *
	 * @param object $intent intent object received from Stripe.
	 *
	 * @return void
	 */
	public function payment_intent_succeeded( $intent ) {


		if ( ! $this->validate_site_url( $intent ) ) {
			Helper::log( 'Website url check failed ' . $intent->id );

			return;
		}
		$order_id = $this->maybe_get_order_id_from_intent( $intent );
		if ( ! $order_id ) {
			Helper::log( 'Could not find order via payment intent: ' . $intent->id );

			return;
		}

		$order = wc_get_order( $order_id );
		do_action( 'fkwcs_webhook_event_intent_succeeded', $intent, $order );


		if ( in_array( $order->get_payment_method(), [
				'fkwcs_stripe',
				'fkwcs_stripe_afterpay',
				'fkwcs_stripe_affirm',
				'fkwcs_stripe_klarna'
			], true ) && '' === Helper::get_meta( $order, '_fkwcs_maybe_check_for_auth' ) ) {
			return;
		}

		if ( 'manual' === $intent->capture_method && 0 === strpos( $order->get_payment_method(), 'fkwcs_' ) ) {
			$this->make_charge( $intent, $order );
		} else {
			if ( ! $order->has_status( [ 'pending', 'failed', 'on-hold', 'wfocu-pri-order' ] ) ) {
				return;
			}
			Helper::log( "Webhook Source Id: " . $intent->payment_method . " Customer: " . $intent->customer );

			$order->update_meta_data( '_fkwcs_source_id', $intent->payment_method );
			$order->update_meta_data( '_fkwcs_customer_id', $intent->customer );
			$order->save_meta_data();

			/**
			 * Get last charge
			 */
			$charge = Helper::get_latest_charge_from_intent_by_gateway( $intent, $order->get_payment_method() );
			/* translators: transaction id, order id */
			Helper::log( "Webhook: Stripe PaymentIntent $charge->id succeeded for order $order_id" );
			$this->process_response( $charge, $order );
		}
	}


	/**
	 * Handles webhook call of event charge.succeeded
	 *
	 * @param object $charge charge object received from Stripe.
	 *
	 * @return void
	 */
	public function charge_succeeded( $charge ) {


		if ( ! $this->validate_site_url( $charge ) ) {
			Helper::log( 'Website url check failed ' . $charge->id );

			return;
		}
		$order_id = $this->maybe_get_order_id_from_intent( $charge );
		if ( ! $order_id ) {
			Helper::log( 'Could not find order via payment intent: ' . $charge->id );

			return;
		}

		$order = wc_get_order( $order_id );

		if ( 'fkwcs_stripe_sepa' !== $order->get_payment_method() ) {
			return;
		}

		if ( 'manual' === $charge->capture_method && 0 === strpos( $order->get_payment_method(), 'fkwcs_' ) ) {
			$this->make_charge( $charge, $order );
		} else {
			if ( ! $order->has_status( [ 'pending', 'failed', 'on-hold', 'wfocu-pri-order' ] ) ) {
				return;
			}
			Helper::log( "Webhook Source Id: " . $charge->payment_method . " Customer: " . $charge->customer );

			$order->update_meta_data( '_fkwcs_source_id', $charge->payment_method );
			$order->update_meta_data( '_fkwcs_customer_id', $charge->customer );
			$order->save_meta_data();


			/* translators: transaction id, order id */
			Helper::log( "Webhook: Stripe PaymentIntent $charge->id succeeded for order $order_id" );
			$this->process_response( $charge, $order );
		}
	}

	/**
	 * Handled Charge Failed Webhook Event
	 *
	 * @param $charge Object
	 *
	 * @return void
	 */
	public function charge_failed( $charge ) {
		Helper::log( 'Charge Failed Webhook Event' );

		if ( ! $this->validate_site_url( $charge ) ) {
			Helper::log( 'Website url check failed ' . $charge->id );

			return;
		}
		$order_id = $this->maybe_get_order_id_from_charge( $charge );
		if ( ! $order_id ) {
			Helper::log( 'Could not find order via payment: ' . $charge->payment_intent );

			return;
		}
		$order   = wc_get_order( $order_id );
		$gateway = $order->get_payment_method();

		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
			return;
		}
		/**
		 * return if order is already paid
		 */
		if ( $order->is_paid() ) {
			return;
		}


		if ( 'live' === $this->mode ) {
			$client_secret = get_option( 'fkwcs_secret_key' );
		} else {
			$client_secret = get_option( 'fkwcs_test_secret_key' );
		}

		if ( empty( $client_secret ) ) {
			return;
		}


		$client   = Helper::get_new_client( $client_secret );
		$response = $client->payment_intents( 'retrieve', [ $charge->payment_intent ] );
		$intent   = $response['success'] ? $response['data'] : false;
		if ( false === $intent ) {
			return;
		}


		$error_message = '';

		$error_message .= __( 'Intent ID', 'funnelkit-stripe-woo-payment-gateway' ) . ":" . $charge->payment_intent;

		$localized_message = Helper::get_localized_error_message( $intent->last_payment_error );


		$error_message .= "\n\n" . $localized_message;
		$error_message .= ' [via Stripe Webhook]';
		if ( ! empty( $error_message ) ) {

			if ( $order->has_status( 'failed' ) ) {

				if ( in_array( $gateway, [ 'fkwcs_stripe_affirm', 'fkwcs_stripe_afterpay', 'fkwcs_stripe_klarna' ], true ) ) {
					$order->add_order_note( $error_message );
				}

			} else {
				$order->update_status( 'failed', $error_message );
			}
		}

		do_action( 'fkwcs_webhook_payment_failed', $order );

	}

	/**
	 * Handles review.opened webhook
	 *
	 * @param $review - Stripe webhook object.
	 *
	 * @return void
	 */
	public function review_opened( $review ) {
		Helper::log( 'Review opened' );
		$payment_intent = sanitize_text_field( $review->payment_intent );
		$order_id       = $this->get_order_id_from_intent_query( $payment_intent );
		if ( ! $order_id ) {
			Helper::log( 'Could not find order via review ID: ' . $review->id );

			return;
		}

		$order = wc_get_order( $order_id );
		$order->update_status( 'on-hold', __( 'This order is under review. Please respond via stripe dashboard.', 'funnelkit-stripe-woo-payment-gateway' ) );
		$order->update_meta_data( 'fkwcs_status_before_review', $order->get_status() );
		$this->send_failed_order_email( $order_id );
	}

	/**
	 * Handles review.closed webhook
	 *
	 * @param $review - Stripe webhook object.
	 *
	 * @return void
	 */
	public function review_closed( $review ) {
		Helper::log( 'review closed' );
		$payment_intent = sanitize_text_field( $review->payment_intent );
		$order_id       = $this->get_order_id_from_intent_query( $payment_intent );
		if ( ! $order_id ) {
			Helper::log( 'Could not find order via review ID: ' . $review->id );

			return;
		}

		$order = wc_get_order( $order_id );
		/* translators: Review reason from Stripe */
		$message = sprintf( __( 'Review for this order has been resolved. Reason: %s', 'funnelkit-stripe-woo-payment-gateway' ), $review->reason );
		$order->update_status( Helper::get_meta( $order, 'fkwcs_status_before_review' ), $message );
	}


	/**
	 * Fetch WooCommerce order id from payment intent
	 *
	 * @param string $payment_intent payment intent received from Stripe.
	 *
	 * @return string|null order id.
	 */
	public function get_order_id_from_intent_query( $payment_intent, $meta_key = '_fkwcs_intent_id' ) {
		global $wpdb;

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {

			$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM " . $wpdb->prefix . "wc_orders_meta WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1", $meta_key, '%' . $payment_intent . '%' ) );

		} else {
			$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts AS posts LEFT JOIN $wpdb->postmeta AS postmeta ON posts.ID = postmeta.post_id WHERE posts.post_type = %s AND postmeta.meta_key = %s AND postmeta.meta_value LIKE %s LIMIT 1", 'shop_order', $meta_key, '%' . $payment_intent . '%' ) );
		}


		return $order_id;


	}

	/**
	 * Sends order failure email.
	 *
	 * @param int $order_id WooCommerce order id.
	 *
	 * @return void
	 */
	public function send_failed_order_email( $order_id ) {
		$emails = WC()->mailer()->get_emails();
		if ( ! empty( $emails ) && ! empty( $order_id ) ) {
			$emails['WC_Email_Failed_Order']->trigger( $order_id );
		}
	}

	/**
	 * Shows time difference as  - XX minutes ago.
	 *
	 * @param string $datetime time of last event.
	 * @param boolean $full show full time difference.
	 *
	 * @return string
	 */
	public static function time_elapsed_string( $datetime, $full = false ) {
		try {
			$current = new \DateTime();
			$ago     = new \DateTime( $datetime );
			$diff    = $current->diff( $ago );

			// Calculate weeks separately and store in a variable
			$weeks   = floor( $diff->d / 7 );
			$diff->d -= $weeks * 7;

			$string = array(
				'y' => 'year',
				'm' => 'month',
				'w' => 'week',
				'd' => 'day',
				'h' => 'hour',
				'i' => 'minute',
				's' => 'second',
			);

			foreach ( $string as $k => &$v ) {
				if ( $k === 'w' && $weeks ) {
					$v = $weeks . ' ' . $v . ( $weeks > 1 ? 's' : '' );
				} elseif ( $k !== 'w' && $diff->$k ) {
					$v = $diff->$k . ' ' . $v . ( $diff->$k > 1 ? 's' : '' );
				} else {
					unset( $string[ $k ] );
				}
			}

			if ( ! $full ) {
				$string = array_slice( $string, 0, 1 );
			}

			return $string ? implode( ', ', $string ) . ' ago' : 'just now';
		} catch ( Exception $e ) {
			return 'just now';
		}
	}


	/**
	 * Process response for saved cards
	 *
	 * @param object $response intent response.
	 * @param \WC_Order $order order response.
	 *
	 * @return Object
	 */
	public function process_response( $response, $order ) {

		$order_id = $order->get_id();
		$captured = ( isset( $response->captured ) && $response->captured ) ? 'yes' : 'no';

		$order->update_meta_data( '_fkwcs_charge_captured', $captured );

		if ( isset( $response->balance_transaction ) ) {
			Helper::update_balance( $order, $response->balance_transaction );
		}

		if ( 'yes' === $captured ) {
			/**
			 * Charge can be captured but in a pending state. Payment methods
			 * that are asynchronous may take couple days to clear. Webhook will
			 * take care of the status changes.
			 */
			if ( 'pending' === $response->status || 'processing' === $response->status ) {
				$order_stock_reduced = Helper::get_meta( $order, '_order_stock_reduced' );

				if ( ! $order_stock_reduced ) {
					wc_reduce_stock_levels( $order_id );
				}

				$order->set_transaction_id( $response->id );
				$others_info = 'fkwcs_stripe_sepa' === $order->get_payment_method() ? __( 'Payment will be completed once payment_intent.succeeded webhook received from Stripe.', 'funnelkit-stripe-woo-payment-gateway' ) : '';

				/* translators: transaction id, other info */
				$order->update_status( 'on-hold', sprintf( __( 'Stripe charge awaiting payment: %1$s. %2$s', 'funnelkit-stripe-woo-payment-gateway' ), $response->id, $others_info ) );
			}

			if ( 'succeeded' === $response->status ) {
				$order->payment_complete( $response->id );

				do_action( 'fkwcs_webhook_payment_succeed', $order );
				/* translators: transaction id */
				$message = sprintf( __( 'Stripe charge complete (Charge ID: %s)', 'funnelkit-stripe-woo-payment-gateway' ), $response->id );
				Helper::log( $message );
				$order->add_order_note( $message );
			}

			if ( 'failed' === $response->status ) {
				$message = __( 'Payment processing failed. Please retry.', 'funnelkit-stripe-woo-payment-gateway' );
				Helper::log( $message );
				$order->add_order_note( $message );
			}
		} else {
			$order->set_transaction_id( $response->id );

			if ( $order->has_status( [ 'pending', 'failed', 'on-hold' ] ) ) {
				wc_reduce_stock_levels( $order_id );
			}

			/* translators: transaction id */
			$order_info = 'fkwcs_stripe_sepa' === $order->get_payment_method() ? sprintf( __( 'Stripe charge awaiting payment: %1$s. Payment will be completed once payment_intent.succeeded webhook received from Stripe.', 'funnelkit-stripe-woo-payment-gateway' ), $response->id ) : sprintf( __( 'Stripe charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization. Attempting to refund the order in part or in full will release the authorization and cancel the payment.', 'funnelkit-stripe-woo-payment-gateway' ), $response->id );

			$order->update_status( 'on-hold', $order_info );
			do_action( 'fkwcs_webhook_payment_on-hold', $order );

		}

		if ( is_callable( [ $order, 'save' ] ) ) {
			$order->save();
		}

		do_action( 'fkwcs_process_response', $response, $order );

		return $response;
	}

	public function maybe_get_order_id_from_charge( $charge ) {

		if ( isset( $charge->metadata->order_id ) && ! isset( $charge->metadata->fk_upsell ) ) {
			$order = wc_get_order( $charge->metadata->order_id );
			if ( $order ) {
				return $charge->metadata->order_id;
			}
		}

		return $this->get_order_id_from_intent_query( $charge->payment_intent );


	}


	/**
	 * Validate Site URL with the one received as metadata in the webhook
	 * we only need to validate if we have in webhook, else returns true
	 *
	 * @param \stdClass $charge
	 *
	 * @return bool
	 */
	public function validate_site_url( $charge ) {
		global $sitepress;
		$domain = get_site_url();

		if ( isset( $sitepress ) && method_exists( $sitepress, 'get_default_language' ) && method_exists( $sitepress, 'get_wp_api' ) && method_exists( $sitepress, 'convert_url' ) ) {
			$default_language = $sitepress->get_default_language();
			$domain           = $sitepress->convert_url( $sitepress->get_wp_api()->get_home_url(), $default_language );
		}
		if ( isset( $charge->metadata->site_url ) && $charge->metadata->site_url !== esc_url( $domain ) ) {
			return false;
		}

		return true;


	}


	public function require_action( $intent ) {
		Helper::log( __FUNCTION__ );

		if ( ! $this->validate_site_url( $intent ) ) {
			Helper::log( 'Website url check failed ' . $intent->id );

			return;
		}
		$order_id = $this->maybe_get_order_id_from_intent( $intent );
		if ( ! $order_id ) {
			Helper::log( 'Could not find order via charge ID: ' . $intent->id );

			return;
		}

		$gateway = wc_get_order( $order_id )->get_payment_method();

		if ( in_array( $gateway, [ 'fkwcs_stripe_affirm', 'fkwcs_stripe_afterpay', 'fkwcs_stripe_klarna' ], true ) ) {
			wc_get_order( $order_id )->add_order_note( wc_get_order( $order_id )->get_payment_method_title() . ' Incomplete Payment: The customer must complete an additional authentication step.' );
		}
	}

	/**
	 * Maybe get order ID from the intent object
	 *
	 * @param Object $intent
	 *
	 * @return mixed|string|null
	 */
	public function maybe_get_order_id_from_intent( $intent ) {

		if ( isset( $intent->metadata->order_id ) && ! isset( $intent->metadata->fk_upsell ) ) {
			$order = wc_get_order( $intent->metadata->order_id );
			if ( $order ) {
				return $intent->metadata->order_id;
			}
		}

		return $this->get_order_id_from_intent_query( $intent->id );

	}


	/**
	 * Method to get dynamic live mode from the payload data, local settings as fallback
	 *
	 * @param string $payload
	 *
	 * @return string live on live and test on test mode
	 */
	public function get_mode( $payload ) {

		if ( empty( $payload ) ) {
			return Helper::get_payment_mode();
		}
		$json_payload = json_decode( $payload, true );
		if ( ! is_array( $json_payload ) || ! array_key_exists( 'livemode', $json_payload ) ) {
			return Helper::get_payment_mode();
		}

		return $json_payload['livemode'] ? 'live' : 'test';
	}

	/**
	 * Get Intent ID from the order
	 * this method takes care of all the other compatibility keys for the intent and return all possible results
	 *
	 * @param \WC_Order $order
	 *
	 * @return false|mixed
	 */
	public function get_intent_from_order( $order ) {
		$value = Helper::get_meta( $order, '_fkwcs_intent_id' );
		if ( ! empty( $value ) ) {
			return $value['id'];
		}
		$keys = Helper::get_compatibility_keys( '_fkwcs_intent_id' );

		foreach ( $keys as $key ) {
			$value = Helper::get_meta( $order, $key );
			if ( ! empty( $value ) ) {
				return $value;
			}
		}

		return false;

	}
}
