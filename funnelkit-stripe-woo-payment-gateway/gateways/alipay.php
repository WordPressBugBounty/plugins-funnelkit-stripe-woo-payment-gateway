<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AliPay extends LocalGateway {
	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id                   = 'fkwcs_stripe_alipay';
	public $payment_method_types = 'alipay';
	protected $payment_element   = true;

	/**
	 * Setup general properties and settings
	 *
	 * @return void
	 */
	protected function init() {
		$this->method_title       = __( 'Stripe Alipay Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Accepts payments via Alipay. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>CNY, AUD, CAD, EUR, GBP, HKD, JPY, SGD, MYR, NZD, USD</strong>', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle           = __( 'Alipay is a popular payment method that enables customers to make online purchases', 'funnelkit-stripe-woo-payment-gateway' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
	}

	protected function override_defaults() {
		// Supported currencies
		$this->supported_currency = array(
			'CNY', // Chinese Yuan
			'AUD', // Australian Dollar
			'CAD', // Canadian Dollar
			'EUR', // Euro
			'GBP', // British Pound
			'HKD', // Hong Kong Dollar
			'JPY', // Japanese Yen
			'SGD', // Singapore Dollar
			'MYR', // Malaysian Ringgit
			'NZD', // New Zealand Dollar
			'USD',  // US Dollar
		);

		$this->specific_country     = array();
		$this->selling_country_type = 'all';
		$this->except_country       = array();

		$this->setting_enable_label        = __( 'Enable Alipay Payment Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = __( 'Alipay', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = __( 'Securely pay with Alipay - A trusted global payment method', 'funnelkit-stripe-woo-payment-gateway' );
	}

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

		$countries_fields = $this->get_countries_admin_fields( $this->selling_country_type, $this->except_country, $this->specific_country );

		// Don't limit options - specific_countries shows all countries like except_countries
		$this->form_fields = apply_filters( $this->id . '_payment_form_fields', array_merge( $settings, $countries_fields ) );
	}
}
