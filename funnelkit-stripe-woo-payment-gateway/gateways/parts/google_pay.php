<?php

use FKWCS\Gateway\Stripe\Helper;

global $wp;
$total       = WC()->cart->total;

// If paying from order, we need to get total from order not cart.
if ( isset( $_GET['pay_for_order'] ) && ! empty( $_GET['key'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$order_obj = wc_get_order( wc_clean( $wp->query_vars['order-pay'] ) );
	$total     = $order_obj->get_total();
}

echo '<div id="fkwcs-stripe-google_pay-payment-data" class="fkwcs_local_gateway_wrapper" data-amount="' . esc_attr( Helper::get_stripe_amount( $total ) ) . '" data-currency="' . esc_attr( strtolower( get_woocommerce_currency() ) ) . '">';
echo "<div class='" . esc_attr( "{$this->id}_error fkwcs-error-text" ) . "'></div>";
echo "<div class='fkwcs_google_pay_button'></div>";

echo '</div>';
if ( ! empty( $this->description ) ) {
	echo '<p>';
	echo wptexturize( $this->description );  //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable,WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</p>';
}