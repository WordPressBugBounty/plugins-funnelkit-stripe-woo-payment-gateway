<?php

namespace FKWCS\Gateway\Stripe\Line_Items;

use WC_Order;
use WC_Order_Item_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Line_Item {
	/**
	 * @var WC_Order_Item_Product
	 */
	protected $item;

	/**
	 * @var WC_Order
	 */
	protected $order;

	public function __construct( WC_Order_Item_Product $item, WC_Order $order ) {
		$this->item  = $item;
		$this->order = $order;
	}

	public function to_array() {
		$product  = $this->item->get_product();
		$quantity = (int) $this->item->get_quantity();
		$quantity = max( 1, $quantity );

		$sku          = $product ? $product->get_sku() : '';
		$product_id   = $this->item->get_product_id();
		$variation_id = $this->item->get_variation_id();
		$product_code = $sku ? $sku : (string) $product_id;
		if ( ! $sku && ! empty( $variation_id ) ) {
			$product_code .= '-' . $variation_id;
		}

		$subtotal = (float) $this->item->get_subtotal();
		$total    = (float) $this->item->get_total();
		$tax      = (float) $this->item->get_total_tax();

		$unit_cost = $subtotal / $quantity;
		$discount  = max( 0, $subtotal - $total );

		$line_item = array(
			'product_code'    => Formatter::sanitize_product_code( $product_code ),
			'product_name'    => Formatter::sanitize_product_name( $this->item->get_name() ),
			'unit_cost'       => Formatter::format_amount( $unit_cost, $this->order->get_currency() ),
			'quantity'        => $quantity,
			'unit_of_measure' => Formatter::sanitize_unit_of_measure( 'each' ),
		);

		if ( $discount > 0 ) {
			$line_item['discount_amount'] = Formatter::format_amount( $discount, $this->order->get_currency() );
		}

		if ( $tax > 0 ) {
			$line_item['tax'] = array(
				'total_tax_amount' => Formatter::format_amount( $tax, $this->order->get_currency() ),
			);
		}

		return apply_filters( 'fkwcs_line_item_data', $line_item, $this->item, $this->order );
	}
}
