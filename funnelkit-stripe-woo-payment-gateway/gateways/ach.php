<?php

namespace FKWCS\Gateway\Stripe;

use FKWCS\Gateway\Stripe\Traits\WC_Subscriptions_Trait;
use WC_Payment_Tokens;

#[\AllowDynamicProperties]
class ACH extends LocalGateway {
	use WC_Subscriptions_Trait;

	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe_ach';
	public $payment_method_types = 'us_bank_account';
	protected $payment_element = true;
	public $shipping_address_required = false;
	public $capture_method = 'automatic';

	public function __construct() {
		parent::__construct();
		$this->init_supports();
	}

	/**
	 * Registers supported filters for payment gateway
	 *
	 * @return void
	 */
	public function init_supports() {
		$this->supports = apply_filters( 'fkwcs_ach_payment_supports', array_merge( $this->supports, [
			'products',
			'refunds',
			'tokenization',
			'add_payment_method'
		] ) );
	}

	/**
	 * Setup general properties and settings
	 *
	 * @return void
	 */

	protected function init() {
		$this->paylater_message_position   = 'description';
		$this->method_title                = __( 'Stripe ACH Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description          = __( 'Accepts payments via ACH. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>USD</strong>', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle                    = __( 'ACH is an online banking payment method that enables your customers in e-commerce to make an online purchase', 'funnelkit-stripe-woo-payment-gateway' );
		$this->title                       = $this->get_option( 'title' );
		$this->description                 = $this->get_option( 'description' );
		$this->enabled                     = $this->get_option( 'enabled' );
		$this->supported_currency          = [ 'USD' ];
		$this->specific_country            = [ 'US' ];
		$this->setting_enable_label        = __( 'Enable Stripe ACH Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = __( 'Stripe ACH', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = __( 'Pay with ACH', 'funnelkit-stripe-woo-payment-gateway' );
		$this->maybe_init_subscriptions();
		$this->init_form_fields();
		$this->init_settings();
		$this->enable_saved_cards = $this->get_option( 'enable_saved_cards' );
		add_filter( 'woocommerce_payment_methods_list_item', [ $this, 'get_saved_payment_methods_list' ], 10, 2 );
		add_filter( 'fkwcs_localized_data', [ $this, 'localize_element_data' ], 999 );
	}

	/**
	 * Initialise gateway settings form fields
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$settings          = apply_filters( 'fkwcs_ach_payment_form_fields', [
			'enabled'            => [
				'label'   => ' ',
				'type'    => 'checkbox',
				'title'   => $this->setting_enable_label,
				'default' => 'no',
			],
			'title'              => [
				'title'       => __( 'Title', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Change the payment gateway title that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => $this->setting_title_default,
				'id'          => $this->setting_title_default,
				'desc_tip'    => true,
			],
			'description'        => [
				'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'textarea',
				'css'         => 'width:25em',
				'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'enable_saved_cards' => [
				'label'       => __( 'Enable Payment via Saved Bank', 'funnelkit-stripe-woo-payment-gateway' ),
				'title'       => __( 'Saved Bank', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Save Bank details for future orders', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			]
		] );
		$this->form_fields = apply_filters( $this->id . '_payment_form_fields', array_merge( $settings, $this->get_countries_admin_fields( $this->selling_country_type, $this->except_country, $this->specific_country ) ) );
	}

	/**
	 * Process the payment
	 *
	 * @param int $order_id Reference.
	 * @param bool $retry Should we retry on fail.
	 * @param bool $force_save_source Force payment source to be saved.
	 *
	 * @return array|void
	 * @throws \Exception If payment will not be accepted.
	 *
	 */
	public function process_payment( $order_id, $retry = true, $force_save_source = false, $previous_error = false, $use_order_source = false ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedParameter,VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		do_action( 'fkwcs_before_process_payment', $order_id );
		try {
			$force_save_source = true;
			$order             = wc_get_order( $order_id );
			$customer_id       = $this->get_customer_id( $order );
			if ( $this->maybe_change_subscription_payment_method( $order_id ) ) {
				return $this->process_change_subscription_payment_method( $order_id, true );
			}
			if ( 0 >= $order->get_total() ) {
				if ( ! $this->is_using_saved_payment_method() ) {
					$intent_data = $this->create_setup_intent( '', $customer_id, $order );
					if ( $intent_data ) {
						$intent_data_insert = [
							'id'            => $intent_data['id'],
							'client_secret' => $intent_data['client_secret'],
						];

						$order->update_meta_data( '_fkwcs_setup_intent', $intent_data_insert );
						$order->save_meta_data();
						$return_url = $this->get_return_url( $order );

						return [
							'result'                    => 'success',
							'fkwcs_redirect'            => $return_url,
							'fkwcs_setup_intent_secret' => $intent_data->client_secret,
							'token_used'                => $this->is_using_saved_payment_method() ? 'yes' : 'no',
							'save_card'                 => $force_save_source
						];
					} else {
						return [
							'result'   => 'fail',
							'redirect' => '',
						];
					}
				} else {
					return $this->process_change_subscription_payment_method( $order_id );
                }
			}
			if ( $this->is_using_saved_payment_method() ) {
				return $this->process_payment_using_saved_token( $order_id );
			}
			/** This will throw exception if not valid */
			$this->validate_minimum_order_amount( $order );
			$idempotency_key  = $order->get_order_key() . time();
			$data             = [
				'amount'               => Helper::get_formatted_amount( $order->get_total() ),
				'currency'             => $this->get_currency(),
				'description'          => $this->get_order_description( $order ),
				'metadata'             => $this->get_metadata( $order_id ),
				'payment_method_types' => [ $this->payment_method_types ],
				'customer'             => $customer_id,
				'capture_method'       => $this->capture_method,
				'setup_future_usage' => 'off_session',
			];
			$data['metadata'] = $this->add_metadata( $order );
			$data             = $this->set_shipping_data( $data, $order, $this->shipping_address_required );
			$intent_data      = $this->get_payment_intent( $order, $idempotency_key, $data );

			Helper::log( sprintf( __( 'Begin processing payment with  %1$1s for order %2$2s for the amount of %3$3s', 'funnelkit-stripe-woo-payment-gateway' ), $this->get_title(), $order_id, $order->get_total() ) );
			if ( $intent_data ) {
				/**
				 * @see modify_successful_payment_result()
				 * This modifies the final response return in WooCommerce process checkout request
				 */
				$return_url = $this->get_return_url( $order );

				return [
					'result'              => 'success',
					'fkwcs_redirect'      => $return_url,
					'save_card'           => $force_save_source,
					'fkwcs_intent_secret' => $intent_data->client_secret,
				];
			} else {
				return [
					'result'   => 'fail',
					'redirect' => '',
				];
			}
		} catch ( \Exception $e ) {
			Helper::log( $e->getMessage(), 'warning' );
			wc_add_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * Process Order payment using existing customer token saved.
	 *
	 * @param $order_id Int Order ID
	 *
	 * @return array|mixed|string[]|null
	 */
	public function process_payment_using_saved_token( $order_id ) {
		$order = wc_get_order( $order_id );

		try {
			$token = $this->find_saved_token();

			$stripe_api     = $this->get_client();
			$response       = $stripe_api->payment_methods( 'retrieve', [ $token->get_token() ] );
			$payment_method = $response['success'] ? $response['data'] : false;

			$prepared_payment_method = Helper::prepare_payment_method( $payment_method, $token );

			$this->save_payment_method_to_order( $order, $prepared_payment_method );
			$return_url = $this->get_return_url( $order );
			Helper::log( "Return URL1: $return_url" );

			/* translators: %1$1s order id, %2$2s order total amount  */
			Helper::log( sprintf( 'Begin processing payment with saved payment method for order %1$1s for the amount of %2$2s', $order_id, $order->get_total() ) );

			$request             = [
				'confirm'                => false,
				'payment_method'         => $payment_method->id,
				'payment_method_types'   => [ $this->payment_method_types ],
				'amount'                 => Helper::get_stripe_amount( $order->get_total() ),
				'currency'               => strtolower( $order->get_currency() ),
				'description'            => $this->get_order_description( $order ),
				'customer'               => $payment_method->customer,
				'expand'                 => [
					'payment_method',
					'charges.data.balance_transaction'
				],
				'payment_method_options' => [
					'us_bank_account' => [
						'financial_connections' => [
							'permissions' => [ 'payment_method' ]
						],
						'verification_method'   => 'automatic'
					]
				]

			];
			$request['metadata'] = $this->add_metadata( $order );
			$request             = $this->set_shipping_data( $request, $order );
			$intent              = $this->make_payment_by_source( $order, $prepared_payment_method, $request );
			$this->save_intent_to_order( $order, $intent );
			if ( $intent->status === 'requires_confirmation' || $intent->status === 'requires_action' ) {
				try {
					$stripe_api       = $this->get_client();
					$confirm_response = $stripe_api->payment_intents( 'confirm', [ $intent->id ] );

					if ( isset( $confirm_response['error'] ) ) {
						throw new \Exception( "Payment confirmation failed: " . $confirm_response['error']['message'] );
					}

					global $woocommerce;
					switch ( $confirm_response['data']['status'] ) {
						case 'succeeded':
							$order->payment_complete();
							$order->add_order_note( __( 'Payment succeeded via Stripe.', 'funnelkit-stripe-woo-payment-gateway' ) );
							break;

						case 'processing':
							$order_stock_reduced = Helper::get_meta( $order, '_order_stock_reduced' );
							if ( ! $order_stock_reduced ) {
								wc_reduce_stock_levels( $order_id );
							}
							$order->set_transaction_id( $intent->id );
							$others_info = __( 'Payment will be completed once payment_intent.succeeded webhook received from Stripe.', 'funnelkit-stripe-woo-payment-gateway' );
							$order->update_status( 'on-hold', sprintf( __( 'Stripe charge awaiting payment: %1$s. %2$s', 'funnelkit-stripe-woo-payment-gateway' ), $intent->id, $others_info ) );
							break;

						case 'requires_payment_method':
							$return_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
							$order->update_status( 'failed', __( 'Payment failed. Please try another payment method.', 'funnelkit-stripe-woo-payment-gateway' ) );
							wc_add_notice( __( 'Payment failed. Please try another payment method.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );
							throw new \Exception( "Payment failed: Payment method was declined or insufficient funds." );
							break;

						case 'requires_action':
							return apply_filters( 'fkwcs_card_payment_return_intent_data', [
								'result'              => 'success',
								'token'               => 'yes',
								'fkwcs_redirect'      => $return_url,
								'payment_method'      => $intent->id,
								'fkwcs_intent_secret' => $intent->client_secret,
							] );
							break;

						case 'canceled':
							$return_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
							$order->update_status( 'failed', __( 'Payment was canceled by the user or expired.', 'funnelkit-stripe-woo-payment-gateway' ) );
							wc_add_notice( __( 'Payment was canceled. Please try again.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );
							throw new \Exception( "Payment failed: Payment intent was canceled." );
							break;

						default:
							$return_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
							$order->update_status( 'failed', __( 'An unknown error occurred. Please contact support.', 'funnelkit-stripe-woo-payment-gateway' ) );
							wc_add_notice( __( 'An unknown error occurred. Please try again.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );
							throw new \Exception( "Unexpected Payment Intent status: " . $confirm_response->status );
					}

					return [
						'result'   => 'success',
						'redirect' => $return_url,
					];

				} catch ( \Exception $e ) {
					Helper::log( "Payment confirmation failed : " . $e->getMessage() );
					wc_add_notice( $e->getMessage(), 'error' );

				}
			}

			if ( $intent->amount > 0 ) {
				/** Use the last charge within the intent to proceed */
				$this->process_final_order( end( $intent->charges->data ), $order );
			} else {
				$order->payment_complete();
			}

			/** Empty cart */
			if ( isset( WC()->cart ) ) {
				WC()->cart->empty_cart();
			}

			/** Return thank you page redirect URL */
			return [
				'result'   => 'success',
				'redirect' => $return_url,
			];

		} catch ( \Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );

			/* translators: error message */
			$order->update_status( 'failed' );

			return [
				'result'   => 'fail',
				'redirect' => '',
			];
		}
	}

	/**
	 * override verify intent after confirm call for redirection.
	 *
	 */
	public function verify_intent() {
		global $woocommerce;

		$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
		try {
			$order_id = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order    = wc_get_order( $order_id );

			if ( ! isset( $_GET['order_key'] ) || ! $order instanceof \WC_Order || ! $order->key_is_valid( wc_clean( $_GET['order_key'] ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
            $this->save_payment_method( $order, $intent );
			if ( ! $order->has_status( apply_filters( 'fkwcs_stripe_allowed_payment_processing_statuses', [ 'pending', 'failed' ], $order ) ) ) {
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
				if ( ! is_null( WC()->cart ) ) {
					WC()->cart->empty_cart();
				}

			} else if ( 'succeeded' === $intent->status || 'requires_capture' === $intent->status ) {
				$redirect_url = $this->process_final_order( end( $intent->charges->data ), $order_id );
			} else if ( 'requires_payment_method' === $intent->status ) {


				$redirect_url = wc_get_checkout_url();
				wc_add_notice( __( 'Unable to process this payment, please try again or use alternative method.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );

				if ( isset( $_GET['wfacp_id'] ) && isset( $_GET['wfacp_is_checkout_override'] ) && 'no' === $_GET['wfacp_is_checkout_override'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$redirect_url = get_the_permalink( wc_clean( $_GET['wfacp_id'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
				$order->update_status( 'failed', $status_message );

			} else if ( 'pending' === $intent->status || 'processing' === $intent->status ) {
				$order_stock_reduced = Helper::get_meta( $order, '_order_stock_reduced' );

				if ( ! $order_stock_reduced ) {
					wc_reduce_stock_levels( $order_id );
				}

				$order->set_transaction_id( $intent->id );
				$others_info = __( 'Payment will be completed once payment_intent.succeeded webhook received from Stripe.', 'funnelkit-stripe-woo-payment-gateway' );

				/** translators: transaction id, other info */
				$order->update_status( 'on-hold', sprintf( __( 'Stripe charge awaiting payment: %1$s. %2$s', 'funnelkit-stripe-woo-payment-gateway' ), $intent->id, $others_info ) );

				$redirect_to = $this->get_return_url( $order );
				Helper::log( "Redirecting to :" . $redirect_to );

				wp_safe_redirect( $redirect_to );
				exit;
			}
			Helper::log( "Redirecting to :" . $redirect_url );
		} catch ( \Exception $e ) {
			$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
		}
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Controls the output on the my account page.
	 *
	 * @param array $item Individual list item from woocommerce_saved_payment_methods_list.
	 * @param \WC_Payment_Token $token The payment token associated with this method entry.
	 *
	 * @return array $item
	 */
	public function get_saved_payment_methods_list( $item, $token ) {
		if ( 'fkwcs_stripe_ach' === strtolower( $token->get_type() ) ) {
			$item['method']['last4'] = $token->get_last4();
			$item['method']['brand'] = $token->get_bank_name();
		}

		return $item;
	}

	/**
	 * Process payment method functionality
	 *
	 * @return array|void
	 */
	public function add_payment_method() {
		$source_id = '';

		if ( empty( $_POST['fkwcs_source'] ) || ! is_user_logged_in() ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
			//phpcs:ignore WordPress.Security.NonceVerification.Missing
			$error_msg = __( 'There was a problem adding the payment method.', 'funnelkit-stripe-woo-payment-gateway' );
			/* translators: error msg */
			Helper::log( sprintf( 'Add payment method Error: %1$1s', $error_msg ) );

			return;
		}

		$customer_id = $this->get_customer_id();

		$source = wc_clean( wp_unslash( $_POST['fkwcs_source'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing

		$stripe_api    = $this->get_client();
		$response      = $stripe_api->payment_methods( 'retrieve', [ $source ] );
		$source_object = $response['success'] ? $response['data'] : false;

		if ( isset( $source_object ) ) {
			if ( ! empty( $source_object->error ) ) {
				$error_msg = __( 'Invalid stripe source', 'funnelkit-stripe-woo-payment-gateway' );
				wc_add_notice( $error_msg, 'error' );
				/* translators: error msg */
				Helper::log( sprintf( 'Add payment method Error: %1$1s', $error_msg ) );

				return;
			}

			$source_id = $source_object->id;
		}

		$response = $stripe_api->payment_methods( 'attach', [ $source_id, [ 'customer' => $customer_id ] ] );
		$response = $response['success'] ? $response['data'] : false;
		$user     = wp_get_current_user();
		$user_id  = ( $user->ID && $user->ID > 0 ) ? $user->ID : false;
		$is_live  = ( 'live' === $this->test_mode ) ? true : false;
		$this->create_payment_token_for_user( $user_id, $source_object, $is_live );

		if ( ! $response || is_wp_error( $response ) || ! empty( $response->error ) ) {
			$error_msg = __( 'Unable to attach payment method to customer', 'funnelkit-stripe-woo-payment-gateway' );
			wc_add_notice( $error_msg, 'error' );
			/* translators: error msg */
			Helper::log( sprintf( 'Add payment method Error: %1$1s', $error_msg ) );

			return;
		}

		do_action( 'fkwcs_add_payment_method_' . ( isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : '' ) . '_success', $source_id, $source_object ); //phpcs:ignore WordPress.Security.NonceVerification.Missing
		Helper::log( 'New payment method added successfully' );

		return [
			'result'   => 'success',
			'redirect' => wc_get_endpoint_url( 'payment-methods' ),
		];
	}

	/**
	 * Tokenize Bank payment
	 *
	 * @param int $user_id id of current user placing .
	 * @param object $payment_method payment method object.
	 *
	 * @return object token object.
	 *
	 */
	public function create_payment_token_for_user( $user_id, $payment_method, $is_live ) {
		global $wpdb;
		$token_exists = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_payment_tokens where token =%s", $payment_method->id ), ARRAY_A );
		if ( ! empty( $token_exists ) ) {

			$token_obj = \WC_Payment_Tokens::get( $token_exists[0]['token_id'] );
			$token_obj->set_gateway_id($this->id );
			$token_obj->save();
			if ( ! is_null( $token_obj ) ) {
				return $token_obj;
			}
		}
		$token = new ACHToken();
		$token->set_last4( $payment_method->us_bank_account->last4 );
		$token->set_bank_name( $payment_method->us_bank_account->bank_name );
		$token->set_gateway_id( $this->id );
		$token->set_token( $payment_method->id );
		$token->set_user_id( $user_id );
		$token->update_meta_data( 'mode', ( $is_live ) ? 'live' : 'test' );
		$token->save_meta_data();
		$token->save();

		return $token;
	}

	public function payment_fields() {
		global $wp;
		$total = WC()->cart->total;

		// Check if tokenization is enabled and user is logged in
		$display_tokenization = $this->supports( 'tokenization' ) && is_checkout() && 'yes' === $this->enable_saved_cards && is_user_logged_in();

		/** If paying from order, get total from order instead of cart */
		if ( isset( $_GET['pay_for_order'] ) && ! empty( $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order = wc_get_order( wc_clean( $wp->query_vars['order-pay'] ) );
			$total = $order->get_total();
		}

		if ( is_add_payment_method_page() ) {
			$total = '';
		}

		echo '<div
        id="fkwcs_stripe_ach-payment-data"
        data-amount="' . esc_attr( Helper::get_stripe_amount( $total ) ) . '"
        data-currency="' . esc_attr( strtolower( get_woocommerce_currency() ) ) . '">';

		if ( $display_tokenization ) {
			$tokens = $this->get_tokens();
			if ( count( $tokens ) > 0 ) {
				$this->saved_payment_methods();
			}
		}

		$this->payment_form();

		if ( apply_filters( 'fkwcs_stripe_ach_display_save_payment_method_checkbox', $display_tokenization ) && ! is_add_payment_method_page() && ! isset( $_GET['change_payment_method'] ) ) {// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->save_payment_method_checkbox();
		}

		do_action( 'fkwcs_stripe_payment_fields_stripe_ach', $this->id );
		echo '</div>';
	}

	public function payment_form() {
		?>
        <fieldset id="<?php echo esc_attr( $this->id ); ?>-form" class="wc-payment-form fkwcs_stripe_ach_payment_form">
            <div id="fkwcs_stripe_ach_form" class="fkwcs_stripe_ach_form"></div>
            <!-- Used to display form errors -->
            <div class="clear"></div>
            <div class="fkwcs_stripe_ach_error fkwcs-error-text" role="alert"></div>
            <div class="clear"></div>
        </fieldset>
		<?php
	}

	public function get_tokens() {
		$tokens = [];

		try {
			if ( is_user_logged_in() && $this->supports( 'tokenization' ) ) {
				$tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), $this->id );
			}
		} catch ( \Throwable $e ) {
			Helper::log( sprintf( 'Error retrieving ACH tokens: %s', $e->getMessage() ), 'error' );
			// Return empty array on error to prevent breaking functionality
		}

		return $tokens;
	}

	/**
	 * Look for saved token
	 *
	 * @return \WC_Payment_Token|null
	 */
	public function find_saved_token() {
		$payment_method = isset( $_POST['payment_method'] ) && ! is_null( $_POST['payment_method'] ) ? wc_clean( $_POST['payment_method'] ) : null; //phpcs:ignore WordPress.Security.NonceVerification.Missing

		$token_request_key = 'wc-' . $payment_method . '-payment-token';
		if ( ! isset( $_POST[ $token_request_key ] ) || 'new' === wc_clean( $_POST[ $token_request_key ] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
			return null;
		}

		$token = WC_Payment_Tokens::get( wc_clean( $_POST[ $token_request_key ] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $token || $payment_method !== $token->get_gateway_id() || $token->get_user_id() !== get_current_user_id() ) {
			return null;
		}

		return $token;
	}

	public function is_using_saved_payment_method() {
		$payment_method = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : $this->id; //phpcs:ignore WordPress.Security.NonceVerification.Missing

		return ( isset( $_POST[ 'wc-' . $payment_method . '-payment-token' ] ) && 'new' !== wc_clean( $_POST[ 'wc-' . $payment_method . '-payment-token' ] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * After verify intent got called its time to save payment method to the order
	 *
	 * @param $order
	 * @param $intent
	 *
	 * @return void
	 */
	public function save_payment_method( $order, $intent ) {


		$payment_method = $intent->payment_method;
		$response       = $this->get_client()->payment_methods( 'retrieve', [ $payment_method ] );
		$payment_method = $response['success'] ? $response['data'] : false;

		$token = null;
		$user  = $order->get_id() ? $order->get_user() : wp_get_current_user();
		if ( $user instanceof \WP_User ) {
			$user_id = $user->ID;
			$token   = $this->create_payment_token_for_user( $user_id, $payment_method, $this->id, $intent->livemode );

			Helper::log( sprintf( 'Payment method tokenized for Order id - %1$1s with token id - %2$2s', $order->get_id(), $token->get_id() ) );
		}

		$prepared_payment_method = Helper::prepare_payment_method( $payment_method, $token );
		$this->save_payment_method_to_order( $order, $prepared_payment_method );
	}

	/**
	 * Checks if current page supports express checkout
	 *
	 * @return boolean
	 */
	public function is_page_supported() {

		return is_checkout() || isset( $_GET['pay_for_order'] ) || is_add_payment_method_page(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function localize_element_data( $data ) {

		if ( ! isset( WC()->cart ) ) {
			return $data;
		}
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id                    = isset( $_GET['key'] ) ? wc_get_order_id_by_order_key( sanitize_text_field( $_GET['key'] ) ) : 0; // @codingStandardsIgnoreLine
			$order                       = wc_get_order( $order_id );
			$fkwcs_order_total           = $order->get_total();
			$data['fkwcs_paylater_data'] = [
				'currency' => strtolower( get_woocommerce_currency() ),
				'amount'   => $fkwcs_order_total * 100
			];
		} else {
			$order_total                 = WC()->cart->get_total( false );
			$data['fkwcs_paylater_data'] = [
				'currency' => strtolower( get_woocommerce_currency() ),
				'amount'   => max( 0, apply_filters( 'fkwcs_stripe_calculated_total', Helper::get_formatted_amount( $order_total ), $order_total, WC()->cart ) )
			];

		}
		$data['fkwcs_ach_payment_data'] = $this->payment_element_data( $this->payment_method_types );

		return $data;
	}

	public function get_element_options() {

		$order_amount = WC()->cart->get_total( 'edit' );
		$amount       = Helper::get_minimum_amount();
		if ( $order_amount >= $amount ) {
			$amount = $order_amount;
		}


		return array(
			"mode"               => "payment",
			"currency"           => strtolower( $this->get_currency() ),
			"setup_future_usage" => 'off_session',
			"amount"             => Helper::get_formatted_amount( $amount ), //keeping it as sample
		);
	}


	public function payment_element_data() {

		$data    = $this->get_payment_element_options();
		$methods = [ $this->payment_method_types ];
		if ( isset( $this->settings['link_none'] ) && 'yes' !== $this->settings['link_none'] ) {
			$methods = $this->get_payment_method_types();
		}

		$data['payment_method_types'] = apply_filters( 'fkwcs_available_payment_element_types', $methods );

		return apply_filters( 'fkwcs_stripe_ach_payment_element_data', [ 'element_data' => $data ], $this );

	}
}