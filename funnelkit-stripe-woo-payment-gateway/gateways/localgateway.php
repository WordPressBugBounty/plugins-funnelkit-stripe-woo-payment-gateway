<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

#[\AllowDynamicProperties]
abstract class LocalGateway extends Abstract_Payment_Gateway {
	protected $supported_currency          = array( 'USD' );
	protected $specific_country            = array();
	protected $selling_country_type        = 'specific';
	protected $except_country              = array();
	protected $setting_enable_label        = '';
	protected $setting_title_default       = '';
	protected $setting_description_default = '';
	protected $paylater_message_service    = false;
	protected $icon_url                    = '';
	protected $shipping_address_required   = false;
	public $paylater_message_position      = 'description';
	public $has_fields                     = true;
	private static $fragment_sent          = false;
	public $capture_method                 = 'automatic';

	public function __construct() {
		$this->override_defaults();
		parent::__construct();
		$this->init_supports();
	}

	protected function override_defaults() {
		$this->setting_enable_label        = __( 'Enable ', 'funnelkit-stripe-woo-payment-gateway' ) . $this->method_title;
		$this->setting_title_default       = $this->method_title;
		$this->setting_description_default = '';
	}

	/**
	 * Checks if payment method available
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->is_available_local_gateway();
	}

	/**
	 * Add hooks
	 *
	 * @return void
	 */
	protected function filter_hooks() {

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_stripe_js' ) );
		add_filter( 'woocommerce_payment_successful_result', array( $this, 'modify_successful_payment_result' ), 999, 2 );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'merge_cart_details' ), 1000 );
		add_filter( 'fkwcs_localized_data', array( $this, 'localize_element_data' ), 999 );
	}

	/**
	 * Registers supported filters for payment gateway
	 *
	 * @return void
	 */
	public function init_supports() {
		$this->supports = apply_filters(
			'fkwcs_affirm_payment_supports',
			array_merge(
				$this->supports,
				array(
					'products',
					'refunds',
				)
			)
		);
	}

	/**
	 * Initialise gateway settings form fields
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$settings = array(
			'enabled'     => array(
				'label'   => ' ',
				'type'    => 'checkbox',
				'title'   => $this->setting_enable_label,
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Change the payment gateway title that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => $this->setting_title_default,
				'id'          => $this->setting_title_default,
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'textarea',
				'css'         => 'width:25em',
				'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
		);

		$this->form_fields = apply_filters( $this->id . '_payment_form_fields', array_merge( $settings, $this->get_countries_admin_fields( $this->selling_country_type, $this->except_country, $this->specific_country ) ) );
	}

	/**
	 * Returns all supported currencies for this payment method
	 *
	 * @return mixed|null
	 */
	public function get_supported_currency() {
		return apply_filters( $this->id . '_supported_currencies', $this->supported_currency );
	}

	/**
	 * Get payment gateway icons
	 *
	 * @return mixed|string|null
	 */
	public function get_icon() {
		$icons      = $this->payment_icons();
		$icons_str  = '';
		$icons_str .= ! empty( $icons[ $this->payment_method_types ] ) ? $icons[ $this->payment_method_types ] : '';
		return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
	}

	/**
	 * Process the payment
	 *
	 * @param int $order_id Reference.
	 *
	 * @return array|void
	 * @throws \Exception If payment will not be accepted.
	 */
	public function process_payment( $order_id ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedParameter,VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		try {
			$order = wc_get_order( $order_id );

			/** This will throw exception if not valid */
			$this->validate_minimum_order_amount( $order );
			$customer_id      = $this->get_customer_id( $order );
			$idempotency_key  = $order->get_order_key() . time();
			$data             = array(
				'amount'               => Helper::get_formatted_amount( $order->get_total() ),
				'currency'             => $this->get_currency(),
				'description'          => $this->get_order_description( $order ),
				'metadata'             => $this->get_metadata( $order_id ),
				'payment_method_types' => array( $this->payment_method_types ),
				'customer'             => $customer_id,
				'capture_method'       => $this->capture_method,
			);
			$data['metadata'] = $this->add_metadata( $order );
			$amount_data      = $this->add_amount_details( $order, $data['payment_method_types'][0] ?? 'card' );
			if ( ! empty( $amount_data ) ) {
				$data = array_merge( $data, $amount_data );
			}
			$data = $this->set_shipping_data( $data, $order, $this->shipping_address_required );

			$intent_data = $this->get_payment_intent( $order, $idempotency_key, $data );

			/* translators: 1: Payment method title, 2: Order ID, 3: Order total */
			Helper::log( sprintf( __( 'Begin processing payment with  %1$1s for order %2$2s for the amount of %3$3s', 'funnelkit-stripe-woo-payment-gateway' ), $this->get_title(), $order_id, $order->get_total() ) );
			if ( $intent_data ) {
				/**
				 * @see modify_successful_payment_result()
				 * This modifies the final response return in WooCommerce process checkout request
				 */
				$return_url = $this->get_return_url( $order );

				return array(
					'result'              => 'success',
					'fkwcs_redirect'      => $return_url,
					'fkwcs_intent_secret' => $intent_data->client_secret,
				);
			} else {
				return array(
					'result'   => 'fail',
					'redirect' => '',
				);
			}
		} catch ( \Exception $e ) {
			Helper::log( $e->getMessage(), 'warning' );
			wc_add_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * Save Meta Data Like Balance Charge ID & status
	 * Add respective  order notes according to stripe charge status
	 *
	 * @param $response
	 * @param $order_id Int Order ID
	 *
	 * @return string
	 */
	public function process_final_order( $response, $order_id ) {
		$order = wc_get_order( $order_id );

		if ( isset( $response->balance_transaction ) ) {
			Helper::update_balance( $order, $response->balance_transaction );
		}
		$this->save_payment_method_details( $order, $response );
		if ( true === $response->captured ) {
			$order->payment_complete( $response->id );
			/* translators: order id */
			Helper::log( sprintf( 'Payment successful Order id - %1s', $order->get_id() ) );

			$order->add_order_note( __( 'Payment Status: ', 'funnelkit-stripe-woo-payment-gateway' ) . ucfirst( $response->status ) . ', ' . __( 'Source: Payment is Completed via ', 'funnelkit-stripe-woo-payment-gateway' ) . $response->payment_method_details->type );
			/* translators: %s: Charge ID */
			$order->add_order_note( sprintf( __( 'Charge ID %s', 'funnelkit-stripe-woo-payment-gateway' ), $response->id ) );
		} else {
			$order->set_transaction_id( $response->id );
			$order->save();

			/* translators: transaction id */
			$order->update_status( 'on-hold', sprintf( __( 'Charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization. Attempting to refund the order in part or in full will release the authorization and cancel the payment.', 'funnelkit-stripe-woo-payment-gateway' ), $response->id ) );
			/* translators: transaction id */
			Helper::log( sprintf( 'Charge authorized Order id - %1s', $order->get_id() ) );
		}
		if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
			WC()->cart->empty_cart();
		}
		$return_url = $this->get_return_url( $order );
		Helper::log( "Return URL: $return_url" );

		return $return_url;
	}

	public function save_payment_method_details( $order, $payment_intent ) {
		// override if you need
		return;
	}

	/**
	 * Print the gateway field
	 *
	 * @return void
	 */
	public function payment_fields() {
		do_action( $this->id . '_before_payment_field_checkout' );
		include __DIR__ . '/parts/local.php';
		do_action( $this->id . '_after_payment_field_checkout' );
	}

	public function modify_successful_payment_result( $result, $order_id ) {
		if ( empty( $order_id ) ) {
			return $result;
		}

		$order = wc_get_order( $order_id );
		if ( $this->id !== $order->get_payment_method() ) {
			return $result;
		}
		if ( ! isset( $result['fkwcs_intent_secret'] ) && ! isset( $result['fkwcs_setup_intent_secret'] ) ) {
			return $result;
		}

		$output = array(
			'order'             => $order_id,
			'order_key'         => $order->get_order_key(),
			'fkwcs_redirect_to' => rawurlencode( $result['fkwcs_redirect'] ),
			'gateway'           => $this->id,
		);
		if ( isset( $_GET['wfacp_id'] ) && isset( $_GET['wfacp_is_checkout_override'] ) && 'no' === $_GET['wfacp_is_checkout_override'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$output['wfacp_id']                   = wc_clean( wp_unslash( $_GET['wfacp_id'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$output['wfacp_is_checkout_override'] = wc_clean( wp_unslash( $_GET['wfacp_is_checkout_override'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$is_token_used = isset( $result['token_used'] ) ? 'yes' : 'no';
		// Put the final thank you page redirect into the verification URL.
		$verification_url = add_query_arg( $output, \WC_AJAX::get_endpoint( 'fkwcs_stripe_verify_payment_intent' ) );
		if ( isset( $result['fkwcs_setup_intent_secret'] ) ) {
			$redirect = sprintf( '#fkwcs-confirm-si-%s:%s:%d:%s:%s', $result['fkwcs_setup_intent_secret'], rawurlencode( $verification_url ), $order->get_id(), $this->id, $is_token_used );
		} else {
			$redirect = sprintf( '#fkwcs-confirm-pi-%s:%s:%d:%s:%s', $result['fkwcs_intent_secret'], rawurlencode( $verification_url ), $order->get_id(), $this->id, $is_token_used );
		}

		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}

	/**
	 * Verify intent secret and redirect to the thankyou page
	 *
	 * @return void
	 */
	public function verify_intent() {
		global $woocommerce;

		$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
		try {
			$order_id = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order    = wc_get_order( $order_id );

			if ( ! isset( $_GET['order_key'] ) || ! $order instanceof \WC_Order || ! $order->key_is_valid( wc_clean( wp_unslash( $_GET['order_key'] ) ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				throw new \Exception( __( 'Invalid Order Key.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}
		} catch ( \Exception $e ) {
			/* translators: Error message text */
			$message = sprintf( __( 'Payment verification error: %s', 'funnelkit-stripe-woo-payment-gateway' ), $e->getMessage() );
			wc_add_notice( esc_html( $message ), 'error' );
			$this->handle_error( $e, $redirect_url );
		}

		try {
			$intent = $this->get_intent_from_order( $order );
			if ( false === $intent ) {
				throw new \Exception( 'Intent Not Found' );
			}

			if ( ! $order->has_status( apply_filters( 'fkwcs_stripe_allowed_payment_processing_statuses', array( 'pending', 'failed' ), $order ) ) ) {
				/**
				 * bail out if the status is not pending or failed
				 */
				$redirect_url = $this->get_return_url( $order );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			if ( 'setup_intent' === $intent->object && 'succeeded' === $intent->status ) {
				$order->payment_complete();
				do_action( 'fkwcs_' . $this->id . '_before_redirect', $order_id );
				$redirect_url = $this->get_return_url( $order );

				// Remove cart.
				if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
					WC()->cart->empty_cart();
				}
			} elseif ( 'succeeded' === $intent->status || 'requires_capture' === $intent->status ) {
				$redirect_url = $this->process_final_order( end( $intent->charges->data ), $order_id );
			} elseif ( 'processing' === $intent->status ) {
				$order->update_status( apply_filters( 'fkwcs_stripe_intent_processing_order_status', 'on-hold', $intent, $order, $this ) );
				$redirect_url = $this->get_return_url( $order );
			} elseif ( 'requires_payment_method' === $intent->status ) {
				$redirect_url = wc_get_checkout_url();
				wc_add_notice( __( 'Unable to process this payment, please try again or use alternative method.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );
				if ( isset( $_GET['wfacp_id'] ) && isset( $_GET['wfacp_is_checkout_override'] ) && 'no' === $_GET['wfacp_is_checkout_override'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$redirect_url = get_the_permalink( wc_clean( wp_unslash( $_GET['wfacp_id'] ) ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}

				/**
				 * Handle intent with no payment method here, we mark the order as failed and show users a notice
				 */
				if ( $order->has_status( 'failed' ) ) {
					wp_safe_redirect( $redirect_url );
					exit;
				}

				// Load the right message and update the status.
				$status_message = isset( $intent->last_payment_error ) /* translators: 1) The error message that was received from Stripe. */ ? sprintf( __( 'Stripe SCA authentication failed. Reason: %s', 'funnelkit-stripe-woo-payment-gateway' ), $intent->last_payment_error->message ) : __( 'Stripe SCA authentication failed.', 'funnelkit-stripe-woo-payment-gateway' );
				$this->mark_order_failed( $order, $status_message );
			}
			Helper::log( 'Redirecting to :' . $redirect_url );
		} catch ( \Exception $e ) {
			$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
		}
		remove_all_actions( 'wp_redirect' );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function merge_cart_details( $fragments ) {
		if ( true === self::$fragment_sent ) {
			return $fragments;
		}
		if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
			$order_total                      = WC()->cart->get_total( false );
			$fragments['fkwcs_paylater_data'] = array(
				'currency' => strtolower( get_woocommerce_currency() ),
				'amount'   => max( 0, apply_filters( 'fkwcs_stripe_calculated_total', Helper::get_formatted_amount( $order_total ), $order_total, WC()->cart ) ),
			);

			self::$fragment_sent = true;
		}

		// Add element localization data for dynamic gateway availability
		$fragments = $this->add_element_data_to_fragments( $fragments );

		return $fragments;
	}

	/**
	 * Add element localization data to fragments for dynamic gateway availability
	 *
	 * @param array $fragments Existing fragments.
	 *
	 * @return array Modified fragments with element data.
	 */
	protected function add_element_data_to_fragments( $fragments ) {
		try {
			// Only add if gateway is available
			if ( ! $this->is_available() ) {
				return $fragments;
			}

			// Initialize element data array if not exists
			if ( ! isset( $fragments['fkwcs_element_data'] ) ) {
				$fragments['fkwcs_element_data'] = array();
			}

			// Get element data for this gateway if method exists
			if ( method_exists( $this, 'get_element_data_for_fragments' ) ) {
				$element_data = $this->get_element_data_for_fragments();
				if ( ! empty( $element_data ) && is_array( $element_data ) ) {
					$fragments['fkwcs_element_data'][ $this->id ] = $element_data;
				}
			}
		} catch ( \Throwable $e ) {
			// Silent failure - log error but don't break checkout
			Helper::log( sprintf( 'Error adding element data to fragments for gateway %s: %s', $this->id, $e->getMessage() ) );
		}

		return $fragments;
	}

	/**
	 * Get element data for fragments - Override in child class if needed
	 * This method should return the same data structure as localize_element_data
	 *
	 * @return array Element data for this gateway.
	 */
	protected function get_element_data_for_fragments() {
		return array();
	}

	public function localize_element_data( $data ) {

		if ( ! $this->is_available() ) {
			return $data;
		}

		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id = isset( $_GET['key'] ) ? wc_get_order_id_by_order_key( sanitize_text_field( $_GET['key'] ) ) : 0; // @codingStandardsIgnoreLine
			$order    = wc_get_order( $order_id );

			// Return early if order is not valid
			if ( ! $order || ! is_a( $order, '\WC_Order' ) ) {
				return $data;
			}

			$fkwcs_order_total           = $order->get_total();
			$data['fkwcs_paylater_data'] = array(
				'currency' => strtolower( get_woocommerce_currency() ),
				'amount'   => Helper::get_formatted_amount( $fkwcs_order_total ),
			);

			return $data;
		}

		if ( is_null( WC()->cart ) || ! WC()->cart instanceof \WC_Cart ) {
			return $data;
		}

		$order_total                 = WC()->cart->get_total( false );
		$data['fkwcs_paylater_data'] = array(
			'currency' => strtolower( get_woocommerce_currency() ),
			'amount'   => max( 0, apply_filters( 'fkwcs_stripe_calculated_total', Helper::get_formatted_amount( $order_total ), $order_total, WC()->cart ) ),
		);

		return $data;
	}
}
