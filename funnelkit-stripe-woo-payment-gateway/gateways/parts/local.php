<?php

use FKWCS\Gateway\Stripe\Helper;

global $wp;
$total       = 0;
$description = $this->get_description(); //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable

// If paying from order, we need to get total from order not cart.
if ( isset( $_GET['pay_for_order'] ) && ! empty( $_GET['key'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$order_obj = wc_get_order( wc_clean( $wp->query_vars['order-pay'] ) );
	$total     = $order_obj->get_total();
}

$method_type = $this->id;

?>
    <div id="<?php echo esc_attr( $method_type ) ?>_payment_data" class="fkwcs_local_gateway_wrapper" data-amount="<?php esc_attr_e( Helper::get_stripe_amount( $total ) ) ?>" data-currency="<?php esc_attr_e( strtolower( get_woocommerce_currency() ) ) ?>">
        <div class="<?php echo esc_attr( $method_type ) ?>_error fkwcs-error-text"></div>
        <div class="<?php echo esc_attr( $method_type ) ?>_form fkwcs_local_gateway_text">
            <div class="<?php echo esc_attr( $method_type ) ?>_select fkwcs_local_gateway_text"></div>
            <div class="fkwcs-offisite-redirect">
                <img src="<?php echo esc_url( FKWCS_URL . 'assets/icons/offsite.svg' ) ?>" class="fkwcs-stripe-klarna-icon stripe-icon" alt="fkwcs-offsite-link"/>
            </div>
			<?php
			if ( $description ) {
				?>
                <div class="fkwcs-test-description fkwcs_local_gateway_text">
					<?php echo wp_kses_post( apply_filters( 'fkwcs_stripe_description', wpautop( wp_kses_post( $description ) ), $this->id ) ); //phpcs:ignore                    VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable ?>
                </div>
				<?php
			}
			?>

        </div>
    </div>
<?php