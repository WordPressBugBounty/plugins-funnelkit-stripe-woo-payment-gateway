<?php

namespace FKWCS\Gateway\Stripe;

#[\AllowDynamicProperties]
class Pix extends LocalGateway {
	public $id = 'fkwcs_stripe_pix';
	public $payment_method_types = 'pix';
	protected $payment_element = true;

	/**
	 * Initialize the Pix gateway settings and configuration.
	 *
	 * Sets up method title, description, supported currencies, countries,
	 * and initializes form fields and settings. Also adds localization filter.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	protected function init() {
		$this->method_title       = esc_html__( 'Stripe Pix Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = wp_kses_post( 'Accepts payments via Pix. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>BRL , USD</strong>', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle           = esc_html__( 'Pix is a payment method that enables your customers to pay using their Pix balance', 'funnelkit-stripe-woo-payment-gateway' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
		add_filter( 'fkwcs_localized_data', [ $this, 'localize_element_data' ], 999 );

	}
	
	protected function override_defaults() {
		$this->supported_currency          = [ 'BRL' , 'USD'];
		$this->specific_country = [ 'BR', 'US'];
		$this->setting_enable_label        = esc_html__( 'Enable Stripe Pix Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = esc_html__( 'Stripe Pix', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = esc_html__( 'Pay with Pix', 'funnelkit-stripe-woo-payment-gateway' );

	}
	/**
	 * Initialize and configure the admin form fields for Pix gateway.
	 *
	 * Creates form fields for gateway configuration including enable/disable toggle,
	 * title, description, and country-specific settings. Handles country restrictions
	 * based on Stripe account settings and removes unsupported options.
	 *
	 * @since 1.0.0
	 * @return void
	 */
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
				'id'          => $this->setting_title_default,
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
		$countries_fields['specific_countries']['default'] = [ 'BR' ];
		$this->form_fields                                 = apply_filters( $this->id . '_payment_form_fields', array_merge( $settings, $countries_fields ) );
	}

	/**
	 * Add Pix-specific payment element data to localized frontend data.
	 *
	 * Appends Pix payment configuration to the localized data array
	 * that gets passed to frontend JavaScript for Stripe Elements integration.
	 *
	 * @since 1.0.0
	 * @param array $data Existing localized data array
	 * @return array Modified data array with Pix payment element data
	 */
	public function localize_element_data( $data ) {
		if ( !$this->is_available() ) {
			return $data;
		}
		$data['fkwcs_payment_data_pix'] = $this->payment_element_data();


		return $data;
	}

	/**
	 * Generate payment element configuration data for Pix.
	 *
	 * Creates the configuration array for Stripe Payment Elements specifically
	 * for Pix payments, including payment method types, appearance settings,
	 * and field configurations (billing details disabled, wallets disabled).
	 *
	 * @since 1.0.0
	 * @return array Payment element configuration with element_data and element_options
	 */
	public function payment_element_data() {

		$data    = $this->get_payment_element_options();
		$methods = [ 'Pix' ];


		$data['payment_method_types'] = apply_filters( 'fkwcs_available_payment_element_types', $methods );
		$data['appearance']           = array(
			"theme" => "stripe",
			'rules' => apply_filters('fkwcs_stripe_payment_element_rules', (object)[], $this)
		);

		$options                      = [
			'fields' => [
				'billingDetails' => 'never'
			]
		];
		$options['wallets']           = [ 'applePay' => 'never', 'googlePay' => 'never' ];

		return apply_filters( 'fkwcs_stripe_payment_element_data_Pix', [ 'element_data' => $data, 'element_options' => $options ], $this );

	}
	public function save_payment_method_details( $order, $charge_response ) {
		try {
			if ( isset( $charge_response->billing_details->tax_id ) && !empty( $charge_response->billing_details->tax_id ) ) {
				$order->update_meta_data( '_fkwcs_pix_tax_id', $charge_response->billing_details->tax_id );
				$order->save();

				Helper::log( 'Saved Pix tax ID for future upsells: for order ' . $order->get_id() . ' - ' . $charge_response->billing_details->tax_id );
			} else {
				Helper::log( 'No tax ID found in charge response billing details' );
			}
		} catch ( Exception | Error $e ) {
			Helper::log( 'Error saving Pix payment method details: ' . $e->getMessage() );
		}
	}


}
