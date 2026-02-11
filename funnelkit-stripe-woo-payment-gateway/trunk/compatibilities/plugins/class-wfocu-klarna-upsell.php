<?php



if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Klarna' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_Klarna extends FKWCS_LocalGateway_Upsell {
		protected static $instance = null;
		public $key = 'fkwcs_stripe_klarna';
		protected $payment_method_type = 'klarna';
		protected $stripe_verify_js_callback = 'confirmKlarnaPayment';

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

	}

	WFOCU_Plugin_Integration_Fkwcs_Klarna::get_instance();
}