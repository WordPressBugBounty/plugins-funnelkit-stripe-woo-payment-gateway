<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '<div id="fkwcs-stripe-google_pay-payment-data" class="fkwcs_local_gateway_wrapper">';
echo "<div class='" . esc_attr( "{$this->id}_error fkwcs-error-text" ) . "'></div>";
echo "<div class='fkwcs_google_pay_button'></div>";

echo '</div>';
if ( ! empty( $this->description ) ) {
	echo '<p>';
	echo wptexturize( $this->description );  //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable,WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</p>';
}
