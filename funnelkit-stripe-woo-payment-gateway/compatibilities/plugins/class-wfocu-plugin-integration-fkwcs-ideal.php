<?php


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FunnelKit (WFOCU) one-click upsell integration for the Stripe iDEAL gateway.
 *
 * iDEAL is a single-use, redirect-based EUR payment method (same class as Bancontact),
 * so the upsell is charged by creating a NEW PaymentIntent for the offer amount and
 * confirming it client-side with confirmIdealPayment(), which redirects the shopper to
 * their bank. All of that is handled generically by FKWCS_LocalGateway_Upsell; this
 * class only declares the gateway key, the Stripe payment method type and the JS
 * confirm callback.
 */
if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Ideal' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_Ideal extends FKWCS_LocalGateway_Upsell {
		protected static $instance           = null;
		public $key                          = 'fkwcs_stripe_ideal';
		protected $payment_method_type       = 'ideal';
		protected $stripe_verify_js_callback = 'confirmIdealPayment';

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * confirmIdealPayment() requires payment_method.ideal to be an object. Modern iDEAL collects the
		 * bank on Stripe's redirect page, so an empty object is enough.
		 *
		 * @param array    $payment_method
		 * @param WC_Order $order
		 *
		 * @return array
		 */
		protected function get_confirm_payment_method_data( $payment_method, $order ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			$payment_method['ideal'] = new \stdClass();

			return $payment_method;
		}
	}

	WFOCU_Plugin_Integration_Fkwcs_Ideal::get_instance();
}
