<?php


if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Alipay' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_Alipay extends FKWCS_LocalGateway_Upsell {
		protected static $instance = null;
		public $key = 'fkwcs_stripe_alipay';
		protected $payment_method_type = 'alipay';
		protected $stripe_verify_js_callback = 'confirmAlipayPayment';

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

	}

	WFOCU_Plugin_Integration_Fkwcs_Alipay::get_instance();
}