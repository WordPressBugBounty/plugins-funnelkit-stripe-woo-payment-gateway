<?php

namespace FKWCS\Gateway\Stripe;

class Multibanco extends LocalGateway {
	public $id = 'fkwcs_stripe_multibanco';
	public $payment_method_types = 'multibanco';
	protected $payment_element = true;

	/**
	 * Initialize the Multibanco gateway settings and configuration.
	 *
	 * Sets up method title, description, supported currencies, countries,
	 * and initializes form fields and settings. Also adds localization filter.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function init() {
		$this->method_title       = __( 'Stripe Multibanco Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Accepts payments via Multibanco. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>EUR</strong>', 'funnelkit-stripe-woo-payment-gateway' );

		$this->supported_currency          = [ 'EUR' ];
		$this->specific_country            = [
			'AT', // Austria
			'BE', // Belgium
			'BG', // Bulgaria
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
			'HU', // Hungary
			'IE', // Ireland
			'IT', // Italy
			'LV', // Latvia
			'LI', // Liechtenstein
			'LT', // Lithuania
			'LU', // Luxembourg
			'MT', // Malta
			'NL', // Netherlands
			'NO', // Norway
			'PL', // Poland
			'PT', // Portugal (primary country for Multibanco)
			'RO', // Romania
			'SK', // Slovakia
			'SI', // Slovenia
			'ES', // Spain
			'SE', // Sweden
			'CH', // Switzerland
			'GB', // United Kingdom
			'US', // United States
		];
		$this->setting_enable_label        = __( 'Enable Stripe Multibanco Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = __( 'Stripe Multibanco', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = __( 'Pay with Multibanco', 'funnelkit-stripe-woo-payment-gateway' );
		$this->title                       = $this->get_option( 'title' );
		$this->description                 = $this->get_option( 'description' );
		$this->enabled                     = $this->get_option( 'enabled' );
		$this->init_form_fields();
		$this->init_settings();
		add_filter( 'fkwcs_localized_data', [ $this, 'localize_element_data' ], 999 );

	}

	/**
	 * Initialize and configure the admin form fields for Multibanco gateway.
	 *
	 * Creates form fields for gateway configuration including enable/disable toggle,
	 * title, description, and country-specific settings. Handles country restrictions
	 * based on Stripe account settings and removes unsupported options.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function init_form_fields() {

		$settings = [
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
		$this->form_fields                                 = apply_filters( $this->id . '_payment_form_fields', array_merge( $settings, $countries_fields ) );
	}

	/**
	 * Check if the Multibanco payment gateway is available for use.
	 *
	 * Validates parent availability, checks if current currency (EUR) is supported,
	 * and ensures all requirements are met for Multibanco payments.
	 *
	 * @return bool True if gateway is available, false otherwise
	 * @since 1.0.0
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( ! in_array( get_woocommerce_currency(), $this->supported_currency ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Add Multibanco-specific payment element data to localized frontend data.
	 *
	 * Appends Multibanco payment configuration to the localized data array
	 * that gets passed to frontend JavaScript for Stripe Elements integration.
	 *
	 * @param array $data Existing localized data array
	 *
	 * @return array Modified data array with Multibanco payment element data
	 * @since 1.0.0
	 */
	public function localize_element_data( $data ) {
		if ( ! $this->is_available() ) {
			return $data;
		}
		$data['fkwcs_payment_data_multibanco'] = $this->payment_element_data();


		return $data;
	}

	/**
	 * Generate payment element configuration data for Multibanco.
	 *
	 * Creates the configuration array for Stripe Payment Elements specifically
	 * for Multibanco payments, including payment method types, appearance settings,
	 * and field configurations (billing details disabled, wallets disabled).
	 *
	 * @return array Payment element configuration with element_data and element_options
	 * @since 1.0.0
	 */
	public function payment_element_data() {

		$data    = $this->get_payment_element_options();
		$methods = [ 'multibanco' ];


		$data['payment_method_types'] = apply_filters( 'fkwcs_available_payment_element_types', $methods );
		$data['appearance']           = array(
			'theme' => 'stripe'
		);

		$options            = [
			'fields' => [
				'billingDetails' => 'never'
			]
		];
		$options['wallets'] = [ 'applePay' => 'never', 'googlePay' => 'never' ];

		return apply_filters( 'fkwcs_stripe_payment_element_data_multibanco', [ 'element_data' => $data, 'element_options' => $options ], $this );

	}


	/**
	 * Verify payment intent and redirect to appropriate page based on intent status.
	 *
	 * Handles the return flow from Stripe after payment intent processing.
	 * Validates order key, retrieves payment intent, and processes different
	 * intent statuses (succeeded, requires_action, requires_payment_method, etc.).
	 * For requires_action status, sets order to on-hold and triggers upsell setup.
	 *
	 * @return void Redirects to appropriate page and exits
	 * @throws \Exception When order key is invalid or intent is not found
	 * @since 1.0.0
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
			} else if ( 'processing' === $intent->status ) {

				$order->update_status( apply_filters( 'fkwcs_stripe_intent_processing_order_status', 'on-hold', $intent, $order, $this ) );
				$redirect_url = $this->get_return_url( $order );
			} else if ( 'requires_payment_method' === $intent->status ) {


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

			} else if ( 'requires_action' === $intent->status ) {

				$order_stock_reduced = Helper::get_meta( $order, '_order_stock_reduced' );

				if ( ! $order_stock_reduced ) {
					wc_reduce_stock_levels( $order_id );
				}

				$order->set_transaction_id( $intent->id );
				$others_info = __( 'Payment will be completed once payment_intent.succeeded webhook received from Stripe.', 'funnelkit-stripe-woo-payment-gateway' );

				/** translators: transaction id, other info */
				$order->update_status( 'on-hold', sprintf( __( 'Stripe charge awaiting payment: %1$s. %2$s', 'funnelkit-stripe-woo-payment-gateway' ), $intent->id, $others_info ) );
				$is_order_pay = $order->get_meta( '_is_order_pay_request' ) === 'yes';

				if ( ! $is_order_pay ) {
					do_action( 'fkwcs_' . $this->id . '_before_redirect', $order_id );
				}
				$redirect_to = $this->get_return_url( $order );
				Helper::log( "Redirecting to :" . $redirect_to );

				wp_safe_redirect( $redirect_to );
				exit;

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

	public function handle_refund_response_status( $refund_response, $reason, $amount, $refund_time, $refund_user_info, $order ) {
		if ( 'succeeded' === $refund_response->status ) {
			return true;
		} elseif ( 'pending' === $refund_response->status ) {
			return new \WP_Error( 'error', __( 'Your refund process is ', 'funnelkit-stripe-woo-payment-gateway' ) . ucfirst( $refund_response->status ) );
		} else {
			return new \WP_Error( 'error', __( 'Your refund process is ', 'funnelkit-stripe-woo-payment-gateway' ) . ucfirst( $refund_response->status ) );
		}
	}
}
