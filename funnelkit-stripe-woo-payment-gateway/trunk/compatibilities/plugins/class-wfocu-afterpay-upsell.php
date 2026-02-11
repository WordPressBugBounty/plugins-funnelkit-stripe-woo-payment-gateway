<?php


if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Afterpay' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_Afterpay extends FKWCS_LocalGateway_Upsell {
		protected static $instance = null;
		public $key = 'fkwcs_stripe_afterpay';
		protected $payment_method_type = 'afterpay_clearpay';
		protected $stripe_verify_js_callback = 'confirmAfterpayClearpayPayment';
		protected $need_shipping_address = true;

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

	}

	WFOCU_Plugin_Integration_Fkwcs_Afterpay::get_instance();
}