<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Payment_Tokens;
use FKWCS\Gateway\Stripe\Traits\WC_Subscriptions_Trait;

#[\AllowDynamicProperties]
class Bancontact extends Abstract_Payment_Gateway {

	use WC_Subscriptions_Trait;

	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id                   = 'fkwcs_stripe_bancontact';
	public $payment_method_types = 'bancontact';
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
		$this->method_title       = __( 'Stripe Bancontact Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Accepts payments via Bancontact. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>EUR</strong>', 'funnelkit-stripe-woo-payment-gateway' );

		$this->subtitle   = __( 'Bancontact is the most popular online payment method in Belgium and over 15 million cards in circulation', 'funnelkit-stripe-woo-payment-gateway' );
		$this->has_fields = true;
		$this->init_form_fields();
		$this->init_settings();
		$this->maybe_init_subscriptions();
		// Bancontact cannot complete the SetupIntent-with-redirect flow a payment-method change
		// requires, so drop the change-PM flags the shared trait advertises. Only the working
		// paid-PaymentIntent path is supported — advertise only what we can actually complete.
		$this->supports    = array_diff( $this->supports, array( 'subscription_payment_method_change', 'subscription_payment_method_change_customer', 'subscription_payment_method_change_admin' ) );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
	}

	/**
	 * Add hooks
	 *
	 * @return void
	 */
	protected function filter_hooks() {
		add_filter( 'woocommerce_payment_successful_result', array( $this, 'modify_successful_payment_result' ), 999, 2 );
	}

	/**
	 * Registers supported filters for payment gateway
	 *
	 * @return void
	 */
	public function init_supports() {
		$this->supports = apply_filters(
			'fkwcs_bancontact_payment_supports',
			array_merge(
				$this->supports,
				array(
					'products',
					'refunds',
					'tokenization',
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
				'title'   => __( 'Enable Stripe Bancontact Gateway', 'funnelkit-stripe-woo-payment-gateway' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Change the payment gateway title that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => __( 'Stripe Bancontact', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'textarea',
				'css'         => 'width:25em',
				'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => __( 'Pay with Bancontact', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc_tip'    => true,
			),
		);

		$this->form_fields = apply_filters( 'fkwcs_bancontact_payment_form_fields', array_merge( $settings, $this->get_countries_admin_fields( 'specific', array(), array( 'BE' ) ) ) );
	}

	/**
	 * Returns all supported currencies for this payment method
	 *
	 * @return mixed|null
	 */
	public function get_supported_currency() {
		return apply_filters(
			'wc_stripe_bancontact_supported_currencies',
			array(
				'EUR',
			)
		);
	}

	/**
	 * Checks if payment method available
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( $this->is_zero_initial_payment() ) {
			return false;
		}

		return $this->is_available_local_gateway();
	}

	/**
	 * Whether the current checkout/order requires a $0 initial payment.
	 *
	 * Bancontact needs a non-zero redirect PaymentIntent to complete, so a free trial
	 * or $0 sign-up must not offer it for a flow it cannot finish. Keyed on the initial
	 * total (not "is a subscription") so a subscription with a non-zero sign-up fee still
	 * takes the working paid path and stays selectable. Guards no-cart admin/API contexts
	 * where get_order_total() is legitimately 0.
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
	 * Get payment gateway icons
	 *
	 * @return mixed|string|null
	 */
	public function get_icon() {
		$icons      = $this->payment_icons();
		$icons_str  = '';
		$icons_str .= ! empty( $icons['bancontact'] ) ? $icons['bancontact'] : '';

		return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
	}

	/**
	 * Gate SEPA-mandate tokenization to a recurring context only.
	 *
	 * Bancontact renders no "save card" checkbox and does not declare `add_payment_method`
	 * support, so a one-time purchase has no legitimate reason to tokenize. Only a
	 * subscription sign-up (or renewal) needs the generated SEPA Direct Debit mandate.
	 * Bancontact sets a top-level `setup_future_usage`, so gating `should_save_card()`
	 * is the only complete fix — without it every single charge mints a SEPA mandate
	 * and a stored token behind the shopper's back.
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
	 * @param int  $order_id Reference.
	 * @param bool $retry
	 * @param bool $force_prevent_source_creation
	 * @param bool $previous_error
	 * @param bool $use_order_source
	 *
	 * @return array|mixed|string[]|void|null
	 */
	public function process_payment( $order_id, $retry = true, $force_prevent_source_creation = false, $previous_error = false, $use_order_source = false ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedParameter,VariableAnalysis.CodeAnalysis.VariableAnalysis
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
			$customer_id     = $this->get_customer_id( $order );
			$idempotency_key = $order->get_order_key() . time();
			$data            = array(
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
				$data['setup_future_usage'] = 'off_session';
			}

			/* translators: 1: Order ID, 2: Order total */
			Helper::log( sprintf( __( 'Begin processing payment with Bancontact for order %1$1s for the amount of %2$2s', 'funnelkit-stripe-woo-payment-gateway' ), $order_id, $order->get_total() ) );

			$intent_data = $this->get_payment_intent( $order, $idempotency_key, $data );

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

	/**
	 * Verify intent secret and redirect to the thankyou page
	 *
	 * @return void
	 */
	public function verify_intent() {
		global $woocommerce;

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
			$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
			$this->handle_error( $e, $redirect_url );
		}

		try {
			$redirect_to = '';
			$order_id    = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$redirect    = isset( $_GET['fkwcs_redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['fkwcs_redirect_to'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order       = wc_get_order( $order_id );
			$intent      = $this->get_intent_from_order( $order );

			if ( false === $intent ) {
				throw new \Exception( 'Intent Not Found' );
			}

			if ( isset( $_GET['save_card'] ) || 'off_session' === $intent->setup_future_usage ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->save_payment_method( $order, $intent );
			}

			if ( 'setup_intent' === $intent->object && 'succeeded' === $intent->status ) {
				$order->payment_complete();

				$order->delete_meta_data( '_fkwcs_webhook_paid' );
				$order->save_meta_data();

				if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
					WC()->cart->empty_cart();
				}
			} elseif ( 'succeeded' === $intent->status || 'requires_capture' === $intent->status ) {
				$redirect_to = $this->process_final_order( end( $intent->charges->data ), $order_id );
			} elseif ( 'processing' === $intent->status ) {
				$order->update_status( apply_filters( 'fkwcs_stripe_intent_processing_order_status', 'on-hold', $intent, $order, $this ) );
				$redirect_url = $this->get_return_url( $order );
			} elseif ( 'requires_payment_method' === $intent->status ) {
				$redirect_url = wc_get_checkout_url();
				wc_add_notice( __( 'Unable to process this payment, please try again or use alternative method.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );

				if ( $order->has_status( 'failed' ) ) {
					wp_safe_redirect( $redirect_url );
					exit;
				}

				$status_message = isset( $intent->last_payment_error ) /* translators: 1) The error message that was received from Stripe. */ ? sprintf( __( 'Stripe SCA authentication failed. Reason: %s', 'funnelkit-stripe-woo-payment-gateway' ), $intent->last_payment_error->message ) : __( 'Stripe SCA authentication failed.', 'funnelkit-stripe-woo-payment-gateway' );
				$this->mark_order_failed( $order, $status_message );
			} else {
				$redirect = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
			}

			if ( 'pending' === $intent->status || 'processing' === $intent->status ) {
				$order_stock_reduced = Helper::get_meta( $order, '_order_stock_reduced' );

				if ( ! $order_stock_reduced ) {
					wc_reduce_stock_levels( $order_id );
				}

				$order->set_transaction_id( $intent->id );
				$others_info = __( 'Payment will be completed once payment_intent.succeeded webhook received from Stripe.', 'funnelkit-stripe-woo-payment-gateway' );
				/* translators: transaction id, other info */
				$order->update_status( 'on-hold', sprintf( __( 'Stripe charge awaiting payment: %1$s. %2$s', 'funnelkit-stripe-woo-payment-gateway' ), $intent->id, $others_info ) );

				do_action( 'fkwcs_' . $this->id . '_before_redirect', $order_id );

				$redirect_to = $this->get_return_url( $order );
				Helper::log( 'Redirecting to :' . $redirect_to );

				wp_safe_redirect( $redirect_to );
				exit;
			}

			$redirect_url = ! empty( $redirect ) ? $redirect : $redirect_to;

		} catch ( \Exception $e ) {
			$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
		}
		wp_safe_redirect( $redirect_url );
		exit;
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

		if ( true === $response->captured ) {
			$order->payment_complete( $response->id );
			/* translators: order id */
			Helper::log( sprintf( 'Payment successful Order id - %1s', $order->get_id() ) );

			$order->add_order_note( __( 'Payment Status: ', 'funnelkit-stripe-woo-payment-gateway' ) . ucfirst( $response->status ) . ', ' . __( 'Source: Payment is Completed via ', 'funnelkit-stripe-woo-payment-gateway' ) . $response->payment_method_details->bancontact->iban_last4 . '(' . $response->payment_method_details->bancontact->bank_name . ')' );
			/* translators: %s: Charge ID */
			$order->add_order_note( sprintf( __( 'Charge ID %s', 'funnelkit-stripe-woo-payment-gateway' ), $response->id ) );
		} else {
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

	/**
	 * Print the gateway field
	 *
	 * @return void
	 */
	public function payment_fields() {
		do_action( $this->id . '_before_payment_field_checkout' );
		include __DIR__ . '/parts/bancontact.php';
		do_action( $this->id . '_after_payment_field_checkout' );
	}

	/**
	 * Return payment method types, using sepa_debit for off-session renewals.
	 *
	 * Bancontact tokenizes into SEPA Direct Debit mandates on Stripe,
	 * so off-session renewal charges must use sepa_debit as the payment method type.
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
		$payment_method = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : $this->id; //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing

		return ( isset( $_POST[ 'wc-' . $payment_method . '-payment-token' ] ) && 'new' !== wc_clean( wp_unslash( $_POST[ 'wc-' . $payment_method . '-payment-token' ] ) ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing
	}

	/**
	 * Look for saved token.
	 *
	 * @return \WC_Payment_Token|null
	 */
	public function find_saved_token() {
		$payment_method = isset( $_POST['payment_method'] ) && ! is_null( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : null; //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing

		$token_request_key = 'wc-' . $payment_method . '-payment-token';
		if ( ! isset( $_POST[ $token_request_key ] ) || 'new' === wc_clean( wp_unslash( $_POST[ $token_request_key ] ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing
			return null;
		}

		$token = WC_Payment_Tokens::get( wc_clean( wp_unslash( $_POST[ $token_request_key ] ) ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing
		if ( ! $token || $payment_method !== $token->get_gateway_id() || $token->get_user_id() !== get_current_user_id() ) {
			return null;
		}

		return $token;
	}

	/**
	 * Process payment using a saved token (SEPA Direct Debit from Bancontact tokenization).
	 *
	 * Reachable only if a `wc-fkwcs_stripe_bancontact-payment-token` is posted; Bancontact
	 * never renders saved tokens (`payment_fields()` does not call `saved_payment_methods()`,
	 * no `add_payment_method` support, no Blocks integration). Kept for parity with
	 * `ideal.php` — do not delete.
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
	 * @param \WC_Order $order Order object.
	 * @param object    $intent Payment intent object.
	 *
	 * @return void
	 */
	public function save_payment_method( $order, $intent ) {
		// Bancontact generates a SEPA Direct Debit payment method for future off-session use.
		// The intent->payment_method is still the original bancontact PM (no sepa_debit property).
		// The generated SEPA PM is on the charge under payment_method_details->bancontact->generated_sepa_debit.
		$charge     = $this->get_latest_charge_from_intent( $intent );
		$sepa_pm_id = ! empty( $charge->payment_method_details->bancontact->generated_sepa_debit ) ? $charge->payment_method_details->bancontact->generated_sepa_debit : null;

		// Bancontact's first charge generates a SEPA Direct Debit mandate; persist its id
		// (distinct from the generated PM id above) so off-session renewals reference it
		// via maybe_add_emandate_data_to_request(). Null-safe no-op when no charge object.
		if ( ! empty( $charge->payment_method_details->bancontact->generated_sepa_debit_mandate ) ) {
			$order->update_meta_data( '_stripe_mandate_id', $charge->payment_method_details->bancontact->generated_sepa_debit_mandate );
			$order->save_meta_data();
			Helper::log( sprintf( 'Bancontact: captured SEPA mandate id for order %s.', $order->get_id() ) );
		}

		if ( empty( $sepa_pm_id ) ) {
			Helper::log( sprintf( 'Bancontact: no generated SEPA PM on charge for order %s, skipping tokenization.', $order->get_id() ) );
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
	 * Tokenize the SEPA Direct Debit payment method created from Bancontact.
	 *
	 * @param int    $user_id User ID.
	 * @param object $payment_method Stripe payment method object.
	 * @param bool   $is_live Whether in live mode.
	 *
	 * @return Token
	 */
	public function create_payment_token_for_user( $user_id, $payment_method, $is_live ) {
		if ( empty( $payment_method ) || empty( $payment_method->id ) || empty( $payment_method->sepa_debit->last4 ) ) {
			Helper::log( 'Bancontact: create_payment_token_for_user received invalid payment method, skipping.' );
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

	/**
	 * Override to check if charge was captured before completing payment
	 * Bancontact gateway needs to verify captured status for authorization-only charges
	 *
	 * @param object    $intent The payment intent object
	 * @param \WC_Order $order The order object
	 *
	 * @return bool True if payment should be completed, false otherwise
	 */
	protected function should_complete_payment_on_thankyou( $intent, $order ) {
		// For Bancontact, check if the charge was actually captured
		$charge = $this->get_latest_charge_from_intent( $intent );

		if ( $charge && true === $charge->captured ) {
			// Charge was captured, safe to complete payment
			Helper::log( 'Bancontact Gateway: Charge captured, payment completion allowed for order ' . $order->get_id() );

			return true;
		} else {
			// Charge was not captured (authorization only), should be on-hold
			Helper::log( 'Bancontact Gateway: Charge not captured (authorization only), payment completion blocked for order ' . $order->get_id() );

			// Set order to on-hold if not already
			if ( ! $order->has_status( 'on-hold' ) ) {
				$order->set_transaction_id( $intent->id );
				/* translators: %s: Charge ID */
				$order->update_status( 'on-hold', sprintf( __( 'Charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization. Attempting to refund the order in part or in full will release the authorization and cancel the payment.', 'funnelkit-stripe-woo-payment-gateway' ), $intent->id ) );
				Helper::log( 'Bancontact Gateway: Order ' . $order->get_id() . ' set to on-hold for authorization-only charge' );
			}

			return false;
		}
	}
}
