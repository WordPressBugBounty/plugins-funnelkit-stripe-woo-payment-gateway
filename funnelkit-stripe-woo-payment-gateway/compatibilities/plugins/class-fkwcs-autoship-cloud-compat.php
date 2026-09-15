<?php

use FKWCS\Gateway\Stripe\Client;
use FKWCS\Gateway\Stripe\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'FKWCS_Autoship_Cloud_Compat' ) && class_exists( 'QPilotPaymentData' ) ) {

	/**
	 * Autoship Cloud (QPilot) compatibility.
	 *
	 * Autoship's FunnelKit handler resolves payment data by looking up a
	 * WC_Payment_Token via _fkwcs_source_id. FunnelKit Stripe does not
	 * create WC payment tokens for Stripe Link (intentional — Link tokens
	 * would surface as fake card entries in the customer's saved methods),
	 * so Autoship's lookup returns null and the QPilot scheduled order is
	 * created without a payment method.
	 *
	 * We hook the autoship_order_payment_data filter and, when Autoship has
	 * given up, build a QPilotPaymentData directly from the order meta that
	 * FunnelKit already stores (_fkwcs_source_id + _fkwcs_customer_id),
	 * detecting Link vs card by calling the Stripe API (result cached on
	 * the order so the call is one-shot per order).
	 */
	class FKWCS_Autoship_Cloud_Compat {

		const PM_TYPE_META = '_fkwcs_autoship_pm_type';

		const QPILOT_TYPE_CARD = 7;
		const QPILOT_TYPE_LINK = 32;

		/**
		 * Singleton instance.
		 *
		 * @var self|null
		 */
		protected static $instance = null;

		/**
		 * Get the singleton instance.
		 *
		 * @return self
		 */
		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Register the autoship_order_payment_data filter at priority 20
		 * (after Autoship Cloud's own handler).
		 */
		public function __construct() {
			add_filter( 'autoship_order_payment_data', array( $this, 'maybe_provide_payment_data' ), 20, 2 );
		}

		/**
		 * Fill in QPilotPaymentData when Autoship couldn't resolve it for a FunnelKit Stripe order.
		 *
		 * @param \QPilotPaymentData|null $payment_data Existing payment data from Autoship.
		 * @param int                     $order_id     WooCommerce order id.
		 *
		 * @return \QPilotPaymentData|null
		 */
		public function maybe_provide_payment_data( $payment_data, $order_id ) {
			if ( ! empty( $payment_data ) ) {
				return $payment_data;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return $payment_data;
			}

			if ( 'fkwcs_stripe' !== $order->get_payment_method() ) {
				return $payment_data;
			}

			$source_id   = Helper::get_meta( $order, '_fkwcs_source_id' );
			$customer_id = Helper::get_meta( $order, '_fkwcs_customer_id' );

			if ( empty( $source_id ) || empty( $customer_id ) ) {
				return $payment_data;
			}

			$pm_type = $this->get_payment_method_type( $order, $source_id );

			$qpilot_data                      = new \QPilotPaymentData();
			$qpilot_data->type                = 'Stripe';
			$qpilot_data->gateway_payment_id  = $source_id;
			$qpilot_data->gateway_customer_id = $customer_id;

			if ( 'link' === $pm_type ) {
				$qpilot_data->gateway_payment_type = self::QPILOT_TYPE_LINK;
				$qpilot_data->description          = 'Stripe Link';
			} else {
				$qpilot_data->gateway_payment_type = self::QPILOT_TYPE_CARD;
				$title                             = $order->get_payment_method_title();
				$qpilot_data->description          = ! empty( $title ) ? $title : 'Stripe';
			}

			return $qpilot_data;
		}

		/**
		 * Resolve the Stripe PaymentMethod type for the given source id.
		 * Cached on the order so subsequent invocations don't re-hit Stripe.
		 *
		 * @param \WC_Order $order     WC order.
		 * @param string    $source_id Stripe PaymentMethod id.
		 *
		 * @return string Stripe PM type ('card', 'link', etc). Empty string on failure.
		 */
		protected function get_payment_method_type( $order, $source_id ) {
			$cached = Helper::get_meta( $order, self::PM_TYPE_META );
			if ( ! empty( $cached ) && is_string( $cached ) ) {
				return $cached;
			}

			$client = $this->build_client_for_order( $order );
			if ( is_null( $client ) ) {
				return '';
			}

			$response = $client->payment_methods( 'retrieve', array( $source_id ) );
			if ( empty( $response['success'] ) || empty( $response['data'] ) || ! is_object( $response['data'] ) || empty( $response['data']->type ) ) {
				Helper::log( sprintf( 'Autoship Cloud compat: failed to resolve Stripe PM type for source %s — falling back to card', $source_id ) );

				return '';
			}

			$type = $response['data']->type;
			$order->update_meta_data( self::PM_TYPE_META, $type );
			$order->save_meta_data();

			return $type;
		}

		/**
		 * Build a Stripe Client for the order's payment mode without touching
		 * Helper's static singleton (which would leak our key into other code
		 * paths in the same request).
		 *
		 * @param \WC_Order $order WC order.
		 *
		 * @return \FKWCS\Gateway\Stripe\Client|null
		 */
		protected function build_client_for_order( $order ) {
			$order_mode = Helper::get_meta( $order, '_fkwcs_payment_mode' );
			$mode       = ! empty( $order_mode ) ? $order_mode : get_option( 'fkwcs_mode', 'test' );

			$secret_key = ( 'test' === $mode )
				? get_option( 'fkwcs_test_secret_key', '' )
				: get_option( 'fkwcs_secret_key', '' );

			if ( empty( $secret_key ) || ! class_exists( '\\FKWCS\\Gateway\\Stripe\\Client' ) ) {
				return null;
			}

			return new Client( apply_filters( 'fkwcs_api_client_secret', $secret_key ) );
		}
	}

	FKWCS_Autoship_Cloud_Compat::get_instance();
}
