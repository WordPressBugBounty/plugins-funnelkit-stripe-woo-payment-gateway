<?php

namespace FKWCS\Gateway\Stripe;

use WC_Payment_Tokens;
use FKWCS\Gateway\Stripe\Traits\WC_Subscriptions_Trait;
use function add_filter;
use function esc_html__;
use function wp_kses_post;
use function __;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

#[\AllowDynamicProperties]
class CashApp extends LocalGateway {

	use WC_Subscriptions_Trait;

	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe_cashapp';
	public $payment_method_types = 'cashapp';
	protected $payment_element = true;

	/**
	 * Setup general properties and settings
	 *
	 * @return void
	 */
	protected function init() {
		$this->method_title       = esc_html__( 'Stripe Cash App Pay Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = wp_kses_post( __( 'Accepts payments via Cash App Pay. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>USD</strong>', 'funnelkit-stripe-woo-payment-gateway' ) );
		$this->subtitle           = esc_html__( 'Cash App Pay is a payment method that enables your customers to pay using their Cash App balance', 'funnelkit-stripe-woo-payment-gateway' );
		$this->init_form_fields();
		$this->init_settings();
		$this->init_supports();
		$this->maybe_init_subscriptions();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
		$this->enable_saved_cards = $this->get_option( 'enable_saved_cards' );
		add_filter( 'woocommerce_payment_methods_list_item', [ $this, 'get_saved_payment_methods_list' ], 10, 2 );

		add_filter( 'fkwcs_localized_data', [ $this, 'localize_element_data' ], 999 );
	}

	protected function override_defaults() {
		$this->supported_currency          = [ 'USD' ];
		$this->specific_country            = [ 'US' ];
		$this->except_country              = [];
		$this->setting_enable_label        = __( 'Enable Cash app Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = __( 'Stripe Cash app Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = __( 'Pay with Cashapp', 'funnelkit-stripe-woo-payment-gateway' );

	}
	/**
	 * Initialise gateway settings form fields
	 *
	 * @return void
	 */
	public function init_form_fields() {


		$settings = [
			'enabled'     => [
				'label'   => ' ',
				'type'    => 'checkbox',
				'title'   => $this->setting_enable_label,
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Change the payment gateway title that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => $this->setting_title_default,
				'id'          => $this->setting_title_default,
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'textarea',
				'css'         => 'width:25em',
				'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'enable_saved_cards' => [
				'label'       => __( 'Enable Payment via Saved Cashapp', 'funnelkit-stripe-woo-payment-gateway' ),
				'title'       => __( 'Saved Cashapp', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Save Cashapp details for future orders', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			],

		];

		$this->form_fields = apply_filters( $this->id . '_payment_form_fields', array_merge( $settings, $this->get_countries_admin_fields( $this->selling_country_type, $this->except_country, $this->specific_country ) ) );
	}
	/**
	 * Registers supported filters for payment gateway
	 *
	 * @return void
	 */
	public function init_supports() {
		$this->supports = apply_filters( 'fkwcs_cashapp_payment_supports', array_merge( $this->supports, [
			'products',
			'refunds',
			'tokenization',
			'add_payment_method'
		] ) );
	}

	/**
	 * Process the payment
	 *
	 * @param int $order_id Reference.
	 *
	 * @return array|void
	 * @throws \Exception If payment will not be accepted.
	 *
	 */
	public function process_payment( $order_id ) {
		try {
			$order = wc_get_order( $order_id );

			// Get customer ID early
			$customer_id = $this->get_customer_id( $order );

			if ( $this->maybe_change_subscription_payment_method( $order_id ) ) {
				return $this->process_change_subscription_payment_method( $order_id, true );
			}

			if ( 0 >= $order->get_total() ) {
				// Save customer ID to order
				$order->update_meta_data( '_fkwcs_customer_id', $customer_id );
				$order->save_meta_data();

				if ( $this->is_using_saved_payment_method() ) {
					$token = $this->find_saved_token();
					if ( $token ) {
						$stripe_api = $this->get_client();
						$response = $stripe_api->payment_methods( 'retrieve', [ $token->get_token() ] );
						$payment_method = $response['success'] ? $response['data'] : false;

						if ( $payment_method ) {
							$prepared_payment_method = Helper::prepare_payment_method( $payment_method, $token );
							$this->save_payment_method_to_order( $order, $prepared_payment_method );
							
							if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
								$subscriptions = wcs_get_subscriptions_for_order( $order_id );
								foreach ( $subscriptions as $subscription ) {
									$subscription->update_meta_data( '_fkwcs_customer_id', $customer_id );
									$subscription->update_meta_data( '_fkwcs_source_id', $token->get_token() );
									$subscription->save_meta_data();
								}
							}

							$order->payment_complete();
							WC()->cart->empty_cart();

							return array(
								'result'   => 'success',
								'redirect' => $this->get_return_url( $order ),
							);
						}
					}
				}

				return $this->process_change_subscription_payment_method( $order_id );
			}

			if ( $this->is_using_saved_payment_method() ) {
				return $this->process_payment_using_saved_token( $order_id );
			}

			/** This will throw exception if not valid */
			$this->validate_minimum_order_amount( $order );

			$this->prepare_source( $order, true );
			// No payment method, create a payment intent
			$idempotency_key = $order->get_order_key() . time();
			$data            = [
				'amount'               => Helper::get_formatted_amount( $order->get_total() ),
				'currency'             => $this->get_currency(),
				'description'          => $this->get_order_description( $order ),
				'metadata'             => $this->get_metadata( $order_id ),
				'payment_method_types' => [ $this->payment_method_types ],
				'customer'             => $customer_id,
				'capture_method'       => $this->capture_method,
				'confirmation_method'  => 'automatic',
				'confirm'              => false,
				'setup_future_usage'   => 'off_session',
			];

			$data['metadata'] = $this->add_metadata( $order );
			$data             = $this->set_shipping_data( $data, $order, $this->shipping_address_required );

			$intent_data = $this->get_payment_intent( $order, $idempotency_key, $data );

			Helper::log( sprintf( esc_html__( 'Begin processing payment with %1$1s for order %2$2s for the amount of %3$3s', 'funnelkit-stripe-woo-payment-gateway' ), $this->get_title(), $order_id, $order->get_total() ) );
			if ( $intent_data ) {
				/**
				 * @see modify_successful_payment_result()
				 * This modifies the final response return in WooCommerce process checkout request
				 */
				return [
					'result'              => 'success',
					'fkwcs_redirect'      => $this->get_return_url( $order ),
					'fkwcs_intent_secret' => $intent_data->client_secret,
				];
			}

			return [
				'result'   => 'fail',
				'redirect' => '',
			];

		} catch ( \Exception $e ) {
			Helper::log( $e->getMessage(), 'warning' );
			wc_add_notice( $e->getMessage(), 'error' );
		}
	}


	/**
	 * Add Cash App Pay-specific payment element data to localized frontend data.
	 *
	 * Appends Cash App Pay payment configuration to the localized data array
	 * that gets passed to frontend JavaScript for Stripe Elements integration.
	 *
	 * @param array $data Existing localized data array
	 *
	 * @return array Modified data array with Cash App Pay payment element data
	 * @since 1.0.0
	 */
	public function localize_element_data( $data ) {
		if ( ! $this->is_available() ) {
			return $data;
		}
		$data['fkwcs_payment_data_cashapp'] = $this->payment_element_data();

		return $data;
	}

	/**
	 * Generate payment element configuration data for Cash App Pay.
	 *
	 * Creates the configuration array for Stripe Payment Elements specifically
	 * for Cash App Pay payments, including payment method types, appearance settings,
	 * and field configurations (billing details disabled, wallets disabled).
	 *
	 * @return array Payment element configuration with element_data and element_options
	 * @since 1.0.0
	 */
	public function payment_element_data() {
		$data    = $this->get_payment_element_options();
		$methods = [ 'cashapp' ];

		$data['payment_method_types'] = apply_filters( 'fkwcs_available_payment_element_types', $methods );
		$data['appearance']           = array(
			"theme" => "stripe"
		);
		$options                      = [
			'fields' => [
				'billingDetails' => 'never'
			]
		];
		$options['wallets']           = [ 'applePay' => 'never', 'googlePay' => 'never' ];

		return apply_filters( 'fkwcs_stripe_payment_element_data_cashapp', [ 'element_data' => $data, 'element_options' => $options ], $this );
	}

	public function process_payment_using_saved_token( $order_id ) {
		$order = wc_get_order( $order_id );

		try {
			$token = $this->find_saved_token();
			$stripe_api = $this->get_client();
			$response = $stripe_api->payment_methods( 'retrieve', [ $token->get_token() ] );
			$payment_method = $response['success'] ? $response['data'] : false;

			$prepared_payment_method = Helper::prepare_payment_method( $payment_method, $token );
			$this->save_payment_method_to_order( $order, $prepared_payment_method );

			$request = [
				'payment_method' => $payment_method->id,
				'payment_method_types' => [ $this->payment_method_types ],
				'amount' => Helper::get_formatted_amount( $order->get_total() ),
				'currency' => strtolower( $order->get_currency() ),
				'description' => $this->get_order_description( $order ),
				'customer' => $payment_method->customer,
				'confirmation_method' => 'automatic',
				'confirm' => true,
			];

			$request['metadata'] = $this->add_metadata( $order );
			$request = $this->set_shipping_data( $request, $order, $this->shipping_address_required );

			$intent = $this->make_payment_by_source( $order, $prepared_payment_method, $request );
			$this->save_intent_to_order( $order, $intent );

			// Handle intent status similar to SEPA gateway
			if ( 'requires_confirmation' === $intent->status || 'requires_action' === $intent->status ) {
				return [
					'result' => 'success',
					'token' => 'yes',
					'fkwcs_redirect' => $this->get_return_url( $order ),
					'payment_method' => $intent->id,
					'fkwcs_intent_secret' => $intent->client_secret,
				];
			}

			if ( $intent->amount > 0 ) {
				$this->process_final_order( end( $intent->charges->data ), $order );
			} else {
				$order->payment_complete();
			}

			if ( isset( WC()->cart ) ) {
				WC()->cart->empty_cart();
			}

			return [
				'result' => 'success',
				'redirect' => $this->get_return_url( $order ),
			];

		} catch ( \Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			$this->mark_order_failed( $order, $e->getMessage() );
			return [
				'result' => 'fail',
				'redirect' => '',
			];
		}
	}

	public function find_saved_token() {
		$payment_method = isset( $_POST['payment_method'] ) ? wc_clean( $_POST['payment_method'] ) : null;
		$token_request_key = 'wc-' . $payment_method . '-payment-token';

		if ( ! isset( $_POST[ $token_request_key ] ) || 'new' === wc_clean( $_POST[ $token_request_key ] ) ) {
			return null;
		}

		$token = WC_Payment_Tokens::get( wc_clean( $_POST[ $token_request_key ] ) );
		if ( ! $token || $payment_method !== $token->get_gateway_id() || $token->get_user_id() !== get_current_user_id() ) {
			return null;
		}

		return $token;
	}

	public function is_using_saved_payment_method() {
		$payment_method = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : $this->id;
		return ( isset( $_POST[ 'wc-' . $payment_method . '-payment-token' ] ) && 'new' !== wc_clean( $_POST[ 'wc-' . $payment_method . '-payment-token' ] ) );
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
			$is_live  = ( 'live' === $this->test_mode ) ? true : false;
			$token   = $this->create_payment_token_for_user( $user_id, $payment_method, $is_live );

			Helper::log( sprintf( 'Payment method tokenized for Order id - %1$1s with token id - %2$2s', $order->get_id(), $token->get_id() ) );
		}

		$prepared_payment_method = Helper::prepare_payment_method( $payment_method, $token );
		$this->save_payment_method_to_order( $order, $prepared_payment_method );
	}

	public function create_payment_token_for_user( $user_id, $payment_method, $is_live ) {
		global $wpdb;
		$token_exists = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_payment_tokens where token =%s", $payment_method->id ), ARRAY_A );

		if ( ! empty( $token_exists ) ) {
			$token_obj = \WC_Payment_Tokens::get( $token_exists[0]['token_id'] );
			if ( $token_obj ) {
				$token_obj->set_gateway_id($this->id );
				$token_obj->save();
				return $token_obj;
			}
		}

		$token = new CashAppToken();
		$token->set_gateway_id( $this->id );
		$token->set_token( $payment_method->id );
		$token->set_user_id( $user_id );
		$token->set_payment_method_type( 'cashapp' );
		$token->update_meta_data( 'mode', ( $is_live ) ? 'live' : 'test' );
		$token->save_meta_data();
		$token->save();

		return $token;
	}
	public function get_saved_payment_methods_list( $item, $token ) {

		if ( 'fkwcs_stripe_cashapp' === strtolower( $token->get_type() ) ) {

			$item['method']['brand'] = esc_html__( 'Cashapp Pay', 'funnelkit-stripe-woo-payment-gateway' );
		}

		return $item;
	}

	/**
	 * Print the payment form field
	 *
	 * @return void
	 */
	public function payment_fields() {
		global $wp;
		$total = WC()->cart->total;

		$display_tokenization = $this->supports( 'tokenization' ) && is_checkout() && 'yes' === $this->enable_saved_cards && is_user_logged_in();

		/** If paying from order, we need to get total from order not cart */
		if ( isset( $_GET['pay_for_order'] ) && ! empty( $_GET['key'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order = wc_get_order( wc_clean( $wp->query_vars['order-pay'] ) );
			$total = $order->get_total();
		}

		if ( is_add_payment_method_page() ) {
			$total = '';
		}

		echo '<div
        id="fkwcs_stripe-cashapp-payment-data"
        data-amount="' . esc_attr( Helper::get_formatted_amount( $total ) ) . '"
        data-currency="' . esc_attr( strtolower( get_woocommerce_currency() ) ) . '">';

		// Display description if set
		$description = $this->get_description();
		if ( $description ) {
			echo '<div class="fkwcs-test-description fkwcs_local_gateway_text">';
			echo wp_kses_post( apply_filters( 'fkwcs_cashapp_description', wpautop( wp_kses_post( $description ) ), $this->id ) );
			echo '</div>';
		}
		if ( $display_tokenization ) {
			$tokens = $this->get_tokens();
			if ( count( $tokens ) > 0 ) {
				$this->saved_payment_methods();
			}
		}

		$this->payment_form();


		if ( apply_filters( 'fkwcs_stripe_cashapp_display_save_payment_method_checkbox', $display_tokenization ) && ! is_add_payment_method_page() && ! isset( $_GET['change_payment_method'] ) ) {  //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->save_payment_method_checkbox();
		}

		if ( $this->test_mode ) {
			echo '<div class="fkwcs-test-description"><p>';
			echo esc_html( $this->get_test_mode_description() );
			echo '</p></div>';
		}

		do_action( 'fkwcs_stripe_payment_fields_stripe_cashapp', $this->id );

		echo '</div>';
	}
	/**
	 * Get test mode description
	 *
	 * @return string|null
	 */
	public function get_test_mode_description() {
		return __( 'TEST MODE ENABLED. Use test Cash App account for payments.', 'funnelkit-stripe-woo-payment-gateway' );
	}
	/**
	 * Renders the Cash App Pay payment form.
	 *
	 * @return void
	 */
	public function payment_form() {
		?>
        <fieldset id="<?php echo esc_attr( $this->id ); ?>-form" class="wc-payment-form <?php echo esc_attr( $this->id ); ?>_form fkwcs_stripe_cashapp_payment_form">
            <div class="form-row form-row-wide">
                <div id="fkwcs_stripe_cashapp_element" class="<?php echo esc_attr( $this->id ); ?>_select fkwcs_stripe_cashapp_select">
                    <!-- Cash App Pay element will be mounted here -->
                </div>
            </div>

            <!-- Used to display form errors -->
            <div class="clear"></div>
            <div class="fkwcs_stripe_cashapp_error fkwcs-error-text" role="alert"></div>
            <div class="clear"></div>
        </fieldset>
		<?php
	}
	/**
	 * Get saved payment tokens for current user
	 *
	 * @return array
	 */
	public function get_tokens() {
		$tokens = [];

		if ( is_user_logged_in() && $this->supports( 'tokenization' ) ) {
			$tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), $this->id );
		}

		return $tokens;
	}
	/**
	 * Change save payment method text for Cash App Pay
	 */
	public function save_payment_method_checkbox() {
		$html = sprintf(
			'<p class="form-row woocommerce-SavedPaymentMethods-saveNew">
				<input id="wc-%1$s-new-payment-method" name="wc-%1$s-new-payment-method" type="checkbox" value="true" style="width:auto;" />
				<label for="wc-%1$s-new-payment-method" style="display:inline;">%2$s</label>
			</p>',
			esc_attr( $this->id ),
			esc_html__( 'Save payment information to my account for future purchases.', 'funnelkit-stripe-woo-payment-gateway' )
		);
		/**
		 * Filter the saved payment method checkbox HTML
		 *
		 * @since 2.6.0
		 * @param string $html Checkbox HTML.
		 * @param WC_Payment_Gateway $this Payment gateway instance.
		 * @return string
		 */
		echo apply_filters( 'woocommerce_payment_gateway_save_new_payment_method_option_html', $html, $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	/**
	 * Process payment method functionality for add payment method page
	 *
	 * @return array|void
	 */
	public function add_payment_method() {
		$source_id = '';

		if ( empty( $_POST['fkwcs_source'] ) || ! is_user_logged_in() ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
			$error_msg = __( 'There was a problem adding the payment method.', 'funnelkit-stripe-woo-payment-gateway' );
			wc_add_notice( $error_msg, 'error' );
			Helper::log( sprintf( 'Add payment method Error: %1$1s', $error_msg ) );
			return;
		}

		$customer_id = $this->get_customer_id();
		$source = wc_clean( wp_unslash( $_POST['fkwcs_source'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing

		$stripe_api = $this->get_client();
		$response = $stripe_api->payment_methods( 'retrieve', [ $source ] );
		$source_object = $response['success'] ? $response['data'] : false;

		if ( isset( $source_object ) ) {
			if ( ! empty( $source_object->error ) ) {
				$error_msg = __( 'Invalid stripe source', 'funnelkit-stripe-woo-payment-gateway' );
				wc_add_notice( $error_msg, 'error' );
				Helper::log( sprintf( 'Add payment method Error: %1$1s', $error_msg ) );
				return;
			}
			$source_id = $source_object->id;
		}

		// Attach payment method to customer
		$response = $stripe_api->payment_methods( 'attach', [ $source_id, [ 'customer' => $customer_id ] ] );
		$response = $response['success'] ? $response['data'] : false;

		$user = wp_get_current_user();
		$user_id = ( $user->ID && $user->ID > 0 ) ? $user->ID : false;
		$is_live = ( 'live' === $this->test_mode ) ? true : false;

		// Create payment token for user
		$this->create_payment_token_for_user( $user_id, $source_object, $is_live );

		if ( ! $response || is_wp_error( $response ) || ! empty( $response->error ) ) {
			$error_msg = __( 'Unable to attach payment method to customer', 'funnelkit-stripe-woo-payment-gateway' );
			wc_add_notice( $error_msg, 'error' );
			Helper::log( sprintf( 'Add payment method Error: %1$1s', $error_msg ) );
			return;
		}

		do_action( 'fkwcs_add_payment_method_' . ( isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : '' ) . '_success', $source_id, $source_object ); //phpcs:ignore WordPress.Security.NonceVerification.Missing
		Helper::log( 'New Cash App Pay payment method added successfully' );

		return [
			'result'   => 'success',
			'redirect' => wc_get_endpoint_url( 'payment-methods' ),
		];
	}
}