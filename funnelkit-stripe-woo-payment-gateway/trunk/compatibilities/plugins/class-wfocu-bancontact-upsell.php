<?php


if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Bancontact' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_Bancontact extends FKWCS_LocalGateway_Upsell {
		protected static $instance = null;
		public $key = 'fkwcs_stripe_bancontact';
		protected $payment_method_type = 'bancontact';
		protected $stripe_verify_js_callback = 'confirmBancontactPayment';

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

	}

	WFOCU_Plugin_Integration_Fkwcs_Bancontact::get_instance();
}