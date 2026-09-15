<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BLIK Payment Gateway
 * Mobile banking payment method for Poland
 *
 * @extends LocalGateway
 */
#[\AllowDynamicProperties]
class Blik extends LocalGateway {

	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe_blik';

	/**
	 * Stripe payment method type
	 *
	 * @var string
	 */
	public $payment_method_types = 'blik';

	/**
	 * Use Payment Elements UI
	 * BLIK uses custom input field for 6-digit code, not Payment Element
	 *
	 * @var bool
	 */
	protected $payment_element = false;

	/**
	 * Supports success webhook
	 * BLIK uses redirect-based confirmation via mobile banking app
	 *
	 * @var bool
	 */
	public $supports_success_webhook = true;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Set gateway defaults
		$this->override_defaults();

		// Call parent constructor
		parent::__construct();
	}

	/**
	 * Setup general properties and settings
	 *
	 * @return void
	 */
	protected function init() {
		$this->method_title       = __( 'BLIK Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Accepts payments via BLIK. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>PLN</strong>', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle           = __( 'BLIK is a mobile banking payment method that enables your customers in Poland to make online purchases using their banking app', 'funnelkit-stripe-woo-payment-gateway' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
		// BLIK does not support capture_method - it's always automatic
	}

	/**
	 * Override default gateway settings
	 * This is required when extending LocalGateway
	 *
	 * @return void
	 */
	protected function override_defaults() {
		// Supported currencies - PLN only
		$this->supported_currency = array( 'PLN' );

		// Country restrictions - Poland only
		$this->selling_country_type = 'specific';
		$this->specific_country     = array( 'PL' );
		$this->except_country       = array();

		// Gateway display settings
		$this->setting_enable_label        = __( 'Enable BLIK Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = __( 'BLIK', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = __( 'After clicking "Complete order", you will be redirected to complete your purchase securely with BLIK', 'funnelkit-stripe-woo-payment-gateway' );

		// Icon URL
		$this->icon_url = FKWCS_URL . 'assets/icons/blik.svg';
	}

	/**
	 * Initialize gateway settings form fields
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
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'textarea',
				'css'         => 'width:25em',
				'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => $this->setting_description_default,
				'desc_tip'    => true,
			),
		);

		// Get country fields
		$countries_fields = $this->get_countries_admin_fields( $this->selling_country_type, $this->except_country, $this->specific_country );

		// Remove 'all' and 'all_except' options since BLIK is Poland-only
		if ( isset( $countries_fields['allowed_countries']['options']['all'] ) ) {
			unset( $countries_fields['allowed_countries']['options']['all'] );
		}

		if ( isset( $countries_fields['allowed_countries']['options']['all_except'] ) ) {
			unset( $countries_fields['allowed_countries']['options']['all_except'] );
		}

		if ( isset( $countries_fields['except_countries'] ) ) {
			unset( $countries_fields['except_countries'] );
		}

		// Set Poland as the only option
		$countries_fields['specific_countries']['options'] = $this->specific_country;
		$countries_fields['specific_countries']['default'] = array( 'PL' );

		$this->form_fields = apply_filters( $this->id . '_payment_form_fields', array_merge( $settings, $countries_fields ) );
	}

	/**
	 * Override payment_fields to add custom BLIK code input field
	 *
	 * @return void
	 */
	public function payment_fields() {
		do_action( $this->id . '_before_payment_field_checkout' );

		// Show test mode instructions if in test mode
		if ( $this->test_mode === 'test' ) {
			echo '<p class="testmode-info">';
			echo '<strong>' . esc_html__( 'Test mode:', 'funnelkit-stripe-woo-payment-gateway' ) . '</strong> ';
			echo esc_html__( 'Use any 6-digit number to authorize payment.', 'funnelkit-stripe-woo-payment-gateway' );
			echo '</p>';
		}

		// Show description if set
		if ( ! empty( $this->get_description() ) ) {
			echo '<p>' . wp_kses_post( $this->get_description() ) . '</p>';
		}

		// BLIK code input field
		?>
		<fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-form" class="wc-payment-form" style="font-size: inherit;">
			<div class="fkwcs-blik-code-wrapper">
				<?php
				woocommerce_form_field(
					'fkwcs-blik-code',
					array(
						'maxlength'   => 6,
						'label'       => esc_html__( 'BLIK Code', 'funnelkit-stripe-woo-payment-gateway' ),
						'required'    => true,
						'type'        => 'text',
						'class'       => array( 'fkwcs-blik-code-input' ),
						'input_class' => array( 'input-text' ),
						'placeholder' => esc_attr__( 'Enter 6-digit code', 'funnelkit-stripe-woo-payment-gateway' ),
					)
				);
				?>
			</div>
			<p class="fkwcs-blik-instructions">
				<?php echo esc_html__( 'After submitting your order, please authorize the payment in your mobile banking application.', 'funnelkit-stripe-woo-payment-gateway' ); ?>
			</p>
		</fieldset>
		<?php

		do_action( $this->id . '_after_payment_field_checkout' );
	}

	/**
	 * Override process_payment to validate BLIK code and add payment_method_options
	 * BLIK code must be added to payment_method_options when creating payment intent with confirm: true
	 * This matches WooCommerce Stripe's approach exactly
	 *
	 * @param int $order_id Order ID
	 * @return array|void
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Validate BLIK code
		$blik_code = isset( $_POST['fkwcs-blik-code'] ) ? sanitize_text_field( wp_unslash( $_POST['fkwcs-blik-code'] ) ) : '';//phpcs:ignore FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, WordPress.Security.NonceVerification.Missing

		if ( empty( $blik_code ) || strlen( $blik_code ) !== 6 || ! ctype_digit( $blik_code ) ) {
			wc_add_notice( __( 'Please enter a valid 6-digit BLIK code.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );
			return;
		}

		// Call parent process_payment
		return parent::process_payment( $order_id );
	}

	/**
	 * Override to prevent saving payment method/token for BLIK
	 * BLIK is not a recurring payment method and cannot be saved for future use
	 *
	 * @param \WC_Order $order Order object
	 * @param object    $intent Payment intent object
	 * @return void
	 */
	public function save_payment_method( $order, $intent ) {
		return;
	}
}