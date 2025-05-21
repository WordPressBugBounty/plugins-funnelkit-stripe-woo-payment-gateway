<?php

namespace FKWCS\Gateway\Stripe;

use WC_Payment_Tokens;
use FKWCS\Gateway\Stripe\Traits\WC_Subscriptions_Trait;
use WC_HTTPS;

#[\AllowDynamicProperties]
class CreditCard extends Abstract_Payment_Gateway {

	use WC_Subscriptions_Trait;
	use Funnelkit_Stripe_Smart_Buttons;

	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe';
	public $token = false;
	public $payment_method_types = 'card';
	public $credit_card_form_type = 'card';
	protected $payment_element = true;
	private static $instance = null;
	public $supports_success_webhook = true;
	public $is_recursion = false;

	public function __construct() {
		parent::__construct();
		$this->init_supports();
	}

	/**
	 * @return CreditCard gateway instance
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

		$this->method_title       = __( 'Stripe Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( ' Accepts payments via Credit or Debit Cards. The gateway supports all popular Card brands. <br/>Use Allowed Card Brands to set up brands as per your choice. ', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle           = __( 'Let your customers pay with major credit and debit cards without leaving your store', 'funnelkit-stripe-woo-payment-gateway' );
		$this->has_fields         = true;
		$this->init_form_fields();
		$this->init_settings();
		$this->maybe_init_subscriptions();
		$this->title                 = $this->get_option( 'title' );
		$this->description           = $this->get_option( 'description' );
		$this->inline_cc             = $this->get_option( 'inline_cc' );
		$this->enabled               = $this->get_option( 'enabled' );
		$this->enable_saved_cards    = $this->get_option( 'enable_saved_cards' );
		$this->capture_method        = $this->get_option( 'charge_type' );
		$this->allowed_cards         = $this->get_option( 'allowed_cards' );
		$this->credit_card_form_type = 'yes' === $this->get_option( 'payment_form' ) ? 'payment' : '';

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_stripe_js' ] );
		add_filter( 'fkwcs_localized_data', [ $this, 'localize_element_data' ], 999 );


		add_action( 'woocommerce_get_customer_payment_tokens', [ $this, 'maybe_sync_gateway_tokens' ], 8, 3 );
		add_action( 'fkwcs_webhook_event_intent_succeeded', [ $this, 'handle_webhook_intent_succeeded' ], 10, 2 );

		add_action( 'woocommerce_payment_token_deleted', [ $this, 'detach_customer_token' ], 10, 2 );
		add_filter( 'woocommerce_gateway_title', function ( $title ) {
			global $theorder;

			if ( $theorder instanceof \WC_Order && $theorder->get_payment_method() === 'fkwcs_stripe' && ! empty( $theorder->get_payment_method_title() ) && ! did_action( 'woocommerce_admin_order_data_after_payment_info' ) ) {
				$title = $theorder->get_payment_method_title();
			}

			return $title;
		} );
		add_action( 'woocommerce_payment_token_set_default', [ $this, 'woocommerce_payment_token_set_default' ] );


	}

	/**
	 * Add hooks
	 *
	 * @return void
	 */
	protected function filter_hooks() {
		add_filter( 'woocommerce_payment_successful_result', [ $this, 'modify_successful_payment_result' ], 999, 2 );
		add_filter( 'woocommerce_update_order_review_fragments', [ $this, 'send_payment_options' ], 999 );

	}

	/**
	 * Registers supported filters for payment gateway
	 *
	 * @return void
	 */
	public function init_supports() {
		$this->supports = apply_filters( 'fkwcs_card_payment_supports', array_merge( $this->supports, [
			'products',
			'refunds',
			'tokenization',
			'add_payment_method'
		] ) );
	}

	/**
	 * Checks whether current page is supported for express checkout
	 *
	 * @return boolean
	 */
	public function is_page_supported() {
		return is_cart() || is_checkout() || isset( $_GET['pay_for_order'] ) || is_add_payment_method_page() || is_account_page(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Initialise gateway settings form fields
	 *
	 * @return void
	 */
	public function init_form_fields() {

		$this->form_fields = apply_filters( 'fkwcs_card_payment_form_fields', [
			'enabled'     => [
				'label'   => ' ',
				'type'    => 'checkbox',
				'title'   => __( 'Enable Stripe Gateway', 'funnelkit-stripe-woo-payment-gateway' ),
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Change the payment gateway title that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => __( 'Credit Card (Stripe)', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'textarea',
				'css'         => 'width:25em',
				'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => __( 'Pay with your credit card via Stripe', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc_tip'    => true,
			],

			'charge_type'           => [
				'title'       => __( 'Charge Type', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'select',
				'description' => __( 'Select how to charge Order', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => 'automatic',
				'options'     => [
					'automatic' => __( 'Charge', 'funnelkit-stripe-woo-payment-gateway' ),
					'manual'    => __( 'Authorize', 'funnelkit-stripe-woo-payment-gateway' ),
				],
				'desc_tip'    => true,
			],
			'enable_saved_cards'    => [
				'label'       => __( 'Enable Payment via Saved Cards', 'funnelkit-stripe-woo-payment-gateway' ),
				'title'       => __( 'Saved Cards', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Save card details for future orders', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			],
			'inline_cc'             => [
				'label'       => __( 'Enable Inline Credit Card Form', 'funnelkit-stripe-woo-payment-gateway' ),
				'title'       => __( 'Credit Card Form Style', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Use inline credit card for card payments', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => 'no',
				'desc_tip'    => true,
				'class'       => 'fkwcs_form_type_selection fkwcs_checkbox_radio',
			],
			'standard_payment_form' => [
				'label'       => __( 'Enable Standard Credit Card Form', 'funnelkit-stripe-woo-payment-gateway' ),
				'title'       => '&nbsp;',
				'type'        => 'checkbox',
				'description' => __( 'Use inline credit card for card payments', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => get_option( 'woocommerce_fkwcs_stripe_settings', false ) === false ? 'no' : 'yes',
				'desc_tip'    => true,
				'class'       => 'fkwcs_form_type_selection fkwcs_checkbox_radio',
			],
			'payment_form'          => [
				'label'       => __( 'Enable Enhanced Payment Element (Recommended)', 'funnelkit-stripe-woo-payment-gateway' ),
				'title'       => '&nbsp;',
				'type'        => 'checkbox',
				'description' => __( 'Use stripe payment elements for card payments', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => get_option( 'woocommerce_fkwcs_stripe_settings', false ) === false ? 'yes' : 'no',
				'desc_tip'    => true,
				'class'       => 'fkwcs_form_type_selection fkwcs_checkbox_radio',
			],
			'link_fields_wrapper'   => [

				'class' => 'link_fields_wrapper',
				'type'  => 'fkwcs_admin_fields_start',
				'value' => 'on_card'
			],
			'link_in_card_field'    => [
				'label'       => __( 'Enable in Card Field', 'funnelkit-stripe-woo-payment-gateway' ),
				'title'       => __( 'Stripe Link Authentication', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'checkbox',
				'field_type'  => 'radio',
				'description' => __( "This setting enables Link in Card Element. By activating the Link feature, Stripe leverages your customer's email address to ascertain whether they have previously utilized Stripe services. In the affirmative, their payment details, along with billing and shipping information, are automatically employed to populate the checkout page. This streamlined process not only enhances conversion rates but also minimizes customer friction. Enabling Link ensures that the Stripe payment form is utilized exclusively, as it is the sole card form compatible with this feature.", 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => 'no',
				'desc_tip'    => true,
				'class'       => 'fkwcs_checkbox_radio fkwcs_link_type_selection',
				'value'       => 'on_card'
			],

			'link_authentication' => [
				'label'       => __( 'Enable on Billing Email Field', 'funnelkit-stripe-woo-payment-gateway' ),
				'title'       => '&nbsp;',
				'type'        => 'checkbox',
				'field_type'  => 'radio',
				'description' => __( "This setting enabled Link on Billing Email field. As soon as user types email the Link begins to detects to check if Stripe has a saved profile. By activating the Link feature, Stripe leverages your customer's email address to ascertain whether they have previously utilized Stripe services. In the affirmative, their payment details, along with billing and shipping information, are automatically employed to populate the checkout page. This streamlined process not only enhances conversion rates but also minimizes customer friction. Enabling Link ensures that the Stripe payment form is utilized exclusively, as it is the sole card form compatible with this feature.", 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => 'no',
				'desc_tip'    => true,
				'class'       => 'fkwcs_checkbox_radio fkwcs_link_type_selection',
				'value'       => 'on_email'
			],
			'link_none'           => [
				'label'       => __( 'None', 'funnelkit-stripe-woo-payment-gateway' ),
				'title'       => '&nbsp;',
				'type'        => 'checkbox',
				'field_type'  => 'radio',
				'description' => '',
				'default'     => 'yes',
				'desc_tip'    => true,
				'class'       => 'fkwcs_checkbox_radio fkwcs_link_type_selection',
				'value'       => 'yes'
			],


			'link_fields_wrapper_end' => [
				'class' => 'link_fields_wrapper',
				'type'  => 'fkwcs_admin_fields_end',
			],

			'allowed_cards' => [
				'title'    => __( 'Allowed Card Brands', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'     => 'multiselect',
				'class'    => 'fkwcs_select_woo',
				'desc_tip' => __( 'Accepts payments using selected cads. Please select atleast one card brand.', 'funnelkit-stripe-woo-payment-gateway' ),
				'options'  => [
					'mastercard' => 'MasterCard',
					'visa'       => 'Visa',
					'amex'       => 'American Express',
					'discover'   => 'Discover',
					'jcb'        => 'JCB',
					'diners'     => 'Diners Club',
					'unionpay'   => 'UnionPay',
				],
				'default'  => [ 'mastercard', 'visa', 'amex', 'discover', 'jcb', 'dinners', 'unionpay' ],
			]
		] );
	}

	/**
	 * Process WooCommerce checkout payment
	 *
	 * @param $order_id Int Order ID
	 * @param $retry  Boolean
	 * @param $force_prevent_source_creation  Boolean
	 * @param $previous_error
	 * @param $use_order_source
	 *
	 * @return array|mixed|string[]|\WP_Error|null
	 * @throws \Exception
	 */
	public function process_payment( $order_id, $retry = true, $force_prevent_source_creation = false, $previous_error = false, $use_order_source = false ) {
		do_action( 'fkwcs_before_process_payment', $order_id );
		Helper::log( 'Entering::' . __FUNCTION__ );

		if ( $this->maybe_change_subscription_payment_method( $order_id ) ) {
			return $this->process_change_subscription_payment_method( $order_id, true );
		}
		$order = wc_get_order( $order_id );
		/**
		 * If save a source and not a valid country found make it false
		 */
		if ( false === $force_prevent_source_creation && true === $this->should_save_card( $order ) && $this->validate_country_for_save_card() ) {
			$save_source = true;
		} else {
			$save_source = false;
		}


		if ( 0 >= $order->get_total() ) {
			return $this->process_change_subscription_payment_method( $order_id );

		}

		if ( $this->is_using_saved_payment_method() ) {
			return $this->process_payment_using_saved_token( $order_id );
		}

		try {
			if ( $use_order_source ) {
				/**
				 * Process subscription renewals
				 */
				$prepared_source = $this->prepare_order_source( $order );
			} else {
				$prepared_source = $this->prepare_source( $order, $save_source );
			}

			if ( is_object( $prepared_source ) && empty( $prepared_source->source ) ) {
				if ( ! empty( $order ) ) {
					/* translators: error message */
					$this->mark_order_failed( $order, __( 'Error: Unable to get payment method from the browser, please check for browser console error. ', 'funnelkit-stripe-woo-payment-gateway' ) );
					throw new \Exception( __( 'Payment processing failed. Please retry.' ), 200 );
				}

			}
			$this->save_payment_method_to_order( $order, $prepared_source );

			$this->validate_minimum_order_amount( $order );

			/**
			 * Prepare Data for the API Call
			 */
			$data = [
				'amount'               => Helper::get_stripe_amount( $order->get_total() ),
				'currency'             => get_woocommerce_currency(),
				'description'          => $this->get_order_description( $order ),
				'payment_method_types' => $this->get_payment_method_types(),
				'payment_method'       => $prepared_source->source,
				'customer'             => $prepared_source->customer,
				'capture_method'       => $this->capture_method,
				'confirm'              => true,
			];

			if ( Helper::should_customize_statement_descriptor() ) {
				$data['statement_descriptor_suffix'] = $this->clean_statement_descriptor( Helper::get_gateway_descriptor_suffix( $order ) );
			}
			if ( $save_source ) {
				$data['setup_future_usage'] = 'off_session';
			}

			$data['metadata'] = $this->add_metadata( $order );
			$data             = $this->set_shipping_data( $data, $order );
			$data             = $this->maybe_mandate_data_required( $data, $order );

			$intent_data = $this->make_payment( $order, $prepared_source, $data );

			if ( ! empty( $intent_data ) ) {


				/**
				 * Order Pay page processing
				 */
				if ( did_action( 'woocommerce_before_pay_action' ) ) {


					if ( $intent_data->status === 'requires_action' ) {
						$return_url = $this->get_return_url( $order );

						return apply_filters( 'fkwcs_card_payment_return_intent_data', [
							'result'              => 'success',
							'fkwcs_redirect'      => $return_url,
							'payment_method'      => $prepared_source->source,
							'fkwcs_intent_secret' => $intent_data->client_secret,
						] );

					} else {
						$return_url = $this->process_final_order( end( $intent_data->charges->data ), $order );
					}


					return apply_filters( 'fkwcs_card_payment_return_intent_data', [
						'result'   => 'success',
						'redirect' => $return_url
					] );
				}


				if ( 'succeeded' === $intent_data->status || 'requires_capture' === $intent_data->status ) {

					if ( $save_source || 'off_session' === $intent_data->setup_future_usage ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$this->save_payment_method( $order, $intent_data );


						$charge = $this->get_latest_charge_from_intent( $intent_data );
						if ( isset( $charge->payment_method_details->card->mandate ) ) {
							$mandate_id = $charge->payment_method_details->card->mandate;


						}

						if ( isset( $mandate_id ) && ! empty( $mandate_id ) ) {
							$order->update_meta_data( '_stripe_mandate_id', $mandate_id );
							$order->save_meta_data();
						}

					}
					$redirect_url = $this->process_final_order( end( $intent_data->charges->data ), $order_id );
					Helper::log( 'Redirect URL for ' . $order->get_id() . ' is ' . $redirect_url );

					return [
						'result'   => 'success',
						'redirect' => $redirect_url,
					];
				} else if ( 'requires_payment_method' === $intent_data->status ) {


					if ( ! $order->has_status( 'failed' ) ) {
						// Load the right message and update the status.
						$status_message = isset( $intent_data->last_payment_error ) /* translators: 1) The error message that was received from Stripe. */ ? sprintf( __( 'Stripe SCA authentication failed. Reason: %s', 'funnelkit-stripe-woo-payment-gateway' ), $intent_data->last_payment_error->message ) : __( 'Stripe SCA authentication failed.', 'funnelkit-stripe-woo-payment-gateway' );
						throw new \Exception( $status_message, 200 );

					}


				}


				/**
				 * @see modify_successful_payment_result()
				 * This modifies the final response return in WooCommerce process checkout request
				 */
				$return_url = $this->get_return_url( $order );

				return apply_filters( 'fkwcs_card_payment_return_intent_data', [
					'result'              => 'success',
					'fkwcs_redirect'      => $return_url,
					'payment_method'      => $prepared_source->source,
					'fkwcs_intent_secret' => $intent_data->client_secret,
					'save_card'           => $save_source,
				] );
			} else {
				return [
					'result'   => 'fail',
					'redirect' => '',
				];
			}
		} catch ( \Exception $e ) {
			//Check if there could be a retry without tokenization
			if ( $this->should_retry_without_tokenization( $e, $order ) ) {

				$this->is_recursion = true;

				Helper::log( 'Card does not support this type of purchase. Retrying payment without saving source.' );

				return $this->process_payment( $order_id, $retry, true, $e->getMessage(), $use_order_source );
			}

			if ( ! empty( $order ) ) {
				$this->mark_order_failed( $order, $e->getMessage() );

				if ( ! empty( $intent_data ) ) {
					$charge = $this->get_latest_charge_from_intent( $intent_data );
					do_action( 'fkwcs_process_response', $charge, $order );
				}
			}

			Helper::log( $e->getMessage() );

			throw new \Exception( $e->getMessage(), 200 ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}


	}

	/**
	 * Determines if a retry without tokenization should be attempted.
	 *
	 * This method checks if the current payment attempt is not recursive,
	 * the order does not have a subscription, and the exception message
	 * indicates that the card does not support the type of purchase.
	 *
	 * @param \Exception $e The exception thrown during the payment process.
	 * @param \WC_Order $order The WooCommerce order object.
	 *
	 * @return bool True if a retry without tokenization should be attempted, false otherwise.
	 */
	public function should_retry_without_tokenization( $e, $order ) {
		return apply_filters( 'fkwcs_should_retry_without_tokenization', ( $this->is_recursion === false && ! $this->has_subscription( $order->get_id() ) && strpos( $e->getMessage(), 'Your card does not support this type of purchase.' ) !== false ) );
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
			$token                   = $this->find_saved_token(); //phpcs:ignore WordPress.Security.NonceVerification.Missing
			$stripe_api              = $this->get_client();
			$response                = $stripe_api->payment_methods( 'retrieve', [ $token->get_token() ] );
			$payment_method          = $response['success'] ? $response['data'] : false;
			$prepared_payment_method = Helper::prepare_payment_method( $payment_method, $token );
			$this->save_payment_method_to_order( $order, $prepared_payment_method );
			$return_url = $this->get_return_url( $order );
			/* translators: %1$1s order id, %2$2s order total amount  */
			Helper::log( sprintf( 'Begin processing payment with saved payment method for order %1$1s for the amount of %2$2s', $order_id, $order->get_total() ) );

			if ( empty( $prepared_payment_method->source ) ) {
				throw new \Exception( __( 'We are unable to process payments using the selected method. Please choose a different payment method.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			$request = [
				'payment_method'       => $prepared_payment_method->source,
				'payment_method_types' => $this->get_payment_method_types(),
				'amount'               => Helper::get_stripe_amount( $order->get_total() ),
				'currency'             => strtolower( $order->get_currency() ),
				'description'          => $this->get_order_description( $order ),
				'customer'             => $prepared_payment_method->customer,
				'confirm'              => true,
				'capture_method'       => $this->capture_method,
			];
			if ( Helper::should_customize_statement_descriptor() ) {
				$request['statement_descriptor_suffix'] = $this->clean_statement_descriptor( Helper::get_gateway_descriptor_suffix( $order ) );
			}
			$request['metadata'] = $this->add_metadata( $order );
			$request             = $this->set_shipping_data( $request, $order );


			$this->validate_minimum_order_amount( $order );
			$request = apply_filters( 'fkwcs_payment_intent_data', $request, $order );
			$intent  = $this->make_payment_by_source( $order, $prepared_payment_method, $request );


			$this->save_intent_to_order( $order, $intent );

			if ( 'requires_confirmation' === $intent->status || 'requires_action' === $intent->status ) {
				return apply_filters( 'fkwcs_card_payment_return_intent_data', [
					'result'              => 'success',
					'token'               => 'yes',
					'fkwcs_redirect'      => $return_url,
					'payment_method'      => $intent->id,
					'fkwcs_intent_secret' => $intent->client_secret,
					'token_used'          => 'yes'
				] );
			}

			if ( $intent->amount > 0 ) {
				/** Use the last charge within the intent to proceed */
				$return_url = $this->process_final_order( end( $intent->charges->data ), $order );
			} else {
				$order->payment_complete();
			}

			/** Empty cart */
			if ( ! is_null( WC()->cart ) ) {
				WC()->cart->empty_cart();
			}

			/** Return thank you page redirect URL */
			return [
				'result'   => 'success',
				'redirect' => $return_url,
			];

		} catch ( \Exception $e ) {
			Helper::log( $e->getMessage(), 'warning' );
			wc_add_notice( $e->getMessage(), 'error' );

			/* translators: error message */
			$this->mark_order_failed( $order, $e->getMessage() );
			if ( ! empty( $intent ) ) {
				$charge = $this->get_latest_charge_from_intent( $intent );
				do_action( 'fkwcs_process_response', $charge, $order );

			}

			return [
				'result'   => 'fail',
				'redirect' => '',
			];
		}
	}


	/**
	 * After verify intent got called it's time to save payment method to the order
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
		$token          = null;

		if ( 'link' !== $payment_method->type ) {
			// Do not create token for link
			$user = $order->get_id() ? $order->get_user() : wp_get_current_user();
			if ( $user instanceof \WP_User ) {
				$user_id = $user->ID;
				$token   = Helper::create_payment_token_for_user( $user_id, $payment_method, $this->id, $intent->livemode );
				Helper::log( sprintf( 'Payment method tokenized for Order id - %1$1s with token id - %2$2s', $order->get_id(), $token->get_id() ) );
				delete_transient( 'fkwcs_user_tokens_' . $user_id );
			}
		}

		$prepared_payment_method = Helper::prepare_payment_method( $payment_method, $token );

		$this->save_payment_method_to_order( $order, $prepared_payment_method );
	}

	/**
	 * Save Metadata Like Balance Charge ID & status
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

		if ( wc_string_to_bool( $response->captured ) ) {
			$order->payment_complete( $response->id );
			$ifpe = ( 'payment' === $this->credit_card_form_type ) ? ' (PE)' : '';
			/* translators: order id */
			Helper::log( sprintf( 'Payment successful Order id - %1s', $order->get_id() ) );

			/* translators: 1: Charge ID. 2: Brand name 3: last four digit */


			if ( property_exists( $response->payment_method_details, 'link' ) || isset( $response->payment_method_details->link ) ) {
				$order->add_order_note( sprintf( __( 'Order charge successful in Stripe%s. Charge: %s. Payment method: %s', 'funnelkit-stripe-woo-payment-gateway' ), $ifpe, $response->id, 'link' ) );
				Helper::log( sprintf( __( 'Order charge successful in Stripe%s. Charge: %s. Payment method: %s', 'funnelkit-stripe-woo-payment-gateway' ), $ifpe, $response->id, 'link' ) );

			}
			if ( property_exists( $response->payment_method_details, 'card' ) || isset( $response->payment_method_details->card ) ) {
				$order->add_order_note( sprintf( __( 'Order charge successful in Stripe%s. Charge: %s. Payment method: %s ending in %d', 'funnelkit-stripe-woo-payment-gateway' ), $ifpe, $response->id, ucfirst( $response->payment_method_details->card->brand ), $response->payment_method_details->card->last4 ) );
				Helper::log( sprintf( __( 'Order charge successful in Stripe%s. Charge: %s. Payment method: %s ending in %d', 'funnelkit-stripe-woo-payment-gateway' ), $ifpe, $response->id, ucfirst( $response->payment_method_details->card->brand ), $response->payment_method_details->card->last4 ) );

				if ( property_exists( $response->payment_method_details->card, 'wallet' ) || isset( $response->payment_method_details->card->wallet ) ) {
					$wallet_name = ( 'google_pay' === $response->payment_method_details->card->wallet->type ) ? 'Google Pay' : ( $response->payment_method_details->card->wallet->type === 'apple_pay' ? 'Apple Pay' : $response->payment_method_details->card->wallet->type );
					$order->add_order_note( sprintf( __( 'Wallet Used %s', 'funnelkit-stripe-woo-payment-gateway' ), $wallet_name ) );
					do_action( 'fkwcs_process_final_order_wallet_payment', $response, $order );

					if ( 'google_pay' === $response->payment_method_details->card->wallet->type ) {

						$gateway = WC()->payment_gateways()->payment_gateways()['fkwcs_stripe_google_pay'];
						$order->set_payment_method_title( $gateway->get_title() );
						$order->save();
					} elseif ( $response->payment_method_details->card->wallet->type === 'apple_pay' ) {
						$gateway = WC()->payment_gateways()->payment_gateways()['fkwcs_stripe_apple_pay'];
						$order->set_payment_method_title( $gateway->get_title() );
						$order->save();

					}
				}

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
			/* translators: transaction id */
			$order->update_status( 'on-hold', sprintf( __( 'Charge authorized (Charge ID: %s). Press an eye icon below Transaction Data / Actions to Capture/Void the charge.', 'funnelkit-stripe-woo-payment-gateway' ), $response->id ) );
			/* translators: transaction id */
			Helper::log( sprintf( 'Charge authorized Order id - %1s', $order->get_id() ) );
		}

		/** Empty cart */
		if ( ! is_null( WC()->cart ) ) {
			WC()->cart->empty_cart();
		}

		do_action( 'fkwcs_process_response', $response, $order );

		$return_url = $this->get_return_url( $order );

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


		if ( ! isset( $_POST[ $token_request_key ] ) || 'new' === wc_clean( $_POST[ $token_request_key ] ) ) {  //phpcs:ignore WordPress.Security.NonceVerification.Missing

			return null;
		}

		$token = WC_Payment_Tokens::get( wc_clean( $_POST[ $token_request_key ] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $token || $token->get_user_id() !== get_current_user_id() ) {
			return null;
		}

		return $token;
	}


	public function is_using_saved_payment_method() {
		$payment_method = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : $this->id; //phpcs:ignore WordPress.Security.NonceVerification.Missing

		return ( isset( $_POST[ 'wc-' . $payment_method . '-payment-token' ] ) && 'new' !== $_POST[ 'wc-' . $payment_method . '-payment-token' ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Print the Credit card field
	 *
	 * @return void
	 */
	public function payment_fields() {
		do_action( $this->id . '_before_payment_field_checkout' );
		include __DIR__ . '/parts/credit-card.php';
		do_action( $this->id . '_after_payment_field_checkout' );
	}

	/**
	 * Add the payment method to the customer account
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

		$source        = wc_clean( wp_unslash( $_POST['fkwcs_source'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing
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

		if ( ! $response || is_wp_error( $response ) || ! empty( $response->error ) ) {
			$error_msg = __( 'Unable to attach payment method to customer', 'funnelkit-stripe-woo-payment-gateway' );
			wc_add_notice( $error_msg, 'error' );
			/* translators: error msg */
			Helper::log( sprintf( 'Add payment method Error: %1$1s', $error_msg ) );

			return;
		}


		$user    = wp_get_current_user();
		$user_id = ( $user->ID && $user->ID > 0 ) ? $user->ID : false;
		$is_live = ( 'live' === $this->test_mode ) ? true : false;
		if ( 'link' !== $source_object->type ) {
			Helper::create_payment_token_for_user( $user_id, $source_object, $this->id, $is_live );
		}
		delete_transient( 'fkwcs_user_tokens_' . $user_id );

		do_action( 'fkwcs_add_payment_method_' . ( isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : '' ) . '_success', $source_id, $source_object ); //phpcs:ignore WordPress.Security.NonceVerification.Missing

		Helper::log( 'New payment method added successfully' );

		return [
			'result'   => 'success',
			'redirect' => wc_get_endpoint_url( 'payment-methods' ),
		];
	}

	/**
	 * Get stripe activated payment cards icon.
	 */
	public function get_icon() {
		if ( empty( $this->allowed_cards ) ) {
			return '';
		}
		$ext   = version_compare( WC()->version, '2.6', '>=' ) ? '.svg' : '.png';
		$style = version_compare( WC()->version, '2.6', '>=' ) ? 'style="margin-left: 0.3em"' : '';
		$icons = '<span class="fkwcs_stripe_icons">';

		if ( ( in_array( 'visa', $this->allowed_cards, true ) ) || ( in_array( 'Visa', $this->allowed_cards, true ) ) ) {
			$icons .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/visa' . $ext ) . '" alt="Visa" width="32" title="VISA" ' . $style . ' />';
		}
		if ( ( in_array( 'mastercard', $this->allowed_cards, true ) ) || ( in_array( 'MasterCard', $this->allowed_cards, true ) ) ) {
			$icons .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/mastercard' . $ext ) . '" alt="Mastercard" width="32" title="Master Card" ' . $style . ' />';
		}
		if ( ( in_array( 'amex', $this->allowed_cards, true ) ) || ( in_array( 'American Express', $this->allowed_cards, true ) ) ) {
			$icons .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/amex' . $ext ) . '" alt="Amex" width="32" title="American Express" ' . $style . ' />';
		}
		if ( ( in_array( 'discover', $this->allowed_cards, true ) ) || ( in_array( 'Discover', $this->allowed_cards, true ) ) ) {
			$icons .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/discover' . $ext ) . '" alt="Discover" width="32" title="Discover" ' . $style . ' />';
		}
		if ( ( in_array( 'jcb', $this->allowed_cards, true ) ) || ( in_array( 'JCB', $this->allowed_cards, true ) ) ) {
			$icons .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/jcb' . $ext ) . '" alt="JCB" width="32" title="JCB" ' . $style . ' />';
		}
		if ( ( in_array( 'diners', $this->allowed_cards, true ) ) || ( in_array( 'Diners Club', $this->allowed_cards, true ) ) ) {
			$icons .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/diners' . $ext ) . '" alt="Diners" width="32" title="Diners Club" ' . $style . ' />';
		}
		if ( ( in_array( 'unionpay', $this->allowed_cards, true ) ) || ( in_array( 'Union Pay', $this->allowed_cards, true ) ) ) {
			$icons_path = FKWCS_URL . 'assets/icons/';
			$icons      .= '<img src="' . WC_HTTPS::force_https_url( $icons_path . 'unionpay' . $ext ) . '" alt="Diners" width="32" title="Union Pay" ' . $style . ' />';
		}

		$icons .= '</span>';

		return apply_filters( 'woocommerce_gateway_icon', $icons, $this->id );
	}


	/**
	 * Get test mode description
	 *
	 * @return string
	 */
	public function get_test_mode_description() {
		return sprintf( esc_html__( '%1$1s Test Mode Enabled:%2$2s Use demo card 4242424242424242 with any future date and CVV. Check more %3$3sdemo cards%4$4s', 'funnelkit-stripe-woo-payment-gateway' ), '<b>', '</b>', "<a href='https://stripe.com/docs/testing' target='_blank'>", '</a>' );
	}

	public function localize_element_data( $data ) {
		$data['link_authentication']  = 'no';
		$data['inline_cc']            = $this->inline_cc;
		$data['card_form_type']       = $this->credit_card_form_type;
		$data['enable_saved_cards']   = $this->enable_saved_cards;
		$data['card_element_options'] = apply_filters( 'fkwcs_card_element_options', [ 'disableLink' => false, 'showIcon' => true, 'iconStyle' => 'solid' ] );
		$data['allowed_cards']        = $this->allowed_cards;
		if ( $this->process_link_payment() ) {
			$data['link_authentication'] = $this->settings['link_authentication'];
		}
		$data['link_none'] = isset( $this->settings['link_none'] ) ? $this->settings['link_none'] : 'no';
		if ( 'payment' === $this->credit_card_form_type ) {
			$data['fkwcs_payment_data'] = $this->payment_element_data();
		}
		$data['country_code'] = substr( get_option( 'woocommerce_default_country' ), 0, 2 );


		return $data;
	}

	public function send_payment_options( $fragments ) {
		if ( 'payment' === $this->credit_card_form_type ) {
			$fragments['fkwcs_payment_data'] = $this->payment_element_data();
		}
		$fragments['fkwcs_cart_total'] = WC()->cart->get_total( 'edit' );

		return $fragments;
	}




	public function payment_element_data() {

		$data    = $this->get_payment_element_options();
		$methods = [ 'card' ];
		if ( isset( $this->settings['link_none'] ) && 'yes' !== $this->settings['link_none'] ) {
			$methods = $this->get_payment_method_types();
		}

		$data['payment_method_types'] = apply_filters( 'fkwcs_available_payment_element_types', $methods );
		$data['appearance']           = array(
			"theme" => "stripe"
		);
		$options                      = [
			'fields' => [
				'billingDetails' => ( true === is_wc_endpoint_url( 'order-pay' ) || true === is_wc_endpoint_url( 'add-payment-method' ) ) ? 'auto' : 'never'
			]
		];
		$options['wallets']           = [ 'applePay' => 'never', 'googlePay' => 'never' ];

		return apply_filters( 'fkwcs_stripe_payment_element_data', [ 'element_data' => $data, 'element_options' => $options ], $this );

	}


	public function process_link_payment() {
		return isset( $this->settings['link_authentication'] ) && 'yes' === $this->settings['link_authentication'] && is_checkout() && WC()->cart;
	}

	/**
	 * @param \stdclass $intent
	 * @param \WC_Order $order
	 *
	 * @return void
	 */
	public function handle_webhook_intent_succeeded( $intent, $order ) {

		if ( false === wc_string_to_bool( $this->enabled ) ) {
			return;
		}

		if ( ! $order instanceof \WC_Order || $order->get_payment_method() !== $this->id || $order->is_paid() || ! is_null( $order->get_date_paid() ) || $order->has_status( 'wfocu-pri-order' ) ) {
			return;
		}

		$save_intent = $this->get_intent_from_order( $order );
		if ( empty( $save_intent ) ) {
			Helper::log( 'Could not find intent in the order handle_webhook_intent_succeeded ' . $order->get_id() );

			return;
		}

		if ( class_exists( '\WFOCU_Core' ) ) {
			Helper::log( $order->get_id() . ' :: Saving meta data during webhook to later process this order' );

			$order->update_meta_data( '_fkwcs_webhook_paid', 'yes' );
			$order->save_meta_data();
		} else {

			try {
				Helper::log( $order->get_id() . ' :: Processing order during webhook' );

				$this->handle_intent_success( $intent, $order );

			} catch ( \Exception $e ) {

			}
		}


	}


	/**
	 * Save payment method to meta of the current order
	 *
	 * @param object $order current WooCommerce order.
	 * @param object $payment_method payment method associated with the current order.
	 *
	 * @return void
	 */
	public function save_payment_method_to_order( $order, $payment_method ) {
		Helper::log( 'Entering::' . __FUNCTION__ );

		if ( ! empty( $payment_method->customer ) ) {
			$order->update_meta_data( Helper::get_customer_key(), $payment_method->customer );
		}

		$order->update_meta_data( '_fkwcs_source_id', $payment_method->source );

		if ( ! empty( $payment_method->token ) ) {
			$order->update_meta_data( '_fkwcs_token_id', $payment_method->token );
			$token_obj = WC_Payment_Tokens::get( $payment_method->token );
			if ( ! is_null( $token_obj ) ) {
				$token_obj->add_meta_data( Helper::get_customer_key(), $payment_method->customer );
				$token_obj->save();
			}
		}
		if ( is_callable( [ $order, 'save' ] ) ) {
			$order->save();
		}

		$this->maybe_update_source_on_subscription_order( $order, $payment_method );
	}

	public function get_link_supported_countries() {
		return 'AE, AT, AU, BE, BG, CA, CH, CY, CZ, DE, DK, EE, ES, FI, FR, GB,GI, GR, HK, HR, HU, IE, IT, JP, LI, LT, LU, LV, MT, MX, MY, NL, NO, NZ, PL, PT, RO, SE, SG, SI, SK, US';
	}

	public function get_payment_method_types() {

		$stripe_account_settings = get_option( 'fkwcs_stripe_account_settings', [] );
		if ( empty( $stripe_account_settings ) ) {

			$client = $this->get_client();
			$args   = $client->accounts( 'retrieve', [ get_option( 'fkwcs_account_id' ) ] );
			if ( ! empty( $args['success'] ) ) {
				$account = $args['data'];

				$stripe_account_settings = array(
					'country'          => $account->country,
					'default_currency' => $account->default_currency,
				);
				update_option( 'fkwcs_stripe_account_settings', $stripe_account_settings );
			}

		}

		if ( ! empty( $stripe_account_settings ) && in_array( $stripe_account_settings['country'], array_map( 'trim', explode( ',', $this->get_link_supported_countries() ) ), true ) ) {
			return [ 'card', 'link' ];
		}

		return [ $this->payment_method_types ];
	}

	/**
	 * Maybe set tokens for Stripe payment gateway.
	 *
	 * @param array $tokens The existing payment tokens.
	 * @param int $user_id The user ID.
	 * @param string $gateway_id The gateway ID.
	 *
	 * @return array The updated payment tokens.
	 */
	public function maybe_sync_gateway_tokens( $tokens, $user_id, $gateway_id ) {
		return $this->sync_gateway_tokens( $tokens, $user_id, $gateway_id );
	}


	/**
	 * Sync gateway tokens from the API, generic method that handled cache detection too
	 * This method will check for existing tokens and then will sync them with the stripe API
	 * Any methods which are not found in the stripe API will be removed from the tokens
	 *
	 * @param $tokens
	 * @param $user_id
	 * @param $gateway_id
	 * @param $skip_cache
	 *
	 * @return mixed
	 */
	public function sync_gateway_tokens( $tokens, $user_id, $gateway_id, $skip_cache = false ) {
		if ( ! $this->is_available() ) {
			Helper::log( 'Gateway is not available.' );

			return $tokens;
		}

		if ( ! empty( $gateway_id ) && ! in_array( $gateway_id, [ 'fkwcs_stripe' ], true ) ) {
			Helper::log( 'Gateway ID not supported: ' . $gateway_id );

			return $tokens;
		}

		$stored_tokens = [];
		if ( ! empty( $tokens ) ) {
			foreach ( $tokens as $token ) {
				if ( method_exists( $token, 'get_token' ) && ! empty( $token->get_token() ) ) {
					$stored_tokens[ $token->get_token() ] = $token;
				}
			}
		}

		try {
			$payment_methods = $this->get_payment_methods_customer( $user_id, $skip_cache );

			remove_action( 'woocommerce_get_customer_payment_tokens', [ $this, 'maybe_sync_gateway_tokens' ], 8 );

			foreach ( $payment_methods as $payment_method ) {
				if ( ! isset( $payment_method->type ) ) {
					continue;
				}

				$token                      = Helper::create_payment_token_for_user( $user_id, $payment_method, $this->id, $payment_method->livemode );
				$tokens[ $token->get_id() ] = $token;

				if ( isset( $stored_tokens[ $token->get_token() ] ) ) {
					unset( $stored_tokens[ $token->get_token() ] );
				}
			}

			if ( ! empty( $tokens ) && ! empty( $gateway_id ) ) {
				foreach ( $tokens as $key => $token ) {
					if ( $this->id !== $token->get_gateway_id() || $this->test_mode !== $token->get_meta( 'mode' ) ) {
						unset( $tokens[ $key ] );
					}
				}

				remove_action( 'woocommerce_payment_token_deleted', [ $this, 'detach_customer_token' ], 10, 2 );

				foreach ( $stored_tokens as $token ) {
					unset( $tokens[ $token->get_id() ] );
					$token->delete();
					Helper::log( 'Deleted stored token ID: ' . $token->get_id() );
				}
				add_action( 'woocommerce_payment_token_deleted', [ $this, 'detach_customer_token' ], 10, 2 );
			}
			add_action( 'woocommerce_get_customer_payment_tokens', [ $this, 'maybe_sync_gateway_tokens' ], 8, 3 );

		} catch ( \Exception|\Error $e ) {
			Helper::log( 'Error: ' . $e->getMessage() );
		} finally {
			add_action( 'woocommerce_payment_token_deleted', [ $this, 'detach_customer_token' ], 10, 2 );
			add_action( 'woocommerce_get_customer_payment_tokens', [ $this, 'maybe_sync_gateway_tokens' ], 8, 3 );
		}

		return $tokens;
	}


	/**
	 * Fetch all user tokens from users account directly from stripe
	 *
	 * @param int $user_id
	 *
	 * @return array|mixed
	 */
	public function get_payment_methods_customer( $user_id, $skip_cache = false ) {
		if ( ! $user_id ) {
			return [];
		}

		if ( $skip_cache ) {
			$payment_methods = false;
		} else {
			$payment_methods = get_transient( 'fkwcs_user_tokens_' . $user_id );

		}
		if ( false === $payment_methods ) {


			$client = $this->get_client();

			$customer = $this->filter_customer_id( get_user_option( '_fkwcs_customer_id', $user_id ) );
			if ( empty( $customer ) ) {
				$compatibility_keys = Helper::get_compatibility_keys( '_fkwcs_customer_id' );
				if ( ! empty( $compatibility_keys ) ) {
					foreach ( $compatibility_keys as $key ) {
						$customer = $this->filter_customer_id( get_user_option( $key, $user_id ) );
						if ( ! empty( $customer ) ) {
							break;
						}
					}
				}

				if ( empty( $customer ) ) {
					return [];
				}

			}
			$response = $client->customers( 'allPaymentMethods', [ $customer, [ 'limit' => 100, 'type' => 'card' ] ] );
			if ( ! empty( $response['error'] ) ) {
				return [];
			}
			if ( ! empty( $response['data'] ) ) {
				$payment_methods = $response['data'];
			}
			set_transient( 'fkwcs_user_tokens_' . $user_id, $payment_methods, DAY_IN_SECONDS );
		}

		return empty( $payment_methods ) ? [] : $payment_methods;
	}

	/**
	 * Deletes a token from Stripe.
	 *
	 * @param int $token_id The WooCommerce token ID.
	 * @param \WC_Payment_Token $token The WC_Payment_Token object.
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 *
	 */
	public function detach_customer_token( $token_id, $token ) {
		try {
			if ( 'test' === $this->test_mode && is_admin() && 'production' !== wp_get_environment_type() ) {
				return $token_id;
			}
			$client = $this->get_client();

			$customer = $this->filter_customer_id( get_user_option( '_fkwcs_customer_id', $token->get_user_id() ) );
			if ( empty( $customer ) ) {
				$compatibility_keys = Helper::get_compatibility_keys( '_fkwcs_customer_id' );
				if ( ! empty( $compatibility_keys ) ) {
					foreach ( $compatibility_keys as $key ) {
						$customer = $this->filter_customer_id( get_user_option( $key, $token->get_user_id() ) );
						if ( ! empty( $customer ) ) {
							break;
						}
					}
				}

				if ( empty( $customer ) ) {
					return [];
				}

			}

			if ( empty( $customer ) ) {
				return [];
			}
			$client->payment_methods( 'detach', [ $token->get_token() ] );
			delete_transient( 'fkwcs_user_tokens_' . $token->get_user_id() );
		} catch ( \Exception|\Error $e ) {
			Helper::log( 'Error: ' . $e->getMessage() );
		}
	}

	/**
	 * Set as default in Stripe.
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 */
	public function woocommerce_payment_token_set_default( $token_id ) {


		try {
			$token    = WC_Payment_Tokens::get( $token_id );
			$client   = $this->get_client();
			$customer = $this->filter_customer_id( get_user_option( '_fkwcs_customer_id', get_current_user_id() ) );
			if ( empty( $customer ) ) {
				return [];
			}
			$client->customers( 'update', [ $customer, [ 'invoice_settings' => [ 'default_payment_method' => sanitize_text_field( $token->get_token() ) ] ] ] );
		} catch ( \Exception|\Error $e ) {
			Helper::log( 'Error: ' . $e->getMessage() );
		}


	}


}



