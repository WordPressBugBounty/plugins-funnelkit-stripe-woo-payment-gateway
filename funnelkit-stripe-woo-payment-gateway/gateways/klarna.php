<?php


namespace FKWCS\Gateway\Stripe;
#[\AllowDynamicProperties]
class klarna extends LocalGateway {

	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe_klarna';
	public $payment_method_types = 'klarna';
	protected $payment_element = true;
	public $supports_success_webhook = true;

	/**
	 * Setup general properties and settings
	 *
	 * @return void
	 */
	protected function init() {

		$this->method_title       = __( 'Klarna', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Accepts payments via Klarna. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong> EUR, DKK, GBP, NOK, SEK, USD, CZK, AUD, NZD, CAD, PLN, CHF</strong>', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle           = __( 'klarna is an online banking payment method that enables your customers in e-commerce to make an online purchase', 'funnelkit-stripe-woo-payment-gateway' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
		$this->capture_method        = $this->get_option( 'charge_type' );
		add_action( 'fkwcs_webhook_event_intent_succeeded', [ $this, 'handle_webhook_intent_succeeded' ], 10, 2 );


	}

	protected function override_defaults() {
		$this->supported_currency          = [ 'EUR', 'DKK', 'GBP', 'NOK', 'SEK', 'USD', 'CZK', 'AUD', 'NZD', 'CAD', 'CHF', 'PLN' ];
		$this->specific_country            = [ 'AU', 'CA', 'US', 'DK', 'NO', 'SE', 'GB', 'PL', 'CH', 'NZ', 'AT', 'BE', 'DE', 'ES', 'FI', 'FR', 'GR', 'IE', 'IT', 'NL', 'PT' ];
		$this->except_country              = [];
		$this->setting_enable_label        = __( 'Enable Klarna', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = __( 'Klarna - Pay Over Time', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = __( 'After clicking the checkout button, you will be redirected to Klarna to <br> complete your purchase securely', 'funnelkit-stripe-woo-payment-gateway' );
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
			'paylater_section' => [
				'title'       => __( 'Klarna Message Location', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'     => [ 'cart' ],
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'css'         => 'min-width: 350px;',
				'desc_tip'    => true,
				/* translators: gateway title */
				'description' => sprintf( __( 'This option lets you limit the %1$s Messages to Specific Pages.', 'funnelkit-stripe-woo-payment-gateway' ), $this->method_title ),
				'options'     => array(
					'product' => __( 'Product Page', 'funnelkit-stripe-woo-payment-gateway' ),
					'cart'    => __( 'Cart Page', 'funnelkit-stripe-woo-payment-gateway' ),
					'shop'    => __( 'Shop/Categories Page', 'funnelkit-stripe-woo-payment-gateway' ),
				),
			]
		];

		$stripe_account_settings = get_option( 'fkwcs_stripe_account_settings', [] );

		$admin_country = ! empty( $stripe_account_settings ) ? strtoupper( $stripe_account_settings['country'] ) : wc_format_country_state_string( get_option( 'woocommerce_default_country', '' ) )['country'];

		if ( in_array( $admin_country, $this->specific_country, true ) ) {

			if ( in_array( $admin_country, $this->list_of_eaa_and_supported_across_countries(), true ) ) {

				$this->specific_country = $this->list_of_eaa_and_supported_across_countries();
			} else {
				$this->specific_country = [ $admin_country ];
			}
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

	public function list_of_eaa_and_supported_across_countries() {
		$countryCodes = array(
			'AT', // Austria - EEA
			'BE', // Belgium - EEA
			'DK', // Denmark - EEA
			'FI', // Finland - EEA
			'FR', // France - EEA
			'DE', // Germany - EEA
			'GR', // Greece - EEA
			'IE', // Ireland - EEA
			'IT', // Italy - EEA
			'NL', // Netherlands - EEA
			'NO', // Norway - EEA
			'NZ', // New Zealand - NZ
			'PL', // Poland - EEA
			'PT', // Portugal - EEA
			'ES', // Spain - EEA
			'SE', // Sweden - EEA
			'CH', // Switzerland
			'GB'  // United Kingdom - GB
		);


		return $countryCodes;
	}

	/**
	 * @param \stdclass $intent
	 * @param \WC_Order $order
	 *
	 * @return void
	 */
	public function handle_webhook_intent_succeeded( $intent, $order ) {

		if ( false === wc_string_to_bool( $this->enabled ) ) {
			return;
		}

		if ( ! $order instanceof \WC_Order || $order->get_payment_method() !== $this->id || $order->is_paid() || ! is_null( $order->get_date_paid() ) || $order->has_status( 'wfocu-pri-order' ) ) {
			return;
		}

		$save_intent = $this->get_intent_from_order( $order );
		if ( empty( $save_intent ) ) {
			Helper::log( 'Could not find intent in the order handle_webhook_intent_succeeded ' . $order->get_id() );

			return;
		}

		if ( class_exists( '\WFOCU_Core' ) ) {
			Helper::log( $order->get_id() . ' :: Saving meta data during webhook to later process this order' );

			$order->update_meta_data( '_fkwcs_webhook_paid', 'yes' );
			$order->save_meta_data();
		} else {

			try {
				Helper::log( $order->get_id() . ' :: Processing order during webhook' );

				$this->handle_intent_success( $intent, $order );

			} catch ( \Exception $e ) {

			}
		}


	}


}