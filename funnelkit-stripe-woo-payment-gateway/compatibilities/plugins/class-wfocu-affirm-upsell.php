<?php


if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Affirm' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_Affirm extends FKWCS_LocalGateway_Upsell {
		protected static $instance = null;
		public $key = 'fkwcs_stripe_affirm';
		protected $payment_method_type = 'affirm';
		protected $stripe_verify_js_callback = 'confirmAffirmPayment';

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		public function __construct() {
			parent::__construct();
		}


	}

	WFOCU_Plugin_Integration_Fkwcs_Affirm::get_instance();
}