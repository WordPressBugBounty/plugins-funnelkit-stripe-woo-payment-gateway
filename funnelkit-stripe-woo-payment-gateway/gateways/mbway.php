<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * MB WAY Payment Gateway
 *
 * MB WAY is a digital wallet payment method available in Portugal.
 * Customers initiate payments using their phone number and authenticate/approve
 * them using the MB WAY mobile app.
 *
 * Stripe Documentation: https://docs.stripe.com/payments/mb-way
 * JS Confirm Method: https://docs.stripe.com/js/payment_intents/confirm_mb_way_payment
 *
 * Limitations:
 * - Currency: EUR only
 * - Country: Portugal (PT)
 * - Amount Range: €0.50 to €5,000 per transaction
 * - No support for recurring payments/subscriptions
 * - No support for saved payment methods (single-use only)
 * - Manual capture not supported
 *
 * @since 1.15.0
 */

/**
 * MB WAY Payment Gateway Class
 *
 * Handles MB WAY payments for Portuguese customers.
 * This is a single-use payment method that does not support
 * recurring payments, subscriptions, or saved payment methods.
 *
 * @class   MBWay
 * @extends LocalGateway
 * @package FKWCS\Gateway\Stripe
 * @since   1.15.0
 */
#[\AllowDynamicProperties]
class MBWay extends LocalGateway {

	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe_mbway';

	/**
	 * Stripe payment method type identifier
	 *
	 * @var string
	 */
	public $payment_method_types = 'mb_way';

	/**
	 * Use Payment Element for checkout
	 *
	 * @var bool
	 */
	protected $payment_element = true;

	/**
	 * Shipping address is required for MB WAY payments
	 *
	 * @var bool
	 */
	protected $shipping_address_required = true;

	/**
	 * Setup general properties and settings
	 *
	 * @return void
	 */
	protected function init() {
		$this->method_title       = __( 'MB WAY Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Accepts payments via MB WAY (Portugal). The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>EUR</strong> | Supported Country: <strong>Portugal</strong>', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle           = __( 'MB WAY is a digital wallet payment method that enables your customers in Portugal to make secure online purchases using their phone', 'funnelkit-stripe-woo-payment-gateway' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->enabled        = $this->get_option( 'enabled' );
		$this->capture_method = 'automatic'; // MB WAY does not support manual capture
	}

	/**
	 * Override default gateway settings
	 *
	 * Sets MB WAY specific configuration:
	 * - Only EUR currency supported
	 * - Only available in Portugal (PT)
	 *
	 * @return void
	 */
	protected function override_defaults() {
		$this->supported_currency          = array( 'EUR' );
		$this->specific_country            = array( 'PT' );
		$this->except_country              = array();
		$this->setting_enable_label        = __( 'Enable MB WAY Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = __( 'MB WAY', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = __( 'Pay securely with MB WAY. After clicking "Complete order", you will receive a notification on your MB WAY app to approve the payment.', 'funnelkit-stripe-woo-payment-gateway' );

		// Set gateway icon (recommended size: 32x20px SVG)
		// Note: Icon file needs to be created at /assets/icons/mbway.svg
		$this->icon_url = FKWCS_URL . 'assets/icons/mbway.svg';
	}

	/**
	 * Initialize gateway form fields for admin settings
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

		// Configure country settings for Portugal only
		$countries_fields = $this->get_countries_admin_fields( $this->selling_country_type, $this->except_country, $this->specific_country );

		// Remove "all countries" and "all except" options since MB WAY is Portugal-specific
		if ( isset( $countries_fields['allowed_countries']['options']['all'] ) ) {
			unset( $countries_fields['allowed_countries']['options']['all'] );
		}

		if ( isset( $countries_fields['allowed_countries']['options']['all_except'] ) ) {
			unset( $countries_fields['allowed_countries']['options']['all_except'] );
		}

		if ( isset( $countries_fields['except_countries'] ) ) {
			unset( $countries_fields['except_countries'] );
		}

		// Set Portugal as the only available country
		$countries_fields['specific_countries']['options'] = $this->specific_country;
		$countries_fields['specific_countries']['default'] = array( 'PT' );

		$this->form_fields = apply_filters( $this->id . '_payment_form_fields', array_merge( $settings, $countries_fields ) );
	}

	/**
	 * Save payment method details to order meta
	 * Saves phone number from charge billing_details for future upsell use
	 *
	 * @param WC_Order $order Order object
	 * @param object   $charge_response Charge response from Stripe
	 *
	 * @return void
	 */
	public function save_payment_method_details( $order, $charge_response ) {
		try {
			// Save phone number from charge billing_details if available
			if ( isset( $charge_response->billing_details->phone ) && ! empty( $charge_response->billing_details->phone ) ) {
				$order->update_meta_data( '_fkwcs_mbway_phone', $charge_response->billing_details->phone );
				$order->save();

				Helper::log( 'Saved MB WAY phone number for future upsells: for order ' . $order->get_id() . ' - ' . $charge_response->billing_details->phone );
			} else {
				Helper::log( 'No phone number found in charge response billing details for order ' . $order->get_id() );
			}
		} catch ( \Exception | \Error $e ) {
			Helper::log( 'Error saving MB WAY payment method details: ' . $e->getMessage() );
		}
	}
}
