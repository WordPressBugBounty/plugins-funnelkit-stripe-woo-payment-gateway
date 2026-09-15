<?php

use FKWCS\Gateway\Stripe\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Amazon_Pay' ) && class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Stripe' ) ) {

	/**
	 * FunnelKit One-Click Upsell support for Amazon Pay.
	 *
	 * The initial Amazon payment saves an off-session reusable mandate (setup_future_usage) and
	 * stores its payment method id as _fkwcs_source_id, so upsell charges reuse the base Stripe
	 * off-session charge logic exactly like Apple Pay / Google Pay — only the gateway key differs.
	 */
	class WFOCU_Plugin_Integration_Fkwcs_Amazon_Pay extends WFOCU_Plugin_Integration_Fkwcs_Stripe {
		protected static $instance = null;
		public $key                = 'fkwcs_stripe_amazon_pay';
		public $supports           = array();

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Amazon Pay charges the upsell through its own WC-AJAX action so it routes to this
		 * integration (amazon_pay token) instead of the shared card handler.
		 *
		 * @return string
		 */
		public function get_upsell_charge_action() {
			return 'wfocu_front_handle_fkwcs_stripe_amazon_pay_payments';
		}

		/**
		 * Charge the saved Amazon Pay mandate off-session (merchant-initiated). Without off_session
		 * the intent comes back requires_action (Amazon redirect), which the upsell flow can't run —
		 * the client would try to confirm an amazon_pay intent as a card and Stripe rejects it.
		 *
		 * off_session and setup_future_usage are mutually exclusive on a PaymentIntent, so we drop
		 * setup_future_usage (the saved Amazon mandate from the original order is already reusable).
		 *
		 * @param array $request The PaymentIntent create request.
		 *
		 * @return array
		 */
		protected function prepare_intent_request( $request ) {
			unset( $request['setup_future_usage'] );
			$request['off_session'] = true;

			return $request;
		}
	}

	WFOCU_Plugin_Integration_Fkwcs_Amazon_Pay::get_instance();
}
