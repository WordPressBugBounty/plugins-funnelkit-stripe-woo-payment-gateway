<?php

namespace FKWCS\Gateway\Stripe;

use WC_Payment_Token;

#[\AllowDynamicProperties]
/**
 * Stripe Payment Token for Cash App Pay.
 *
 * Representation of a payment token for Cash App Pay.
 *
 * @class CashAppToken
 */
class CashAppToken extends WC_Payment_Token {

	/**
	 * Stores payment type.
	 *
	 * @var string
	 */
	protected $type = 'fkwcs_stripe_cashapp';

	/**
	 * Stores Cash App payment token data.
	 *
	 * @var array
	 */
	protected $extra_data = [
		'payment_method_type' => 'cashapp',
	];

	/**
	 * Hook prefix
	 *
	 * @return string
	 */
	protected function get_hook_prefix() {
		return 'fkwcs_payment_token_cashapp_get_';
	}

	/**
	 * Get type to display to user.
	 *
	 * @param string $deprecated Deprecated.
	 * @return string
	 */
	public function get_display_name( $deprecated = '' ) {
		return __( 'Cashapp', 'funnelkit-stripe-woo-payment-gateway' );
	}

	/**
	 * Validate Cash App payment tokens.
	 *
	 * @return boolean True if the passed data is valid
	 */
	public function validate() {
		if ( false === parent::validate() ) {
			return false;
		}

		if ( ! $this->get_payment_method_type( 'edit' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Set Stripe payment method type.
	 *
	 * @param string $type Payment method type.
	 * @return void
	 */
	public function set_payment_method_type( $type ) {
		$this->set_prop( 'payment_method_type', $type );
	}

	/**
	 * Returns Stripe payment method type.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 * @return string $payment_method_type
	 */
	public function get_payment_method_type( $context = 'view' ) {
		return $this->get_prop( 'payment_method_type', $context );
	}
}