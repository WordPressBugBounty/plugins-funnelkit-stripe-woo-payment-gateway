<?php

namespace FKWCS\Gateway\Stripe;
#[\AllowDynamicProperties]
class P24 extends LocalGateway {

	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe_p24';
	public $payment_method_types = 'p24';
	protected $payment_element = true;


	/**
	 * Setup general properties and settings
	 *
	 * @return void
	 */
	protected function init() {
		$this->paylater_message_position='description';
		$this->method_title                = __( 'Stripe Przelewy 24 (P24) Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description          = __( 'Accepts payments via Przelewy 24 (P24). The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>EUR, PLN</strong>', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle                    = __( 'P24 is an online banking payment method that enables your customers in e-commerce to make an online purchase', 'funnelkit-stripe-woo-payment-gateway' );
		$this->title                       = $this->get_option( 'title' );
		$this->description                 = $this->get_option( 'description' );
		$this->enabled                     = $this->get_option( 'enabled' );
		$this->supported_currency          = [ 'EUR', 'PLN' ];
		$this->specific_country            = [ 'PL' ];
		$this->setting_enable_label        = __( 'Enable Stripe Przelewy 24 (P24) Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = __( 'Stripe Przelewy 24 (P24)', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = __( 'Pay with Przelewy 24 (P24)', 'funnelkit-stripe-woo-payment-gateway' );
		$this->init_form_fields();
		$this->init_settings();

	}
}
