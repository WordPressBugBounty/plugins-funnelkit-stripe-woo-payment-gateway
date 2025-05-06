<?php

namespace FKWCS\Gateway\Stripe;

trait Funnelkit_Stripe_Smart_Buttons {


	/**
	 * Gets product data either form product page or page where shortcode is used
	 *
	 * @return object|false
	 */
	public function get_product() {
		global $post;
		if ( is_product() ) {
			return wc_get_product( $post->ID );
		} elseif ( wc_post_content_has_shortcode( 'product_page' ) ) {
			/** Get id from product_page shortcode */
			preg_match( '/\[product_page id="(?<id>\d+)"\]/', $post->post_content, $shortcode_match );

			if ( ! isset( $shortcode_match['id'] ) ) {
				return false;
			}

			return wc_get_product( $shortcode_match['id'] );
		}

		return false;
	}

	/**
	 * Get data of selected product
	 *
	 * @return false|mixed|null
	 * @throws \Exception
	 */
	public function get_product_data() {
		if ( ! $this->is_product() ) {
			return false;
		}

		$product = $this->get_product();
		if ( 'variable' === $product->get_type() ) {
			$variation_attributes = $product->get_variation_attributes();
			$attributes           = [];

			foreach ( $variation_attributes as $attribute_name => $attribute_values ) {
				$attribute_key = 'attribute_' . sanitize_title( $attribute_name );

				$attributes[ $attribute_key ] = isset( $_GET[ $attribute_key ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					? wc_clean( wp_unslash( $_GET[ $attribute_key ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					: $product->get_variation_default_attribute( $attribute_name );
			}

			$data_store   = \WC_Data_Store::load( 'product' );
			$variation_id = $data_store->find_matching_product_variation( $product, $attributes );

			if ( ! empty( $variation_id ) ) {
				$product = wc_get_product( $variation_id );
			}
		}

		$data  = [];
		$items = [];

		if ( 'subscription' === $product->get_type() && class_exists( '\WC_Subscriptions_Product' ) ) {

			$items[] = $this->get_smart_button_line_item( $product->get_name(), $product->get_price() );
			$items[] = $this->get_smart_button_line_item( __( 'Sign up Fee', 'funnelkit-stripe-woo-payment-gateway' ), \WC_Subscriptions_Product::get_sign_up_fee( $product ) );

		} else {
			$items[] = $this->get_smart_button_line_item( $product->get_name(), $product->get_price() );
		}

		if ( wc_tax_enabled() ) {
			$items[] = [
				'label'   => __( 'Tax', 'funnelkit-stripe-woo-payment-gateway' ),
				'amount'  => 0,
				'type'    => 'tax',
				'pending' => true,
			];
		}

		if ( wc_shipping_enabled() && $product->needs_shipping() ) {
			$items[] = [
				'label'   => __( 'Shipping', 'funnelkit-stripe-woo-payment-gateway' ),
				'amount'  => 0,
				'pending' => true,
			];

			$data['shippingOptions'] = [
				'id'     => 'pending',
				'label'  => __( 'Pending', 'funnelkit-stripe-woo-payment-gateway' ),
				'detail' => '',
				'amount' => 0,
			];
		}

		$data['displayItems'] = $items;


		$total_amount            = $this->get_product_price( $product );
		$data['total']           = $this->get_smart_button_line_totals( [
			'label'   => __( 'Total', 'funnelkit-stripe-woo-payment-gateway' ),
			'amount'  => Helper::get_stripe_amount( $total_amount ),
			'pending' => true,
		], $total_amount );
		$data['requestShipping'] = wc_bool_to_string( wc_shipping_enabled() && $product->needs_shipping() && 0 !== wc_get_shipping_method_count( true ) );

		return apply_filters( 'fkwcs_payment_request_product_data', $data, $product );
	}

	protected function build_display_items( $display_items = true, $is_localized = false ) {

		if ( false === $is_localized ) {
			wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );
		}

		$items     = [];
		$lines     = [];
		$subtotal  = 0;
		$discounts = 0;

		foreach ( WC()->cart->get_cart() as $item ) {
			$subtotal       += $item['line_subtotal'];
			$amount         = $item['line_subtotal'];
			$quantity_label = 1 < $item['quantity'] ? ' (' . $item['quantity'] . ')' : '';
			$product_name   = $item['data']->get_name();
			$items[]        = $this->get_smart_button_line_item( $product_name . $quantity_label, $amount );
		}

		if ( $display_items ) {
			$items = array_merge( $items, $lines );
		} else {
			/** Default show only subtotal instead of itemization */
			$items[] = $this->get_smart_button_line_item( 'Subtotal', $subtotal, 'SUBTOTAL' );
		}

		$applied_coupons = array_values( WC()->cart->get_coupon_discount_totals() );
		foreach ( $applied_coupons as $amount ) {
			$discounts += (float) $amount;
		}

		$discounts   = wc_format_decimal( $discounts, WC()->cart->dp );
		$tax         = wc_format_decimal( WC()->cart->tax_total + WC()->cart->shipping_tax_total, WC()->cart->dp );
		$shipping    = wc_format_decimal( WC()->cart->shipping_total, WC()->cart->dp );
		$order_total = WC()->cart->get_total( false );

		if ( wc_tax_enabled() ) {
			$items[] = $this->get_smart_button_line_item( esc_html( __( 'Tax', 'funnelkit-stripe-woo-payment-gateway' ) ), $tax, 'TAX' );
		}

		if ( WC()->cart->needs_shipping() ) {
			$items[] = $this->get_smart_button_line_item( esc_html( __( 'Shipping', 'funnelkit-stripe-woo-payment-gateway' ) ), $shipping );
		}

		if ( WC()->cart->has_discount() ) {
			$items[] = $this->get_smart_button_line_item( esc_html( __( 'Discount', 'funnelkit-stripe-woo-payment-gateway' ) ), $discounts );
		}

		$cart_fees = WC()->cart->get_fees();

		/** Include fees and taxes as display items */
		foreach ( $cart_fees as $fee ) {
			$items[] = $this->get_smart_button_line_item( $fee->name, $fee->amount );
		}


		$totals = $this->get_smart_button_line_totals( [
			'label'   => __( 'Total', 'funnelkit-stripe-woo-payment-gateway' ),
			'amount'  => max( 0, apply_filters( 'fkwcs_stripe_calculated_total', Helper::get_stripe_amount( $order_total ), $order_total, WC()->cart ) ),
			'pending' => false,
		], $order_total );

		return [
			'displayItems' => $items,
			'total'        => $totals,
		];
	}

	protected function get_smart_button_line_item( $name, $amount, $type = 'LINE_ITEM' ) {
		return [
			'label'  => $name,
			'type'   => $type,
			'amount' => Helper::get_stripe_amount( $amount ),
		];
	}

	protected function get_smart_button_line_totals( $data, $total_amount = 0 ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

		return $data;
	}

	/**
	 * Get price of selected product
	 *
	 * @param object $product Selected product data.
	 *
	 * @return string
	 */
	public function get_product_price( $product ) {
		$product_price = $product->get_price();
		/** Add subscription sign-up fees to product price */
		if ( 'subscription' === $product->get_type() && class_exists( '\WC_Subscriptions_Product' ) ) {
			$product_price = $product->get_price() + \WC_Subscriptions_Product::get_sign_up_fee( $product );
		}

		return $product_price;
	}

	/**
	 * Fetch cart details
	 *
	 * @return array
	 */
	public function ajax_get_cart_details( $is_localized = false ) {
		if ( 'wc_ajax_fkwcs_get_cart_details' === current_action() ) {
			check_ajax_referer( 'fkwcs_nonce', 'fkwcs_nonce' );

		}
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', [] );
		WC()->cart->calculate_totals();
		$this->maybe_restore_recurring_chosen_shipping_methods( $chosen_shipping_methods );
		$currency = get_woocommerce_currency();
		/** Set mandatory payment details */
		$data = [
			'shipping_required' => wc_bool_to_string( WC()->cart->needs_shipping() ),
			'order_data'        => [
				'currency'     => strtolower( $currency ),
				'country_code' => substr( get_option( 'woocommerce_default_country' ), 0, 2 ),
			],
		];

		/**
		 * disabled GPay button if stripe not need payments
		 */
		$data['is_fkwcs_need_payment'] = false;
		if ( WC()->cart->needs_payment() ) {
			$data['is_fkwcs_need_payment'] = true;
		}

		$data['order_data']       += $this->build_display_items( true, $is_localized );
		$data['shipping_options'] = $this->get_formatted_shipping_methods();

		if ( 'wc_ajax_fkwcs_get_cart_details' === current_action() ) {
			wp_send_json_success( $data );
		}

		return $data;
	}


	/**
	 * Updates cart on product variant change
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function ajax_add_to_cart() {
		check_ajax_referer( 'fkwcs_nonce', 'fkwcs_nonce' );

		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}

		WC()->shipping->reset_shipping();

		$product_id   = isset( $_POST['product_id'] ) ? absint( wc_clean( $_POST['product_id'] ) ) : 0;
		$qty          = ! isset( $_POST['qty'] ) ? 1 : absint( wc_clean( $_POST['qty'] ) );
		$product      = wc_get_product( $product_id );
		$product_type = $product->get_type();

		/** First empty the cart to prevent wrong calculation */
		WC()->cart->empty_cart();

		if ( ( 'variable' === $product_type || 'variable-subscription' === $product_type ) && isset( $_POST['attributes'] ) ) {
			$attributes = wc_clean( wp_unslash( $_POST['attributes'] ) );

			$data_store   = \WC_Data_Store::load( 'product' );
			$variation_id = $data_store->find_matching_product_variation( $product, $attributes );
			WC()->cart->add_to_cart( $product->get_id(), $qty, $variation_id, $attributes );
		}

		if ( 'simple' === $product_type || 'subscription' === $product_type ) {
			WC()->cart->add_to_cart( $product->get_id(), $qty );
		}
		\WC_AJAX::get_refreshed_fragments();
	}

	/**
	 * Updates data as per selected product variant
	 *
	 * @return void
	 * @throws \Exception Error messages.
	 */
	public function ajax_selected_product_data() {
		check_ajax_referer( 'fkwcs_nonce', 'fkwcs_nonce' );

		try {
			$product_id   = isset( $_POST['product_id'] ) ? absint( wc_clean( $_POST['product_id'] ) ) : 0;
			$qty          = ! isset( $_POST['qty'] ) ? 1 : apply_filters( 'woocommerce_add_to_cart_quantity', absint( wc_clean( $_POST['qty'] ) ), $product_id );
			$addon_value  = isset( $_POST['addon_value'] ) ? max( floatval( wc_clean( $_POST['addon_value'] ) ), 0 ) : 0;
			$product      = wc_get_product( $product_id );
			$variation_id = null;

			if ( ! is_a( $product, 'WC_Product' ) ) {
				/* translators: %d is the product Id */
				throw new \Exception( sprintf( __( 'Product with the ID (%d) cannot be found.', 'funnelkit-stripe-woo-payment-gateway' ), $product_id ) );
			}

			$product_type = $product->get_type();
			if ( ( 'variable' === $product_type || 'variable-subscription' === $product_type ) && isset( $_POST['attributes'] ) ) {
				$attributes = wc_clean( wp_unslash( $_POST['attributes'] ) );

				$data_store   = \WC_Data_Store::load( 'product' );
				$variation_id = $data_store->find_matching_product_variation( $product, $attributes );

				if ( ! empty( $variation_id ) ) {
					$product = wc_get_product( $variation_id );
				}
			}

			if ( $product->is_sold_individually() ) {
				$qty = apply_filters( 'fkwcs_payment_request_add_to_cart_sold_individually_quantity', 1, $qty, $product_id, $variation_id );
			}

			if ( ! $product->has_enough_stock( $qty ) ) {
				/* translators: 1: product name 2: quantity in stock */
				throw new \Exception( sprintf( __( 'You cannot add that amount of "%1$s"; to the cart because there is not enough stock (%2$s remaining).', 'funnelkit-stripe-woo-payment-gateway' ), $product->get_name(), wc_format_stock_quantity_for_display( $product->get_stock_quantity(), $product ) ) );
			}

			$total = $qty * $this->get_product_price( $product ) + $addon_value;

			$quantity_label = 1 < $qty ? ' (' . $qty . ')' : '';

			$data  = [];
			$items = [];

			$items[] = [
				'label'  => $product->get_name() . $quantity_label,
				'amount' => Helper::get_stripe_amount( $total ),
			];

			if ( wc_tax_enabled() ) {
				$items[] = [
					'label'   => __( 'Tax', 'funnelkit-stripe-woo-payment-gateway' ),
					'amount'  => 0,
					'pending' => true,
				];
			}

			if ( wc_shipping_enabled() && $product->needs_shipping() ) {
				$items[] = [
					'label'   => __( 'Shipping', 'funnelkit-stripe-woo-payment-gateway' ),
					'amount'  => 0,
					'pending' => true,
				];

				$data['shippingOptions'] = [
					'id'     => 'pending',
					'label'  => __( 'Pending', 'funnelkit-stripe-woo-payment-gateway' ),
					'detail' => '',
					'amount' => 0,
				];
			}

			$data['displayItems'] = $items;
			$data['total']        = [
				'label'   => apply_filters( 'fkwcs_payment_request_total_label', $this->clean_statement_descriptor() ),
				'amount'  => Helper::get_stripe_amount( $total ),
				'pending' => true,
			];

			$data['requestShipping'] = wc_bool_to_string( wc_shipping_enabled() && $product->needs_shipping() );
			$data['currency']        = strtolower( get_woocommerce_currency() );
			$data['country_code']    = substr( get_option( 'woocommerce_default_country' ), 0, 2 );

			wp_send_json( $data );
		} catch ( \Exception $e ) {
			wp_send_json( [ 'error' => wp_strip_all_tags( $e->getMessage() ) ] );
		}
	}

	/**
	 * Updates shipping address
	 *
	 * @return void
	 */
	public function update_shipping_address() {
		check_ajax_referer( 'fkwcs_nonce', 'fkwcs_nonce' );

		$shipping_address = filter_input_array( INPUT_POST, [
			'country'   => FILTER_UNSAFE_RAW,
			'state'     => FILTER_UNSAFE_RAW,
			'postcode'  => FILTER_UNSAFE_RAW,
			'city'      => FILTER_UNSAFE_RAW,
			'address'   => FILTER_UNSAFE_RAW,
			'address_2' => FILTER_UNSAFE_RAW,
		] );

		$request = wc_clean( $_POST );

		wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );

		add_filter( 'woocommerce_cart_ready_to_calc_shipping', function () {
			return true;
		}, 1000 );
		try {
			$this->wc_stripe_update_customer_location( $shipping_address );
			$this->wc_stripe_update_shipping_methods( $this->get_shipping_method_from_request( $request ) );

			/** update the WC cart with the new shipping options */
			$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', [] );
			WC()->cart->calculate_totals();
			$this->maybe_restore_recurring_chosen_shipping_methods( $chosen_shipping_methods );
			/** if shipping address is not serviceable, throw an error */
			if ( ! $this->wc_stripe_shipping_address_serviceable( $this->get_shipping_packages() ) ) {
				$this->reason_code = 'SHIPPING_ADDRESS_UNSERVICEABLE';
				throw new \Exception( __( 'Your shipping address is not serviceable.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			$data = apply_filters( 'wc_stripe_googlepay_paymentdata_response', array_merge( array(
				'shipping_methods' => $this->get_formatted_shipping_methods(),
				'address'          => $shipping_address,
				'result'           => 'success'
			), $this->build_display_items() ) );
		} catch ( \Exception $e ) {
			$data = array(
				'result' => 'fail'
			);
		}

		wp_send_json( $data );
	}


	/**
	 * Return whether or not the cart is displaying prices including tax, rather than excluding tax
	 *
	 * @return bool
	 */
	public function display_prices_including_tax() {
		$cart = WC()->cart;
		if ( method_exists( $cart, 'display_prices_including_tax' ) ) {
			return $cart->display_prices_including_tax();
		}
		if ( is_callable( array( $cart, 'get_tax_price_display_mode' ) ) ) {
			return 'incl' === $cart->get_tax_price_display_mode() && ( WC()->customer && ! WC()->customer->is_vat_exempt() );
		}

		return 'incl' === $cart->tax_display_cart && ( WC()->customer && ! WC()->customer->is_vat_exempt() );
	}

	/**
	 * Get all Shipping methods
	 *
	 * @param $methods
	 *
	 * @return array|mixed
	 */
	public function get_formatted_shipping_methods( $methods = array() ) {
		if ( function_exists( 'wcs_is_subscription' ) && \WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) {
			return $methods;
		}

		$methods        = array();
		$chosen_methods = array();
		$packages       = $this->get_shipping_packages();
		$incl_tax       = $this->display_prices_including_tax();
		foreach ( WC()->session->get( 'chosen_shipping_methods', array() ) as $id ) {
			$chosen_methods[] = $this->get_shipping_method_id( $id );
		}
		foreach ( $packages as $i => $package ) {
			foreach ( $package['rates'] as $rate ) {
				$price     = $incl_tax ? (float) $rate->cost + (float) $rate->get_shipping_tax() : (float) $rate->cost;
				$methods[] = $this->get_formatted_shipping_method( $price, $rate, $i, $package, $incl_tax );
			}
		}

		/**
		 * Sort shipping methods so the selected method is first in the array.
		 */
		usort( $methods, function ( $method ) use ( $chosen_methods ) {
			foreach ( $chosen_methods as $id ) {
				if ( in_array( $id, $method, true ) ) {
					return - 1;
				}
			}

			return 1;
		} );

		/**
		 * @param array $methods
		 */
		$methods = apply_filters( 'wc_stripe_get_formatted_shipping_methods', $methods, $this );
		if ( empty( $methods ) ) {
			/** GPay does not like empty shipping methods. Make a temporary one */
			$methods[] = array(
				'id'          => 'default',
				'label'       => __( 'Waiting...', 'funnelkit-stripe-woo-payment-gateway' ),
				'description' => __( 'loading shipping methods...', 'funnelkit-stripe-woo-payment-gateway' ),
			);
		}

		return $methods;
	}

	/**
	 * Get all formatted shipping methods
	 *
	 * @param $price
	 * @param $rate
	 * @param $i
	 * @param $package
	 * @param $incl_tax
	 *
	 * @return array
	 */
	public function get_formatted_shipping_method( $price, $rate, $i, $package, $incl_tax ) {
		$method = array(
			'id'     => $this->get_shipping_method_id( $rate->id ),
			'label'  => $this->get_formatted_shipping_label( $price, $rate, $incl_tax ),
			'detail' => '',
			'amount' => Helper::get_stripe_amount( $price )
		);

		if ( $incl_tax ) {
			if ( $rate->get_shipping_tax() > 0 && ! wc_prices_include_tax() ) {
				$method['detail'] = WC()->countries->inc_tax_or_vat();
			}
		} else {
			if ( $rate->get_shipping_tax() > 0 && wc_prices_include_tax() ) {
				$method['detail'] = WC()->countries->ex_tax_or_vat();
			}
		}

		return $method;
	}

	/**
	 * Get formatted shipping label
	 *
	 * @param $price
	 * @param \WC_Shipping_Rate $rate
	 * @param $incl_tax
	 *
	 * @return string
	 */
	protected function get_formatted_shipping_label( $price, $rate, $incl_tax ) {
		$label = sprintf( '%s: %s %s', esc_attr( $rate->get_label() ), number_format( $price, 2 ), get_woocommerce_currency() );
		if ( $incl_tax ) {
			if ( $rate->get_shipping_tax() > 0 && ! wc_prices_include_tax() ) {
				$label .= ' ' . WC()->countries->inc_tax_or_vat();
			}
		} else {
			if ( $rate->get_shipping_tax() > 0 && wc_prices_include_tax() ) {
				$label .= ' ' . WC()->countries->ex_tax_or_vat();
			}
		}

		return $label;
	}


	protected function get_shipping_method_id( $id ) {
		return $id;
	}

	/**
	 * Return true if there are shipping packages that contain rates.
	 *
	 * @param array $packages
	 *
	 * @return boolean
	 * @package Stripe/Functions
	 */
	public function wc_stripe_shipping_address_serviceable( $packages = array() ) {
		if ( $packages ) {
			foreach ( $packages as $package ) {
				if ( count( $package['rates'] ) > 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param [] $request
	 *
	 * @return array
	 * @throws \Exception
	 */
	private function get_shipping_method_from_request( $request ) {
		if ( isset( $request['shipping_method'] ) ) {
			if ( ! preg_match( '/^(?P<index>[\w]+)\:(?P<id>.+)$/', $request['shipping_method'], $shipping_method ) ) {
				throw new \Exception( __( 'Invalid shipping method format. Expected: index:id', 'funnelkit-stripe-woo-payment-gateway' ) ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			return array( $shipping_method['index'] => $shipping_method['id'] );
		}

		return array();
	}

	/**
	 * Updates shipping option
	 *
	 * @return void
	 */
	public function update_shipping_option() {
		check_ajax_referer( 'fkwcs_nonce', 'fkwcs_nonce' );

		wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );
		$shipping_methods = ! empty( $_POST['shipping_method'] ) ? wc_clean( $_POST['shipping_method'] ) : '';
		WC()->shipping->reset_shipping();

		$this->update_shipping_method( $shipping_methods );
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', [] );
		WC()->cart->calculate_totals();
		$this->maybe_restore_recurring_chosen_shipping_methods( $chosen_shipping_methods );
		$product_view_options      = filter_input_array( INPUT_POST, [ 'is_product_page' => FILTER_UNSAFE_RAW ] );
		$should_show_itemized_view = ! isset( $product_view_options['is_product_page'] ) ? true : filter_var( $product_view_options['is_product_page'], FILTER_VALIDATE_BOOLEAN );
		$data                      = [];
		$data                      += $this->build_display_items( $should_show_itemized_view );
		$data['result']            = 'success';

		wp_send_json( $data );
	}

	/**
	 *
	 * @param   [] $address
	 *
	 * @throws Exception
	 * @package Stripe/Functions
	 */
	public function wc_stripe_update_customer_location( $address ) {

		// resolve states for countries that have states
		$address['state'] = $this->get_normalized_state( $address['state'], $address['country'] );

		// resolve postal code in case of redacted data from Apple Pay.
		$address['postcode'] = $this->get_normalized_postal_code( $address['postcode'], $address['country'] );
		// address validation for countries other than US is problematic when using responses from payment sources like
		// Apple Pay.
		if ( $address['postcode'] && $address['country'] === 'US' && ! \WC_Validation::is_postcode( $address['postcode'], $address['country'] ) ) {
			throw new \Exception( __( 'Please enter a valid postcode / ZIP.', 'woocommerce' ) ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( $address['postcode'] ) {
			$address['postcode'] = wc_format_postcode( $address['postcode'], $address['country'] );
		}

		if ( $address['country'] ) {
			WC()->customer->set_billing_location( $address['country'], $address['state'], $address['postcode'], $address['city'] );
			WC()->customer->set_shipping_location( $address['country'], $address['state'], $address['postcode'], $address['city'] );
			// set the customer's address if it's in the $address array
			if ( ! empty( $address['address_1'] ) ) {
				WC()->customer->set_shipping_address_1( wc_clean( $address['address_1'] ) );
			}
			if ( ! empty( $address['address_2'] ) ) {
				WC()->customer->set_shipping_address_2( wc_clean( $address['address_2'] ) );
			}
			if ( ! empty( $address['first_name'] ) ) {
				WC()->customer->set_shipping_first_name( $address['first_name'] );
			}
			if ( ! empty( $address['last_name'] ) ) {
				WC()->customer->set_shipping_last_name( $address['last_name'] );
			}
		} else {
			WC()->customer->set_billing_address_to_base();
			WC()->customer->set_shipping_address_to_base();
		}

		WC()->customer->set_calculated_shipping( true );
		WC()->customer->save();

		do_action( 'woocommerce_calculated_shipping' );
	}


	/**
	 * Calculated shipping charges
	 *
	 * @param array $address updated address.
	 *
	 * @return void
	 */
	protected function calculate_shipping( $address = [] ) {
		$country   = $address['country'];
		$state     = $address['state'];
		$postcode  = $address['postcode'];
		$city      = $address['city'];
		$address_1 = $address['address'];
		$address_2 = $address['address_2'];

		WC()->shipping->reset_shipping();

		if ( $postcode && \WC_Validation::is_postcode( $postcode, $country ) ) {
			$postcode = wc_format_postcode( $postcode, $country );
		}

		if ( $country ) {
			WC()->customer->set_location( $country, $state, $postcode, $city );
			WC()->customer->set_shipping_location( $country, $state, $postcode, $city );
		} else {
			WC()->customer->set_billing_address_to_base();
			WC()->customer->set_shipping_address_to_base();
		}

		WC()->customer->set_calculated_shipping( true );
		WC()->customer->save();

		$packages = [];

		$packages[0]['contents']                 = WC()->cart->get_cart();
		$packages[0]['contents_cost']            = 0;
		$packages[0]['applied_coupons']          = WC()->cart->applied_coupons;
		$packages[0]['user']['ID']               = get_current_user_id();
		$packages[0]['destination']['country']   = $country;
		$packages[0]['destination']['state']     = $state;
		$packages[0]['destination']['postcode']  = $postcode;
		$packages[0]['destination']['city']      = $city;
		$packages[0]['destination']['address']   = $address_1;
		$packages[0]['destination']['address_2'] = $address_2;

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( $item['data']->needs_shipping() ) {
				if ( isset( $item['line_total'] ) ) {
					$packages[0]['contents_cost'] += $item['line_total'];
				}
			}
		}

		$packages = apply_filters( 'woocommerce_cart_shipping_packages', $packages );

		WC()->shipping->calculate_shipping( $packages );
	}

	/**
	 * Updates shipping method
	 *
	 * @param array $shipping_methods available shipping methods array.
	 *
	 * @return void
	 */
	public function update_shipping_method( $shipping_methods ) {
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );

		if ( is_array( $shipping_methods ) ) {
			foreach ( $shipping_methods as $i => $value ) {
				$chosen_shipping_methods[ $i ] = wc_clean( $value );
			}
		}

		WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
	}

	public function merge_cart_details( $fragments ) {
		$fragments['fkwcs_cart_details'] = $this->ajax_get_cart_details();

		return $fragments;
	}

	/**
	 *
	 * @param   [] $methods
	 *
	 * @package Stripe/Functions
	 */
	public function wc_stripe_update_shipping_methods( $methods ) {
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', array() );

		foreach ( $methods as $i => $method ) {
			$chosen_shipping_methods[ $i ] = $method;
		}

		WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
	}

	/**
	 * @return array
	 */
	public function get_shipping_packages() {
		$packages = WC()->shipping()->get_packages();
		if ( empty( $packages ) && function_exists( 'wcs_is_subscription' ) && \WC_Subscriptions_Cart::cart_contains_free_trial() ) {
			// there is a subscription with a free trial in the cart. Shipping packages will be in the recurring cart.
			\WC_Subscriptions_Cart::set_calculation_type( 'recurring_total' );
			$count = 0;
			if ( isset( WC()->cart->recurring_carts ) ) {
				foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {
					foreach ( $recurring_cart->get_shipping_packages() as $base_package ) {
						$packages[ $recurring_cart_key . '_' . $count ] = WC()->shipping->calculate_shipping_for_package( $base_package );
					}
					$count ++;
				}
			}
			\WC_Subscriptions_Cart::set_calculation_type( 'none' );
		}

		return $packages;
	}

	/**
	 * Process checkout on payment request button click
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function process_smart_checkout() {
		check_ajax_referer( 'fkwcs_nonce', 'fkwcs_nonce' );

		if ( WC()->cart->is_empty() ) {
			wp_send_json_error( __( 'Empty cart', 'funnelkit-stripe-woo-payment-gateway' ) );
		}

		wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );

		/** Setting the checkout nonce to avoid exception */
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'woocommerce-process_checkout' );
		$_POST['_wpnonce']    = ! empty( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( $_REQUEST['_wpnonce'] ) : '';

		Helper::log( 'Payment request call received for ' . wc_clean( $_REQUEST['payment_request_type'] ) . ' from page -' . wc_clean( $_REQUEST['page_from'] ) ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		$this->button_type = ! empty( $_REQUEST['payment_request_type'] ) ? wc_clean( $_REQUEST['payment_request_type'] ) : '';
		add_filter( 'woocommerce_cart_needs_payment', '__return_true', PHP_INT_MAX );
		add_filter( 'woocommerce_checkout_fields', [ $this, 'un_required_billing_address' ], PHP_INT_MAX );
		// Handle states for PRB
		$this->normalize_state();
		if ( current_action() === 'wc_ajax_fkwcs_gpay_button_payment_request' ) {
			WC()->session->reload_checkout = true;
		}
		if ( class_exists( '\WC_Subscriptions_Cart' ) ) {
			remove_action( 'woocommerce_after_checkout_validation', 'WC_Subscriptions_Cart::validate_recurring_shipping_methods' );
		}

		// Hook the custom function to the 'woocommerce_add_error' action
		add_filter( 'woocommerce_add_error', function ( $message ) {
			Helper::log( 'WooCommerce Error recorded during order creation:: ' . $message ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			return $message;
		}, 10 );

		WC()->checkout()->process_checkout();
		exit();
	}

	/**
	 * @hooked over `woocommerce_checkout_fields`
	 * THis method make all billing field unrequire for the non checkout interfaces as in few countries gpay doesn't offer billing address
	 *
	 * @param $fields
	 *
	 * @return array|mixed
	 */
	public function un_required_billing_address( $fields ) {
		if ( ! isset( $fields['billing'] ) || empty( $fields['billing'] ) ) {
			return $fields;
		}

		// List of billing fields
		$required_fields = $this->get_wc_default_fields_fields();

		// Loop through all billing fields
		foreach ( $fields['billing'] as $key => $field ) {
			// Un-require fields that are in the default required list
			if ( in_array( $key, $required_fields, true ) && isset( $fields['billing'][ $key ] ) && isset( $fields['billing'][ $key ]['required'] ) ) {
				$fields['billing'][ $key ]['required'] = false;
			}
		}

		/**
		 * Set value in checkout custom email confirmation field for handle mismatch case issue
		 */
		if ( ! empty( $_POST ) && isset( $_POST['billing_email'] ) && isset( $_POST['billing_email_2'] ) ) { //phpcs:ignore
			$_POST['billing_email_2'] = $_POST['billing_email'];//phpcs:ignore
		}

		return $fields;
	}

	/**
	 * Resolves billing and shipping state fields for the Payment Request API.
	 *
	 * @since 1.8.0
	 */
	public function normalize_state() {
		$billing_country  = ! empty( $_POST['billing_country'] ) ? wc_clean( wp_unslash( $_POST['billing_country'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing
		$shipping_country = ! empty( $_POST['shipping_country'] ) ? wc_clean( wp_unslash( $_POST['shipping_country'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing
		$billing_state    = ! empty( $_POST['billing_state'] ) ? wc_clean( wp_unslash( $_POST['billing_state'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing
		$shipping_state   = ! empty( $_POST['shipping_state'] ) ? wc_clean( wp_unslash( $_POST['shipping_state'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'HK' === $billing_country ) {
			include_once FKWCS_DIR . '/gateways/helpers/class-wc-stripe-hong-kong-states.php';
			if ( ! Helpers\WC_Stripe_Hong_Kong_States::is_valid_state( strtolower( $billing_state ) ) ) {
				$billing_postcode = ! empty( $_POST['billing_postcode'] ) ? wc_clean( wp_unslash( $_POST['billing_postcode'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( Helpers\WC_Stripe_Hong_Kong_States::is_valid_state( strtolower( $billing_postcode ) ) ) {
					$billing_state = $billing_postcode;
				}
			}
		}
		if ( 'HK' === $shipping_country ) {
			include_once FKWCS_DIR . '/gateways/helpers/class-wc-stripe-hong-kong-states.php';
			if ( ! Helpers\WC_Stripe_Hong_Kong_States::is_valid_state( strtolower( $shipping_state ) ) ) {
				$shipping_postcode = ! empty( $_POST['shipping_postcode'] ) ? wc_clean( wp_unslash( $_POST['shipping_postcode'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( Helpers\WC_Stripe_Hong_Kong_States::is_valid_state( strtolower( $shipping_postcode ) ) ) {
					$shipping_state = $shipping_postcode;
				}
			}
		}
		// lets we resolve the state value we want to process.
		if ( $billing_state && $billing_country ) {
			$_POST['billing_state'] = $this->get_normalized_state( $billing_state, $billing_country ); //phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( $shipping_state && $shipping_country ) {
			$_POST['shipping_state'] = $this->get_normalized_state( $shipping_state, $shipping_country ); //phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	/**
	 * we need to handle states manually for the PRB api as both gpay and apple pay provides different state values
	 * So we need to merge them in a way that we process only which states that are compatible with WC
	 *
	 * @param $state
	 * @param $country
	 *
	 * @return string
	 */
	public function get_normalized_state( $state, $country ) {
		if ( ! $state || $this->is_normalized_state( $state, $country ) ) {
			return $state;
		}
		$state = $this->get_normalized_state_from_pr_states( $state, $country );
		if ( $this->is_normalized_state( $state, $country ) ) {
			return $state;
		}

		return $this->get_normalized_state_from_wc_states( $state, $country );
	}

	/**
	 * Checks if given state is normalized.
	 *
	 * @param string $state State.
	 * @param string $country Two-letter country code.
	 *
	 * @return bool Whether state is normalized or not.
	 * @since 5.1.0
	 *
	 */
	public function is_normalized_state( $state, $country ) {
		$wc_states = WC()->countries->get_states( $country );

		return ( is_array( $wc_states ) && in_array( $state, array_keys( $wc_states ), true ) );
	}

	/**
	 * Get normalized state from Payment Request API dropdown list of states.
	 *
	 * @param string $state Full state name or state code.
	 * @param string $country Two-letter country code.
	 *
	 * @return string Normalized state or original state input value.
	 * @since 5.1.0
	 *
	 */
	public function get_normalized_state_from_pr_states( $state, $country ) {
		// Include Payment Request API State list for compatibility with WC countries/states.
		include_once FKWCS_DIR . '/gateways/helpers/class-wc-stripe-payment-request-button-states.php';
		$pr_states = Helpers\WC_Stripe_Payment_Request_Button_States::STATES;
		if ( ! isset( $pr_states[ $country ] ) ) {
			return $state;
		}
		foreach ( $pr_states[ $country ] as $wc_state_abbr => $pr_state ) {
			$sanitized_state_string = $this->sanitize_string( $state );
			// Checks if input state matches with Payment Request state code (0), name (1) or localName (2).
			if ( ( ! empty( $pr_state[0] ) && $sanitized_state_string === $this->sanitize_string( $pr_state[0] ) ) || ( ! empty( $pr_state[1] ) && $sanitized_state_string === $this->sanitize_string( $pr_state[1] ) ) || ( ! empty( $pr_state[2] ) && $sanitized_state_string === $this->sanitize_string( $pr_state[2] ) ) ) {
				return $wc_state_abbr;
			}
		}

		return $state;
	}

	/**
	 * Sanitize string for comparison.
	 *
	 * @param string $string String to be sanitized.
	 *
	 * @return string The sanitized string.
	 * @since 5.1.0
	 *
	 */
	public function sanitize_string( $string ) {
		return trim( wc_strtolower( remove_accents( $string ) ) );
	}

	/**
	 * Get resolved state from WooCommerce list of translated states.
	 *
	 * @param string $state Full state name or state code.
	 * @param string $country Two-letter country code.
	 *
	 * @return string Resolved state or original state input value.
	 * @since 5.1.0
	 *
	 */
	public function get_normalized_state_from_wc_states( $state, $country ) {
		$wc_states = WC()->countries->get_states( $country );
		if ( is_array( $wc_states ) ) {
			foreach ( $wc_states as $wc_state_abbr => $wc_state_value ) {
				if ( preg_match( '/' . preg_quote( $wc_state_value, '/' ) . '/i', $state ) ) {
					return $wc_state_abbr;
				}
			}
		}

		return $state;
	}

	/**
	 * Resolves postal code in case of redacted data from Apple Pay.
	 *
	 * @param string $postcode Postal code.
	 * @param string $country Country.
	 *
	 * @since 5.2.0
	 *
	 */
	public function get_normalized_postal_code( $postcode, $country ) {
		if ( 'GB' === $country ) {
			// Replaces a redacted string with something like LN10***.
			return str_pad( preg_replace( '/\s+/', '', $postcode ), 7, '*' );
		}
		if ( 'CA' === $country ) {
			// Replaces a redacted string with something like L4Y***.
			return str_pad( preg_replace( '/\s+/', '', $postcode ), 6, '*' );
		}

		return $postcode;
	}

	/**
	 * Returns a default shipping method based on the chosen shipping methods.
	 *
	 * @param array $methods
	 *
	 * @return string
	 */
	private function get_mapped_default_shipping_method( $methods ) {
		$selected_methods     = WC()->session->get( 'chosen_shipping_methods', array() );
		$method_ids           = array_column( $methods, 'id' );
		$temp_shipping_method = false;
		foreach ( $selected_methods as $idx => $method ) {
			$method_id = sprintf( '%s:%s', $idx, $method );
			if ( in_array( $method_id, $method_ids, true ) ) {
				$temp_shipping_method = $method_id;
			}
		}
		if ( ! $temp_shipping_method ) {
			return current( $method_ids );
		}
	}

	public function get_payment_response_data( $shipping_address ) {
		$shipping_options = $this->get_formatted_shipping_methods();
		$items            = $this->build_display_items()['displayItems'];

		$new_data = array(
			'newTransactionInfo'          => array(
				'currencyCode'     => get_woocommerce_currency(),
				'countryCode'      => WC()->countries->get_base_country(),
				'totalPriceStatus' => 'FINAL',
				'totalPrice'       => wc_format_decimal( WC()->cart->total, 2 ),
				'displayItems'     => $items,
				'totalPriceLabel'  => __( 'Total', 'funnelkit-stripe-woo-payment-gateway' ),
			),
			'newShippingOptionParameters' => array(
				'shippingOptions'         => $shipping_options,
				'defaultSelectedOptionId' => $this->get_mapped_default_shipping_method( $shipping_options ),
			),
		);

		return array(
			'shipping_methods'     => WC()->session->get( 'chosen_shipping_methods', array() ),
			'paymentRequestUpdate' => $new_data,
			'address'              => $shipping_address
		);
	}

	/**
	 * Updates shipping address
	 *
	 * @return void
	 */
	public function gpay_update_shipping_address() {

		check_ajax_referer( 'fkwcs_nonce', 'fkwcs_nonce' );

		if ( ! isset( $_POST['shipping_address'] ) || ! isset( $_POST['shipping_method'] ) ) {
			$data = array(
				'result' => 'fail'
			);
			wp_send_json( $data );
		}
		$shipping_address = wc_clean( $_POST['shipping_address'] );


		wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );
		add_filter( 'woocommerce_cart_ready_to_calc_shipping', function () {
			return true;
		}, 1000 );
		try {
			$this->wc_stripe_update_customer_location( $shipping_address );
			$this->update_shipping_method( wc_clean( $_POST['shipping_method'] ) );


			/** update the WC cart with the new shipping options */
			$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', [] );
			WC()->cart->calculate_totals();
			$this->maybe_restore_recurring_chosen_shipping_methods( $chosen_shipping_methods );
			/** if shipping address is not serviceable, throw an error */
			if ( ! $this->wc_stripe_shipping_address_serviceable( $this->get_shipping_packages() ) ) {
				$this->reason_code = 'SHIPPING_ADDRESS_UNSERVICEABLE';
				throw new \Exception( __( 'Your shipping address is not serviceable.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}


			$data = $this->get_payment_response_data( $shipping_address );
		} catch ( \Exception $e ) {
			$data = array(
				'result' => 'fail'
			);
		}

		wp_send_json( $data );
	}

	/**
	 * Restores the shipping methods previously chosen for each recurring cart after shipping was reset and recalculated
	 * during the Payment Request get_shipping_options flow.
	 *
	 * When the cart contains multiple subscriptions with different billing periods, customers are able to select different shipping
	 * methods for each subscription, however, this is not supported when purchasing with Apple Pay and Google Pay as it's
	 * only concerned about handling the initial purchase.
	 *
	 * In order to avoid Woo Subscriptions's `WC_Subscriptions_Cart::validate_recurring_shipping_methods` throwing an error, we need to restore
	 * the previously chosen shipping methods for each recurring cart.
	 *
	 * This function needs to be called after `WC()->cart->calculate_totals()` is run, otherwise `WC()->cart->recurring_carts` won't exist yet.
	 *
	 * @param array $previous_chosen_methods The previously chosen shipping methods.
	 *
	 * @since 8.3.0
	 *
	 */
	function maybe_restore_recurring_chosen_shipping_methods( $previous_chosen_methods = [] ) {
		if ( empty( WC()->cart->recurring_carts ) || ! method_exists( '\WC_Subscriptions_Cart', 'get_recurring_shipping_package_key' ) ) {
			return;
		}

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', [] );

		foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {
			foreach ( $recurring_cart->get_shipping_packages() as $recurring_cart_package_index => $recurring_cart_package ) {
				if ( class_exists( 'WC_Subscriptions_Cart' ) ) {
					$package_key = \WC_Subscriptions_Cart::get_recurring_shipping_package_key( $recurring_cart_key, $recurring_cart_package_index );

					// If the recurring cart package key is found in the previous chosen methods, but not in the current chosen methods, restore it.
					if ( isset( $previous_chosen_methods[ $package_key ] ) && ! isset( $chosen_shipping_methods[ $package_key ] ) ) {
						$chosen_shipping_methods[ $package_key ] = $previous_chosen_methods[ $package_key ];
					}
				}
			}
		}

		WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
	}


	/**
	 * prepare list for all woocommerce fields list for un-require expect 'billing_country', 'billing_postcode'
	 * @return string[]
	 */
	public function get_wc_default_fields_fields() {
		return array(
			'billing_first_name',
			'billing_last_name',
			'billing_company',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_email',
			'billing_same_as_shipping',
			'billing_phone',
			'shipping_first_name',
			'shipping_last_name',
			'shipping_company',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_state',
			'shipping_postcode',
			'shipping_country',
			'shipping_phone',
		);
	}


}
