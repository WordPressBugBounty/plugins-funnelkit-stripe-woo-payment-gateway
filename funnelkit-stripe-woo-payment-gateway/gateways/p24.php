<?php

namespace FKWCS\Gateway\Stripe;
#[\AllowDynamicProperties]
class P24 extends LocalGateway {

	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe_p24';
	public $payment_method_types = 'p24';
	protected $payment_element = true;

	public $supports_success_webhook = true;

	/**
	 * Setup general properties and settings
	 *
	 * @return void
	 */
	protected function init() {
		$this->paylater_message_position   = 'description';
		$this->method_title                = __( 'Stripe Przelewy 24 (P24) Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description          = __( 'Accepts payments via Przelewy 24 (P24). The gateway should be enabled in your Stripe Account. Log into your Stripe account to review the <a href="https://dashboard.stripe.com/account/payments/settings" target="_blank">available gateways</a> <br/>Supported Currency: <strong>EUR, PLN</strong>', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle                    = __( 'P24 is an online banking payment method that enables your customers in e-commerce to make an online purchase', 'funnelkit-stripe-woo-payment-gateway' );
		$this->title                       = $this->get_option( 'title' );
		$this->description                 = $this->get_option( 'description' );
		$this->enabled                     = $this->get_option( 'enabled' );
		$this->supported_currency          = [ 'EUR', 'PLN' ];
		$this->specific_country            = [ 'PL' ];
		$this->setting_enable_label        = __( 'Enable Stripe Przelewy 24 (P24) Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_title_default       = __( 'Stripe Przelewy 24 (P24)', 'funnelkit-stripe-woo-payment-gateway' );
		$this->setting_description_default = __( 'Pay with Przelewy 24 (P24)', 'funnelkit-stripe-woo-payment-gateway' );
		$this->init_form_fields();
		$this->init_settings();
		add_action( 'fkwcs_webhook_event_intent_succeeded', [ $this, 'handle_webhook_intent_succeeded' ], 10, 2 );
		add_action( 'fk_fb_every_4_minute', array( $this, 'delay_process_intent_success' ), 20 );


	}

	/**
	 * Handle requires_action status for P24
	 * Fires action hook and redirects to return URL without marking order as succeeded
	 *
	 * @param object $intent The payment intent object
	 * @param int $order_id The order ID
	 *
	 * @return string The redirect URL
	 */
	protected function handle_requires_action_status( $intent, $order_id ) {
		$order = wc_get_order( $order_id );

		// Fire action hook for P24 requires_action status
		do_action( 'fkwcs_p24_requires_action', $intent, $order_id, $order );

		// Redirect to return URL without marking order as succeeded
		return $this->get_return_url( $order );
	}

	/**
	 * Verify the payment intent
	 * Override to handle requires_action status specifically for P24
	 *
	 * @return void
	 */
	public function verify_intent() {
		global $woocommerce;

		$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
		try {
			$order_id = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order    = wc_get_order( $order_id );

			if ( ! isset( $_GET['order_key'] ) || ! $order instanceof \WC_Order || ! $order->key_is_valid( wc_clean( $_GET['order_key'] ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				throw new \Exception( __( 'Invalid Order Key.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

		} catch ( \Exception $e ) {
			/* translators: Error message text */
			$message = sprintf( __( 'Payment verification error: %s', 'funnelkit-stripe-woo-payment-gateway' ), $e->getMessage() );
			wc_add_notice( esc_html( $message ), 'error' );
			$this->handle_error( $e, $redirect_url );
		}

		try {
			$intent = $this->get_intent_from_order( $order );

			if ( false === $intent ) {
				throw new \Exception( __( 'Payment intent not found.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			if ( ! $order->has_status( apply_filters( 'fkwcs_stripe_allowed_payment_processing_statuses', [ 'pending', 'failed' ], $order ) ) ) {
				$get_current_offer = WFOCU_Core()->data->get_current_offer();

				if ( ! empty( $get_current_offer ) && 0 === did_action( 'wfocu_front_init_funnel_hooks' ) ) {
					$get_upsell_url = WFOCU_Core()->public->get_the_upsell_url( $get_current_offer );
					wp_redirect( $get_upsell_url );
					exit();
				}

				/**
				 * bail out if the status is not pending or failed
				 */
				$redirect_url = $this->get_return_url( $order );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			// Handle requires_action status specifically for P24
			if ( 'requires_action' === $intent->status ) {
				$redirect_url = $this->handle_requires_action_status( $intent, $order_id );
				Helper::log( "P24 requires_action - Redirecting to: " . $redirect_url );
				remove_all_actions( 'wp_redirect' );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			// For all other statuses, use parent logic
			if ( 'setup_intent' === $intent->object && 'succeeded' === $intent->status ) {
				$order->payment_complete();
				do_action( 'fkwcs_' . $this->id . '_before_redirect', $order_id );
				$redirect_url = $this->get_return_url( $order );

				// Remove cart.
				if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
					WC()->cart->empty_cart();
				}

			} else if ( 'succeeded' === $intent->status || 'requires_capture' === $intent->status ) {
				$redirect_url = $this->process_final_order( end( $intent->charges->data ), $order_id );
			} else if ( 'processing' === $intent->status ) {

				$order->update_status( apply_filters( 'fkwcs_stripe_intent_processing_order_status', 'on-hold', $intent, $order, $this ) );
				$redirect_url = $this->get_return_url( $order );
			} elseif ( 'requires_payment_method' === $intent->status ) {
				$redirect_url = wc_get_checkout_url();
				wc_add_notice( __( 'Unable to process this payment, please try again or use alternative method.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );
				if ( isset( $_GET['wfacp_id'] ) && isset( $_GET['wfacp_is_checkout_override'] ) && 'no' === $_GET['wfacp_is_checkout_override'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$redirect_url = get_the_permalink( wc_clean( $_GET['wfacp_id'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}

				/**
				 * Handle intent with no payment method here, we mark the order as failed and show users a notice
				 */
				if ( $order->has_status( 'failed' ) ) {
					wp_safe_redirect( $redirect_url );
					exit;
				}

				// Load the right message and update the status.
				$status_message = isset( $intent->last_payment_error ) /* translators: 1) The error message that was received from Stripe. */ ? sprintf( __( 'Stripe SCA authentication failed. Reason: %s', 'funnelkit-stripe-woo-payment-gateway' ), $intent->last_payment_error->message ) : __( 'Stripe SCA authentication failed.', 'funnelkit-stripe-woo-payment-gateway' );
				$this->mark_order_failed( $order, $status_message );
			}
			Helper::log( "Redirecting to :" . $redirect_url );
		} catch ( \Exception $e ) {
			$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
		}
		remove_all_actions( 'wp_redirect' );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle webhook intent succeeded event
	 *
	 * @param \stdclass $intent
	 * @param \WC_Order $order
	 *
	 * @return void
	 */
	public function handle_webhook_intent_succeeded( $intent, $order ) {

		if ( false === wc_string_to_bool( $this->enabled ) ) {
			return;
		}

		if ( ! $order instanceof \WC_Order || $order->get_payment_method() !== $this->id || $order->is_paid() || ! is_null( $order->get_date_paid() ) || $order->has_status( 'wfocu-pri-order' ) ) {
			return;
		}

		$save_intent = $this->get_intent_from_order( $order );
		if ( empty( $save_intent ) ) {
			Helper::log( 'Could not find intent in the order handle_webhook_intent_succeeded ' . $order->get_id() );

			return;
		}

		if ( class_exists( '\WFOCU_Core' ) ) {
			Helper::log( $order->get_id() . ' :: Saving meta data during webhook to later process this order' );

			$order->update_meta_data( '_fkwcs_webhook_paid', 'yes' );
			$order->save_meta_data();
		} else {

			try {
				Helper::log( $order->get_id() . ' :: Processing order during webhook' );

				$this->handle_intent_success( $intent, $order );

			} catch ( \Exception $e ) {

			}
		}


	}

	public function delay_process_intent_success() {

		if ( ! class_exists( 'WFOCU_Common' ) ) {
			return;
		}
		global $wpdb;
		\WFOCU_Common::$start_time = time();

		// @codingStandardsIgnoreStart

		if ( \WFOCU_Common::is_hpos_enabled() ) {
			$order_table      = $wpdb->prefix . 'wc_orders';
			$order_meta_table = $wpdb->prefix . 'wc_orders_meta';
			$query            = $wpdb->prepare( "SELECT ord.id as ID FROM {$order_table} ord
                                INNER JOIN {$order_meta_table} om ON (ord.id = om.order_id AND om.meta_key = '_fkwcs_webhook_paid')
                                WHERE ord.type = %s
                                ORDER BY ord.date_created_gmt DESC LIMIT 0, 100", 'shop_order' );
		} else {
			$query = $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p
                                INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_fkwcs_webhook_paid')
                                WHERE p.post_type = %s
                                ORDER BY p.post_date DESC LIMIT 0, 100", 'shop_order' );
		}

		$query_results = $wpdb->get_results( $query );

		// @codingStandardsIgnoreEnd
		if ( ! empty( $query_results ) && is_array( $query_results ) ) {

			$get_orders = array_map( function ( $query_instance ) {
				return wc_get_order( $query_instance->ID );
			}, $query_results );

			$i = 0;
			if ( ! empty( $get_orders ) ) {

				do {
					if ( ( \WFOCU_Common::time_exceeded() || \WFOCU_Common::memory_exceeded() ) ) {
						// Batch limits reached.
						break;
					}
					$order = $get_orders[ $i ];

					try {


						/**
						 * Delete the metadata straight way to avoid any scenario of processing the order more than once
						 */
						$order->delete_meta_data( '_fkwcs_webhook_paid' );
						$order->save_meta_data();


						if ( $order->get_payment_method() !== $this->id ) {
							return;
						}
						/**
						 * If the order is already paid, we should not process it again
						 */
						if ( ! is_null( $order->get_date_paid() ) ) {
							continue;
						}

						/**
						 * @var $gateway FKWCS\Gateway\Stripe\CreditCard
						 */
						$gateway = WC()->payment_gateways()->payment_gateways()[ $order->get_payment_method() ];

						if ( ! $gateway instanceof \WC_Payment_Gateway ) {
							continue;
						}
						$intent = $gateway->get_intent_from_order( $order );
						if ( false === $intent ) {
							Helper::log( " Intent Not Found  - " . $order->get_id() );

							continue;
						}
						if ( method_exists( $gateway, 'handle_intent_success' ) ) {
							Helper::log( " Upsell schedule Processing order  - " . $order->get_id() );

							$gateway->handle_intent_success( $intent, $order );
						}
					} catch ( \Error|\Exception $e ) {
						if ( isset( $order ) && $order instanceof \WC_Order ) {
							$order->delete_meta_data( '_fkwcs_webhook_paid' );
							$order->save_meta_data();
						}
						Helper::log( " Upsell schedule Error occurred - " . $e->getMessage() );
					}

					unset( $get_orders[ $i ] );
					$i ++;
				} while ( ! ( \WFOCU_Common::time_exceeded() || \WFOCU_Common::memory_exceeded() ) && ! empty( $get_orders ) );
			}
		}
	}

}
