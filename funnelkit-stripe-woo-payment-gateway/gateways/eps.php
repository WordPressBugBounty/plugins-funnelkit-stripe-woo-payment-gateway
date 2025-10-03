<?php

namespace FKWCS\Gateway\Stripe;

class EPS extends LocalGateway {
	public $id = 'fkwcs_stripe_eps';
	public $payment_method_types = 'eps';
	protected $payment_element = true;

	/**
	 * Initialize the EPS gateway settings and configuration.
	 *
	 * Sets up method title, description, supported currencies, countries,
	 * and initializes form fields and settings. Also adds localization filter.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	protected function init() {
		$this->method_title       = __( 'Stripe EPS Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Accepts payments via EPS. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>EUR</strong>', 'funnelkit-stripe-woo-payment-gateway' );

		$this->supported_currency          = [ 'EUR'];
		$this->specific_country = [
			'AT', // Austria
			'AU', // Australia
			'BE', // Belgium
			'BG', // Bulgaria
			'CA', // Canada
			'CH', // Switzerland
			'HR', // Croatia
			'CY', // Cyprus
			'CZ', // Czech Republic
			'DE', // Germany
			'DK', // Denmark
			'EE', // Estonia
			'ES', // Spain
			'FI', // Finland
			'FR', // France
			'GB', // United Kingdom
			'GI', // Gibraltar
			'GR', // Greece
			'HK', // Hong Kong
			'HU', // Hungary
			'IE', // Ireland
			'IT', // Italy
			'JP', // Japan
			'LI', // Liechtenstein
			'LT', // Lithuania
			'LU', // Luxembourg
			'LV', // Latvia
			'MT', // Malta
			'MX', // Mexico
			'NL', // Netherlands
			'NO', // Norway
			'NZ', // New Zealand
			'PL', // Poland
			'PT', // Portugal
			'RO', // Romania
			'SE', // Sweden
			'SG', // Singapore
			'SI', // Slovenia
			'SK', // Slovakia
			'US'  // United States
		];
		$this->setting_enable_label        = __( 'Enable Stripe EPS Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = __( 'Stripe EPS', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = __( 'Pay with EPS', 'funnelkit-stripe-woo-payment-gateway' );
		$this->title                       = $this->get_option( 'title' );
		$this->description                 = $this->get_option( 'description' );
		$this->enabled                     = $this->get_option( 'enabled' );
		$this->init_form_fields();
		$this->init_settings();
		add_filter( 'fkwcs_localized_data', [ $this, 'localize_element_data' ], 999 );

	}

	/**
	 * Initialize and configure the admin form fields for EPS gateway.
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
		$countries_fields['specific_countries']['default'] = [ 'AT' ];
		$this->form_fields                                 = apply_filters( $this->id . '_payment_form_fields', array_merge( $settings, $countries_fields ) );
	}

	/**
	 * Add EPS-specific payment element data to localized frontend data.
	 *
	 * Appends EPS payment configuration to the localized data array
	 * that gets passed to frontend JavaScript for Stripe Elements integration.
	 *
	 * @since 1.0.0
	 * @param array $data Existing localized data array
	 * @return array Modified data array with EPS payment element data
	 */
	public function localize_element_data( $data ) {
		if ( !$this->is_available() ) {
			return $data;
		}
		$data['fkwcs_payment_data_eps'] = $this->payment_element_data();


		return $data;
	}

	/**
	 * Generate payment element configuration data for EPS.
	 *
	 * Creates the configuration array for Stripe Payment Elements specifically
	 * for EPS payments, including payment method types, appearance settings,
	 * and field configurations (billing details disabled, wallets disabled).
	 *
	 * @since 1.0.0
	 * @return array Payment element configuration with element_data and element_options
	 */
	public function payment_element_data() {

		$data    = $this->get_payment_element_options();
		$methods = [ 'eps' ];


		$data['payment_method_types'] = apply_filters( 'fkwcs_available_payment_element_types', $methods );
		$data['appearance']           = array(
			"theme" => "stripe"
		);
		$options                      = [
			'fields' => [
				'billingDetails' => 'never'
			]
		];
		$options['wallets']           = [ 'applePay' => 'never', 'googlePay' => 'never' ];

		return apply_filters( 'fkwcs_stripe_payment_element_data_eps', [ 'element_data' => $data, 'element_options' => $options ], $this );

	}
	/**
	 * Verify intent secret and redirect to the thankyou page
	 *
	 * @return void
	 */
	public function verify_intent() {
		global $woocommerce;

		$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
		try {
			$order_id = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order    = wc_get_order( $order_id );

			if ( ! isset( $_GET['order_key'] ) || ! $order instanceof \WC_Order || ! $order->key_is_valid( wc_clean( $_GET['order_key'] ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				throw new \Exception( __( 'Invalid Order Key.', 'funnelkit-stripe-woo-payment-gateway' ) );

			}

		} catch ( \Exception $e ) {
			/* translators: Error message text */
			$message = sprintf( __( 'Payment verification error: %s', 'funnelkit-stripe-woo-payment-gateway' ), $e->getMessage() );
			wc_add_notice( esc_html( $message ), 'error' );
			$this->handle_error( $e, $redirect_url );
		}

		try {
			$intent = $this->get_intent_from_order( $order );

			if ( false === $intent ) {
				throw new \Exception( 'Intent Not Found' );
			}

			if ( ! $order->has_status( apply_filters( 'fkwcs_stripe_allowed_payment_processing_statuses', [ 'pending', 'failed' ], $order ) ) ) {
				/**
				 * bail out if the status is not pending or failed
				 */
				$redirect_url = $this->get_return_url( $order );
				wp_safe_redirect( $redirect_url );
				exit;
			}


			if ( 'setup_intent' === $intent->object && 'succeeded' === $intent->status ) {
				$order->payment_complete();
				do_action( 'fkwcs_' . $this->id . '_before_redirect', $order_id );
				$redirect_url = $this->get_return_url( $order );


				// Remove cart.
				if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
					WC()->cart->empty_cart();
				}

			} else if ( 'succeeded' === $intent->status || 'requires_capture' === $intent->status ) {
				$redirect_url = $this->process_final_order( end( $intent->charges->data ), $order_id );
			} else if ( 'requires_payment_method' === $intent->status || 'requires_action' === $intent->status ) {


				$redirect_url = wc_get_checkout_url();
				wc_add_notice( __( 'Unable to process this payment, please try again or use alternative method.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );
				if ( isset( $_GET['wfacp_id'] ) && isset( $_GET['wfacp_is_checkout_override'] ) && 'no' === $_GET['wfacp_is_checkout_override'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended

					$redirect_url = get_the_permalink( wc_clean( $_GET['wfacp_id'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}

				/**
				 * Handle intent with no payment method here, we mark the order as failed and show users a notice
				 */
				if ( $order->has_status( 'failed' ) ) {
					wp_safe_redirect( $redirect_url );
					exit;

				}

				// Load the right message and update the status.
				$status_message = isset( $intent->last_payment_error ) /* translators: 1) The error message that was received from Stripe. */ ? sprintf( __( 'Stripe SCA authentication failed. Reason: %s', 'funnelkit-stripe-woo-payment-gateway' ), $intent->last_payment_error->message ) : __( 'Stripe SCA authentication failed.', 'funnelkit-stripe-woo-payment-gateway' );
				$this->mark_order_failed( $order, $status_message );

			}
			Helper::log( "Redirecting to :" . $redirect_url );
		} catch ( \Exception $e ) {
			$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
		}
		remove_all_actions( 'wp_redirect' );
		wp_safe_redirect( $redirect_url );
		exit;
	}

}