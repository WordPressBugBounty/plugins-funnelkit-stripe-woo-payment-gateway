<?php

use FKWCS\Gateway\Stripe\Helper;

global $wp;

$total       = WC()->cart->total;

// If paying from order, we need to get total from order not cart.
if ( isset( $_GET['pay_for_order'] ) && ! empty( $_GET['key'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$order_obj = wc_get_order( wc_clean( $wp->query_vars['order-pay'] ) );
	$total     = $order_obj->get_total();
}


echo '<div id="fkwcs-stripe-ideal-payment-data" data-amount="' . esc_attr( Helper::get_stripe_amount( $total ) ) . '" data-currency="' . esc_attr( strtolower( get_woocommerce_currency() ) ) . '">';

echo "<div class='fkwcs_stripe_ideal_form'><div class='fkwcs_stripe_ideal_select'></div></div>";
echo '</div>';