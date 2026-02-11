<?php


add_filter( 'fkwcs_express_buttons_is_only_buttons', '__return_true', 20 );
$instance = \FKWCS\Gateway\Stripe\SmartButtons::get_instance();
// Credit card enabled checking already Handled in Payment_request_button settings below
if ( ! empty( $this->description ) ) {

	echo '<p>';
	echo wptexturize( $this->description );  //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable,WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</p>';
}
echo '<div class="fkwcs_apple_pay_gateway_wrap fkwcs_wallet_gateways">';
$instance->payment_request_button( true );
echo '</div>';