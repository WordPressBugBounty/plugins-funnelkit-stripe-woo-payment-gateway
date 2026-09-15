<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$description = $this->get_description(); //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable

echo '<div id="fkwcs-stripe-bancontact-payment-data">';

if ( $description ) {
	echo wp_kses_post( apply_filters( 'fkwcs_stripe_description', wpautop( wp_kses_post( $description ) ), $this->id ) ); //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
}

echo '</div>';
