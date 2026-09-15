<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

#[\AllowDynamicProperties]
class Twint extends LocalGateway {

	public $id                   = 'fkwcs_stripe_twint';
	public $payment_method_types = 'twint';
	protected $payment_element   = true;

	protected function init() {
		$this->method_title       = __( 'Stripe TWINT Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Accepts payments via TWINT. The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>CHF</strong>', 'funnelkit-stripe-woo-payment-gateway' );

		$this->supported_currency    = array( 'CHF' );
		$this->specific_country      = array( 'CH' );
		$this->setting_enable_label  = __( 'Enable Stripe TWINT Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default = __( 'TWINT', 'funnelkit-stripe-woo-payment-gateway' );
		$this->title                 = $this->get_option( 'title' );
		$this->description           = $this->get_option( 'description' );
		$this->enabled               = $this->get_option( 'enabled' );
		$this->init_form_fields();
		$this->init_settings();
		add_filter( 'fkwcs_localized_data', array( $this, 'localize_element_data' ), 999 );
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
				'default'     => '',
				'desc_tip'    => true,
			),
		);

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
		$countries_fields['specific_countries']['default'] = array( 'CH' );
		$this->form_fields                                 = apply_filters( $this->id . '_payment_form_fields', array_merge( $settings, $countries_fields ) );
	}

	public function localize_element_data( $data ) {
		if ( ! $this->is_available() ) {
			return $data;
		}
		$data['fkwcs_payment_data_twint'] = $this->payment_element_data();

		return $data;
	}

	public function payment_element_data() {

		$data    = $this->get_payment_element_options();
		$methods = array( 'twint' );

		$data['payment_method_types'] = apply_filters( 'fkwcs_available_payment_element_types', $methods );
		$data['appearance']           = array(
			'theme' => 'stripe',
		);
		$options                      = array(
			'fields' => array(
				'billingDetails' => 'never',
			),
		);
		$options['wallets']           = array(
			'applePay'  => 'never',
			'googlePay' => 'never',
		);

		return apply_filters(
			'fkwcs_stripe_payment_element_data_twint',
			array(
				'element_data'    => $data,
				'element_options' => $options,
			),
			$this
		);
	}

	/**
	 * Mount-only template (see Cash App / EPS: no extra offsite row under the iframe).
	 */
	public function payment_fields() {
		do_action( $this->id . '_before_payment_field_checkout' );
		include __DIR__ . '/parts/twint-checkout.php';
		do_action( $this->id . '_after_payment_field_checkout' );
	}

	/**
	 * Fragment payload when gateway becomes available after checkout update (PIX / iDEAL pattern).
	 *
	 * @return array
	 */
	protected function get_element_data_for_fragments() {
		try {
			return $this->payment_element_data();
		} catch ( \Throwable $e ) {
			Helper::log( sprintf( 'Error getting TWINT element data for fragments: %s', $e->getMessage() ) );
			return array();
		}
	}

	public function verify_intent() {
		global $woocommerce;

		$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
		try {
			$order_id = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order    = wc_get_order( $order_id );

			if ( ! isset( $_GET['order_key'] ) || ! $order instanceof \WC_Order || ! $order->key_is_valid( wc_clean( wp_unslash( $_GET['order_key'] ) ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

			if ( ! $order->has_status( apply_filters( 'fkwcs_stripe_allowed_payment_processing_statuses', array( 'pending', 'failed' ), $order ) ) ) {
				$redirect_url = $this->get_return_url( $order );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			if ( 'setup_intent' === $intent->object && 'succeeded' === $intent->status ) {
				$order->payment_complete();
				do_action( 'fkwcs_' . $this->id . '_before_redirect', $order_id );
				$redirect_url = $this->get_return_url( $order );

				if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
					WC()->cart->empty_cart();
				}
			} elseif ( 'succeeded' === $intent->status || 'requires_capture' === $intent->status ) {
				$redirect_url = $this->process_final_order( end( $intent->charges->data ), $order_id );
			} elseif ( 'requires_payment_method' === $intent->status || 'requires_action' === $intent->status ) {

				$redirect_url = wc_get_checkout_url();
				wc_add_notice( __( 'Unable to process this payment, please try again or use alternative method.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );
				if ( isset( $_GET['wfacp_id'] ) && isset( $_GET['wfacp_is_checkout_override'] ) && 'no' === $_GET['wfacp_is_checkout_override'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$redirect_url = get_the_permalink( wc_clean( wp_unslash( $_GET['wfacp_id'] ) ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}

				if ( $order->has_status( 'failed' ) ) {
					wp_safe_redirect( $redirect_url );
					exit;
				}

				$status_message = isset( $intent->last_payment_error ) /* translators: 1) The error message that was received from Stripe. */ ? sprintf( __( 'Stripe SCA authentication failed. Reason: %s', 'funnelkit-stripe-woo-payment-gateway' ), $intent->last_payment_error->message ) : __( 'Stripe SCA authentication failed.', 'funnelkit-stripe-woo-payment-gateway' );
				$this->mark_order_failed( $order, $status_message );
			}
			Helper::log( 'Redirecting to :' . $redirect_url );
		} catch ( \Exception $e ) {
			$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
		}
		remove_all_actions( 'wp_redirect' );
		wp_safe_redirect( $redirect_url );
		exit;
	}
}
