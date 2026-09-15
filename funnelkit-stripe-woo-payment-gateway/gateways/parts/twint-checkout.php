<?php
/**
 * TWINT checkout markup: Payment Element mount only (no offsite graphic / description).
 *
 * @package funnelkit-stripe-woo-payment-gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$method_type = $this->id;

?>
<div id="<?php echo esc_attr( $method_type ); ?>_payment_data" class="fkwcs_local_gateway_wrapper">
	<div class="<?php echo esc_attr( $method_type ); ?>_error fkwcs-error-text"></div>
	<div class="<?php echo esc_attr( $method_type ); ?>_form fkwcs_local_gateway_text">
		<div class="<?php echo esc_attr( $method_type ); ?>_select fkwcs_local_gateway_text"></div>
	</div>
</div>
