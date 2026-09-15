<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Credit card enabled checking already Handled in Payment_request_button settings below
if ( ! empty( $this->description ) ) {

	echo '<p>';
	echo wptexturize( $this->description );  //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable,WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</p>';
}
echo '<div class="fkwcs_apple_pay_gateway_wrap fkwcs_wallet_gateways">';
echo '</div>';
