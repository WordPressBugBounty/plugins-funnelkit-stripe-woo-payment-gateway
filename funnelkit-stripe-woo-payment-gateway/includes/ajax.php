<?php

namespace FKWCS\Gateway\Stripe;


class AJAX {

	protected static $instance = null;

	private function __construct() {
		add_action( 'wp_ajax_fkwcs_create_setup_intent', [ $this, 'create_intent' ] );
		add_action( 'wc_ajax_fkwcs_stripe_sepa_verify_payment_intent', [ $this, 'verify_intent_sepa' ] );

		add_action( 'wc_ajax_fkwcs_stripe_verify_payment_intent', [ $this, 'verify_intent_gateway' ] );
		add_action( 'wc_ajax_wc_stripe_verify_intent_checkout', [ $this, 'verify_intent_card' ], - 1 );
		add_action( 'wc_ajax_fkwcs_stripe_bancontact_verify_payment_intent', [ $this, 'verify_intent_bancontact' ] );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_payments', [ $this, 'ajax_for_upsells' ] );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_sepa_payments', [ $this, 'ajax_for_upsells_sepa' ] );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_affirm_localgateway_payment', [ $this, 'ajax_for_upsells_affirm' ] );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_afterpay_localgateway_payment', [ $this, 'ajax_for_upsells_afterpay' ] );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_bancontact_localgateway_payment', [ $this, 'ajax_for_upsells_bancontact' ] );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_klarna_localgateway_payment', [ $this, 'ajax_for_upsells_klarna' ] );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_p24_localgateway_payment', [ $this, 'ajax_for_upsells_p24' ] );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_alipay_localgateway_payment', [ $this, 'ajax_for_upsells_alipay' ] );
		add_action( 'wp_ajax_fkwcs_js_errors', [ $this, 'log_frontend_error' ] );
		add_action( 'wp_ajax_nopriv_fkwcs_js_errors', [ $this, 'log_frontend_error' ] );

	}


	/**
	 * @return Ajax
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}


	public function create_intent() {

		if ( empty( wc_clean( $_POST['fkwcs_nonce'] ) ) || ! wp_verify_nonce( wc_clean( $_POST['fkwcs_nonce'] ), 'fkwcs_nonce' ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_send_json( [ 'status' => false, 'message' => 'Something went wrong' ] );
		}
		Helper::log( 'Entering::' . __FUNCTION__ );

		if ( ! empty( $_POST['fkwcs_source'] ) ) {
			$source = htmlspecialchars( sanitize_text_field( $_POST['fkwcs_source'] ) );
		}
		if ( ! empty( $_POST['gateway_id'] ) ) {
			$gateway_id = sanitize_text_field( $_POST['gateway_id'] );
		}
		$response = WC()->payment_gateways()->payment_gateways()[ $gateway_id ]->create_setup_intent( $source );

		$resp = [ 'status' => 'success', 'data' => $response ];

		wp_send_json( $resp );
	}

	public function verify_intent_card() {

		WC()->payment_gateways()->payment_gateways()['fkwcs_stripe']->verify_intent();
	}

	public function verify_intent_gateway() {

		if ( isset( $_GET['gateway'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			WC()->payment_gateways()->payment_gateways()[ wc_clean( $_GET['gateway'] ) ]->verify_intent(); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	public function verify_intent_sepa() {
		WC()->payment_gateways()->payment_gateways()['fkwcs_stripe_sepa']->verify_intent();
	}


	public function ajax_for_upsells() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe' )->process_client_payment();
	}


	public function ajax_for_upsells_sepa() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_sepa' )->process_client_payment();
	}

	public function ajax_for_upsells_affirm() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_affirm' )->process_client_payment();
	}

	public function ajax_for_upsells_afterpay() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_afterpay' )->process_client_payment();
	}

	public function ajax_for_upsells_bancontact() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_bancontact' )->process_client_payment();
	}

	public function ajax_for_upsells_klarna() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_klarna' )->process_client_payment();
	}

	public function ajax_for_upsells_p24() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_p24' )->process_client_payment();
	}

	public function ajax_for_upsells_alipay() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_alipay' )->process_client_payment();
	}

	/**
	 * Log Frontend Error when payment throw error after submit button pressed
	 * @return void
	 */
	public function log_frontend_error() {
		$_security = wc_clean( filter_input( INPUT_POST, '_security' ) );
		$str       = "====Frontend Js Error Log start=== \n\n " . print_r( $_POST, true ) . " \n\n ====End==="; //phpcs:ignore

		Helper::log( $str, 'info' );
		if ( is_null( $_security ) || ! wp_verify_nonce( $_security, 'fkwcs_js_nonce' ) ) {
			wp_send_json_error( [ 'status' => 'false', 'message' => __( 'invalid nonce', 'funnelkit-stripe-woo-payment-gateway' ) ] );
		}
		if ( ! isset( $_POST['error'] ) ) {
			wp_send_json( [ 'status' => 'false' ] );
		}


		if ( isset( $_POST['order_id'] ) && ! empty( $_POST['order_id'] ) ) {
			$order_id = wc_clean( $_POST['order_id'] );
			$order    = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order && false === $order->is_paid() && ! $order->has_status( 'wfocu-pri-order' ) ) {
				$error_message = '';
				if ( isset( $_POST['error']['payment_intent']['id'] ) ) {
					$error_message .= __( 'Intent ID', 'funnelkit-stripe-woo-payment-gateway' ) . ":" . wc_clean( $_POST['error']['payment_intent']['id'] );
				}
				$localized_message = Helper::get_localized_error_message( wc_clean( $_POST['error'] ) );


				$error_message .= "\n\n" . $localized_message;


				if ( ! empty( $error_message ) ) {

					if ( $order->has_status( 'failed' ) ) {
						$order->add_order_note( $error_message );
					} else {
						$order->update_status( 'failed', $error_message );
					}
				}
			}
		}

		wp_send_json( [ 'status' => 'true' ] );
	}


}

AJAX::get_instance();