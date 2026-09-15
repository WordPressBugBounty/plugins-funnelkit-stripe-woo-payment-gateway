<?php

namespace FKWCS\Gateway\Stripe\Line_Items;

use FKWCS\Gateway\Stripe\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Formatter {
	public static function format_amount( $amount, $currency ) {
		if ( '' === $amount || is_null( $amount ) ) {
			$amount = 0;
		}

		return Helper::get_stripe_amount( $amount, strtolower( $currency ) );
	}

	public static function sanitize_product_code( $code ) {
		$code = sanitize_text_field( (string) $code );
		$code = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', $code );
		$code = substr( $code, 0, 12 );

		return ( '' !== $code ) ? $code : 'item';
	}

	public static function sanitize_product_name( $name ) {
		$name = wp_strip_all_tags( (string) $name );
		$name = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
		$name = trim( $name );

		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, 1024 );
		} else {
			$name = substr( $name, 0, 1024 );
		}

		return ( '' !== $name ) ? $name : 'Item';
	}

	public static function sanitize_unit_of_measure( $unit ) {
		$unit = sanitize_text_field( (string) $unit );
		$unit = preg_replace( '/[^a-zA-Z0-9]/', '', $unit );
		$unit = substr( $unit, 0, 12 );

		return ( '' !== $unit ) ? $unit : 'each';
	}

	public static function sanitize_postal_code( $postal_code ) {
		$postal_code = sanitize_text_field( (string) $postal_code );
		$postal_code = str_replace( ' ', '', $postal_code );
		$postal_code = preg_replace( '/[^a-zA-Z0-9\\-]/', '', $postal_code );
		$postal_code = substr( $postal_code, 0, 10 );

		return $postal_code;
	}

	public static function sanitize_reference( $reference ) {
		$reference = sanitize_text_field( (string) $reference );
		$reference = preg_replace( '/\\s+/', '', $reference );
		$reference = preg_replace( '/[^a-zA-Z0-9]/', '', $reference );
		$reference = substr( $reference, 0, 25 );

		return $reference;
	}
}
