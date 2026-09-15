<?php

namespace FKWCS\Gateway\Stripe;

use FKWCS\Gateway\Stripe\Line_Items\Line_Items_Builder;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Amount_Details {
	/**
	 * @var WC_Order
	 */
	protected $order;

	/**
	 * @var string
	 */
	protected $payment_method_type;

	/**
	 * @var array
	 */
	protected $products;

	/**
	 * @var float|null
	 */
	protected $expected_total;

	/**
	 * When true, amount details are built only from package data (e.g. upsell);
	 * order line items, fees, and shipping are not used. If package data is empty, build() returns empty.
	 *
	 * @var bool
	 */
	protected $package_only;

	public function __construct( WC_Order $order, $payment_method_type = 'card', $products = array(), $package_only = false ) {
		$this->order               = $order;
		$this->payment_method_type = $payment_method_type;
		$this->expected_total      = null;
		$this->package_only        = (bool) $package_only;

		if ( is_array( $products ) && isset( $products['products'] ) && is_array( $products['products'] ) ) {
			$this->products       = $products['products'];
			$this->expected_total = isset( $products['total'] ) ? (float) $products['total'] : null;
		} elseif ( is_array( $products ) && isset( $products['cart_details'] ) && is_array( $products['cart_details'] ) ) {
			$this->products = $products['cart_details'];
			if ( isset( $products['price'] ) ) {
				$this->expected_total = (float) $products['price'];
			} elseif ( isset( $products['total'] ) ) {
				$this->expected_total = (float) $products['total'];
			}
		} else {
			$this->products = is_array( $products ) ? $products : array();
			if ( empty( $this->products ) ) {
				$this->expected_total = $this->package_only ? null : (float) $order->get_total();
			} elseif ( isset( $products['total'] ) ) {
				$this->expected_total = (float) $products['total'];
			}
		}
	}

	public function is_enabled() {
		if ( $this->package_only && empty( $this->products ) ) {
			return false;
		}
		$builder = new Line_Items_Builder( $this->order, $this->payment_method_type, $this->products, $this->expected_total, $this->package_only );

		return $builder->is_enabled();
	}

	public function build() {
		if ( $this->package_only && empty( $this->products ) ) {
			return array();
		}
		$builder    = new Line_Items_Builder( $this->order, $this->payment_method_type, $this->products, $this->expected_total, $this->package_only );
		$line_items = $builder->build();

		if ( empty( $line_items ) ) {
			return array();
		}

		$amount_details = array(
			'line_items' => $line_items,
		);

		$postal_code = $this->order->get_shipping_postcode();
		if ( empty( $postal_code ) ) {
			$postal_code = $this->order->get_billing_postcode();
		}
		$postal_code = Line_Items\Formatter::sanitize_postal_code( $postal_code );
		if ( ! empty( $postal_code ) ) {
			$amount_details['shipping'] = array(
				'to_postal_code' => $postal_code,
			);
		}

		return apply_filters( 'fkwcs_amount_details', $amount_details, $this->order, $this->payment_method_type, $this->products );
	}
}
