<?php

namespace FKWCS\Gateway\Stripe;

use WC_Payment_Token;
#[\AllowDynamicProperties]
/**
 * Stripe Payment Token.
 *
 * Representation of a payment token for SEPA.
 *
 * @class Token
 *
 */
class ACHToken extends WC_Payment_Token {

	/**
	 * Stores payment type.
	 *
	 * @var string
	 */
	protected $type = 'fkwcs_stripe_ach';

	/**
	 * Stores SEPA payment token data.
	 *
	 * @var array
	 */
	protected $extra_data = [
		'last4'               => '',
		'bank_name'               => '',
		'payment_method_type' => 'us_bank_account',
	];


	/**
	 * Hook prefix
	 *
	 *
	 * @return string
	 */
	protected function get_hook_prefix() {
		return 'fkwcs_payment_token_ach_get_';
	}


	/**
	 * Get type to display to user.
	 *
	 *
	 * @param string $deprecated Deprecated.
	 *
	 * @return string
	 */
	public function get_display_name( $deprecated = '' ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedParameter,VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$display = sprintf( /* translators: last 4 digits of IBAN account */ __( '%s ending in %s', 'funnelkit-stripe-woo-payment-gateway' ), $this->get_bank_name(), $this->get_last4() );

		return $display;
	}

	/**
	 * Validate SEPA payment tokens.
	 *
	 * These fields are required by all SEPA payment tokens:
	 * last4  - string Last 4 digits of the iBAN.
	 *
	 *
	 * @return boolean True if the passed data is valid
	 */
	public function validate() {
		if ( false === parent::validate() ) {
			return false;
		}

		if ( ! $this->get_last4( 'edit' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns the last four digits.
	 *
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string Last 4 digits
	 */
	public function get_last4( $context = 'view' ) {
		return $this->get_prop( 'last4', $context );
	}
	/**
	 * Returns the Bank Name.
	 *
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string Selected Bank Name.
	 */
	public function get_bank_name( $context = 'view' ) {
		return $this->get_prop( 'bank_name', $context );
	}

	/**
	 * Set the last four digits.
	 *
	 *
	 * @param string $last4 Last 4 digits card number.
	 *
	 * @return void
	 */
	public function set_last4( $last4 ) {
		$this->set_prop( 'last4', $last4 );
	}
	/**
	 * Set the Bank Name.
	 *
	 *
	 * @param string $bank_name Select Bank Name.
	 *
	 * @return void
	 */
	public function set_bank_name( $bank_name ) {
		$this->set_prop( 'bank_name', $bank_name );
	}

	/**
	 * Set Stripe payment method type.
	 *
	 *
	 * @param string $type Payment method type.
	 *
	 * @return void
	 */
	public function set_payment_method_type( $type ) {
		$this->set_prop( 'payment_method_type', $type );
	}

	/**
	 * Returns Stripe payment method type.
	 *
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return string $payment_method_type
	 */
	public function get_payment_method_type( $context = 'view' ) {
		return $this->get_prop( 'payment_method_type', $context );
	}
}