<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_mbway_localgateway_payment', array( $this, 'ajax_for_upsells_mbway' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_twint_localgateway_payment', array( $this, 'ajax_for_upsells_twint' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_blik_localgateway_payment', array( $this, 'ajax_for_upsells_blik' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_eps_localgateway_payment', array( $this, 'ajax_for_upsells_eps' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_ideal_localgateway_payment', array( $this, 'ajax_for_upsells_ideal' ) );
		add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_amazon_pay_payments', array( $this, 'ajax_for_upsells_amazon' ) );
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

		if ( empty( wc_clean( wp_unslash( $_POST['fkwcs_nonce'] ) ) ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_POST['fkwcs_nonce'] ) ), 'fkwcs_nonce' ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verification is being performed, frontend AJAX handler for checkout processing
			wp_send_json_error(
				array(
					'status'  => false,
					'message' => 'Something went wrong',
				)
			);
			return;
		}

		Helper::log( 'Entering::' . __FUNCTION__ );

		$gateway_id       = isset( $_POST['gateway_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_id'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above
		$payment_gateways = WC()->payment_gateways()->payment_gateways();

		if ( empty( $gateway_id ) || ! isset( $payment_gateways[ $gateway_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payment gateway.', 'funnelkit-stripe-woo-payment-gateway' ) ) );
			return;
		}

		$gateway = $payment_gateways[ $gateway_id ];
		if ( ! ( $gateway instanceof Abstract_Payment_Gateway ) || ! method_exists( $gateway, 'create_setup_intent' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payment gateway.', 'funnelkit-stripe-woo-payment-gateway' ) ) );
			return;
		}

		$source = isset( $_POST['fkwcs_source'] ) ? sanitize_text_field( wp_unslash( $_POST['fkwcs_source'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above
		if ( ! empty( $source ) ) {
			$source = htmlspecialchars( $source );
		}

		/**
		 * A subscription is present only on the My-Account change-payment-method
		 * flow. Passing it lets the SetupIntent request pick up India
		 * card.mandate_options (applied via maybe_add_emandate_data_to_request())
		 * so Stripe mints a valid recurring mandate for the new card (ticket 86bbb5gg8).
		 * The helper returns the subscription only when the current user owns it.
		 */
		$subscription = $this->get_change_pm_subscription();

		$response = $gateway->create_setup_intent( $source, '', $subscription );

		/**
		 * Persist the SetupIntent id on the subscription so the change-PM handler can
		 * retrieve the resulting mandate server-side after client-side confirmation.
		 */
		if ( $subscription instanceof \WC_Subscription && is_object( $response ) && ! empty( $response->id ) ) {
			// Store the canonical array shape used by every other writer/reader so the
			// array-expecting get_intent_from_order() reader never indexes a string.
			$subscription->update_meta_data(
				'_fkwcs_setup_intent',
				array(
					'id'            => $response->id,
					'client_secret' => isset( $response->client_secret ) ? $response->client_secret : '',
				)
			);
			$subscription->save_meta_data();
		}

		wp_send_json(
			array(
				'status' => 'success',
				'data'   => $response,
			)
		);
	}

	public function verify_intent_card() {

		WC()->payment_gateways()->payment_gateways()['fkwcs_stripe']->verify_intent();
	}

	public function verify_intent_gateway() {
		if ( ! isset( $_GET['gateway'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$gateway_id       = wc_clean( wp_unslash( $_GET['gateway'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$payment_gateways = WC()->payment_gateways()->payment_gateways();

		// Validate gateway exists and is a valid payment gateway instance
		if ( ! isset( $payment_gateways[ $gateway_id ] ) || ! $payment_gateways[ $gateway_id ] instanceof \WC_Payment_Gateway ) {
			return;
		}

		$payment_gateways[ $gateway_id ]->verify_intent();
	}

	public function verify_intent_sepa() {
		WC()->payment_gateways()->payment_gateways()['fkwcs_stripe_sepa']->verify_intent();
	}

	public function ajax_for_upsells() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe' )->process_client_payment();
	}

	public function ajax_for_upsells_amazon() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_amazon_pay' )->process_client_payment();
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

	public function ajax_for_upsells_mbway() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_mbway' )->process_client_payment();
	}

	public function ajax_for_upsells_blik() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_blik' )->process_client_payment();
	}

	public function ajax_for_upsells_eps() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_eps' )->process_client_payment();
	}

	public function ajax_for_upsells_ideal() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_ideal' )->process_client_payment();
	}

	public function ajax_for_upsells_cashapp() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_cashapp' )->process_client_payment();
	}

	public function ajax_for_upsells_multibanco() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_multibanco' )->process_client_payment();
	}

	public function ajax_for_upsells_twint() {
		WFOCU_Core()->gateways->get_integration( 'fkwcs_stripe_twint' )->process_client_payment();
	}

	/**
	 * Log Frontend Error when payment throw error after submit button pressed
	 *
	 * @return void
	 */
	public function log_frontend_error() {
		$_security = wc_clean( filter_input( INPUT_POST, '_security' ) );

		if ( is_null( $_security ) || ! wp_verify_nonce( $_security, 'fkwcs_js_nonce' ) ) {
			wp_send_json_error(
				array(
					'status'  => 'false',
					'message' => __( 'invalid nonce', 'funnelkit-stripe-woo-payment-gateway' ),
				)
			);
		}

		// Log only after nonce is verified to prevent log injection from unauthenticated requests.
		// Allowlist specific diagnostic fields, sanitize each, and strip CR/LF to prevent log forgery
		// and avoid dumping arbitrary checkout PII into the log file.
		$error      = isset( $_POST['error'] ) && is_array( $_POST['error'] ) ? wp_unslash( $_POST['error'] ) : array(); //phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above, frontend AJAX handler; individual fields sanitized below
		$log_fields = array(
			'order_id'       => isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : '', //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above, frontend AJAX handler for error logging
			'error_type'     => isset( $error['type'] ) && is_string( $error['type'] ) ? $error['type'] : '',
			'error_code'     => isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : '',
			'decline_code'   => isset( $error['decline_code'] ) && is_string( $error['decline_code'] ) ? $error['decline_code'] : '',
			'error_message'  => isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : '',
			'payment_intent' => isset( $error['payment_intent']['id'] ) && is_string( $error['payment_intent']['id'] ) ? wc_clean( $error['payment_intent']['id'] ) : '',
		);

		$log_parts = array();
		foreach ( $log_fields as $label => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			$value       = preg_replace( '/[\r\n]+/', ' ', sanitize_text_field( (string) $value ) );
			$log_parts[] = $label . ': ' . $value;
		}

		Helper::log( '====Frontend Js Error=== ' . implode( ' | ', $log_parts ) . ' ====End====', 'info' );

		if ( ! isset( $_POST['error'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above
			wp_send_json( array( 'status' => 'false' ) );
		}

		if ( isset( $_POST['order_id'] ) && ! empty( $_POST['order_id'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above, frontend AJAX handler for error logging
			$order_id  = absint( wp_unslash( $_POST['order_id'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above, frontend AJAX handler for error logging
			$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above, frontend AJAX handler for error logging

			if ( $this->verify_order_ownership_for_log_error( $order_id, $order_key ) ) {
				$order = wc_get_order( $order_id );
				if ( $order instanceof \WC_Order && false === $order->is_paid() && ! $order->has_status( 'wfocu-pri-order' ) ) {
					$error_message = '';
					if ( isset( $_POST['error']['payment_intent']['id'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above, frontend AJAX handler for error logging
						$error_message .= __( 'Intent ID', 'funnelkit-stripe-woo-payment-gateway' ) . ':' . wc_clean( wp_unslash( $_POST['error']['payment_intent']['id'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above, frontend AJAX handler for error logging
					}
					$localized_message = Helper::get_localized_error_message( wc_clean( wp_unslash( $_POST['error'] ) ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above, frontend AJAX handler for error logging

					$error_message .= "\n\n" . $localized_message;

					if ( ! empty( $error_message ) ) {
						WC()->payment_gateways()->payment_gateways()[ $order->get_payment_method() ]->mark_order_failed( $order, $error_message );
					}
				}
			}
		}

		wp_send_json( array( 'status' => 'true' ) );
	}

	public function fkwcs_create_payment_intent() {
		global $wp;
		check_ajax_referer( 'fkwcs_nonce', 'security' );

		$order_id   = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above with check_ajax_referer, frontend AJAX handler for checkout processing
		$order_key  = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above with check_ajax_referer, frontend AJAX handler for checkout processing
		$gateway_id = isset( $_POST['gateway_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_id'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above with check_ajax_referer, frontend AJAX handler for checkout processing
		$type       = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above with check_ajax_referer, frontend AJAX handler for checkout processing

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
			$order_pay_gateways = array( 'fkwcs_stripe_ideal', 'fkwcs_stripe_multibanco', 'fkwcs_stripe_pix', 'fkwcs_stripe_multibanco', 'fkwcs_stripe_eps', 'fkwcs_stripe_cashapp', 'fkwcs_stripe_blik' );
			if ( in_array( $gateway_id, $order_pay_gateways ) && $type === 'order_review' ) {
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
			$amount_data      = $gateway->add_amount_details( $order, $gateway->payment_method_types );
			if ( ! empty( $amount_data ) ) {
				$data = array_merge( $data, $amount_data );
			}

			$intent_data = $gateway->get_payment_intent( $order, $idempotency_key, $data );

			$return_url = $gateway->get_return_url( $order );
			$output     = array(
				'order'             => $order_id,
				'order_key'         => $order->get_order_key(),
				'fkwcs_redirect_to' => rawurlencode( $return_url ),
				'gateway'           => $gateway->id,
			);

			if ( isset( $_GET['wfacp_id'] ) && isset( $_GET['wfacp_is_checkout_override'] ) && 'no' === wc_clean( wp_unslash( $_GET['wfacp_is_checkout_override'] ) ) ) {
				$output['wfacp_id']                   = wc_clean( wp_unslash( $_GET['wfacp_id'] ) );
				$output['wfacp_is_checkout_override'] = wc_clean( wp_unslash( $_GET['wfacp_is_checkout_override'] ) );
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

	/**
	 * Verify that the current request owns the order via order_key.
	 * For logged-in users, also checks that the order belongs to their account.
	 *
	 * @param int    $order_id  Order ID.
	 * @param string $order_key Order key from POST.
	 * @return bool
	 */
	private function verify_order_ownership( $order_id, $order_key ) {
		if ( empty( $order_id ) || empty( $order_key ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		if ( ! $order->key_is_valid( $order_key ) ) {
			return false;
		}

		if ( is_user_logged_in() ) {
			$customer_id = $order->get_customer_id();
			if ( $customer_id > 0 && get_current_user_id() !== $customer_id ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Verify order ownership for log_frontend_error.
	 * Accepts checkout-session orders (matched via WC session) or order-pay orders (valid order_key).
	 *
	 * @param int    $order_id  Order ID.
	 * @param string $order_key Order key from POST (optional, for order-pay flow).
	 * @return bool
	 */
	private function verify_order_ownership_for_log_error( $order_id, $order_key ) {
		if ( empty( $order_id ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		if ( WC()->session ) {
			$session_order_id = absint( WC()->session->get( 'order_awaiting_payment', 0 ) );
			if ( 0 === $session_order_id ) {
				$session_order_id = absint( WC()->session->get( 'store_api_draft_order', 0 ) );
			}
			if ( $session_order_id === $order_id ) {
				return true;
			}
		}

		if ( ! empty( $order_key ) && $order->key_is_valid( $order_key ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Resolve and authorize the subscription targeted by a My-Account
	 * change-payment-method SetupIntent request.
	 *
	 * Returns the subscription only when it exists AND belongs to the current
	 * logged-in user, so the handler can never apply mandate options to - or write
	 * meta on - a subscription the requester does not own (ticket 86bbb5gg8).
	 *
	 * @return \WC_Subscription|false
	 */
	private function get_change_pm_subscription() {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return false;
		}

		// Nonce already verified in create_intent() before this helper is reached.
		$subscription_id = absint( filter_input( INPUT_POST, 'fkwcs_change_subscription_id', FILTER_SANITIZE_NUMBER_INT ) );
		if ( $subscription_id <= 0 ) {
			return false;
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription instanceof \WC_Subscription ) {
			return false;
		}

		// Ownership gate mirrors verify_order_ownership() - only the owning user may target this subscription.
		$user_id = get_current_user_id();
		if ( 0 === $user_id || (int) $subscription->get_user_id() !== $user_id ) {
			return false;
		}

		return $subscription;
	}
}

AJAX::get_instance();
