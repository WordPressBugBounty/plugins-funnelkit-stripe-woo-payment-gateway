<?php

namespace FKWCS\Gateway\Stripe\Traits;

use FKWCS\Gateway\Stripe\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for Pre-Orders compatibility.
 */
trait WC_Pre_Orders_Trait {

	/**
	 * Stores a flag to indicate if the Pre-order integration hooks have been attached.
	 *
	 * The callbacks attached as part of maybe_init_pre_orders() only need to be attached once to avoid duplication.
	 *
	 * @var bool False by default, true once the callbacks have been attached.
	 */
	private static $has_attached_pre_order_integration_hooks = false;

	/**
	 * Check if Pre-Orders plugin is properly loaded and available
	 *
	 * @return bool
	 */
	private function is_pre_orders_plugin_available() {
		return class_exists( 'WC_Pre_Orders' ) &&
				class_exists( 'WC_Pre_Orders_Order' ) &&
				class_exists( 'WC_Pre_Orders_Cart' ) &&
				class_exists( 'WC_Pre_Orders_Product' );
	}

	/**
	 * Initialize pre-orders hook.
	 *
	 * @since 1.0.0
	 */
	public function maybe_init_pre_orders() {
		try {
			// Early return if Pre-Orders plugin is not active
			if ( ! $this->is_pre_orders_enabled() ) {
				return;
			}

			// Double check that required classes exist
			if ( ! class_exists( 'WC_Pre_Orders' ) || ! class_exists( 'WC_Pre_Orders_Order' ) || ! class_exists( 'WC_Pre_Orders_Cart' ) ) {
				Helper::log( 'Pre-Orders plugin classes not found. Skipping pre-orders initialization.' );
				return;
			}

			$this->supports[] = 'pre-orders';

			add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array( $this, 'process_pre_order_release_payment' ) );

			/**
			 * The callbacks attached below only need to be attached once. We don't need each gateway instance to have its own callback.
			 * Therefore we only attach them once on the main `fkwcs_stripe` gateway and store a flag to indicate that they have been attached.
			 */
			if ( self::$has_attached_pre_order_integration_hooks || 'fkwcs_stripe' !== $this->id ) {
				return;
			}

			add_filter( 'fkwcs_stripe_display_save_payment_method_checkbox', array( $this, 'hide_save_payment_for_pre_orders_charged_upon_release' ) );

			self::$has_attached_pre_order_integration_hooks = true;
		} catch ( \Exception $e ) {
			Helper::log( 'Pre-Orders Initialization Error: ' . $e->getMessage() );
		}
	}

	/**
	 * Checks if pre-orders are enabled on the site.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_pre_orders_enabled() {
		try {
			return $this->is_pre_orders_plugin_available();
		} catch ( \Exception $e ) {
			Helper::log( 'Pre-Orders Check Error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Is $order_id a pre-order?
	 *
	 * @since 1.0.0
	 *
	 * @param  int $order_id
	 * @return bool
	 */
	public function has_pre_order( $order_id ) {
		try {
			if ( ! $this->is_pre_orders_enabled() || ! class_exists( 'WC_Pre_Orders_Order' ) ) {
				return false;
			}

			// Use call_user_func to avoid namespace resolution issues
			if ( method_exists( 'WC_Pre_Orders_Order', 'order_contains_pre_order' ) ) {
				return call_user_func( array( 'WC_Pre_Orders_Order', 'order_contains_pre_order' ), $order_id );
			}

			return false;
		} catch ( \Exception $e ) {
			Helper::log( 'Has Pre-Order Check Error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Returns true if the pre-order completed.
	 *
	 * @since 1.0.0
	 *
	 * @param  int $order_id
	 * @return bool
	 */
	public function is_pre_order_completed( $order_id ) {
		try {
			if ( ! $this->is_pre_orders_enabled() || ! class_exists( 'WC_Pre_Orders_Order' ) ) {
				return false;
			}

			// Use call_user_func to avoid namespace resolution issues
			if ( method_exists( 'WC_Pre_Orders_Order', 'get_pre_order_status' ) ) {
				return 'completed' === call_user_func( array( 'WC_Pre_Orders_Order', 'get_pre_order_status' ), $order_id );
			}

			return false;
		} catch ( \Exception $e ) {
			Helper::log( 'Pre-Order Completed Check Error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Returns boolean on whether current cart contains a pre-order item.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_pre_order_item_in_cart() {
		try {
			if ( ! $this->is_pre_orders_enabled() || ! class_exists( 'WC_Pre_Orders_Cart' ) ) {
				return false;
			}

			// Use call_user_func to avoid namespace resolution issues
			if ( method_exists( 'WC_Pre_Orders_Cart', 'cart_contains_pre_order' ) ) {
				return call_user_func( array( 'WC_Pre_Orders_Cart', 'cart_contains_pre_order' ) );
			}

			return false;
		} catch ( \Exception $e ) {
			Helper::log( 'Pre-Order Cart Check Error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Returns pre-order product from cart.
	 *
	 * @since 1.0.0
	 *
	 * @return object|null
	 */
	public function get_pre_order_product_from_cart() {
		try {
			if ( ! $this->is_pre_orders_enabled() || ! class_exists( 'WC_Pre_Orders_Cart' ) ) {
				return false;
			}

			// Use call_user_func to avoid namespace resolution issues
			if ( method_exists( 'WC_Pre_Orders_Cart', 'get_pre_order_product' ) ) {
				return call_user_func( array( 'WC_Pre_Orders_Cart', 'get_pre_order_product' ) );
			}

			return false;
		} catch ( \Exception $e ) {
			Helper::log( 'Get Pre-Order Product from Cart Error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Returns pre-order product from order.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id
	 *
	 * @return object|null
	 */
	public function get_pre_order_product_from_order( $order_id ) {
		try {
			if ( ! $this->is_pre_orders_enabled() || ! class_exists( 'WC_Pre_Orders_Order' ) ) {
				return false;
			}

			// Use call_user_func to avoid namespace resolution issues
			if ( method_exists( 'WC_Pre_Orders_Order', 'get_pre_order_product' ) ) {
				return call_user_func( array( 'WC_Pre_Orders_Order', 'get_pre_order_product' ), $order_id );
			}

			return false;
		} catch ( \Exception $e ) {
			Helper::log( 'Get Pre-Order Product from Order Error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Returns boolean on whether product is charged upon release.
	 *
	 * @since 1.0.0
	 *
	 * @param object $product
	 *
	 * @return bool
	 */
	public function is_pre_order_product_charged_upon_release( $product ) {
		try {
			if ( ! $this->is_pre_orders_enabled() || ! class_exists( 'WC_Pre_Orders_Product' ) ) {
				return false;
			}

			// Use call_user_func to avoid namespace resolution issues
			if ( method_exists( 'WC_Pre_Orders_Product', 'product_is_charged_upon_release' ) ) {
				return call_user_func( array( 'WC_Pre_Orders_Product', 'product_is_charged_upon_release' ), $product );
			}

			return false;
		} catch ( \Exception $e ) {
			Helper::log( 'Pre-Order Product Charged Upon Release Check Error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Returns boolean on whether product is charged upfront.
	 *
	 * @since 1.0.0
	 *
	 * @param object $product
	 *
	 * @return bool
	 */
	public function is_pre_order_product_charged_upfront( $product ) {
		try {
			if ( ! $this->is_pre_orders_enabled() || ! class_exists( 'WC_Pre_Orders_Product' ) ) {
				return false;
			}

			// Use call_user_func to avoid namespace resolution issues
			if ( method_exists( 'WC_Pre_Orders_Product', 'product_is_charged_upfront' ) ) {
				return call_user_func( array( 'WC_Pre_Orders_Product', 'product_is_charged_upfront' ), $product );
			}

			return false;
		} catch ( \Exception $e ) {
			Helper::log( 'Pre-Order Product Charged Upfront Check Error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Checks if we need to process pre-orders when
	 * a pre-order product is in the cart.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id
	 *
	 * @return bool
	 */
	public function maybe_process_pre_orders( $order_id ) {
		try {
			if ( ! $this->has_pre_order( $order_id ) || ! class_exists( 'WC_Pre_Orders_Order' ) ) {
				return false;
			}

			// Use call_user_func to avoid namespace resolution issues
			if ( method_exists( 'WC_Pre_Orders_Order', 'order_requires_payment_tokenization' ) ) {
				return call_user_func( array( 'WC_Pre_Orders_Order', 'order_requires_payment_tokenization' ), $order_id );
			}

			return false;
		} catch ( \Exception $e ) {
			Helper::log( 'Maybe Process Pre-Orders Error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Remove order meta.
	 *
	 * @param object $order
	 */
	public function remove_order_source_before_retry( $order ) {
		try {
			if ( ! $order ) {
				throw new \Exception( __( 'Order not found.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			$order->delete_meta_data( '_fkwcs_stripe_source_id' );
			$order->delete_meta_data( '_fkwcs_stripe_card_id' );
			$order->save();
		} catch ( \Exception $e ) {
			Helper::log( 'Remove Order Source Error: ' . $e->getMessage() );
		}
	}

	/**
	 * Marks the order as pre-ordered.
	 * The native function is wrapped so we can call it separately and more easily mock it in our tests.
	 *
	 * @param object $order
	 */
	public function mark_order_as_pre_ordered( $order ) {
		try {
			if ( ! $order ) {
				throw new \Exception( __( 'Order not found.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			if ( ! class_exists( 'WC_Pre_Orders_Order' ) ) {
				Helper::log( 'WC_Pre_Orders_Order class not found. Pre-Orders plugin may not be active.' );
				return;
			}

			// Use call_user_func to avoid namespace resolution issues
			if ( method_exists( 'WC_Pre_Orders_Order', 'mark_order_as_pre_ordered' ) ) {
				call_user_func( array( 'WC_Pre_Orders_Order', 'mark_order_as_pre_ordered' ), $order );
			}
		} catch ( \Exception $e ) {
			Helper::log( 'Mark Order as Pre-Ordered Error: ' . $e->getMessage() );
		}
	}

	/**
	 * Process the pre-order when pay upon release is used.
	 *
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function process_pre_order( $order_id ) {
		try {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				throw new \Exception( __( 'Order not found.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			// This will throw exception if not valid.
			$this->validate_minimum_order_amount( $order );

			$prepared_source = $this->prepare_source( $order, false );

			// We need a source on file to continue.
			if ( empty( $prepared_source->customer ) || empty( $prepared_source->source ) ) {
				throw new \Exception( __( 'Unable to store payment details. Please try again.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			// Setup the response early to allow later modifications.
			$response = array(
				'result'         => 'success',
				'fkwcs_redirect' => $this->get_return_url( $order ),
			);

			$this->save_source_to_order_pre_order( $order, $prepared_source );

			// Try setting up a payment intent.
			$intent_secret = $this->setup_intent( $order, $prepared_source );
			if ( ! empty( $intent_secret ) ) {
				// Extract client secret from the setup intent object
				$client_secret                         = isset( $intent_secret->client_secret ) ? $intent_secret->client_secret : $intent_secret;
				$response['fkwcs_setup_intent_secret'] = $client_secret;
				return $response;
			}

			// Remove cart.
			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}

			// Is pre ordered!
			$this->mark_order_as_pre_ordered( $order );

			// Return thank you page redirect
			return $response;
		} catch ( \Exception $e ) {
			Helper::log( 'Pre Orders Error: ' . $e->getMessage() );
			wc_add_notice( $e->getMessage(), 'error' );

			$order = wc_get_order( $order_id );
			return array(
				'result'         => 'success',
				'fkwcs_redirect' => $order ? $order->get_checkout_payment_url( true ) : wc_get_checkout_url(),
			);
		}
	}

	/**
	 * Process a pre-order payment when the pre-order is released.
	 *
	 * @param \WC_Order $order
	 * @param bool      $retry
	 *
	 * @return void
	 */
	public function process_pre_order_release_payment( $order, $retry = true ) {
		try {
			// Remove WFOCU upsell action when pre-order is completed
			if ( class_exists( 'WFOCU_Core' ) ) {
				remove_action( 'woocommerce_pre_payment_complete', array( WFOCU_Core()->public, 'maybe_setup_upsell' ), 99 );
			}
			if ( ! $order ) {
				throw new \Exception( __( 'Order not found.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			$source = $this->prepare_order_source( $order );

			if ( ! $source || empty( $source->customer ) || empty( $source->source ) ) {
				throw new \Exception( __( 'No payment method found for this pre-order.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			$response = $this->create_and_confirm_intent_for_off_session_pre_order( $order, $source );

			if ( ! $response ) {
				throw new \Exception( __( 'Failed to create payment intent.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			$is_authentication_required = $this->is_authentication_required_for_payment_pre_order( $response );

			if ( ! empty( $response->error ) && ! $is_authentication_required ) {
				if ( ! $retry ) {
					throw new \Exception( $response->error->message );
				}
				$this->remove_order_source_before_retry( $order );
				$this->process_pre_order_release_payment( $order, false );
			} elseif ( $is_authentication_required ) {
				$charge = $this->get_latest_charge_from_intent( $response->error->payment_intent );
				$id     = $charge->id;

				$order->set_transaction_id( $id );
				/* translators: %s is the charge Id */
				$order->update_status( 'failed', sprintf( __( 'Stripe charge awaiting authentication by user: %s.', 'funnelkit-stripe-woo-payment-gateway' ), $id ) );
				if ( is_callable( array( $order, 'save' ) ) ) {
					$order->save();
				}

				\WC_Emails::instance();

				do_action( 'fkwcs_gateway_stripe_process_payment_authentication_required', $order );

				throw new \Exception( print_r( $response, true ) );
			} else {
				// Successful - get the charge and process the response
				$charge = $this->get_latest_charge_from_intent( $response );
				if ( $charge ) {
					$this->process_response_pre_order( $charge, $order );
				} else {
					throw new \Exception( __( 'No charge found in payment intent.', 'funnelkit-stripe-woo-payment-gateway' ) );
				}
			}
		} catch ( \Exception $e ) {
			Helper::log( 'Pre Order Release Payment Error: ' . $e->getMessage() );

			$error_message = $e->getMessage();
			/* translators: error message */
			$order_note = sprintf( __( 'Stripe Transaction Failed (%s)', 'funnelkit-stripe-woo-payment-gateway' ), $error_message );

			// Mark order as failed if not already set,
			// otherwise, make sure we add the order note so we can detect when someone fails to check out multiple times
			if ( $order && ! $order->has_status( 'failed' ) ) {
				$order->update_status( 'failed', $order_note );
			} elseif ( $order ) {
				$order->add_order_note( $order_note );
			}
		}
	}

	/**
	 * Determines if there is a pre-order in the cart and if it is charged upon release.
	 *
	 * @return bool
	 */
	public function is_pre_order_charged_upon_release_in_cart() {
		try {
			$pre_order_product = $this->get_pre_order_product_from_cart();
			return $pre_order_product && $this->is_pre_order_product_charged_upon_release( $pre_order_product );
		} catch ( \Exception $e ) {
			Helper::log( 'Pre-Order Charged Upon Release in Cart Check Error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Determines if an order contains a pre-order and if it is charged upon release.
	 *
	 * @return bool
	 */
	public function has_pre_order_charged_upon_release( $order ) {
		$pre_order_product = $this->get_pre_order_product_from_order( $order );
		return $pre_order_product && $this->is_pre_order_product_charged_upon_release( $pre_order_product );
	}

	/**
	 * Hides the save payment method checkbox when the cart contains a pre-order that is charged upon release.
	 *
	 * @param bool $display_save_option The default value of whether the save payment method checkbox should be displayed.
	 * @return bool Whether the save payment method checkbox should be displayed.
	 */
	public function hide_save_payment_for_pre_orders_charged_upon_release( $display_save_option ) {
		try {
			// Early return if Pre-Orders plugin is not active
			if ( ! $this->is_pre_orders_enabled() ) {
				return $display_save_option;
			}

			// This function only sets the display param to false, so if it's already hidden or the cart doesn't contain a pre-order, we don't need to do anything.
			if ( ! $display_save_option || ! $this->is_pre_order_item_in_cart() ) {
				return $display_save_option;
			}

			// If the cart contains a pre-order that is charged upon release, we hide the save payment method checkbox because the payment method is force saved.
			if ( $this->is_pre_order_charged_upon_release_in_cart() ) {
				return false;
			}

			return $display_save_option;
		} catch ( \Exception $e ) {
			Helper::log( 'Hide Save Payment for Pre-Orders Error: ' . $e->getMessage() );
			return $display_save_option; // Return original value on error
		}
	}





	/**
	 * Save source to order
	 *
	 * @param \WC_Order $order
	 * @param object    $prepared_source
	 */
	public function save_source_to_order_pre_order( $order, $prepared_source ) {
		try {
			if ( ! $order ) {
				throw new \Exception( __( 'Order not found.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			if ( ! $prepared_source || empty( $prepared_source->customer ) || empty( $prepared_source->source ) ) {
				throw new \Exception( __( 'Invalid payment source.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			$order->update_meta_data( '_fkwcs_stripe_customer_id', $prepared_source->customer );
			$order->update_meta_data( '_fkwcs_stripe_source_id', $prepared_source->source );
			$order->save();
		} catch ( \Exception $e ) {
			Helper::log( 'Save Source to Order Error: ' . $e->getMessage() );
			throw $e; // Re-throw to be handled by calling method
		}
	}

	/**
	 * Create and confirm intent for off session
	 *
	 * @param \WC_Order $order
	 * @param object    $source
	 * @return object
	 */
	public function create_and_confirm_intent_for_off_session_pre_order( $order, $source, $previous_error = null ) {
		try {
			$request = array(
				'payment_method'       => $source->source,
				'payment_method_types' => $this->get_payment_method_types(),
				'amount'               => Helper::get_stripe_amount( $order->get_total(), strtolower( $order->get_currency() ) ),
				'currency'             => strtolower( $order->get_currency() ),
				'description'          => $this->get_order_description( $order ),
				'customer'             => $source->customer,
				'off_session'          => 'true',
				'confirm'              => 'true',
				'confirmation_method'  => 'automatic',
			);

			if ( true === \in_array( 'card', $request['payment_method_types'], true ) && Helper::should_customize_statement_descriptor() ) {
				$request['statement_descriptor_suffix'] = $this->clean_statement_descriptor( Helper::get_gateway_descriptor_suffix( $order ) );
			}

			if ( empty( $source->source ) ) {
				unset( $request['payment_method'] );
			}

			if ( isset( $source->customer ) ) {
				$request['customer'] = $source->customer;
			}

			$request['metadata'] = $this->add_metadata( $order );
			$amount_data         = $this->add_amount_details( $order, $request['payment_method_types'][0] ?? 'card' );
			if ( ! empty( $amount_data ) ) {
				$request = array_merge( $request, $amount_data );
			}
			$request = apply_filters( 'fkwcs_payment_intent_data', $request, $order );

			$client   = $this->get_client();
			$response = $client->payment_intents( 'create', array( $request ) );
			$obj      = $this->handle_client_response( $response );

			return $obj;
		} catch ( \Exception $e ) {
			Helper::log( 'Create Intent for Off Session Error: ' . $e->getMessage() );
			return (object) array(
				'error' => (object) array(
					'code'    => 'api_error',
					'message' => $e->getMessage(),
				),
			);
		}
	}

	/**
	 * Check if authentication is required for payment
	 *
	 * @param object $response
	 * @return bool
	 */
	public function is_authentication_required_for_payment_pre_order( $response ) {
		try {
			if ( ! $response ) {
				return false;
			}
			return ( ! empty( $response->error ) && 'authentication_required' === $response->error->code ) || ( ! empty( $response->last_payment_error ) && 'authentication_required' === $response->last_payment_error->code );
		} catch ( \Exception $e ) {
			Helper::log( 'Authentication Check Error: ' . $e->getMessage() );
			return false;
		}
	}


	/**
	 * Process response
	 *
	 * @param object    $response
	 * @param \WC_Order $order
	 */
	public function process_response_pre_order( $response, $order ) {
		try {
			if ( ! $order ) {
				throw new \Exception( __( 'Order not found.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			if ( ! $response || empty( $response->id ) ) {
				throw new \Exception( __( 'Invalid payment response.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			$order->payment_complete( $response->id );
			/* translators: %s: Charge ID */
			$order->add_order_note( sprintf( __( 'Stripe charge complete (Charge ID: %s)', 'funnelkit-stripe-woo-payment-gateway' ), $response->id ) );
		} catch ( \Exception $e ) {
			Helper::log( 'Process Response Error: ' . $e->getMessage() );

			if ( $order ) {
				/* translators: %s: Error message */
				$order->add_order_note( sprintf( __( 'Payment processing error: %s', 'funnelkit-stripe-woo-payment-gateway' ), $e->getMessage() ) );
			}
		}
	}
}
