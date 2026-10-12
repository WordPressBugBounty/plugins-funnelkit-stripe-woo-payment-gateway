<?php

namespace FKWCS\Gateway\Stripe\Line_Items;

use FKWCS\Gateway\Stripe\Helper;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Line_Items_Builder {
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
	 * When true, line items are built only from $products (e.g. upsell package).
	 * Order line items, fees, and shipping are not used.
	 *
	 * @var bool
	 */
	protected $package_only;

	public function __construct( WC_Order $order, $payment_method_type, $products = array(), $expected_total = null, $package_only = false ) {
		$this->order               = $order;
		$this->payment_method_type = $payment_method_type;
		$this->products            = is_array( $products ) ? $products : array();
		$this->expected_total      = is_null( $expected_total ) ? null : (float) $expected_total;
		$this->package_only        = (bool) $package_only;
	}

	public function is_enabled() {
		return Helper::line_items_enabled();
	}

	public function build() {
		if ( ! $this->is_enabled() ) {
			return array();
		}

		$line_items = array();

		if ( ! empty( $this->products ) ) {
			$line_items = $this->build_from_products( $this->products );
		} elseif ( $this->package_only ) {
			// Upsell/package context with no package products: do not use order items.
			return array();
		} else {
			foreach ( $this->order->get_items( 'line_item' ) as $item ) {
				if ( $item instanceof WC_Order_Item_Product ) {
					$line_items[] = ( new Line_Item( $item, $this->order ) )->to_array();
				}
			}

			$line_items = array_merge( $line_items, $this->build_from_fees() );
			$line_items = array_merge( $line_items, $this->build_from_shipping() );
		}

		$line_items = array_values( array_filter( $line_items ) );

		// When building from upsell package, ensure line items total matches the package total (Stripe requirement).
		if ( $this->package_only && ! empty( $line_items ) && ! is_null( $this->expected_total ) ) {
			$currency        = $this->order->get_currency();
			$expected_stripe = Formatter::format_amount( $this->expected_total, $currency );
			$line_items_sum  = $this->sum_line_items( $line_items );
			if ( abs( $line_items_sum - $expected_stripe ) > 1 ) {
				Helper::log( sprintf( 'Line items total mismatch (package). Line items: %d, Expected total: %d, Order: %d. Normalizing to package total.', $line_items_sum, $expected_stripe, $this->order->get_id() ) );
				$line_items = $this->build_single_line_item_for_package_total( $expected_stripe, $currency, $line_items );
			}
		}

		$line_items = $this->validate_total( $line_items );

		return $line_items;
	}

	/**
	 * Sum line items total (unit_cost * quantity + tax - discount) in Stripe units.
	 *
	 * @param array $line_items
	 * @return int
	 */
	private function sum_line_items( array $line_items ) {
		$sum = 0;
		foreach ( $line_items as $line_item ) {
			$quantity = isset( $line_item['quantity'] ) ? (int) $line_item['quantity'] : 1;
			$unit     = isset( $line_item['unit_cost'] ) ? (int) $line_item['unit_cost'] : 0;
			$tax      = isset( $line_item['tax']['total_tax_amount'] ) ? (int) $line_item['tax']['total_tax_amount'] : 0;
			$discount = isset( $line_item['discount_amount'] ) ? (int) $line_item['discount_amount'] : 0;
			$sum     += ( $unit * $quantity ) + $tax - $discount;
		}
		return $sum;
	}

	/**
	 * Build a single line item for package total so amount_details match the payment intent amount.
	 * Uses first product name/code for display when available.
	 *
	 * @param int    $stripe_total Total in Stripe smallest unit (e.g. cents).
	 * @param string $currency
	 * @param array  $original_items Original items (used for product name/code).
	 * @return array
	 */
	private function build_single_line_item_for_package_total( $stripe_total, $currency, array $original_items ) {
		$product_name = __( 'Upsell', 'funnelkit-stripe-woo-payment-gateway' );
		$product_code = 'upsell';
		if ( ! empty( $original_items[0] ) && is_array( $original_items[0] ) ) {
			if ( ! empty( $original_items[0]['product_name'] ) ) {
				$product_name = $original_items[0]['product_name'];
			}
			if ( ! empty( $original_items[0]['product_code'] ) && 'item' !== $original_items[0]['product_code'] ) {
				$product_code = $original_items[0]['product_code'];
			}
		}
		return array(
			array(
				'product_code'    => Formatter::sanitize_product_code( $product_code ),
				'product_name'    => Formatter::sanitize_product_name( $product_name ),
				'unit_cost'       => (int) $stripe_total,
				'quantity'        => 1,
				'unit_of_measure' => Formatter::sanitize_unit_of_measure( 'each' ),
			),
		);
	}

	private function build_from_fees() {
		$items = array();
		foreach ( $this->order->get_items( 'fee' ) as $fee ) {
			$total = (float) $fee->get_total();
			$tax   = (float) $fee->get_total_tax();

			$items[] = array(
				'product_code'    => 'fee',
				'product_name'    => Formatter::sanitize_product_name( $fee->get_name() ? 'Fee: ' . $fee->get_name() : 'Fee' ),
				'unit_cost'       => Formatter::format_amount( $total, $this->order->get_currency() ),
				'quantity'        => 1,
				'unit_of_measure' => Formatter::sanitize_unit_of_measure( 'each' ),
				'tax'             => ( $tax > 0 ) ? array(
					'total_tax_amount' => Formatter::format_amount( $tax, $this->order->get_currency() ),
				) : null,
			);
		}

		return $this->filter_line_items( $items );
	}

	private function build_from_shipping() {
		$items = array();
		foreach ( $this->order->get_items( 'shipping' ) as $shipping ) {
			$total = (float) $shipping->get_total();
			$tax   = (float) $shipping->get_total_tax();

			$items[] = array(
				'product_code'    => 'shipping',
				'product_name'    => Formatter::sanitize_product_name( $shipping->get_name() ? $shipping->get_name() : 'Shipping' ),
				'unit_cost'       => Formatter::format_amount( $total, $this->order->get_currency() ),
				'quantity'        => 1,
				'unit_of_measure' => Formatter::sanitize_unit_of_measure( 'each' ),
				'tax'             => ( $tax > 0 ) ? array(
					'total_tax_amount' => Formatter::format_amount( $tax, $this->order->get_currency() ),
				) : null,
			);
		}

		return $this->filter_line_items( $items );
	}

	private function build_from_products( $products ) {
		$items = array();

		foreach ( $products as $product_item ) {
			if ( $product_item instanceof WC_Order_Item_Product ) {
				$items[] = ( new Line_Item( $product_item, $this->order ) )->to_array();
				continue;
			}

			if ( ! is_array( $product_item ) ) {
				continue;
			}

			$product = isset( $product_item['data'] ) && $product_item['data'] instanceof WC_Product ? $product_item['data'] : null;
			$qty     = isset( $product_item['qty'] ) ? (int) $product_item['qty'] : ( isset( $product_item['quantity'] ) ? (int) $product_item['quantity'] : 1 );
			$qty     = max( 1, $qty );

			$subtotal = isset( $product_item['subtotal'] ) ? (float) $product_item['subtotal'] : ( isset( $product_item['line_subtotal'] ) ? (float) $product_item['line_subtotal'] : null );
			$total    = isset( $product_item['total'] ) ? (float) $product_item['total'] : ( isset( $product_item['line_total'] ) ? (float) $product_item['line_total'] : null );
			$tax      = isset( $product_item['tax'] ) ? (float) $product_item['tax'] : ( isset( $product_item['line_tax'] ) ? (float) $product_item['line_tax'] : 0 );
			$price    = isset( $product_item['price'] ) ? (float) $product_item['price'] : ( isset( $product_item['item_price'] ) ? (float) $product_item['item_price'] : null );

			if ( null === $subtotal && null !== $total ) {
				$subtotal = $total;
			}

			if ( null === $subtotal && $product ) {
				$subtotal = (float) $product->get_price() * $qty;
			}

			$discount = ( null !== $subtotal && null !== $total ) ? max( 0, $subtotal - $total ) : 0;
			if ( isset( $product_item['discount'] ) ) {
				$discount = (float) $product_item['discount'];
			} elseif ( isset( $product_item['discount_amount'] ) ) {
				$discount = (float) $product_item['discount_amount'];
			}
			$unit = 0;
			if ( null !== $subtotal ) {
				$unit = $subtotal / $qty;
			} elseif ( null !== $total ) {
				$unit = $total / $qty;
			} elseif ( null !== $price ) {
				$unit = $price;
			}

			$product_code = '';
			$product_name = '';
			if ( $product ) {
				$product_code = $product->get_sku() ? $product->get_sku() : (string) $product->get_id();
				$product_name = $product->get_name();
			} else {
				if ( isset( $product_item['sku'] ) ) {
					$product_code = (string) $product_item['sku'];
				} elseif ( isset( $product_item['id'] ) ) {
					$product_code = (string) $product_item['id'];
				} elseif ( isset( $product_item['item_number'] ) ) {
					$product_code = (string) $product_item['item_number'];
				}
				if ( isset( $product_item['name'] ) ) {
					$product_name = (string) $product_item['name'];
				}
			}

			$items[] = array(
				'product_code'    => Formatter::sanitize_product_code( $product_code ),
				'product_name'    => Formatter::sanitize_product_name( $product_name ),
				'unit_cost'       => Formatter::format_amount( $unit, $this->order->get_currency() ),
				'quantity'        => $qty,
				'unit_of_measure' => Formatter::sanitize_unit_of_measure( 'each' ),
				'discount_amount' => ( $discount > 0 ) ? Formatter::format_amount( $discount, $this->order->get_currency() ) : null,
				'tax'             => ( $tax > 0 ) ? array(
					'total_tax_amount' => Formatter::format_amount( $tax, $this->order->get_currency() ),
				) : null,
			);
		}

		return $this->filter_line_items( $items );
	}

	private function filter_line_items( $items ) {
		$filtered = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item       = array_filter(
				$item,
				function ( $value ) {
					return ! is_null( $value ) && '' !== $value;
				}
			);
			$filtered[] = $item;
		}

		return $filtered;
	}

	/**
	 * Ensure the itemized line-item sum exactly matches the PaymentIntent amount.
	 *
	 * Stripe rejects the PaymentIntent when sum( amount_details.line_items ) differs from `amount`
	 * (even by 1 cent). Because each line derives its unit_cost/tax/discount from independent
	 * roundings, the reconstructed sum can drift from the single-conversion PI amount on
	 * VAT-inclusive, mixed-tax or qty>1 orders. This actively reconciles any residual difference so
	 * the invariant holds on every checkout flow (not just upsell packages).
	 *
	 * @param array $line_items
	 * @return array Reconciled line items.
	 */
	private function validate_total( array $line_items ) {
		if ( is_null( $this->expected_total ) ) {
			return $line_items;
		}

		$currency        = $this->order->get_currency();
		$expected_stripe = (int) Formatter::format_amount( $this->expected_total, $currency );
		$line_items_sum  = $this->sum_line_items( $line_items );

		if ( $line_items_sum === $expected_stripe ) {
			return $line_items;
		}

		Helper::log( sprintf( 'Line items total mismatch. Line items: %d, Expected total: %d, Order: %d. Reconciling.', $line_items_sum, $expected_stripe, $this->order->get_id() ) );
		do_action( 'fkwcs_line_items_total_mismatch', $this->order, $line_items_sum, $expected_stripe, $line_items );

		return $this->reconcile_line_items( $line_items, $expected_stripe );
	}

	/**
	 * Absorb a rounding-sized difference between the line-item sum and the expected Stripe total.
	 *
	 * Tax amounts are never changed. A gap no larger than the per-line rounding can produce
	 * (one minor unit per line plus one per unit of quantity) goes into the price of a quantity-1
	 * line or into a line's discount. A larger or unabsorbable gap is not a rounding issue, so
	 * amount_details is omitted and the PaymentIntent is sent with only `amount`.
	 *
	 * @param array $line_items
	 * @param int   $expected_stripe Expected total in Stripe smallest unit.
	 * @return array Reconciled line items, or an empty array to omit amount_details.
	 */
	private function reconcile_line_items( array $line_items, $expected_stripe ) {
		$expected_stripe = (int) $expected_stripe;
		$diff            = $expected_stripe - $this->sum_line_items( $line_items );

		if ( 0 === $diff ) {
			return $line_items;
		}

		$tolerance = 0;
		foreach ( $line_items as $line_item ) {
			$tolerance += 1 + max( 1, isset( $line_item['quantity'] ) ? (int) $line_item['quantity'] : 1 );
		}

		if ( ! empty( $line_items ) && abs( $diff ) <= $tolerance ) {
			// Price first: a priced quantity-1 line takes the difference 1:1 in unit_cost (free lines such as free shipping stay free).
			for ( $i = count( $line_items ) - 1; $i >= 0; $i-- ) {
				$unit_cost = isset( $line_items[ $i ]['unit_cost'] ) ? (int) $line_items[ $i ]['unit_cost'] : 0;
				$discount  = isset( $line_items[ $i ]['discount_amount'] ) ? (int) $line_items[ $i ]['discount_amount'] : 0;
				if ( $unit_cost > 0 && 1 === ( isset( $line_items[ $i ]['quantity'] ) ? (int) $line_items[ $i ]['quantity'] : 1 ) && $unit_cost + $diff >= $discount ) {
					$line_items[ $i ]['unit_cost'] = $unit_cost + $diff;

					return $line_items;
				}
			}

			// Otherwise the discount: a shortfall reduces it, an overage adds to it.
			for ( $i = count( $line_items ) - 1; $i >= 0; $i-- ) {
				$discount = isset( $line_items[ $i ]['discount_amount'] ) ? (int) $line_items[ $i ]['discount_amount'] : 0;
				$gross    = ( isset( $line_items[ $i ]['unit_cost'] ) ? (int) $line_items[ $i ]['unit_cost'] : 0 ) * ( isset( $line_items[ $i ]['quantity'] ) ? (int) $line_items[ $i ]['quantity'] : 1 );
				$new      = $discount - $diff;
				if ( $new >= 0 && $new <= $gross ) {
					$line_items[ $i ]['discount_amount'] = $new;
					if ( 0 === $new ) {
						unset( $line_items[ $i ]['discount_amount'] );
					}

					return $line_items;
				}
			}
		}

		Helper::log( sprintf( 'Line items differ from the payment amount by %d (tolerance %d), order %d. Sending the PaymentIntent without amount_details.', $diff, $tolerance, $this->order ? $this->order->get_id() : 0 ) );

		return array();
	}
}
