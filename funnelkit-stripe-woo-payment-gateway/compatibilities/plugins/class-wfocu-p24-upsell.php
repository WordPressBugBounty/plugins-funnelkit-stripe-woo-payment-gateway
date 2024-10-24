<?php


if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_P24' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_P24 extends FKWCS_LocalGateway_Upsell {
		protected static $instance = null;
		public $key = 'fkwcs_stripe_p24';
		protected $payment_method_type = 'p24';
		protected $stripe_verify_js_callback = 'confirmP24Payment';

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

	}

	WFOCU_Plugin_Integration_Fkwcs_P24::get_instance();
}