<?php

namespace FKWCS\Gateway\Stripe;
#[\AllowDynamicProperties]
class Ideal extends Abstract_Payment_Gateway {
	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe_ideal';
	public $payment_method_types = 'ideal';
	protected $payment_element = true;

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
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_filter( 'fkwcs_localized_data', [ $this, 'localize_element_data' ], 999 );

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
	 * Registers supported filters for payment gateway
	 *
	 * @return void
	 */
	public function init_supports() {
		$this->supports = apply_filters( 'fkwcs_ideal_payment_supports', array_merge( $this->supports, [ 'products', 'refunds' ] ) );
	}

	/**
	 * Returns all supported currencies for this payment method
	 *
	 * @return mixed|null
	 */
	public function get_supported_currency() {
		return apply_filters( 'fkwcs_stripe_ideal_supported_currencies', [ 'EUR' ] );
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

		if ( ! empty( $this->get_option( 'allowed_countries' ) ) && 'all_except' === $this->get_option( 'allowed_countries' ) ) {
			return ! in_array( $this->get_billing_country(), $this->get_option( 'except_countries', array() ), true );
		} elseif ( ! empty( $this->get_option( 'allowed_countries' ) ) && 'specific' === $this->get_option( 'allowed_countries' ) ) {
			return in_array( $this->get_billing_country(), $this->get_option( 'specific_countries', array() ), true );
		}

		return parent::is_available();
	}

	/**
	 * Initialise gateway settings form fields
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$settings = [


			'enabled'     => [
				'title'       => __( 'Enable/Disable', 'funnelkit-stripe-woo-payment-gateway' ),
				'label'       => __( 'Enable Stripe iDEAL', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Change the payment gateway title that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => __( 'iDEAL', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => __( 'You will be redirected to iDEAL.', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc_tip'    => true,
			]
		];

		$this->form_fields = apply_filters( 'fkwcs_ideal_payment_form_fields', array_merge( $settings, $this->get_countries_admin_fields( 'specific', [], [ 'NL' ] ) ) );
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
		$icons     = $this->payment_icons();
		$icons_str = '';
		$icons_str .= ! empty( $icons['ideal'] ) ? $icons['ideal'] : '';

		return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
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
	public function process_payment( $order_id ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedParameter,VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		try {
			$order = wc_get_order( $order_id );

			/** This will throw exception if not valid */
			$this->validate_minimum_order_amount( $order );
			$customer_id      = $this->get_customer_id( $order );
			$idempotency_key  = $order->get_order_key() . time();
			$data             = [
				'amount'               => Helper::get_stripe_amount( $order->get_total() ),
				'currency'             => $this->get_currency(),
				'description'          => $this->get_order_description( $order ),
				'metadata'             => $this->get_metadata( $order_id ),
				'payment_method_types' => [ $this->payment_method_types ],
				'customer'             => $customer_id,
			];
			$data['metadata'] = $this->add_metadata( $order );
			$data             = $this->set_shipping_data( $data, $order );

			$intent_data = $this->get_payment_intent( $order, $idempotency_key, $data );

			Helper::log( sprintf( __( 'Begin processing payment with Ideal for order %1$1s for the amount of %2$2s', 'funnelkit-stripe-woo-payment-gateway' ), $order_id, $order->get_total() ) );

			if ( $intent_data ) {
				/**
				 * @see modify_successful_payment_result()
				 * This modifies the final response return in WooCommerce process checkout request
				 */
				$return_url = $this->get_return_url( $order );

				return [
					'result'              => 'success',
					'fkwcs_redirect'      => $return_url,
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

	public function localize_element_data( $data ) {
		if ( !$this->is_available() ) {
			return $data;
		}
		$data['fkwcs_payment_data_ideal'] = $this->payment_element_data();


		return $data;
	}

	public function payment_element_data() {

		$data    = $this->get_payment_element_options();
		$methods = [ 'ideal' ];


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

		return apply_filters( 'fkwcs_stripe_payment_element_data_ideal', [ 'element_data' => $data, 'element_options' => $options ], $this );

	}


}
