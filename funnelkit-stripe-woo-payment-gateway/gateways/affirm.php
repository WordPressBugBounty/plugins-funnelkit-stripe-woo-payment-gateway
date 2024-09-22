<?php

namespace FKWCS\Gateway\Stripe;
#[\AllowDynamicProperties]
class Affirm extends LocalGateway {
	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe_affirm';
	public $payment_method_types = 'affirm';
	protected $payment_element = true;
	protected $paylater_message_service = true;

	protected $min_amount = 5000;
	public $max_amount = 300000;

	/**
	 * Setup general properties and settings
	 *
	 * @return void
	 */
	protected function init() {
		$this->method_title       = __( 'Affirm', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Accepts payments via Affirm. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>USD,CAD</strong>', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle           = __( 'affirm is an online banking payment method that enables your customers in e-commerce to make an online purchase', 'funnelkit-stripe-woo-payment-gateway' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

	}

	protected function override_defaults() {
		$this->supported_currency          = [ 'USD', 'CAD' ];
		$this->specific_country            = [ 'US', 'CA' ];
		$this->country_type                = 'specific';
		$this->except_country              = [];
		$this->setting_enable_label        = __( 'Enable Affirm Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = __( 'Affirm - Pay Over Time', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = __( 'After clicking "Complete order", you will be redirected to Affirm <br> - Pay Over Time to complete your purchase securely', 'funnelkit-stripe-woo-payment-gateway' );
	}


	public function init_form_fields() {

		$settings = [
			'enabled'          => [
				'label'   => ' ',
				'type'    => 'checkbox',
				'title'   => $this->setting_enable_label,
				'default' => 'no',
			],
			'title'            => [
				'title'       => __( 'Title', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Change the payment gateway title that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => $this->setting_title_default,
				'desc_tip'    => true,
			],
			'description'      => [
				'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'textarea',
				'css'         => 'width:25em',
				'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => $this->setting_description_default,
				'desc_tip'    => true,
			],
			'paylater_section' => [
				'title'       => __( 'Affirm Message Location', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => [  'cart' ],
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'css'         => 'min-width: 350px;',
				'desc_tip'    => true,
				/* translators: gateway title */
				'description' => sprintf( __( 'This option lets you limit the %1$s to which countries you are willing to sell to.', 'funnelkit-stripe-woo-payment-gateway' ), $this->method_title ),
				'options'     => array(
					'product' => __( 'Product Page', 'funnelkit-stripe-woo-payment-gateway' ),
					'cart'    => __( 'Cart Page', 'funnelkit-stripe-woo-payment-gateway' ),
					'shop'    => __( 'Shop/Categories Page', 'funnelkit-stripe-woo-payment-gateway' ),
				),
			],
		];


		$countries_fields = $this->get_countries_admin_fields( $this->selling_country_type, $this->except_country, $this->specific_country );
		if (isset($countries_fields['allowed_countries']['options']['all'])) {
			unset($countries_fields['allowed_countries']['options']['all']);
		}

		if (isset($countries_fields['allowed_countries']['options']['all_except'])) {
			unset($countries_fields['allowed_countries']['options']['all_except']);
		}
		if ( isset( $countries_fields['except_countries'] ) ) {
			unset( $countries_fields['except_countries'] );
		}
		$countries_fields['specific_countries']['options'] = $this->specific_country;
		$this->form_fields                                 = apply_filters( $this->id . '_payment_form_fields', array_merge( $settings, $countries_fields ) );
	}
}
