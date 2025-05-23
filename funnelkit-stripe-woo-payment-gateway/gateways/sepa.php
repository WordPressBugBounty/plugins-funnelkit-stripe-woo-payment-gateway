<?php

namespace FKWCS\Gateway\Stripe;

use WC_Payment_Tokens;
use FKWCS\Gateway\Stripe\Traits\WC_Subscriptions_Trait;
use Exception;
use WC_AJAX;

#[\AllowDynamicProperties]
class Sepa extends Abstract_Payment_Gateway {

	use WC_Subscriptions_Trait;

	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe_sepa';
	public $payment_method_types = 'sepa_debit';
	protected $payment_element = true;
	private static $instance = null;

	public function __construct() {
		parent::__construct();
		$this->init_supports();
	}

	/**
	 * @return Sepa gateway instance
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Setup general properties and settings
	 *
	 * @return void
	 */
	protected function init() {
		$this->method_title       = __( 'Stripe SEPA', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Accepts payments via SEPA. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>EUR</strong>', 'funnelkit-stripe-woo-payment-gateway' );

		$this->subtitle = __( 'The single euro payments area (SEPA) harmonises the way cashless euro payments are made across Europe.', 'funnelkit-stripe-woo-payment-gateway' );

		$this->has_fields = true;
		$this->init_form_fields();
		$this->init_settings();
		$this->maybe_init_subscriptions();
		$this->inline_cc          = $this->get_option( 'inline_cc' );
		$this->title              = $this->get_option( 'title' );
		$this->description        = $this->get_option( 'description' );
		$this->enabled            = $this->get_option( 'enabled' );
		$this->enable_saved_cards = $this->get_option( 'enable_saved_cards' );
		$this->allowed_cards      = empty( $this->get_option( 'allowed_cards' ) ) ? [ 'mastercard', 'visa', 'diners', 'discover', 'amex', 'jcb', 'unionpay' ] : $this->get_option( 'allowed_cards' );

		$this->payment_conform = true;

		add_filter( 'woocommerce_payment_methods_list_item', [ $this, 'get_saved_payment_methods_list' ], 10, 2 );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_stripe_js' ] );



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
		if ( 'fkwcs_stripe_sepa' === strtolower( $token->get_type() ) ) {
			$item['method']['last4'] = $token->get_last4();
			$item['method']['brand'] = esc_html__( 'SEPA IBAN', 'funnelkit-stripe-woo-payment-gateway' );
		}

		return $item;
	}

	/**
	 * Registers supported filters for payment gateway
	 *
	 * @return void
	 */
	public function init_supports() {
		$this->supports = apply_filters( 'fkwcs_sepa_payment_supports', array_merge( $this->supports, [
			'products',
			'refunds',
			'tokenization',
			'add_payment_method'
		] ) );
	}

	/**
	 * Add hooks
	 *
	 * @return void
	 */
	protected function filter_hooks() {
		add_filter( 'woocommerce_payment_successful_result', [ $this, 'modify_successful_payment_result' ], 999, 2 );
	}

	/**
	 * Initialise gateway settings form fields
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$settings = apply_filters( 'fkwcs_sepa_payment_form_fields', [


			'enabled'            => [
				'title'       => __( 'Enable/Disable', 'funnelkit-stripe-woo-payment-gateway' ),
				'label'       => __( 'Enable Stripe SEPA Direct Debit', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			],
			'title'              => [
				'title'       => __( 'Title', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => __( 'SEPA Direct Debit', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc_tip'    => true,
			],
			'description'        => [
				'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => __( 'Mandate Information', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc_tip'    => true,
			],
			'enable_saved_cards' => [
				'label'       => __( 'Enable Payment via Saved IBAN', 'funnelkit-stripe-woo-payment-gateway' ),
				'title'       => __( 'Saved IBAN', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Save IBAN details for future orders', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			],
			'company_name'       => [
				'title'       => __( 'Company Name', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'default'     => get_bloginfo( 'name' ),
				'desc_tip'    => true,
				'description' => __( 'The name of your company that will appear in the SEPA mandate info.', 'funnelkit-stripe-woo-payment-gateway' ),
			],
		] );

		$this->form_fields = apply_filters( 'fkwcs_ideal_payment_form_fields', array_merge( $settings, $this->get_countries_admin_fields( 'all', [], [] ) ) );
	}

	/**
	 * Returns all supported currencies for this payment method
	 *
	 * @return array
	 */
	public function get_supported_currency() {
		return apply_filters( 'fkwcs_stripe_sepa_supported_currencies', [
			'EUR',
		] );
	}

	/**
	 * Checks to see if all criteria is met before showing payment method.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->is_available_local_gateway();
	}

	/**
	 * Get gateway icon
	 *
	 * @return mixed|string|null
	 */
	public function get_icon() {
		$icons     = $this->payment_icons();
		$icons_str = '';
		$icons_str .= ! empty( $icons['sepa'] ) ? $icons['sepa'] : '';

		return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
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
			id="fkwcs_stripe-sepa_debit-payment-data"
			data-amount="' . esc_attr( Helper::get_stripe_amount( $total ) ) . '"
			data-currency="' . esc_attr( strtolower( get_woocommerce_currency() ) ) . '">';

		if ( $display_tokenization ) {
			$tokens = $this->get_tokens();
			if ( count( $tokens ) > 0 ) {
				$this->saved_payment_methods();
			}
		}

		$this->payment_form();

		if ( apply_filters( 'fkwcs_stripe_sepa_display_save_payment_method_checkbox', $display_tokenization ) && ! is_add_payment_method_page() && ! isset( $_GET['change_payment_method'] ) ) {  //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->save_payment_method_checkbox();
		}

		if ( $this->test_mode ) {
			echo '<div class="fkwcs-test-description"><p>';
			esc_js( $this->get_test_mode_description() );
			echo '</p></div>';
		}
		do_action( 'fkwcs_stripe_payment_fields_stripe_sepa', $this->id );

		echo '</div>';
	}

	/**
	 * Process WooCommerce checkout payment
	 *
	 * @param $order_id Int Order ID
	 * @param $retry
	 * @param $force_prevent_source_creation
	 * @param $previous_error
	 * @param $use_order_source
	 *
	 * @return array|mixed|string[]|void|null
	 */
	public function process_payment( $order_id, $retry = true, $force_prevent_source_creation = false, $previous_error = false, $use_order_source = false ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedParameter,VariableAnalysis.CodeAnalysis.VariableAnalysis
		do_action( 'fkwcs_before_process_payment', $order_id );

		$force_save_source = false;
		$order             = wc_get_order( $order_id );

		if ( $this->maybe_change_subscription_payment_method( $order_id ) ) {
			return $this->process_change_subscription_payment_method( $order_id, true );
		}
		if ( 0 >= $order->get_total() ) {
			return $this->process_change_subscription_payment_method( $order_id );

		}
		if ( $this->is_using_saved_payment_method() ) {
			return $this->process_payment_using_saved_token( $order_id );
		}

		try {
			$order = wc_get_order( $order_id );
			if ( $this->should_save_card( $order ) ) {
				$force_save_source = true;
			}
			$customer_id     = $this->get_customer_id( $order );
			$idempotency_key = $order->get_order_key() . time();

			$data = [
				'amount'               => Helper::get_stripe_amount( $order->get_total() ),
				'currency'             => $this->get_currency(),
				'description'          => $this->get_order_description( $order ),
				'metadata'             => $this->get_metadata( $order_id ),
				'payment_method_types' => [ 'sepa_debit' ],
				'customer'             => $customer_id
			];

			$data['metadata'] = $this->add_metadata( $order );
			$data             = $this->set_shipping_data( $data, $order );
			if ( $force_save_source ) {
				$data['setup_future_usage'] = 'off_session';
			}
			Helper::log( sprintf( __( 'Begin processing payment with SEPA for order %1$1s for the amount of %2$2s', 'funnelkit-stripe-woo-payment-gateway' ), $order_id, $order->get_total() ) );
			$data['payment_method'] = isset( $_POST['fkwcs_source'] ) ? wc_clean( $_POST['fkwcs_source'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing
			$intent_data            = $this->get_payment_intent( $order, $idempotency_key, $data );

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
		} catch ( Exception $e ) {
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

			$request = [
				'payment_method'       => $payment_method->id,
				'payment_method_types' => [ $this->payment_method_types ],
				'amount'               => Helper::get_stripe_amount( $order->get_total() ),
				'currency'             => strtolower( $order->get_currency() ),
				'description'          => $this->get_order_description( $order ),
				'customer'             => $payment_method->customer,
			];
			$request['metadata'] = $this->add_metadata( $order );
			$request             = $this->set_shipping_data( $request, $order );
			$intent = $this->make_payment_by_source( $order, $prepared_payment_method, $request );

			$this->save_intent_to_order( $order, $intent );


			if ( 'requires_confirmation' === $intent->status || 'requires_action' === $intent->status ) {
				return apply_filters( 'fkwcs_card_payment_return_intent_data', [
					'result'              => 'success',
					'token'               => 'yes',
					'fkwcs_redirect'      => $return_url,
					'payment_method'      => $intent->id,
					'fkwcs_intent_secret' => $intent->client_secret,
				] );
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
			$this->mark_order_failed( $order, $e->getMessage() );

			return [
				'result'   => 'fail',
				'redirect' => '',
			];
		}
	}


	/**
	 * Verify intent secret and redirect to the thankyou page
	 *
	 * @return void
	 * @throws \WC_Data_Exception
	 */
	public function verify_intent() {

		$order_id = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = wc_get_order( $order_id );

		if ( ! isset( $_GET['order_key'] ) || ! $order instanceof \WC_Order || ! $order->key_is_valid( wc_clean( $_GET['order_key'] ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			throw new \Exception( esc_html__( 'Invalid Order Key.', 'funnelkit-stripe-woo-payment-gateway' ) );

		}


		$intent = $this->get_intent_from_order( $order );

		if ( ! $intent ) {
			return;
		}

		if ( isset( $_GET['save_card'] ) || 'off_session' === $intent->setup_future_usage ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->save_payment_method( $order, $intent );
		}

		if ( 'setup_intent' === $intent->object && 'succeeded' === $intent->status ) {
			$order->payment_complete();
			$redirect_url = $this->get_return_url( $order );

			/**
			 * Remove the webhook paid meta data from the order
			 * This is to avoid any extra processing of this order
			 */
			$order->delete_meta_data( '_fkwcs_webhook_paid' );
			$order->save_meta_data();

			// Remove cart.
			if ( ! is_null( WC()->cart ) ) {
				WC()->cart->empty_cart();
			}
			wp_safe_redirect( $redirect_url );
			exit;

		} else if ( 'succeeded' === $intent->status || 'requires_capture' === $intent->status ) {
			$redirect_to = $this->process_final_order( end( $intent->charges->data[0] ), $order_id );
			wp_safe_redirect( $redirect_to );
			exit;
		} else if ( 'requires_payment_method' === $intent->status ) {


			$redirect_url = wc_get_checkout_url();
			wc_add_notice( __( 'Unable to process this payment, please try again or use alternative method.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );

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


		if ( 'pending' === $intent->status || 'processing' === $intent->status ) {
			$order_stock_reduced = Helper::get_meta( $order, '_order_stock_reduced' );

			if ( ! $order_stock_reduced ) {
				wc_reduce_stock_levels( $order_id );
			}

			$order->set_transaction_id( $intent->id );
			$others_info = __( 'Payment will be completed once payment_intent.succeeded webhook received from Stripe.', 'funnelkit-stripe-woo-payment-gateway' );

			/** translators: transaction id, other info */
			$order->update_status( 'on-hold', sprintf( __( 'Stripe charge awaiting payment: %1$s. %2$s', 'funnelkit-stripe-woo-payment-gateway' ), $intent->id, $others_info ) );

			do_action( 'fkwcs_'.$this->id.'_before_redirect', $order_id );

			$redirect_to = $this->get_return_url( $order );
			Helper::log( "Redirecting to :" . $redirect_to );

			wp_safe_redirect( $redirect_to );
			exit;
		}

		exit();
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

			$order->add_order_note( __( 'Payment Status: ', 'funnelkit-stripe-woo-payment-gateway' ) . ucfirst( $response->status ) . ', ' . __( 'Source: Payment is Completed via ', 'funnelkit-stripe-woo-payment-gateway' ) . $response->payment_method_details->card->brand );
			$order->add_order_note( __( 'Charge ID ' . $response->id ) );
		} else {
			/* translators: transaction id */
			$order->update_status( 'on-hold', sprintf( __( 'Charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization. Attempting to refund the order in part or in full will release the authorization and cancel the payment.', 'funnelkit-stripe-woo-payment-gateway' ), $response->id ) );
			/* translators: transaction id */
			Helper::log( sprintf( 'Charge authorized Order id - %1s', $order->get_id() ) );
		}
		if ( ! is_null( WC()->cart ) ) {
			WC()->cart->empty_cart();
		}
		do_action( 'fkwcs_'.$this->id.'_before_redirect', $order_id );
		$return_url = $this->get_return_url( $order );
		Helper::log( "Return URL3: $return_url" );

		return $return_url;
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
	 * Renders the Stripe elements form.
	 *
	 * @return void
	 */
	public function payment_form() {
		// translators: %s: company name.
		$description = sprintf( __( 'By providing your IBAN and confirming this payment, you are authorizing %s and Stripe, our payment service provider, to send instructions to your bank to debit your account and your bank to debit your account in accordance with those instructions. You are entitled to a refund from your bank under the terms and conditions of your agreement with your bank. A refund must be claimed within 8 weeks starting from the date on which your account was debited.', 'funnelkit-stripe-woo-payment-gateway' ), $this->get_option( 'company_name' ) );
		?>
        <fieldset id="<?php echo esc_attr( $this->id ); ?>-form" class="wc-payment-form fkwcs_stripe_sepa_payment_form">
            <div class="fkwcs-test-description">
                <p>
					<?php echo wpautop( wp_kses_post( $description ) ); //phpcs:ignore ?>
                </p>
            </div>
            <div class="form-row form-row-wide">
                <label for="fkwcs-sepa-stripe-iban-element">
					<?php esc_html_e( 'IBAN.', 'funnelkit-stripe-woo-payment-gateway' ); ?> <span class="required">*</span>
                </label>
                <div id="fkwcs_stripe_sepa_iban_element" class="fkwcs_stripe_sepa_iban_element_field">

                </div>
            </div>

            <!-- Used to display form errors -->
            <div class="clear"></div>
            <div class="fkwcs_stripe_sepa_error fkwcs-error-text" role="alert"></div>
            <div class="clear"></div>
        </fieldset>
		<?php
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
	 * Tokenize card payment
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
	 * Get test mode description
	 *
	 * @return string|null
	 */
	public function get_test_mode_description() {
		return __( 'TEST MODE ENABLED. In test mode, you can use IBAN number DE89370400440532013000.', 'funnelkit-stripe-woo-payment-gateway' );
	}

	public function get_tokens() {
		$tokens = [];

		if ( is_user_logged_in() && $this->supports( 'tokenization' ) ) {
			$tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), $this->id );
		}

		return $tokens;
	}

	/**
	 * Checks if current page supports express checkout
	 *
	 * @return boolean
	 */
	public function is_page_supported() {

		return  is_checkout() || isset( $_GET['pay_for_order'] ) || is_add_payment_method_page(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}
