<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$description = $this->get_description(); //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable

echo '<div id="fkwcs-stripe-ideal-payment-data">';

if ( $description ) {
	echo wp_kses_post( apply_filters( 'fkwcs_stripe_description', wpautop( wp_kses_post( $description ) ), $this->id ) ); //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
}

echo "<div class='fkwcs_stripe_ideal_form'><div class='fkwcs_stripe_ideal_select'></div></div>";
echo '</div>';
