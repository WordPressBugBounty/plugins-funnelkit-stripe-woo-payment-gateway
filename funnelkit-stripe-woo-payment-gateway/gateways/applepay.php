<?php

namespace FKWCS\Gateway\Stripe;

use FKWCS\Gateway\Stripe\Traits\WC_Subscriptions_Trait;

class ApplePay extends CreditCard {
	use WC_Subscriptions_Trait;
	use Funnelkit_Stripe_Smart_Buttons;

	private static $instance = null;
	public $id = 'fkwcs_stripe_apple_pay';
	public $payment_method_types = 'card';
	public $merchant_id = '';
	public $merchant_name = '';

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		/**
		 * Validate if its setup correctly
		 */
		$this->set_api_keys();

		$this->init_supports();
		$this->init();
		$this->maybe_init_subscriptions();
		if ( false === $this->is_configured() ) {
			return;
		}
		add_action( 'wc_ajax_fkwcs_gpay_update_shipping_address', [ $this, 'gpay_update_shipping_address' ] );
		add_filter( 'woocommerce_update_order_review_fragments', [ $this, 'add_apple_pay_data' ], 100 );


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

	protected function init() {
		$this->has_fields = true;
		$this->init_form_fields();
		$this->init_settings();
		$this->method_title       = __( 'Apple Pay', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Enable Apple Pay as Inline Payment Gateway.', 'funnelkit-stripe-woo-payment-gateway' );

		$this->title                = $this->get_option( 'title' );
		$this->subtitle             = __( 'Apple Pays allows customers to securely make payments using Apple Pay on their iPhone, iPad, or Apple Watch.', 'funnelkit-stripe-woo-payment-gateway' );
		$this->description          = $this->get_option( 'description' );
		$this->merchant_name        = $this->get_option( 'merchant_name' );
		$this->merchant_id          = $this->get_option( 'merchant_id' );
		$this->description          = $this->get_option( 'description' );
		$this->statement_descriptor = $this->get_option( 'statement_descriptor' );
		$this->capture_method        = $this->get_option( 'charge_type' );
		if ( false === $this->is_configured() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_stripe_js' ] );
		$this->filter_hooks();

	}


	/**
	 * Add hooks
	 *
	 * @return void
	 */
	protected function filter_hooks() {
		add_filter( 'woocommerce_payment_successful_result', [ $this, 'modify_successful_payment_result' ], 999, 2 );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}


	/**
	 * Initialise gateway settings form fields
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = apply_filters( 'fkwcs_apple_pay_payment_form_fields', [
			'enabled'     => [
				'label'   => ' ',
				'type'    => 'checkbox',
				'title'   => __( 'Enable Apple Pay', 'funnelkit-stripe-woo-payment-gateway' ),
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Change the payment gateway title that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => __( 'Apple Pay', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc_tip'    => true,

			],
			'description' => [
				'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'textarea',
				'css'         => 'width:25em',
				'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => __( 'Pay with Apple Pay', 'funnelkit-stripe-woo-payment-gateway' ),
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
		] );
	}

	public function enqueue_stripe_js() {


		if ( ! $this->is_available() ) {
			return;
		}

		/**
		 * Check if selected location is not the current location
		 * OR
		 * Allow devs to enqueue assets
		 */
		if ( ! ( $this->is_selected_location() || ( apply_filters( 'fkwcs_enqueue_express_button_assets', false, $this ) ) ) ) {
			return;
		}
		parent::enqueue_stripe_js();
	}

	/**
	 * Checks if current location is chosen to display express checkout button
	 *
	 * @return boolean
	 */
	private function is_selected_location() {
		if ( $this->is_product() ) {
			return true;
		}

		if ( is_cart() ) {
			return true;
		}

		if ( is_checkout() ) {
			return true;
		}

		return false;

	}


	/**
	 * Print the gateway field
	 *
	 * @return void
	 */
	public function payment_fields() {
		do_action( $this->id . '_before_payment_field_checkout' );
		include __DIR__ . '/parts/apple_pay.php';
		do_action( $this->id . '_after_payment_field_checkout' );
	}


	public function add_apple_pay_data( $fragments ) {

		$fragments['fkwcs_apple_pay_data'] = $this->ajax_get_cart_details();


		return $fragments;
	}

	public function get_method_description() {

		if ( did_action( 'woocommerce_admin_field_payment_gateways' ) > 0 ) {
			return $this->method_description;
		}
		$description = $this->method_description;

		$description .= sprintf( __( '<p>Note: Apple Pay Gateway visibility is dependent on Apple Pay supported browsers. <a href=%s>Learn more</a>', 'funnelkit-stripe-woo-payment-gateway' ), 'https://funnelkit.com/docs/stripe-gateway-for-woocommerce/troubleshooting/express-payment-buttons-not-showing/#apple-pay' );


		return $description;
	}

	public function get_icon() {

		$icons = '<span class="fkwcs_stripe_apple_pay_icons">';
		$icons .= '<img src="' . \FKWCS_URL . 'assets/icons/apple_pay.svg' . '" alt="Visa" title="apple pay" /></span>';

		return $icons;
	}

}