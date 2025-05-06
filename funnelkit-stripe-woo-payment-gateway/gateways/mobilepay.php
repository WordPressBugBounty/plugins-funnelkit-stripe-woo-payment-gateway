<?php

namespace FKWCS\Gateway\Stripe;
#[\AllowDynamicProperties]
class mobilepay extends LocalGateway {

	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe_mobilepay';
	public $payment_method_types = 'mobilepay';
	protected $payment_element = true;
	protected $shipping_address_required = true;

	/**
	 * Setup general properties and settings
	 *
	 * @return void
	 */
	protected function init() {
		$this->method_title       = __( 'MobilePay Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Accepts payments via MobilePay. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>DKK,NOK,SEK,EUR</strong>', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle           = __( 'MobilePay is an online banking payment method that enables your customers in e-commerce to make an online purchase', 'funnelkit-stripe-woo-payment-gateway' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
		$this->capture_method        = $this->get_option( 'charge_type' );
	}

	protected function override_defaults() {
		$this->supported_currency          = [ 'DKK', 'EUR', 'NOK', 'SEK' ];
		$this->specific_country            = [ 'DK', 'FI' ];
		$this->except_country              = [];
		$this->setting_enable_label        = __( 'Enable MobilePay Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = __( 'MobilePay - Pay Over Time', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = __( 'After clicking "Complete order", you will be redirected to MobilePay to <br> complete your purchase securely', 'funnelkit-stripe-woo-payment-gateway' );
	}

	public function init_form_fields() {

		$settings                = [
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
