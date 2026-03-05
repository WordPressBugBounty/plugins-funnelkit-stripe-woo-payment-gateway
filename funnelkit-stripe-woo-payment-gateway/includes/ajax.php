<?php

namespace FKWCS\Gateway\Stripe;

class AJAX {

	protected static $instance = null;

	private function __construct() {
		add_action( 'wp_ajax_fkwcs_create_setup_intent', array( $this, 'create_intent' ) );
		add_action( 'wc_ajax_fkwcs_stripe_sepa_verify_payment_intent', array( $this, 'verify_intent_sepa' ) );

		add_action( 'wc_ajax_fkwcs_stripe_verify_payment_intent', array( $this, 'verify_intent_gateway' ) );
		add_action( 'wc_ajax_wc_stripe_verify_intent_checkout', array( $this, 'verify_intent_card' ), - 1 );
		add_action( 'wc_ajax_fkwcs_stripe_bancontact_verify_payment_intent', array( $this, 'verify_intent_bancontact' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_payments', array( $this, 'ajax_for_upsells' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_sepa_payments', array( $this, 'ajax_for_upsells_sepa' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_multibanco_payments', array( $this, 'ajax_for_upsells_mulibanco' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_affirm_localgateway_payment', array( $this, 'ajax_for_upsells_affirm' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_afterpay_localgateway_payment', array( $this, 'ajax_for_upsells_afterpay' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_bancontact_localgateway_payment', array( $this, 'ajax_for_upsells_bancontact' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_klarna_localgateway_payment', array( $this, 'ajax_for_upsells_klarna' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_p24_localgateway_payment', array( $this, 'ajax_for_upsells_p24' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_alipay_localgateway_payment', array( $this, 'ajax_for_upsells_alipay' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_cashapp_payments', array( $this, 'ajax_for_upsells_cashapp' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_multibanco_localgateway_payment', array( $this, 'ajax_for_upsells_multibanco' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_pix_localgateway_payment', array( $this, 'ajax_for_upsells_pix' ) );
		add_action( 'wp_ajax_fkwcs_js_errors', array( $this, 'log_frontend_error' ) );
		add_action( 'wp_ajax_nopriv_fkwcs_js_errors', array( $this, 'log_frontend_error' ) );
		add_action( 'wp_ajax_fkwcs_create_payment_intent', array( $this, 'fkwcs_create_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_fkwcs_create_payment_intent', array( $this, 'fkwcs_create_payment_intent' ) );
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
			wp_send_json(
				array(
					'status'  => false,
					'message' => 'Something went wrong',
				)
			);
		}
		Helper::log( 'Entering::' . __FUNCTION__ );
		$source = $_POST['fkwcs_source'];
		if ( ! empty( $source ) ) {
			$source = htmlspecialchars( sanitize_text_field( $source ) );
		}
		if ( ! empty( $_POST['gateway_id'] ) ) {
			$gateway_id = sanitize_text_field( $_POST['gateway_id'] );
		}
		$response = WC()->payment_gateways()->payment_gateways()[ $gateway_id ]->create_setup_intent( $source );

		$resp = array(
			'status' => 'success',
			'data'   => $response,
		);

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

	public function ajax_for_upsells_mulibanco() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_multibanco' )->process_client_payment();
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

	public function ajax_for_upsells_pix() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_pix' )->process_client_payment();
	}

	public function ajax_for_upsells_cashapp() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_cashapp' )->process_client_payment();
	}

	public function ajax_for_upsells_multibanco() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_multibanco' )->process_client_payment();
	}

	/**
	 * Log Frontend Error when payment throw error after submit button pressed
	 *
	 * @return void
	 */
	public function log_frontend_error() {
		$_security = wc_clean( filter_input( INPUT_POST, '_security' ) );
		$str       = "====Frontend Js Error Log start=== \n\n " . print_r( $_POST, true ) . " \n\n ====End==="; //phpcs:ignore

		Helper::log( $str, 'info' );
		if ( is_null( $_security ) || ! wp_verify_nonce( $_security, 'fkwcs_js_nonce' ) ) {
			wp_send_json_error(
				array(
					'status'  => 'false',
					'message' => __( 'invalid nonce', 'funnelkit-stripe-woo-payment-gateway' ),
				)
			);
		}
		if ( ! isset( $_POST['error'] ) ) {
			wp_send_json( array( 'status' => 'false' ) );
		}

		if ( isset( $_POST['order_id'] ) && ! empty( $_POST['order_id'] ) ) {
			$order_id  = absint( $_POST['order_id'] );
			$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';

			if ( $this->verify_order_ownership_for_log_error( $order_id, $order_key ) ) {
				$order = wc_get_order( $order_id );
				if ( $order instanceof \WC_Order && false === $order->is_paid() && ! $order->has_status( 'wfocu-pri-order' ) ) {
					$error_message = '';
					if ( isset( $_POST['error']['payment_intent']['id'] ) ) {
						$error_message .= __( 'Intent ID', 'funnelkit-stripe-woo-payment-gateway' ) . ':' . wc_clean( $_POST['error']['payment_intent']['id'] );
					}
					$localized_message = Helper::get_localized_error_message( wc_clean( $_POST['error'] ) );

					$error_message .= "\n\n" . $localized_message;

					if ( ! empty( $error_message ) ) {

						if ( method_exists( WC()->payment_gateways()->payment_gateways()[ $order->get_payment_method() ], 'mark_order_failed' ) ) {
							WC()->payment_gateways()->payment_gateways()[ $order->get_payment_method() ]->mark_order_failed( $order, $error_message );
						}
					}
				}
			}
		}

		wp_send_json( array( 'status' => 'true' ) );
	}

	/**
	 * Verify order ownership for log_frontend_error (checkout + order-pay).
	 * Uses session order_awaiting_payment for checkout (no order_key needed) or
	 * order_key for order-pay page. Prevents IDOR.
	 *
	 * @param int    $order_id  Order ID.
	 * @param string $order_key Order key (optional, from URL for order-pay).
	 * @return bool True if ownership is verified.
	 */
	private function verify_order_ownership_for_log_error( $order_id, $order_key ) {
		if ( empty( $order_id ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		// Checkout flow: order must match session (order_awaiting_payment or store_api_draft_order for Blocks).
		if ( WC()->session ) {
			$session_order_id = absint( WC()->session->get( 'order_awaiting_payment', 0 ) );
			if ( 0 === $session_order_id ) {
				$session_order_id = absint( WC()->session->get( 'store_api_draft_order', 0 ) );
			}
			if ( $session_order_id === $order_id ) {
				return true;
			}
		}

		// Order-pay flow: order_key must be valid (user arrived from email link, no session order).
		if ( ! empty( $order_key ) && $order->key_is_valid( $order_key ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Verify that the current request owns the order via order_key.
	 * Prevents IDOR by ensuring only the order owner can perform actions on it.
	 *
	 * @param int    $order_id  Order ID.
	 * @param string $order_key Order key (from URL or session).
	 * @return bool True if ownership is verified.
	 */
	private function verify_order_ownership( $order_id, $order_key ) {
		if ( empty( $order_id ) || empty( $order_key ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		return $order->key_is_valid( $order_key );
	}

	public function fkwcs_create_payment_intent() {
		global $wp;
		check_ajax_referer( 'fkwcs_nonce', 'security' );

		$order_id   = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key  = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$gateway_id = isset( $_POST['gateway_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_id'] ) ) : '';

		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				throw new \Exception( __( 'Invalid order ID.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			if ( ! $this->verify_order_ownership( $order_id, $order_key ) ) {
				throw new \Exception( __( 'Invalid order. Please refresh the page and try again.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			$payment_gateways = WC()->payment_gateways()->payment_gateways();
			if ( ! isset( $payment_gateways[ $gateway_id ] ) ) {
				throw new \Exception( __( 'Invalid payment gateway.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			$gateway            = $payment_gateways[ $gateway_id ];
			$order_pay_gateways = array( 'fkwcs_stripe_ideal', 'fkwcs_stripe_multibanco', 'fkwcs_stripe_pix', 'fkwcs_stripe_multibanco', 'fkwcs_stripe_eps', 'fkwcs_stripe_cashapp' );
			if ( in_array( $gateway_id, $order_pay_gateways ) ) {
				$wp->set_query_var( 'order-pay', $order_id );
				$order->set_payment_method( $gateway );
				$order->update_meta_data( '_is_order_pay_request', 'yes' );
				$order->save();
			}

			$gateway->validate_minimum_order_amount( $order );
			$customer_id     = $gateway->get_customer_id( $order );
			$idempotency_key = $order->get_order_key() . time();

			$data = array(
				'amount'               => Helper::get_formatted_amount( $order->get_total() ),
				'currency'             => $gateway->get_currency(),
				'description'          => $gateway->get_order_description( $order ),
				'metadata'             => $gateway->get_metadata( $order_id ),
				'payment_method_types' => array( $gateway->payment_method_types ),
				'customer'             => $customer_id,
				'capture_method'       => isset( $gateway->capture_method ) ? $gateway->capture_method : 'automatic',
			);

			if ( $gateway_id === 'fkwcs_stripe_cashapp' ) {
				$data['confirmation_method'] = 'automatic';
				$data['confirm']             = false;

				Helper::log( "Cash App Pay: Setting up for upsells - order_id: {$order_id}, customer_id: {$customer_id}" );
			}

			if ( in_array( $gateway_id, array( 'fkwcs_stripe_ach', 'fkwcs_stripe_cashapp' ) ) ) {
				$data['setup_future_usage'] = 'off_session';
			}

			$data['metadata'] = $gateway->add_metadata( $order );

			$intent_data = $gateway->get_payment_intent( $order, $idempotency_key, $data );

			$return_url = $gateway->get_return_url( $order );
			$output     = array(
				'order'             => $order_id,
				'order_key'         => $order->get_order_key(),
				'fkwcs_redirect_to' => rawurlencode( $return_url ),
				'gateway'           => $gateway->id,
			);

			if ( isset( $_GET['wfacp_id'] ) && isset( $_GET['wfacp_is_checkout_override'] ) && 'no' === $_GET['wfacp_is_checkout_override'] ) {
				$output['wfacp_id']                   = wc_clean( $_GET['wfacp_id'] );
				$output['wfacp_is_checkout_override'] = wc_clean( $_GET['wfacp_is_checkout_override'] );
			}

			// Put the final thank you page redirect into the verification URL.
			$verification_url = add_query_arg( $output, \WC_AJAX::get_endpoint( 'fkwcs_stripe_verify_payment_intent' ) );

			wp_send_json_success(
				array(
					'payment_id'    => $intent_data->id,
					'client_secret' => $intent_data->client_secret,
					'redirect_url'  => $verification_url,
				)
			);

		} catch ( \Exception | \Error $e ) {
			Helper::log( 'Cash App Pay: Payment intent creation failed - ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
}

AJAX::get_instance();
