<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Payment_Tokens;
use FKWCS\Gateway\Stripe\Traits\WC_Subscriptions_Trait;

#[\AllowDynamicProperties]
class Ideal extends Abstract_Payment_Gateway {

	use WC_Subscriptions_Trait;

	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id                   = 'fkwcs_stripe_ideal';
	public $payment_method_types = 'ideal';
	protected $payment_element   = true;

	public function __construct() {
		parent::__construct();
		$this->init_supports();
	}

	/**
	 * Setup general properties and settings
	 *
	 * @return void
	 */
	protected function init() {
		$this->method_title       = __( 'Stripe iDeal Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Accepts payments via iDeal. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>EUR</strong>', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle           = __( 'iDeal is Netherlands based payment method that allows customers to complete transactions online', 'funnelkit-stripe-woo-payment-gateway' );
		$this->has_fields         = true;
		$this->init_form_fields();
		$this->init_settings();
		$this->maybe_init_subscriptions();
		// iDEAL cannot complete the SetupIntent-with-redirect flow a payment-method change
		// requires, so drop the change-PM flags the shared trait advertises. Only the working
		// paid-PaymentIntent path is supported — advertise only what we can actually complete.
		$this->supports    = array_diff( $this->supports, array( 'subscription_payment_method_change', 'subscription_payment_method_change_customer', 'subscription_payment_method_change_admin' ) );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
		add_filter( 'fkwcs_localized_data', array( $this, 'localize_element_data_ideal' ), 999 );
	}

	/**
	 * Add hooks
	 *
	 * @return void
	 */
	protected function filter_hooks() {
		add_filter( 'woocommerce_payment_successful_result', array( $this, 'modify_successful_payment_result' ), 999, 2 );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'add_ideal_element_data_to_fragments' ), 1000 );
	}

	/**
	 * Registers supported filters for payment gateway
	 *
	 * @return void
	 */
	public function init_supports() {
		$this->supports = apply_filters( 'fkwcs_ideal_payment_supports', array_merge( $this->supports, array( 'products', 'refunds', 'tokenization' ) ) );
	}

	/**
	 * Returns all supported currencies for this payment method
	 *
	 * @return mixed|null
	 */
	public function get_supported_currency() {
		return apply_filters( 'fkwcs_stripe_ideal_supported_currencies', array( 'EUR' ) );
	}

	/**
	 * Checks if payment method available
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( ! in_array( get_woocommerce_currency(), $this->get_supported_currency(), true ) ) {
			return false;
		}

		if ( $this->is_zero_initial_payment() ) {
			return false;
		}

		if ( ! empty( $this->get_option( 'allowed_countries' ) ) && 'all_except' === $this->get_option( 'allowed_countries' ) ) {
			return ! in_array( $this->get_billing_country(), $this->get_option( 'except_countries', array() ), true );
		} elseif ( ! empty( $this->get_option( 'allowed_countries' ) ) && 'specific' === $this->get_option( 'allowed_countries' ) ) {
			return in_array( $this->get_billing_country(), $this->get_option( 'specific_countries', array() ), true );
		}

		return parent::is_available();
	}

	/**
	 * Whether the current checkout/order requires a $0 initial payment.
	 *
	 * iDEAL needs a non-zero redirect PaymentIntent to complete, so a free trial or
	 * $0 sign-up must not offer it for a flow it cannot finish. Keyed on the initial
	 * total (not "is a subscription") so a subscription with a non-zero sign-up fee
	 * still takes the working paid path and stays selectable. Guards no-cart admin/API
	 * contexts where get_order_total() is legitimately 0.
	 *
	 * @return bool True when a payable context exists and its initial total is 0.
	 * @since 1.14.0.4
	 */
	private function is_zero_initial_payment() {
		$has_cart     = ! is_null( WC()->cart ) && ! WC()->cart->is_empty();
		$is_order_pay = is_wc_endpoint_url( 'order-pay' );

		if ( ! $has_cart && ! $is_order_pay ) {
			return false;
		}

		return 0 >= $this->get_order_total();
	}

	/**
	 * Initialise gateway settings form fields
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$settings = array(

			'enabled'     => array(
				'title'       => __( 'Enable/Disable', 'funnelkit-stripe-woo-payment-gateway' ),
				'label'       => __( 'Enable Stripe iDEAL', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Change the payment gateway title that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => __( 'iDEAL', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => __( 'You will be redirected to iDEAL.', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc_tip'    => true,
			),
		);

		$this->form_fields = apply_filters( 'fkwcs_ideal_payment_form_fields', array_merge( $settings, $this->get_countries_admin_fields( 'specific', array(), array( 'NL' ) ) ) );
	}

	/**
	 * Print the gateway field
	 *
	 * @return void
	 */
	public function payment_fields() {
		do_action( $this->id . '_before_payment_field_checkout' );
		include __DIR__ . '/parts/ideal.php';
		do_action( $this->id . '_after_payment_field_checkout' );
	}

	/**
	 * Get payment gateway icons
	 *
	 * @return mixed|string|null
	 */
	public function get_icon() {
		$icons      = $this->payment_icons();
		$icons_str  = '';
		$icons_str .= ! empty( $icons['ideal'] ) ? $icons['ideal'] : '';

		return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
	}

	/**
	 * Gate SEPA-mandate tokenization to a recurring context only.
	 *
	 * iDEAL renders no "save card" checkbox and does not declare `add_payment_method`
	 * support, so a one-time purchase has no legitimate reason to tokenize. Only a
	 * subscription sign-up (or renewal) needs the generated SEPA Direct Debit mandate;
	 * without this gate every single iDEAL charge silently mints a SEPA mandate and a
	 * stored token behind the shopper's back.
	 *
	 * @param \WC_Order $order WooCommerce order being paid.
	 *
	 * @return bool Whether the generated SEPA payment method should be saved.
	 * @since 1.14.0.4
	 */
	public function should_save_card( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		$should_save = $this->is_subscriptions_enabled() && $this->has_subscription( $order->get_id() );

		return apply_filters( 'fkwcs_should_save_card', $should_save, $order );
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
		do_action( 'fkwcs_before_process_payment', $order_id );

		$force_save_source = false;

		if ( $this->is_using_saved_payment_method() ) {
			return $this->process_payment_using_saved_token( $order_id );
		}

		try {
			$order = wc_get_order( $order_id );
			if ( $this->should_save_card( $order ) ) {
				$force_save_source = true;
			}

			/** This will throw exception if not valid */
			$this->validate_minimum_order_amount( $order );
			$customer_id      = $this->get_customer_id( $order );
			$idempotency_key  = $order->get_order_key() . time();
			$data             = array(
				'amount'               => Helper::get_stripe_amount( $order->get_total() ),
				'currency'             => $this->get_currency(),
				'description'          => $this->get_order_description( $order ),
				'metadata'             => $this->get_metadata( $order_id ),
				'payment_method_types' => array( $this->payment_method_types ),
				'customer'             => $customer_id,
			);
			$data['metadata'] = $this->add_metadata( $order );
			$amount_data      = $this->add_amount_details( $order, $data['payment_method_types'][0] ?? 'card' );
			if ( ! empty( $amount_data ) ) {
				$data = array_merge( $data, $amount_data );
			}
			$data = $this->set_shipping_data( $data, $order );
			if ( $force_save_source ) {
				/**
				 * iDEAL uses the Stripe Payment Element (deferred mode), which validates the intent's
				 * TOP-LEVEL setup_future_usage against the element (created with none). Setting it at
				 * the top level breaks the confirm call ("provided setup_future_usage (off_session)
				 * does not match the expected setup_future_usage (null)"). Set it per-method instead:
				 * the top level stays null (so the element matches) while Stripe still generates the
				 * SEPA Direct Debit mandate for off-session renewals.
				 */
				$data['payment_method_options'] = array(
					'ideal' => array(
						'setup_future_usage' => 'off_session',
					),
				);
			}

			$intent_data = $this->get_payment_intent( $order, $idempotency_key, $data );

			/* translators: 1: Order ID, 2: Order total */
			Helper::log( sprintf( __( 'Begin processing payment with Ideal for order %1$1s for the amount of %2$2s', 'funnelkit-stripe-woo-payment-gateway' ), $order_id, $order->get_total() ) );

			if ( $intent_data ) {
				/**
				 * @see modify_successful_payment_result()
				 * This modifies the final response return in WooCommerce process checkout request
				 */
				$return_url = $this->get_return_url( $order );

				return array(
					'result'              => 'success',
					'fkwcs_redirect'      => $return_url,
					'save_card'           => $force_save_source,
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

	public function localize_element_data_ideal( $data ) {
		if ( ! $this->is_available() ) {
			return $data;
		}
		$data['fkwcs_payment_data_ideal'] = $this->payment_element_data();

		return $data;
	}

	public function payment_element_data() {

		$data    = $this->get_payment_element_options();
		$methods = array( 'ideal' );

		$data['payment_method_types'] = apply_filters( 'fkwcs_available_payment_element_types', $methods );
		$data['appearance']           = array(
			'theme' => 'stripe',
		);

		$options            = array(
			'fields' => array(
				'billingDetails' => ( true === is_wc_endpoint_url( 'order-pay' ) || true === is_wc_endpoint_url( 'add-payment-method' ) ) ? 'auto' : 'never',
			),
		);
		$options['wallets'] = array(
			'applePay'  => 'never',
			'googlePay' => 'never',
		);

		return apply_filters(
			'fkwcs_stripe_payment_element_data_ideal',
			array(
				'element_data'    => $data,
				'element_options' => $options,
			),
			$this
		);
	}

	public function process_final_order( $response, $order_id ) {
		$order = wc_get_order( $order_id );

		if ( isset( $response->balance_transaction ) ) {
			Helper::update_balance( $order, $response->balance_transaction );
		}

		if ( isset( $response->status ) && 'succeeded' === $response->status ) {
			$order->payment_complete( $response->id );

			Helper::log( sprintf( 'iDEAL Payment successful Order id - %1s', $order->get_id() ) );

			if ( isset( $response->payment_method_details->ideal ) ) {
				$ideal_details = $response->payment_method_details->ideal;

				$bank_name = isset( $ideal_details->bank ) ? $ideal_details->bank : 'iDEAL';
				$bic       = isset( $ideal_details->bic ) ? $ideal_details->bic : '';

				if ( ! empty( $bic ) ) {
					/* translators: 1: Charge ID, 2: Bank name, 3: BIC */
					$order->add_order_note( sprintf( __( 'Order charge successful in Stripe. Charge: %1$s. Payment method: %2$s (%3$s)', 'funnelkit-stripe-woo-payment-gateway' ), $response->id, $bank_name, $bic ) );
					/* translators: 1: Charge ID, 2: Bank name, 3: BIC */
					Helper::log( sprintf( __( 'Order charge successful in Stripe. Charge: %1$s. Payment method: %2$s (%3$s)', 'funnelkit-stripe-woo-payment-gateway' ), $response->id, $bank_name, $bic ) );
				} else {
					/* translators: 1: Charge ID, 2: Bank name */
					$order->add_order_note( sprintf( __( 'Order charge successful in Stripe. Charge: %1$s. Payment method: %2$s', 'funnelkit-stripe-woo-payment-gateway' ), $response->id, $bank_name ) );
					/* translators: 1: Charge ID, 2: Bank name */
					Helper::log( sprintf( __( 'Order charge successful in Stripe. Charge: %1$s. Payment method: %2$s', 'funnelkit-stripe-woo-payment-gateway' ), $response->id, $bank_name ) );
				}
			} else {
				/* translators: %s: Charge ID */
				$order->add_order_note( sprintf( __( 'Order charge successful in Stripe. Charge: %s. Payment method: iDEAL', 'funnelkit-stripe-woo-payment-gateway' ), $response->id ) );
				/* translators: %s: Charge ID */
				Helper::log( sprintf( __( 'Order charge successful in Stripe. Charge: %s. Payment method: iDEAL', 'funnelkit-stripe-woo-payment-gateway' ), $response->id ) );
			}

			/**
			 * Remove webhook paid meta-data if order is paid from same IP
			 */
			if ( $order->get_customer_ip_address() === \WC_Geolocation::get_ip_address() ) {
				$order->delete_meta_data( '_fkwcs_webhook_paid' );
				$order->save_meta_data();
			}
		} else {
			$order->set_transaction_id( $response->id );
			$order->save();

			$status = isset( $response->status ) ? $response->status : 'unknown';
			/* translators: 1: Payment Intent ID, 2: Status */
			$order->update_status( 'failed', sprintf( __( 'iDEAL payment failed (Payment Intent ID: %1$s). Status: %2$s', 'funnelkit-stripe-woo-payment-gateway' ), $response->id, $status ) );

			Helper::log( sprintf( 'iDEAL payment failed Order id - %1s, Status: %2s', $order->get_id(), $status ) );
		}

		/** Empty cart only if payment was successful */
		if ( isset( $response->status ) && 'succeeded' === $response->status && ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
			WC()->cart->empty_cart();
		}

		do_action( 'fkwcs_process_response_ideal', $response, $order );
		do_action( 'fkwcs_process_response', $response, $order );

		$return_url = $this->get_return_url( $order );

		return $return_url;
	}

	protected function get_successful_intent_statuses() {
		return array( 'succeeded' ); // Only 'succeeded', no 'requires_capture'
	}

	/**
	 * Get element data for fragments to support dynamic gateway availability
	 *
	 * @return array Element data for this gateway.
	 */
	protected function get_element_data_for_fragments() {
		try {
			// Return the same data structure as payment_element_data method
			return $this->payment_element_data();
		} catch ( \Throwable $e ) {
			Helper::log( sprintf( 'Error getting iDEAL element data for fragments: %s', $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Add iDEAL element data to WooCommerce fragments
	 * This enables dynamic gateway initialization when country changes
	 *
	 * @param array $fragments Existing fragments.
	 *
	 * @return array Modified fragments with iDEAL element data.
	 */
	public function add_ideal_element_data_to_fragments( $fragments ) {
		try {
			// Only add if gateway is available
			if ( ! $this->is_available() ) {
				return $fragments;
			}

			// Initialize element data array if not exists
			if ( ! isset( $fragments['fkwcs_element_data'] ) ) {
				$fragments['fkwcs_element_data'] = array();
			}

			// Get element data for this gateway
			$element_data = $this->get_element_data_for_fragments();
			if ( ! empty( $element_data ) && is_array( $element_data ) ) {
				$fragments['fkwcs_element_data'][ $this->id ] = $element_data;
			}
		} catch ( \Throwable $e ) {
			// Silent failure - log error but don't break checkout
			Helper::log( sprintf( 'Error adding iDEAL element data to fragments: %s', $e->getMessage() ) );
		}

		return $fragments;
	}

	/**
	 * Return payment method types, using sepa_debit for off-session renewals.
	 *
	 * iDEAL tokenizes into SEPA Direct Debit mandates on Stripe, so off-session
	 * renewal charges must use sepa_debit as the payment method type.
	 *
	 * @return array
	 */
	public function get_payment_method_types() {
		if ( 0 < did_action( 'woocommerce_scheduled_subscription_payment_' . $this->id ) ) {
			return array( 'sepa_debit' );
		}

		return array( $this->payment_method_types );
	}

	/**
	 * Check if customer is using a saved payment method.
	 *
	 * @return bool
	 */
	public function is_using_saved_payment_method() {
		$payment_method = filter_input( INPUT_POST, 'payment_method', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( null === $payment_method || false === $payment_method ) {
			$payment_method = $this->id;
		}

		$token_value = filter_input( INPUT_POST, 'wc-' . $payment_method . '-payment-token', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		return ( null !== $token_value && false !== $token_value && 'new' !== $token_value );
	}

	/**
	 * Look for saved token.
	 *
	 * @return \WC_Payment_Token|null
	 */
	public function find_saved_token() {
		$payment_method = filter_input( INPUT_POST, 'payment_method', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$payment_method = ( null === $payment_method || false === $payment_method ) ? null : $payment_method;

		$token_request_key = 'wc-' . $payment_method . '-payment-token';
		$token_value       = filter_input( INPUT_POST, $token_request_key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( null === $token_value || false === $token_value || 'new' === $token_value ) {
			return null;
		}

		$token = WC_Payment_Tokens::get( $token_value );
		if ( ! $token || $payment_method !== $token->get_gateway_id() || $token->get_user_id() !== get_current_user_id() ) {
			return null;
		}

		return $token;
	}

	/**
	 * Process payment using a saved token (SEPA Direct Debit from iDEAL tokenization).
	 *
	 * Reachable only if a `wc-fkwcs_stripe_ideal-payment-token` is posted; iDEAL never
	 * renders saved tokens (`payment_fields()` does not call `saved_payment_methods()`,
	 * no `add_payment_method` support, no Blocks integration). Kept for parity with
	 * `bancontact.php` — do not delete.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array|mixed|string[]|null
	 */
	public function process_payment_using_saved_token( $order_id ) {
		$order = wc_get_order( $order_id );

		try {
			$token = $this->find_saved_token();
			if ( is_null( $token ) ) {
				return array(
					'result'   => 'fail',
					'redirect' => '',
				);
			}

			$stripe_api     = $this->get_client();
			$response       = $stripe_api->payment_methods( 'retrieve', array( $token->get_token() ) );
			$payment_method = $response['success'] ? $response['data'] : false;

			$prepared_payment_method = Helper::prepare_payment_method( $payment_method, $token );

			$this->save_payment_method_to_order( $order, $prepared_payment_method );
			$return_url = $this->get_return_url( $order );

			/* translators: %1$1s order id, %2$2s order total amount  */
			Helper::log( sprintf( 'Begin processing payment with saved payment method for order %1$1s for the amount of %2$2s', $order_id, $order->get_total() ) );

			$request             = array(
				'payment_method'       => $payment_method->id,
				'payment_method_types' => array( 'sepa_debit' ),
				'amount'               => Helper::get_stripe_amount( $order->get_total() ),
				'currency'             => strtolower( $order->get_currency() ),
				'description'          => $this->get_order_description( $order ),
				'customer'             => $payment_method->customer,
			);
			$request['metadata'] = $this->add_metadata( $order );
			$amount_data         = $this->add_amount_details( $order, $request['payment_method_types'][0] ?? 'card' );
			if ( ! empty( $amount_data ) ) {
				$request = array_merge( $request, $amount_data );
			}
			$request = $this->set_shipping_data( $request, $order );
			$intent  = $this->make_payment_by_source( $order, $prepared_payment_method, $request );

			$this->save_intent_to_order( $order, $intent );

			if ( 'requires_confirmation' === $intent->status || 'requires_action' === $intent->status ) {
				return apply_filters(
					'fkwcs_card_payment_return_intent_data',
					array(
						'result'              => 'success',
						'token'               => 'yes',
						'fkwcs_redirect'      => $return_url,
						'payment_method'      => $intent->id,
						'fkwcs_intent_secret' => $intent->client_secret,
					)
				);
			}

			/**
			 * SEPA Direct Debit charges settle asynchronously, so an off-session charge
			 * lands in 'processing'. Keep the order on-hold until the webhook confirms it,
			 * instead of treating a non-succeeded charge as failed.
			 */
			if ( 'processing' === $intent->status ) {
				$order->set_transaction_id( $intent->id );
				$order->update_status( 'on-hold', __( 'Stripe charge awaiting payment via SEPA Direct Debit. Payment will be completed once payment_intent.succeeded webhook received from Stripe.', 'funnelkit-stripe-woo-payment-gateway' ) );

				if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
					WC()->cart->empty_cart();
				}

				return array(
					'result'   => 'success',
					'redirect' => $return_url,
				);
			}

			if ( $intent->amount > 0 ) {
				$this->process_final_order( end( $intent->charges->data ), $order->get_id() );
			} else {
				$order->payment_complete();
			}

			if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
				WC()->cart->empty_cart();
			}

			return array(
				'result'   => 'success',
				'redirect' => $return_url,
			);

		} catch ( \Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );

			/* translators: error message */
			$this->mark_order_failed( $order, $e->getMessage() );

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}
	}

	/**
	 * Save payment method from intent after verification.
	 *
	 * iDEAL generates a SEPA Direct Debit payment method for future off-session use.
	 * The intent->payment_method is still the original iDEAL PM (no sepa_debit property);
	 * the generated SEPA PM is on the charge under payment_method_details->ideal->generated_sepa_debit.
	 *
	 * @param \WC_Order $order Order object.
	 * @param object    $intent Payment intent object.
	 *
	 * @return void
	 */
	public function save_payment_method( $order, $intent ) {
		$charge     = $this->get_latest_charge_from_intent( $intent );
		$sepa_pm_id = ! empty( $charge->payment_method_details->ideal->generated_sepa_debit ) ? $charge->payment_method_details->ideal->generated_sepa_debit : null;

		// iDEAL's first charge generates a SEPA Direct Debit mandate; persist its id
		// (distinct from the generated PM id above) so off-session renewals reference it
		// via maybe_add_emandate_data_to_request(). Null-safe no-op on the SetupIntent/$0
		// path where there is no charge object.
		if ( ! empty( $charge->payment_method_details->ideal->generated_sepa_debit_mandate ) ) {
			$order->update_meta_data( '_stripe_mandate_id', $charge->payment_method_details->ideal->generated_sepa_debit_mandate );
			$order->save_meta_data();
			Helper::log( sprintf( 'iDEAL: captured SEPA mandate id for order %s.', $order->get_id() ) );
		}

		if ( empty( $sepa_pm_id ) ) {
			Helper::log( sprintf( 'iDEAL: no generated SEPA PM on charge for order %s, skipping tokenization.', $order->get_id() ) );
			$this->save_payment_method_to_order( $order, Helper::prepare_payment_method( false, null ) );

			return;
		}

		$response       = $this->get_client()->payment_methods( 'retrieve', array( $sepa_pm_id ) );
		$payment_method = $response['success'] ? $response['data'] : false;

		$token = null;
		$user  = $order->get_id() ? $order->get_user() : wp_get_current_user();
		if ( $user instanceof \WP_User ) {
			$user_id = $user->ID;
			$token   = $this->create_payment_token_for_user( $user_id, $payment_method, $intent->livemode );

			if ( $token ) {
				Helper::log( sprintf( 'Payment method tokenized for Order id - %1$1s with token id - %2$2s', $order->get_id(), $token->get_id() ) );
			}
		}

		$prepared_payment_method = Helper::prepare_payment_method( $payment_method, $token );
		$this->save_payment_method_to_order( $order, $prepared_payment_method );
	}

	/**
	 * Tokenize the SEPA Direct Debit payment method created from iDEAL.
	 *
	 * @param int    $user_id User ID.
	 * @param object $payment_method Stripe payment method object.
	 * @param bool   $is_live Whether in live mode.
	 *
	 * @return Token|null
	 */
	public function create_payment_token_for_user( $user_id, $payment_method, $is_live ) {
		if ( empty( $payment_method ) || empty( $payment_method->id ) || empty( $payment_method->sepa_debit->last4 ) ) {
			Helper::log( 'iDEAL: create_payment_token_for_user received invalid payment method, skipping.' );

			return null;
		}

		global $wpdb;
		$token_exists = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_payment_tokens where token =%s", $payment_method->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! empty( $token_exists ) ) {
			$token_obj = \WC_Payment_Tokens::get( $token_exists[0]['token_id'] );
			if ( ! is_null( $token_obj ) ) {
				$token_obj->set_gateway_id( $this->id );
				$token_obj->save();

				return $token_obj;
			}
		}
		$token = new Token();
		$token->set_last4( $payment_method->sepa_debit->last4 );
		$token->set_gateway_id( $this->id );
		$token->set_token( $payment_method->id );
		$token->set_user_id( $user_id );
		$token->update_meta_data( 'mode', ( $is_live ) ? 'live' : 'test' );
		$token->save_meta_data();
		$token->save();

		return $token;
	}
}
