<?php

namespace FKWCS\Gateway\Stripe;

class AliPay extends LocalGateway {
	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe_alipay';
	public $payment_method_types = 'alipay';
	protected $payment_element = true;

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
		$this->supported_currency = [
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
			'USD'  // US Dollar
		];

		// All supported countries based on Stripe documentation
		$this->specific_country = [
			'AU', // Australia
			'AT', // Austria
			'BE', // Belgium
			'BG', // Bulgaria
			'CA', // Canada
			'HR', // Croatia
			'CY', // Cyprus
			'CZ', // Czech Republic
			'DK', // Denmark
			'EE', // Estonia
			'FI', // Finland
			'FR', // France
			'DE', // Germany
			'GI', // Gibraltar
			'GR', // Greece
			'HK', // Hong Kong
			'HU', // Hungary
			'IE', // Ireland
			'IT', // Italy
			'JP', // Japan
			'LV', // Latvia
			'LI', // Liechtenstein
			'LT', // Lithuania
			'LU', // Luxembourg
			'MY', // Malaysia
			'MT', // Malta
			'NL', // Netherlands
			'NZ', // New Zealand
			'NO', // Norway
			'PT', // Portugal
			'RO', // Romania
			'SG', // Singapore
			'SK', // Slovakia
			'SI', // Slovenia
			'ES', // Spain
			'SE', // Sweden
			'CH', // Switzerland
			'GB', // United Kingdom
			'US'  // United States
		];

		$this->selling_country_type = 'specific';
		$this->except_country       = [];

		$this->setting_enable_label        = __( 'Enable Alipay Payment Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = __( 'Alipay', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = __( 'Securely pay with Alipay - A trusted global payment method', 'funnelkit-stripe-woo-payment-gateway' );
	}

	public function init_form_fields() {

		$settings                = [
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
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'        => 'textarea',
				'css'         => 'width:25em',
				'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => $this->setting_description_default,
				'desc_tip'    => true,
			]
		];
		$stripe_account_settings = get_option( 'fkwcs_stripe_account_settings', [] );

		$admin_country = ! empty( $stripe_account_settings ) ? strtoupper( $stripe_account_settings['country'] ) : wc_format_country_state_string( get_option( 'woocommerce_default_country', '' ) )['country'];

		if ( in_array( $admin_country, $this->specific_country, true ) ) {
			$this->specific_country = [ $admin_country ];
		} else {
			$this->specific_country = [];
		}

		$countries_fields = $this->get_countries_admin_fields( $this->selling_country_type, $this->except_country, $this->specific_country );

		if ( isset( $countries_fields['allowed_countries']['options']['all'] ) ) {
			unset( $countries_fields['allowed_countries']['options']['all'] );
		}

		if ( isset( $countries_fields['allowed_countries']['options']['all_except'] ) ) {
			unset( $countries_fields['allowed_countries']['options']['all_except'] );
		}
		if ( isset( $countries_fields['except_countries'] ) ) {
			unset( $countries_fields['except_countries'] );
		}

		$countries_fields['specific_countries']['options'] = $this->specific_country;
		$this->form_fields                                 = apply_filters( $this->id . '_payment_form_fields', array_merge( $settings, $countries_fields ) );
	}
}