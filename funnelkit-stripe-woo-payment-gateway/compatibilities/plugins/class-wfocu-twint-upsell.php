<?php
/**
 * FunnelKit Stripe — TWINT one-click upsell (PaymentIntent + confirmTwintPayment + redirect).
 *
 * @package funnelkit-stripe-woo-payment-gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Twint' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_Twint extends FKWCS_LocalGateway_Upsell {
		protected static $instance           = null;
		public $key                          = 'fkwcs_stripe_twint';
		protected $payment_method_type       = 'twint';
		protected $stripe_verify_js_callback = 'confirmTwintPayment';

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}
	}

	WFOCU_Plugin_Integration_Fkwcs_Twint::get_instance();
}
