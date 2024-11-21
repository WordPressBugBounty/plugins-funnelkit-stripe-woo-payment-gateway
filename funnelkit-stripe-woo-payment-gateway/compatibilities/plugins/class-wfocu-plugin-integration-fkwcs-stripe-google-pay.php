<?php

use FKWCS\Gateway\Stripe\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Google_Pay' ) && class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Stripe' ) ) {

	class WFOCU_Plugin_Integration_Fkwcs_Google_Pay extends WFOCU_Plugin_Integration_Fkwcs_Stripe {
		protected static $instance = null;
		public $key = 'fkwcs_stripe_google_pay';
		public $supports = [];

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}
	}

	WFOCU_Plugin_Integration_Fkwcs_Google_Pay::get_instance();
}