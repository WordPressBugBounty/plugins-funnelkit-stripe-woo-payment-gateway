<?php


if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_P24' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_P24 extends FKWCS_LocalGateway_Upsell {
		protected static $instance = null;
		public $key = 'fkwcs_stripe_p24';
		protected $payment_method_type = 'p24';
		protected $stripe_verify_js_callback = 'confirmP24Payment';
		public $current_order_id = null;

		public function __construct() {
			parent::__construct();
			
			// Add action hook to handle P24 requires_action status
			add_action( 'fkwcs_p24_requires_action', array( $this, 'maybe_setup_upsell_on_p24_requires_action' ), 99, 3 );
		}

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Handle P24 requires_action status and setup upsell
		 * Similar to SEPA but adapted for P24's LocalGateway structure
		 *
		 * @param object $intent The payment intent object
		 * @param int $order_id The order ID
		 * @param WC_Order $order The order object
		 */
		public function maybe_setup_upsell_on_p24_requires_action( $intent, $order_id, $order ) {
			if ( $this->is_enabled() ) {
				// Set the current order ID for upsell processing
				$this->current_order_id = $order_id;
				
				// Setup upsell funnel
				WFOCU_Core()->public->maybe_setup_upsell( $this->current_order_id );
				
				// Set funnel running status
				WFOCU_Core()->orders->maybe_set_funnel_running_status( $order );
				
				// Log the action for debugging
				WFOCU_Core()->log->log( 'P24 requires_action - Upsell setup initiated for order #' . $order_id );
			}
		}

	}

	WFOCU_Plugin_Integration_Fkwcs_P24::get_instance();
}