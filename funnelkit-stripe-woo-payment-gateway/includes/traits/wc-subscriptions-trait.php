<?php

namespace FKWCS\Gateway\Stripe\Traits;

use FKWCS\Gateway\Stripe;
use FKWCS\Gateway\Stripe\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for Subscriptions compatibility.
 */
trait WC_Subscriptions_Trait {

	use WC_Subscriptions_Helper_Trait;

	/**
	 * @var array
	 */
	public $customer_data = array();

	/**
	 * Initialize subscription support and hooks.
	 */
	public function maybe_init_subscriptions() {
		if ( ! $this->is_subscriptions_enabled() ) {
			return;
		}

		$this->supports = array_merge(
			$this->supports,
			array(
				'subscriptions',
				'subscription_cancellation',
				'subscription_suspension',
				'subscription_reactivation',
				'subscription_amount_changes',
				'subscription_date_changes',
				'subscription_payment_method_change',
				'subscription_payment_method_change_customer',
				'subscription_payment_method_change_admin',
				'multiple_subscriptions',
			)
		);

		if ( false === $this->is_configured() ) {
			return;
		}

		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
		add_action( 'woocommerce_scheduled_subscription_payment', array( $this, 'attach_hooks_to_update_payment_method' ), - 999, 2 );

		add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $this->id, array( $this, 'update_failing_payment_method' ), 10, 2 );
		add_action( 'wcs_resubscribe_order_created', array( $this, 'delete_resubscribe_meta' ), 10 );
		add_filter( 'wcs_renewal_order_created', array( $this, 'delete_renewal_meta' ), 10, 2 );

		add_action( $this->id . '_after_payment_field_checkout', array( $this, 'display_update_subs_payment_checkout' ) );
		add_action( 'fkwcs_add_payment_method_' . $this->id . '_success', array( $this, 'handle_add_payment_method_success' ), 10 );

		// Display the payment method used for a subscription in the "My Subscriptions" table.
		add_filter( 'woocommerce_my_subscriptions_payment_method', array( $this, 'maybe_render_subscription_payment_method' ), 10, 2 );

		// Allow store managers to manually set Stripe as the payment method on a subscription.
		add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'add_subscription_payment_meta' ), 10, 2 );
		add_action( 'woocommerce_subscription_validate_payment_meta', array( $this, 'validate_subscription_payment_meta' ), 10, 3 );
		add_filter( 'fkwcs_stripe_display_save_payment_method_checkbox', array( $this, 'display_save_payment_method_checkbox' ) );

		add_filter( 'fkwcs_payment_intent_data', array( $this, 'maybe_add_emandate_data_to_request' ), 10, 3 );
	}

	/**
	 * Displays a checkbox to allow users to update all subs payments with new
	 * payment.
	 */
	public function display_update_subs_payment_checkout() {
		$subs_statuses = apply_filters( 'fkwcs_stripe_update_subs_payment_method_card_statuses', array( 'active' ) );
		if ( is_add_payment_method_page() && apply_filters( 'fkwcs_stripe_display_update_subs_payment_method_card_checkbox', true ) && wcs_user_has_subscription( get_current_user_id(), '', $subs_statuses ) ) {
			$label = esc_html( apply_filters( 'wc_stripe_save_to_subs_text', __( 'Update the Payment Method used for all of my active subscriptions.', 'funnelkit-stripe-woo-payment-gateway' ) ) );
			$id    = sprintf( 'wc-%1$s-update-subs-payment-method-card', $this->id );
			woocommerce_form_field(
				$id,
				array(
					'type'    => 'checkbox',
					'label'   => $label,
					'default' => apply_filters( 'fkwcs_stripe_save_to_subs_checked', false ),
				)
			);
		}
	}

	/**
	 * Updates all active subscriptions payment method.
	 *
	 * @param string $source_id
	 */
	public function handle_add_payment_method_success( $source_id ) {
		if ( ! isset( $_POST[ 'wc-' . $this->id . '-update-subs-payment-method-card' ] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment method addition
			return;
		}
		$all_subs      = wcs_get_users_subscriptions();
		$subs_statuses = apply_filters( 'wc_stripe_update_subs_payment_method_card_statuses', array( 'active' ) );
		if ( empty( $all_subs ) ) {
			return;
		}
		$fkwcs_customer_id = Helper::get_customer_key();
		$stripe_id         = $this->get_customer_id();
		foreach ( $all_subs as $sub ) {
			if ( ! $sub->has_status( $subs_statuses ) ) {
				continue;
			}
			\WC_Subscriptions_Change_Payment_Gateway::update_payment_method(
				$sub,
				$this->id,
				array(
					'post_meta' => array(
						'_fkwcs_source_id' => array( 'value' => $source_id ),
						$fkwcs_customer_id => array( 'value' => $stripe_id ),
					),
				)
			);
		}
	}


	/**
	 * Maybe process payment method change for subscriptions.
	 *
	 * @param int $order_id
	 *
	 * @return bool
	 */
	public function maybe_change_subscription_payment_method( $order_id ) {
		return ( $this->is_subscriptions_enabled() && $this->has_subscription( $order_id ) && $this->is_changing_payment_method_for_subscription() );
	}

	/**
	 * Process the payment method change for subscriptions.
	 *
	 * @param int $order_id
	 *
	 * @return array|null
	 */
	public function process_change_subscription_payment_method( $order_id, $is_change_subs = false ) {
		try {
			$subscription = wc_get_order( $order_id );

			/**
			 * Change-payment / add-payment / pay-for-order pages already create AND confirm the
			 * SetupIntent (with 3DS) client-side via the `fkwcs_create_setup_intent` AJAX request
			 * before this form submits, so the card is already attached and authenticated by now;
			 * re-attaching is harmless. The free-trial / $0 checkout path ($is_change_subs = false)
			 * has no such pre-flow, so we must NOT attach upfront here: a pre-intent bare
			 * PaymentMethod.attach makes SCA-regulated (EU/UK) cards reject with a 402
			 * authentication_required, and no client_secret exists yet to present the 3DS challenge.
			 * Defer attachment to the SetupIntent created below instead.
			 */
			$attach_payment_method = $is_change_subs ? $this->validate_country_for_save_card() : false;
			$prepared_source       = $this->prepare_source( $subscription, $attach_payment_method );

			if ( is_object( $prepared_source ) && empty( $prepared_source->source ) ) {
				if ( ! empty( $subscription ) ) {
					/* translators: error message */
					$this->mark_order_failed( $subscription, __( 'Error: Unable to get payment method from the browser, please check for browser console error. ', 'funnelkit-stripe-woo-payment-gateway' ) );
					throw new \Exception( __( 'Payment processing failed. Please retry.', 'funnelkit-stripe-woo-payment-gateway' ), 200 );
				}
			}

			/**
			 * Change-payment / add-payment / pay-for-order pages already create AND confirm the
			 * SetupIntent (with 3DS) client-side via the `fkwcs_create_setup_intent` AJAX request
			 * before this form submits, so the card is already attached and authenticated here -
			 * creating another would be a duplicate.
			 *
			 * The branch below is only for the regular checkout path (e.g. free-trial / $0 orders),
			 * which has no such pre-flow and needs the SetupIntent + return URL created server-side.
			 */
			if ( false === $is_change_subs ) {
				$intent_secret = $this->create_setup_intent( $prepared_source->source, $prepared_source->customer, $subscription );
				if ( ! empty( $intent_secret ) ) {

					$intent_data = array(
						'id'            => $intent_secret['id'],
						'client_secret' => $intent_secret['client_secret'],
					);

					$subscription->update_meta_data( '_fkwcs_setup_intent', $intent_data );
					$subscription->save_meta_data();
					if ( $this->id === 'fkwcs_stripe_cashapp' ) {
						$this->save_payment_method_to_order( $subscription, $prepared_source );
					}

						// `get_return_url()` must be called immediately before returning a value.
						return array(
							'result'                    => 'success',
							'fkwcs_redirect'            => $this->get_return_url( $subscription ),
							'fkwcs_setup_intent_secret' => $intent_secret['client_secret'],
							'token_used'                => $this->is_using_saved_payment_method() ? 'yes' : 'no',
						);
				}
			}

			$this->save_payment_method_to_order( $subscription, $prepared_source );
			delete_transient( 'fkwcs_user_tokens_' . $subscription->get_user_id() );
			$subscription = $this->force_update_payment_method( $subscription );

			/**
			 * India RBI e-mandates are bound to a single card. When the customer changes
			 * the subscription card, clear the previous card's mandate (subscription and
			 * parent order) and capture the new one so the next renewal charges the new
			 * card with a matching mandate (ticket 86bbb5gg8).
			 */
			if ( 'card' === $this->payment_method_types ) {
				try {
					$parent_order = $subscription->get_parent();
					if ( $parent_order instanceof \WC_Order ) {
						$parent_order->delete_meta_data( '_stripe_mandate_id' );
						$parent_order->save_meta_data();
					}
				} catch ( \Throwable $e ) {
					Helper::log( 'Clearing parent mandate on PM change failed: ' . $e->getMessage(), 'warning' );
				}

				$this->sync_subscription_mandate( $subscription, $prepared_source->source );
			}

			do_action( 'fkwcs_change_subs_payment_method_success', $prepared_source->source, $prepared_source );
			if ( false === $is_change_subs ) {
				if ( 'automatic' === $this->capture_method ) {
					$subscription->payment_complete();
				} else {
					$subscription->update_status( apply_filters( 'fkwcs_stripe_authorized_order_status', 'on-hold' ) );
				}
			}

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $subscription ),
			);
		} catch ( \Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			Helper::log( 'Payment Failed. Reason: ' . $e->getMessage() );
			if ( false === $is_change_subs && ! empty( $subscription ) ) {
				/* translators: error message */
				$this->mark_order_failed( $subscription, $e->getMessage() );
			}
		}
	}

	/**
	 * Scheduled_subscription_payment function.
	 *
	 * @param $amount_to_charge float The amount to charge.
	 * @param $renewal_order \WC_Order A WC_Order object created to record the renewal payment.
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {

		$this->process_subscription_payment( $amount_to_charge, $renewal_order, true, false );
	}


	/**
	 * Process_subscription_payment function.
	 *
	 * @param float           $amount
	 * @param mixed|\WC_order $renewal_order
	 * @param bool            $retry Should we retry the process?
	 * @param object          $previous_error
	 *
	 * @return void|null
	 */
	public function process_subscription_payment( $amount, $renewal_order, $retry = true, $previous_error = false ) {
		// Guard against an invalid renewal order before dereferencing it below (get_id(), get_order_key(), etc.).
		if ( ! is_object( $renewal_order ) || ! method_exists( $renewal_order, 'get_id' ) ) {
			Helper::log( 'process_subscription_payment called without a valid renewal order object; aborting.', 'error' );

			return;
		}

		// Static recursion depth counter per order to prevent infinite loops.
		// Uses order ID as key to track depth independently for each order.
		static $recursion_depth = array();
		$order_id               = $renewal_order->get_id();
		$max_recursion          = apply_filters( 'fkwcs_stripe_max_payment_recursion_depth', 10, $renewal_order );

		if ( false === $this->is_configured() ) {
			return;
		}

		// Initialize recursion depth for this order if not set.
		if ( ! isset( $recursion_depth[ $order_id ] ) ) {
			$recursion_depth[ $order_id ] = 0;
		}

		// Check recursion depth before processing to prevent infinite loops.
		if ( $recursion_depth[ $order_id ] >= $max_recursion ) {
			Helper::log( "Error: Maximum recursion depth ({$max_recursion}) reached for order {$order_id}. Aborting to prevent infinite loop.", 'error' );
			throw new \Exception( esc_html__( 'Payment processing exceeds maximum retries! Please contact support.', 'funnelkit-stripe-woo-payment-gateway' ) );
		}

		// Increment recursion depth counter for this order.
		++$recursion_depth[ $order_id ];

		try {

			// Unlike regular off-session subscription payments, early renewals are treated as on-session payments, involving the customer.
			// This makes the SCA authorization popup show up for the "Renew early" modal (Subscriptions settings > Accept Early Renewal Payments via a Modal).
			// Card-backed wallet/express gateways (Apple Pay, Google Pay, Link) share this trait and must take the same on-session path so WCS advances next_payment and unschedules the queued renewal action.
			if ( isset( $_REQUEST['process_early_renewal'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing early renewal request
				$early_renewal_gateways = (array) apply_filters(
					'fkwcs_early_renewal_supported_gateways',
					array( 'fkwcs_stripe', 'fkwcs_stripe_apple_pay', 'fkwcs_stripe_google_pay', 'fkwcs_stripe_link' )
				);
			}
			if ( isset( $_REQUEST['process_early_renewal'] ) && in_array( $this->id, $early_renewal_gateways, true ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing early renewal request
				$response = $this->process_payment( $order_id, true, false, $previous_error, true );

				if ( ! is_null( $response ) && 'success' === $response['result'] && isset( $response['fkwcs_intent_secret'] ) ) {
					$verification_url = add_query_arg(
						array(
							'order'                       => $order_id,
							// verify_intent() validates the order key; without it the request fails with "Invalid Order Key".
							'order_key'                   => $renewal_order->get_order_key(),
							'early_renewal_payment_nonce' => wp_create_nonce( 'fkwcs_stripe_confirm_payment_intent' ),
							// verify_intent() reads fkwcs_redirect_to (not redirect_to) for the post-verification redirect.
							'fkwcs_redirect_to'           => rawurlencode( remove_query_arg( array( 'process_early_renewal', 'subscription_id', 'wcs_nonce' ) ) ),
							'gateway'                     => $this->id,
							'early_renewal'               => true,
						),
						\WC_AJAX::get_endpoint( 'fkwcs_stripe_verify_payment_intent' )
					);

					echo wp_json_encode(
						array(
							'fkwcs_stripe_sca_required' => true,
							'intent_secret'             => $response['fkwcs_intent_secret'],
							'redirect_url'              => $verification_url,
						)
					);

					exit;
				}

				// Hijack all other redirects in order to do the redirection in JavaScript.
				add_action( 'wp_redirect', array( $this, 'redirect_after_early_renewal' ), 100 );

				return;
			}

			// Check for an existing intent, which is associated with the order.
			if ( false === apply_filters( 'fkwcs_stripe_allow_subscription_payment_method_retry', false, $renewal_order ) && $this->has_authentication_already_failed( $renewal_order ) ) {
				return;
			}

			// Check for existing successful intent - if the renewal order already has an intent_id, this could be a duplicate request.
			// If the intent has already succeeded, don't continue with the payment.
			$existing_intent = $this->get_intent_from_order( $renewal_order );
			if ( $existing_intent ) {
				if ( in_array( $existing_intent->status, array( 'succeeded', 'requires_capture', 'processing' ) ) ) {
					if ( isset( $existing_intent->metadata['order_id'] ) && absint( $existing_intent->metadata['order_id'] ) === $renewal_order->get_id() ) {
						Helper::log( "Info: Found existing successful intent {$existing_intent->id} for order {$renewal_order->get_id()}, skipping payment processing" );
						// Process the successful payment
						do_action( 'fkwcs_gateway_stripe_process_payment', $existing_intent, $renewal_order );
						$this->process_final_order( isset( $existing_intent->charges ) ? end( $existing_intent->charges->data ) : $existing_intent, $renewal_order );

						return;
					}
				}
			}

			Helper::log( "Info: Begin processing subscription payment for order {$order_id} for the amount of {$amount}" );

			// Reset tried payment methods list only if this is a truly fresh attempt
			// (no previous error AND no existing tried payment methods metadata)
			// This prevents deleting metadata when retrying with alternative payment methods
			$existing_tried_methods = Helper::get_meta( $renewal_order, '_fkwcs_tried_payment_methods' );
			if ( false === $previous_error && empty( $existing_tried_methods ) ) {
				$renewal_order->delete_meta_data( '_fkwcs_tried_payment_methods' );
				$renewal_order->save_meta_data();
			}

			// Get source from order
			$prepared_source = $this->prepare_subscription_order_source( $renewal_order );
			$subscriptions   = wcs_get_subscriptions_for_renewal_order( $renewal_order->get_id() );
			foreach ( $subscriptions as $subscription ) {
				$this->force_update_payment_method( $subscription );
			}
			if ( is_null( $prepared_source ) || ! $prepared_source->customer ) {
				throw new \Exception( 'Failed to process renewal for order ' . $renewal_order->get_id() . '. Stripe customer id is missing in the order', 200 );
			}

			try {
				// Check for temporary alternative payment method (from retry logic)
				// This is set when retrying with alternative payment methods but not persisted to order meta
				// Only modify prepared_source, don't persist until payment succeeds
				$temp_alternative_source = Helper::get_meta( $renewal_order, '_fkwcs_temp_alternative_source_id' );
				if ( ! empty( $temp_alternative_source ) ) {
					if ( '__default__' === $temp_alternative_source ) {
						$prepared_source->source        = '';
						$prepared_source->source_object = (object) false;
						Helper::log( "Info: Using temporary alternative payment method (default customer source) for order {$order_id}" );
					} else {
						$source_object = $this->retrieve_payment_method_object( $temp_alternative_source );
						if ( $source_object ) {
							$prepared_source->source        = $temp_alternative_source;
							$prepared_source->source_object = (object) $source_object;
							Helper::log( "Info: Using temporary alternative payment method {$temp_alternative_source} for order {$order_id}" );
						} else {
							Helper::log( "Warning: Failed to retrieve payment method object for {$temp_alternative_source} for order {$order_id}, falling back to default behavior", 'warning' );
							$prepared_source->source        = '';
							$prepared_source->source_object = (object) false;
						}
					}
					// Clean up temporary meta after use (don't persist until success)
					$renewal_order->delete_meta_data( '_fkwcs_temp_alternative_source_id' );
					$renewal_order->save_meta_data();
				}
			} catch ( \Exception $e ) {
				Helper::log( 'Unable to plant any alternative payment method for order ' . $order_id . 'due to ' . $e->getMessage(), 'warning' );
			}

			if ( ( $this->is_no_such_source_error( $prepared_source->source_object ) || $this->is_no_linked_source_error( $prepared_source->source_object ) || $this->is_source_must_be_attached_error( $prepared_source->source_object ) ) && apply_filters( 'fkwcs_stripe_use_default_customer_source', true ) ) {

				// Passing empty source will charge customer default.
				$prepared_source->source = '';
			}

			if ( ( $this->is_no_such_source_error( $previous_error ) || $this->is_no_linked_source_error( $previous_error ) || $this->is_source_must_be_attached_error( $previous_error ) || $this->is_payment_method_attachment_error( $previous_error ) ) && apply_filters( 'fkwcs_stripe_use_default_customer_source', true ) ) {

				// Passing empty source will charge customer default.
				Helper::log( "Info: Payment method attachment error detected for order {$order_id}, switching to customer default payment method" );
				$prepared_source->source = '';
			}

			$this->lock_order_payment( $renewal_order );
			$response                   = $this->create_and_confirm_intent_for_off_session( $renewal_order, $prepared_source, $previous_error );
			$is_authentication_required = $this->is_authentication_required_for_payment( $response );

			// Handle payment errors (including authentication_required)
			if ( ! empty( $response->error ) ) {

				// Try alternative payment methods if available and enabled
				if ( apply_filters( 'fkwcs_stripe_allow_subscription_payment_method_retry', false, $renewal_order ) ) {
					$alternative_payment_method = $this->try_alternative_payment_method( $renewal_order, $prepared_source, $response->error );
					if ( $alternative_payment_method ) {
						Helper::log( "Info: Retrying payment for order {$order_id} with alternative payment method: {$alternative_payment_method}" );

						// Store alternative payment method in temporary meta (not persisted to order meta)
						// This will be used in the recursive call to modify prepared_source without persisting
						// Only persist to order/subscription meta after successful payment
						if ( '' !== $alternative_payment_method ) {
							$renewal_order->update_meta_data( '_fkwcs_temp_alternative_source_id', $alternative_payment_method );
						} else {
							// For empty string (default source), use special marker
							$renewal_order->update_meta_data( '_fkwcs_temp_alternative_source_id', '__default__' );
						}
						$renewal_order->save_meta_data();

						// Clear the previous error to allow fresh attempt
						$previous_error = false;

						// Retry with the alternative payment method
						return $this->process_subscription_payment( $amount, $renewal_order, $retry, $previous_error );
					}
				}

				// If authentication is required and no alternative method found, proceed to authentication handling
				if ( $is_authentication_required ) {
					// Clean up temporary alternative payment method meta before proceeding to authentication handling
					$renewal_order->delete_meta_data( '_fkwcs_temp_alternative_source_id' );
					$renewal_order->save_meta_data();
					// Fall through to authentication_required handling below
				} else {

					// We want to retry.
					if ( $this->is_retryable_error( $response->error ) ) {
						if ( $retry ) {
							// Don't do anymore retries after this.
							if ( 5 <= $this->retry_interval ) {
								// Clean up temporary meta before recursive call
								$renewal_order->delete_meta_data( '_fkwcs_temp_alternative_source_id' );
								$renewal_order->save_meta_data();
								return $this->process_subscription_payment( $amount, $renewal_order, false, $response->error );
							}

							sleep( $this->retry_interval );

							++$this->retry_interval;

							return $this->process_subscription_payment( $amount, $renewal_order, true, $response->error );
						} else {
							// Clean up temporary meta before throwing exception
							$renewal_order->delete_meta_data( '_fkwcs_temp_alternative_source_id' );
							$renewal_order->save_meta_data();
							$localized_message = __( 'Sorry, we are unable to process your payment at this time. Please retry later.', 'funnelkit-stripe-woo-payment-gateway' );
							throw new \Exception( $localized_message ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
						}
					}

					// Clean up temporary meta before throwing exception
					$renewal_order->delete_meta_data( '_fkwcs_temp_alternative_source_id' );
					$renewal_order->save_meta_data();
					$localized_message = Helper::get_localized_error_message( $response->error );

					throw new \Exception( $localized_message ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
				}
			}

			// Either the charge was successfully captured, or it requires further authentication.
			if ( $is_authentication_required ) {
				do_action( 'fkwcs_gateway_stripe_process_payment_authentication_required', $renewal_order, $response );

				$error_message = __( 'This transaction requires authentication.', 'funnelkit-stripe-woo-payment-gateway' );
				$renewal_order->add_order_note( $error_message );

				$charge = end( $response->error->payment_intent->charges->data );
				$id     = $charge->id;
				$this->save_intent_to_order( $renewal_order, $response->error->payment_intent );

				$renewal_order->set_transaction_id( $id );
				do_action( 'fkwcs_process_response', $charge, $renewal_order );

				/* translators: %s is the charge Id */

				$this->mark_order_failed( $renewal_order, sprintf( __( 'Stripe charge awaiting authentication by user: %s.', 'funnelkit-stripe-woo-payment-gateway' ), $id ) );
				if ( is_callable( array( $renewal_order, 'save' ) ) ) {
					$renewal_order->save();
				}
			} elseif ( $this->maybe_check_for_auth( $response->data ) ) {
				$charge_attempt_at = $response->data->processing->card->customer_notification->completes_at;
				$attempt_date      = wp_date( get_option( 'date_format', 'F j, Y' ), $charge_attempt_at, wp_timezone() );
				$attempt_time      = wp_date( get_option( 'time_format', 'g:i a' ), $charge_attempt_at, wp_timezone() );

				$message = sprintf( /* translators: 1) a date in the format yyyy-mm-dd, e.g. 2021-09-21; 2) time in the 24-hour format HH:mm, e.g. 23:04 */ __( 'The customer must authorize this payment via the pre-debit notification sent to them by their card issuing bank, before %1$s at %2$s, when the charge will be attempted.', 'funnelkit-stripe-woo-payment-gateway' ), $attempt_date, $attempt_time );
				$renewal_order->add_order_note( $message );
				$renewal_order->update_status( 'pending' );
				$renewal_order->update_meta_data( '_fkwcs_maybe_check_for_auth', 'yes' );
				$this->save_intent_to_order( $renewal_order, $response->data );
				do_action( 'fkwcs_process_response', $this->get_latest_charge_from_intent( $response->data ), $renewal_order );

				if ( is_callable( array( $renewal_order, 'save' ) ) ) {
					$renewal_order->save();
				}
			} else {

				$response = $response->data;
				if ( 'pending' === $response->status || 'processing' === $response->status ) {
					$this->save_intent_to_order( $renewal_order, $response );

					$order_stock_reduced = Helper::get_meta( $renewal_order, '_order_stock_reduced' );

					if ( ! $order_stock_reduced ) {
						wc_reduce_stock_levels( $order_id );
					}

					$renewal_order->set_transaction_id( $response->id );
					$others_info = __( 'Payment will be completed once payment_intent.succeeded webhook received from Stripe.', 'funnelkit-stripe-woo-payment-gateway' );

					/* translators: transaction id, other info */
					$renewal_order->update_status( 'on-hold', sprintf( __( 'Stripe charge awaiting payment: %1$s. %2$s', 'funnelkit-stripe-woo-payment-gateway' ), $response->id, $others_info ) );
					do_action( 'fkwcs_process_response', $this->get_latest_charge_from_intent( $response ), $renewal_order );
				} else {

					$this->save_intent_to_order( $renewal_order, $response );
					// The charge was successfully captured
					do_action( 'fkwcs_gateway_stripe_process_payment', $response, $renewal_order );

					// If payment succeeded with a different payment method, update subscription meta
					// Get the actual payment method from the intent if source was empty (default)
					$payment_method_id = ! empty( $prepared_source->source ) ? $prepared_source->source : '';
					if ( empty( $payment_method_id ) && isset( $response->payment_method ) ) {
						$payment_method_id = $response->payment_method;
					}
					if ( ! empty( $payment_method_id ) ) {
						$this->update_subscription_payment_method_on_success( $renewal_order, $payment_method_id );
					}

					// Use the last charge within the intent or the full response body in case of SEPA  Or ACH.
					$this->process_final_order( isset( $response->charges ) ? end( $response->charges->data ) : $response, $renewal_order );
				}
			}
		} catch ( \Exception $e ) {
			Helper::log( $e->getMessage(), 'warning' );

			// Clean up temporary alternative payment method meta if it exists
			// This ensures failed attempts don't leave temporary data
			$renewal_order->delete_meta_data( '_fkwcs_temp_alternative_source_id' );
			$renewal_order->save_meta_data();

			do_action( 'fkwcs_gateway_stripe_process_payment_error', $e, $renewal_order );

			$this->mark_order_failed( $renewal_order, $e->getMessage() );

			if ( ! empty( $response ) ) {
				$charge = $this->get_latest_charge_from_intent( $response );
				do_action( 'fkwcs_process_response', $charge, $renewal_order );

			}
		} finally {
			// Always decrement recursion depth counter, even if exception occurs.
			// This ensures the counter is properly reset for future calls.
			if ( isset( $recursion_depth[ $order_id ] ) && $recursion_depth[ $order_id ] > 0 ) {
				--$recursion_depth[ $order_id ];
				// Clean up recursion depth tracking for this order if depth reaches zero.
				if ( 0 === $recursion_depth[ $order_id ] ) {
					unset( $recursion_depth[ $order_id ] );
				}
			}
		}
	}

	public function prepare_subscription_order_source( $order = null ) {
		$stripe_source      = false;
		$token_id           = false;
		$source_object      = false;
		$customer_key       = Helper::get_customer_key();
		$stripe_customer_id = '';
		if ( $order ) {
			$client = $this->get_client();

			if ( is_null( $client ) ) {

				Helper::log( __FUNCTION__ . ' Stripe Client not setup' );

				return null;
			}

			$stripe_customer_id = $this->get_order_stripe_data( $customer_key, $order );
			$source_id          = $this->get_order_stripe_data( '_fkwcs_source_id', $order );
			if ( $source_id ) {
				$stripe_source = $source_id;
				$source_object = $this->retrieve_payment_method_object( $source_id );
			} elseif ( apply_filters( 'fkwcs_stripe_use_default_customer_source', true ) ) {
				/*
				 * We can attempt to charge the customer's default source
				 * by sending empty source id.
				 */
				$stripe_source = '';
			}
		}

		return (object) array(
			'token_id'       => $token_id,
			'customer'       => $stripe_customer_id,
			'source'         => $stripe_source,
			'source_object'  => (object) $source_object,
			'payment_method' => null,
		);
	}

	/**
	 * Updates other subscription sources.
	 */
	public function maybe_update_source_on_subscription_order( $order, $source ) {
		if ( ! $this->is_subscriptions_enabled() ) {
			return;
		}

		$order_id = $order->get_id();

		// Also store it on the subscriptions being purchased or paid for in the order
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order_id ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order_id );
		} elseif ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order_id ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );
		} else {
			$subscriptions = array();
		}
		$customer_key = Helper::get_customer_key();
		foreach ( $subscriptions as $subscription ) {
			$subscription->update_meta_data( $customer_key, $source->customer );
			$new_source_id = ! empty( $source->payment_method ) ? $source->payment_method : $source->source;
			$subscription->update_meta_data( '_fkwcs_source_id', $new_source_id );
			$subscription->save_meta_data();

			// Keep the India e-mandate symmetric with the source by copying the mandate the
			// paying order already captured - no Stripe lookup on this path.
			$this->sync_subscription_mandate_from_order( $subscription, $order );
		}
	}


	/**
	 * Don't transfer payment meta to resubscribe orders.
	 *
	 * @param \WC_Order        $resubscribe_order The resubscribe order.
	 * @param \WC_Subscription $subscription      The subscription the resubscribe is related to.
	 *
	 * @return void
	 */
	public function delete_resubscribe_meta( $resubscribe_order, $subscription = null ) {
		// Validate the resubscribe order before processing
		if ( ! is_a( $resubscribe_order, 'WC_Order' ) ) {
			return;
		}

		$resubscribe_order->delete_meta_data( Helper::get_customer_key() );
		$resubscribe_order->delete_meta_data( '_fkwcs_source_id' );
		$resubscribe_order->delete_meta_data( '_fkwcs_card_id' );
		$resubscribe_order->delete_meta_data( '_fkwcs_intent_id' );

		// Also clean renewal meta (reuses the same logic)
		$this->delete_renewal_meta( $resubscribe_order, $subscription );
	}

	/**
	 * Don't transfer payment intent meta to renewal orders.
	 *
	 * @param \WC_Order        $renewal_order The renewal order.
	 * @param \WC_Subscription $subscription  The subscription the renewal is related to.
	 *
	 * @return \WC_Order The renewal order (or original value if invalid to maintain filter chain).
	 */
	public function delete_renewal_meta( $renewal_order, $subscription = null ) {
		// Validate the renewal order before processing
		if ( ! is_a( $renewal_order, 'WC_Order' ) ) {
			// Return the original value to maintain filter chain integrity
			// This prevents breaking other plugins that depend on this filter
			return $renewal_order;
		}

		// Delete payment intent ID from renewal order
		$renewal_order->delete_meta_data( '_fkwcs_intent_id' );
		$renewal_order->save_meta_data();

		// Always return the order to maintain filter chain
		return $renewal_order;
	}

	/**
	 * @param \WC_Subscription $subscription
	 * @param $renewal_order
	 *
	 * @return void
	 */
	public function update_failing_payment_method( $subscription, $renewal_order ) {
		$subscription->update_meta_data( Helper::get_customer_key(), Helper::get_meta( $renewal_order, Helper::get_customer_key() ) );
		$subscription->update_meta_data( '_fkwcs_source_id', Helper::get_meta( $renewal_order, '_fkwcs_source_id' ) );
		$subscription->save_meta_data();

		// Hooked on the SUCCESS of a (previously failing) renewal order - the renewal
		// order carries the fresh mandate for the card actually charged. Always copy it.
		$this->sync_subscription_mandate_from_order( $subscription, $renewal_order );
	}

	/**
	 * Include the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscriptions screen in 2.0+.
	 *
	 * @param array            $payment_meta associative array of meta data required for automatic payments
	 * @param \WC_Subscription $subscription An instance of a subscription object
	 *
	 * @return array
	 */
	public function add_subscription_payment_meta( $payment_meta, $subscription ) {
		$source_id = Helper::get_meta( $subscription, '_fkwcs_source_id' );

		// For BW compat will remove in future.
		if ( empty( $source_id ) ) {
			$source_id = Helper::get_meta( $subscription, '_fkwcs_card_id' );

			// Take this opportunity to update the key name.
			$subscription->update_meta_data( '_fkwcs_source_id', $source_id );
			$subscription->delete_meta_data( '_fkwcs_card_id' );
			$subscription->save_meta_data();
		}
		$customer_key              = Helper::get_customer_key();
		$payment_meta[ $this->id ] = array(
			'post_meta' => array(
				$customer_key      => array(
					'value' => $this->get_order_stripe_data( $customer_key, $subscription ),
					'label' => 'Stripe Customer ID',
				),
				'_fkwcs_source_id' => array(
					'value' => $this->get_order_stripe_data( '_fkwcs_source_id', $subscription ),
					'label' => 'Stripe Source ID',
				),

			),
		);

		/**
		 * we are passing the same meta for official stripe as well to let the woocommerce subscription know that we have the same meta for that gateway
		 * this need arises when token payment method gets into consideration instead of subscriptions
		 */
		$payment_meta['stripe'] = array(
			'post_meta' => array(
				$customer_key      => array(
					'value' => $this->get_order_stripe_data( $customer_key, $subscription ),
					'label' => 'Stripe Customer ID',
				),
				'_fkwcs_source_id' => array(
					'value' => $this->get_order_stripe_data( '_fkwcs_source_id', $subscription ),
					'label' => 'Stripe Source ID',
				),

			),
		);

		return $payment_meta;
	}

	/**
	 * Validate the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscriptions screen in 2.0+.
	 *
	 * @param string           $payment_method_id The ID of the payment method to validate
	 * @param array            $payment_meta associative array of meta data required for automatic payments
	 * @param \WC_Subscription $subscription associative array of meta data required for automatic payments
	 *
	 * @return array
	 */
	public function validate_subscription_payment_meta( $payment_method_id, $payment_meta, $subscription ) {
		if ( $this->id === $payment_method_id ) {
			$fkwcs_customer_id = Helper::get_customer_key();

			/**
			 * Try to find out customer ID from all the sources available
			 */
			$customer_id        = false;
			$other_customer_IDs = Helper::get_compatibility_keys( $fkwcs_customer_id );

			if ( isset( $payment_meta['post_meta'][ $fkwcs_customer_id ]['value'] ) && ! empty( $payment_meta['post_meta'][ $fkwcs_customer_id ]['value'] ) ) {
				$customer_id = $payment_meta['post_meta'][ $fkwcs_customer_id ]['value'];
			} elseif ( Helper::get_meta( $subscription, $other_customer_IDs[0] ) ) {
				$customer_id = Helper::get_meta( $subscription, $other_customer_IDs[0] );

			} elseif ( Helper::get_meta( $subscription, $other_customer_IDs[1] ) ) {
				$customer_id = Helper::get_meta( $subscription, $other_customer_IDs[1] );

			}

			if ( empty( $customer_id ) ) {
				// Allow empty stripe customer id during subscription renewal. It will be added when processing payment if required.
				if ( ! isset( $_POST['wc_order_action'] ) || 'wcs_process_renewal' !== sanitize_text_field( wp_unslash( $_POST['wc_order_action'] ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing order action during payment processing
					throw new \Exception( esc_html__( 'A "Stripe Customer ID" value is required.', 'funnelkit-stripe-woo-payment-gateway' ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
			} elseif ( 0 !== strpos( $customer_id, 'cus_' ) ) {
				throw new \Exception( __( 'Invalid customer ID. A valid "Stripe Customer ID" must begin with "cus_".', 'funnelkit-stripe-woo-payment-gateway' ) ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			/**
			 * try and find our source ID from the all possible meta
			 */
			$source           = false;
			$other_source_IDs = Helper::get_compatibility_keys( '_fkwcs_source_id' );
			if ( isset( $payment_meta['post_meta']['_fkwcs_source_id']['value'] ) && ! empty( $payment_meta['post_meta']['_fkwcs_source_id']['value'] ) ) {
				$source = $payment_meta['post_meta']['_fkwcs_source_id']['value'];
			} elseif ( Helper::get_meta( $subscription, $other_source_IDs[0] ) ) {
				$source = Helper::get_meta( $subscription, $other_source_IDs[0] );

			} elseif ( Helper::get_meta( $subscription, $other_source_IDs[1] ) ) {
				$source = Helper::get_meta( $subscription, $other_source_IDs[1] );

			}

			if ( ! empty( $source ) && ( 0 !== strpos( $source, 'card_' ) && 0 !== strpos( $source, 'src_' ) && 0 !== strpos( $source, 'pm_' ) ) ) {
				throw new \Exception( __( 'Invalid source ID. A valid source "Stripe Source ID" must begin with "src_", "pm_", or "card_".', 'funnelkit-stripe-woo-payment-gateway' ) ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
	}

	/**
	 * Render the payment method used for a subscription in the "My Subscriptions" table
	 *
	 * @param string           $payment_method_to_display the default payment method text to display
	 * @param \WC_Subscription $subscription the subscription details
	 *
	 * @return string the subscription payment method
	 */
	public function maybe_render_subscription_payment_method( $payment_method_to_display, $subscription ) {

		$customer_user     = $subscription->get_customer_id();
		$fkwcs_customer_id = Helper::get_customer_key();

		// bail for other payment methods
		if ( $subscription->get_payment_method() !== $this->id || ! $customer_user ) {
			return $payment_method_to_display;
		}

		$other_source_IDs = Helper::get_compatibility_keys( '_fkwcs_source_id' );
		$stripe_source_id = Helper::get_meta( $subscription, '_fkwcs_source_id' );
		if ( empty( $stripe_source_id ) ) {
			if ( Helper::get_meta( $subscription, $other_source_IDs[0] ) ) {
				$stripe_source_id = Helper::get_meta( $subscription, $other_source_IDs[0] );

			} elseif ( Helper::get_meta( $subscription, $other_source_IDs[1] ) ) {
				$stripe_source_id = Helper::get_meta( $subscription, $other_source_IDs[1] );

			}
		}

		$stripe_customer_id = Helper::get_meta( $subscription, $fkwcs_customer_id );
		$user_id            = $subscription->get_customer_id();
		if ( empty( $stripe_customer_id ) ) {
			$stripe_customer_id = $this->filter_customer_id( get_user_option( '_fkwcs_customer_id', $user_id ) );
			if ( empty( $stripe_customer_id ) ) {
				$compatibility_keys = Helper::get_compatibility_keys( '_fkwcs_customer_id' );
				if ( ! empty( $compatibility_keys ) ) {
					foreach ( $compatibility_keys as $key ) {
						$stripe_customer_id = $this->filter_customer_id( get_user_option( $key, $user_id ) );
						if ( ! empty( $stripe_customer_id ) ) {
							break;
						}
					}
				}
			}
		}

		// If we couldn't find a Stripe customer linked to the subscription, fallback to the user meta data.
		if ( ! $stripe_customer_id || ! is_string( $stripe_customer_id ) ) {
			$user_id            = $customer_user;
			$stripe_customer_id = $this->filter_customer_id( get_user_option( $fkwcs_customer_id, $user_id ) );
			$stripe_source_id   = $this->filter_customer_id( get_user_option( '_fkwcs_source_id', $user_id ) );
		}

		// If we couldn't find a Stripe customer linked to the account, fallback to the order meta data.
		if ( ( ! $stripe_customer_id || ! is_string( $stripe_customer_id ) ) && false !== $subscription->get_parent() ) {
			$stripe_customer_id = Helper::get_meta( $subscription->get_parent(), $fkwcs_customer_id );
			$stripe_source_id   = Helper::get_meta( $subscription->get_parent(), '_fkwcs_source_id' );
		}

		// Retrieve all possible payment methods for subscriptions.
		$payment_type              = isset( $this->payment_method_types ) ? $this->payment_method_types : 'card';
		$sources                   = array_merge( $this->get_payment_methods( $stripe_customer_id, $payment_type ) );
		$payment_method_to_display = __( 'N/A', 'funnelkit-stripe-woo-payment-gateway' );

		if ( $sources ) {

			foreach ( $sources as $source ) {
				if ( $source->id === $stripe_source_id ) {
					$card = false;
					if ( isset( $source->type ) && 'card' === $source->type ) {
						$card = $source->card;
					} elseif ( isset( $source->object ) && 'card' === $source->object ) {
						$card = $source;
					}
					if ( $card ) {
						/* translators: 1) card brand 2) last 4 digits */
						$payment_method_to_display = sprintf( __( 'Via %1$s card ending in %2$s', 'funnelkit-stripe-woo-payment-gateway' ), ( isset( $card->brand ) ? $card->brand : __( 'N/A', 'funnelkit-stripe-woo-payment-gateway' ) ), $card->last4 );
					} elseif ( isset( $source->sepa_debit ) && $source->sepa_debit ) {
						/* translators: 1) last 4 digits of SEPA Direct Debit */
						$payment_method_to_display = sprintf( __( 'Via SEPA Direct Debit ending in %1$s', 'funnelkit-stripe-woo-payment-gateway' ), $source->sepa_debit->last4 );
					} elseif ( isset( $source->us_bank_account ) && $source->us_bank_account ) {
						/* translators: 1) last 4 digits of ACH Bank */
						$payment_method_to_display = sprintf( __( 'Via %1$s ending in %2$s', 'funnelkit-stripe-woo-payment-gateway' ), $source->us_bank_account->bank_name, $source->us_bank_account->last4 );
					} elseif ( isset( $source->cashapp ) && $source->cashapp ) {
						/* translators: 1) last 4 digits of ACH Bank */
						$payment_method_to_display = sprintf( __( 'Via %1$s ', 'funnelkit-stripe-woo-payment-gateway' ), $source->type );
					}
					break;
				}
			}
		}

		return $payment_method_to_display;
	}


	/**
	 * Checks if a renewal already failed because a manual authentication is required.
	 *
	 * @param \WC_Order $renewal_order The renewal order.
	 *
	 * @return boolean
	 */
	public function has_authentication_already_failed( $renewal_order ) {
		$existing_intent = $this->get_intent_from_order( $renewal_order );

		if ( ! $existing_intent || 'requires_payment_method' !== $existing_intent->status || empty( $existing_intent->last_payment_error ) || 'authentication_required' !== $existing_intent->last_payment_error->code ) {
			return false;
		}

		// Make sure all emails are instantiated.
		\WC_Emails::instance();

		/**
		 * A payment attempt failed because SCA authentication is required.
		 *
		 * @param \WC_Order $renewal_order The order that is being renewed.
		 */
		do_action( 'wc_gateway_stripe_process_payment_authentication_required', $renewal_order );

		// Fail the payment attempt (order would be currently pending because of retry rules).
		$charge    = end( $existing_intent->charges->data );
		$charge_id = $charge->id;
		/* translators: %s is the stripe charge Id */
		$this->mark_order_failed( $renewal_order, sprintf( __( 'Stripe charge awaiting authentication by user: %s.', 'funnelkit-stripe-woo-payment-gateway' ), $charge_id ) );

		return true;
	}

	/**
	 * Hijacks `wp_redirect` in order to generate a JS-friendly object with the URL.
	 *
	 * @param string $url The URL that Subscriptions attempts a redirect to.
	 *
	 * @return void
	 */
	public function redirect_after_early_renewal( $url ) {
		echo wp_json_encode(
			array(
				'fkwcs_stripe_sca_required' => false,
				'redirect_url'              => $url,
			)
		);

		exit;
	}


	public function get_payment_methods( $customer_id, $payment_method_type ) {
		if ( ! $customer_id ) {
			return array();
		}

		if ( is_array( $this->customer_data ) && isset( $this->customer_data[ $customer_id ] ) ) {
			return $this->customer_data[ $customer_id ];
		}

		$stripe_api  = $this->get_client();
		$list_params = array(
			'customer' => $customer_id,
			'type'     => $payment_method_type,
			'limit'    => 100, // Maximum allowed value.
		);

		$response        = $stripe_api->payment_methods( 'all', array( $list_params ) );
		$payment_methods = $response['success'] ? $response['data'] : false;

		if ( $payment_methods === false || ! empty( $payment_methods->error ) ) {
			return array();
		}

		if ( is_array( $payment_methods->data ) ) {
			$payment_methods = $payment_methods->data;
		}

		$this->customer_data[ $customer_id ] = $payment_methods;

		return empty( $payment_methods ) ? array() : $payment_methods;
	}


	public function maybe_add_emandate_data_to_request( $data, $order, $is_setup_intent = false ) {

		/**
		 * Do not proceed further if there we do not have subscriptions
		 */
		if ( false === $order || ! $this->has_subscription( $order->get_id() ) ) {
			return $data;
		}

		/**
		 * Handle automatic subscription renewal request here
		 */
		if ( 0 < did_action( 'woocommerce_scheduled_subscription_payment_' . $this->id ) ) {

			/**
			 * Non-card gateways (SEPA/iDEAL/Bancontact) keep the legacy mandate
			 * resolution untouched - only India card e-mandates are bound to a single
			 * PaymentMethod and can go stale when the customer changes the card.
			 */
			if ( 'card' !== $this->payment_method_types ) {
				$mandate = $order->get_meta( '_stripe_mandate_id', true );
				if ( ! empty( $mandate ) ) {
					$data['mandate'] = $mandate;

					return $data;
				}

				$renewals = wcs_get_subscriptions_for_renewal_order( $order );
				if ( 1 === count( $renewals ) ) {
					$renewal_order = reset( $renewals );
					$parent_order  = wc_get_order( $renewal_order->get_parent_id() );

					if ( $parent_order ) {
						$mandate = $parent_order->get_meta( '_stripe_mandate_id', true );
						if ( ! empty( $mandate ) ) {
							$data['mandate'] = $mandate;

							return $data;
						}
					}
				}

				// No stored mandate for this non-card gateway - fall through to the
				// mandate_options builder below, preserving the pre-refactor behavior
				// for SEPA/iDEAL/Bancontact renewals (AC7).
			} else {
				/**
				 * Card gateway: the mandate is bound to exactly one PaymentMethod, so it
				 * must match the card currently on the subscription. Resolve candidates
				 * most-specific first (renewal order -> subscription -> parent order) and
				 * attach only the one that belongs to the current source. Subscriptions
				 * with no stored mandate at all (non-India stores) keep the legacy request
				 * untouched; only when stored mandate(s) exist but are confirmed to belong
				 * to a different card does the request fall through to mint a fresh
				 * mandate_options payload for the card actually being charged.
				 */
				$renewals       = wcs_get_subscriptions_for_renewal_order( $order );
				$subscription   = ( 1 === count( $renewals ) ) ? reset( $renewals ) : false;
				$current_source = ( $subscription instanceof \WC_Subscription ) ? Helper::get_meta( $subscription, '_fkwcs_source_id' ) : '';

				$mandate_candidates = array( $order->get_meta( '_stripe_mandate_id', true ) );
				if ( $subscription instanceof \WC_Subscription ) {
					$mandate_candidates[] = $subscription->get_meta( '_stripe_mandate_id', true );
					$parent_order         = $subscription->get_parent();
					if ( $parent_order instanceof \WC_Order ) {
						$mandate_candidates[] = $parent_order->get_meta( '_stripe_mandate_id', true );
					}
				}

				$indeterminate_candidate = '';
				$has_mandate_history     = false;

				foreach ( $mandate_candidates as $candidate ) {
					if ( empty( $candidate ) ) {
						continue;
					}

					// A stored mandate means this subscription is on the India e-mandate
					// lifecycle - only then may the no-match fall-through below engage.
					$has_mandate_history = true;

					/**
					 * When the current source can't be determined (legacy data) trust the
					 * stored mandate to avoid regressing original-card renewals; otherwise
					 * the mandate must belong to the card actually being charged.
					 */
					if ( empty( $current_source ) ) {
						$data['mandate'] = $candidate;

						return $data;
					}

					$belongs = $this->mandate_belongs_to_source( $candidate, $current_source );

					if ( true === $belongs ) {
						$data['mandate'] = $candidate;

						return $data;
					}

					/**
					 * Indeterminate verdict (Stripe lookup failed): remember the first such
					 * candidate so a transient API failure never drops a possibly-valid
					 * mandate, but keep scanning for a confirmed match first.
					 */
					if ( null === $belongs && empty( $indeterminate_candidate ) ) {
						$indeterminate_candidate = $candidate;
					}
				}

				if ( ! empty( $indeterminate_candidate ) ) {
					Helper::log( 'Renewal order ' . $order->get_id() . ': mandate ownership indeterminate (Stripe lookup failed), attaching stored mandate ' . $indeterminate_candidate );
					$data['mandate'] = $indeterminate_candidate;

					return $data;
				}

				/**
				 * No stored mandate at all: this subscription never had an India
				 * e-mandate (the overwhelmingly common, non-India case). Leave the
				 * renewal request exactly as before - do NOT fall through, so renewals
				 * outside the India mandate lifecycle are byte-identical to legacy.
				 */
				if ( ! $has_mandate_history ) {
					return $data;
				}

				/**
				 * India e-mandate lifecycle only: every stored candidate is confirmed to
				 * belong to a different card (the customer changed the subscription card)
				 * - fall through to the mandate_options builder below so a fresh mandate
				 * is minted for the card actually being charged.
				 */
				Helper::log( 'Renewal order ' . $order->get_id() . ': stored mandate(s) belong to a different card than source ' . $current_source . ', building fresh mandate_options.' );
			}
		}

		$subscriptions = wcs_get_subscriptions_for_order( $order );

		/**
		 * The change-payment-method SetupIntent passes the subscription itself rather
		 * than a parent order, so resolve it directly to still apply India mandate
		 * options to the SetupIntent for the new card.
		 */
		if ( empty( $subscriptions ) && function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order ) ) {
			$subscriptions = array( $order );
		}

		/**
		 * Card renewal fall-through: a renewal order is neither a parent nor a switch
		 * order, so resolve its subscriptions directly to build fresh mandate_options
		 * when no stored mandate matched the current card. Card only - non-card
		 * gateways keep the legacy renewal behavior untouched (AC7).
		 */
		if ( empty( $subscriptions ) && 'card' === $this->payment_method_types && 0 < did_action( 'woocommerce_scheduled_subscription_payment_' . $this->id ) && function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
		}

		/**
		 * return from here because creating mandate is not required at all
		 */
		if ( 0 === count( $subscriptions ) ) {
			return $data;
		}

		$sub_amount = 0;
		foreach ( $subscriptions as $sub ) {
			$sub_amount += Helper::get_stripe_amount( $sub->get_total() );
		}

		/**
		 * Avoid creating mandate when zero amount,if zero it will throw an API error later on
		 */
		if ( 0 === $sub_amount ) {
			return $data;
		}
		$sub = reset( $subscriptions );

		if ( 1 === count( $subscriptions ) ) {
			$data['payment_method_options']['card']['mandate_options']['amount_type']    = 'fixed';
			$data['payment_method_options']['card']['mandate_options']['interval']       = $sub->get_billing_period();
			$data['payment_method_options']['card']['mandate_options']['interval_count'] = $sub->get_billing_interval();
		} else {
			// If there are multiple subscriptions the amount_type becomes 'maximum' so we can charge anything
			// less than the order total, and the interval is sporadic so we don't have to follow a set interval.
			$data['payment_method_options']['card']['mandate_options']['amount_type'] = 'maximum';
			$data['payment_method_options']['card']['mandate_options']['interval']    = 'sporadic';
		}

		/**
		 * Set other common params
		 */
		$data['payment_method_options']['card']['mandate_options']['amount']          = $sub_amount;
		$data['payment_method_options']['card']['mandate_options']['reference']       = $order->get_id();
		$data['payment_method_options']['card']['mandate_options']['start_date']      = $sub->get_time( 'start' );
		$data['payment_method_options']['card']['mandate_options']['supported_types'] = array( 'india' );

		if ( true === $is_setup_intent ) {
			$data['payment_method_options']['card']['mandate_options']['currency'] = strtolower( $order->get_currency() );
		}

		return $data;
	}

	/**
	 * Check for processing card reason
	 *
	 * Only valid for mandates for Indian 3DS regulations.
	 *
	 * @param \StdClass $payment_intent the Payment Intent to be evaluated.
	 *
	 * @return bool true if payment intent must be authorized off session, false otherwise.
	 */
	public function maybe_check_for_auth( $payment_intent ) {
		return ! empty( $payment_intent->status ) && 'processing' === $payment_intent->status && ! empty( $payment_intent->processing->card->customer_notification->completes_at );
	}


	/**
	 * Force update the payment method for a subscription.
	 *
	 * This method updates the payment method for a given subscription to the current instance's payment method.
	 *
	 * @param \WC_Subscription||\WC_Order $subscription The subscription object to update.
	 *
	 * @return \WC_Subscription The updated subscription object.
	 */
	public function force_update_payment_method( $subscription ) {
		try {
			remove_filter( 'woocommerce_subscription_get_payment_method', array( Stripe::get_instance(), 'change_payment_method' ), 99 );

			$subscription->set_payment_method( $this->id );
			$subscription->save();

			remove_filter( 'woocommerce_subscription_get_payment_method', array( Stripe::get_instance(), 'change_payment_method' ), 99 );

			return $subscription;
		} catch ( \Exception $e ) {

			return $subscription;
		}
	}


	/**
	 * @param int    $wordpress_user_id
	 * @param string $token
	 * @param string $type 'id' Or 'token'
	 *
	 * @return void
	 */
	public function update_subscriptions_payment_method( $wordpress_user_id, $token, $type = 'id' ) {
		// Maybe mark the token ID as default
		$default_token = false;
		if ( $type === 'id' ) {
			$default_token = \WC_Payment_Tokens::get( $token );
		} else {
			global $wpdb;
			$token_exists = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_payment_tokens where token =%s", $token ), ARRAY_A );

			if ( ! empty( $token_exists ) ) {
				$default_token = \WC_Payment_Tokens::get( $token_exists[0]['token_id'] );
			}
		}

		if ( ! $default_token ) {
			Helper::log( 'Default token not found for user ID: ' . $wordpress_user_id );

			return;
		}

		\WC_Payment_Tokens::set_users_default( $wordpress_user_id, intval( $default_token->get_id() ) );
		Helper::log( 'Set default token for user ID: ' . $wordpress_user_id . ' to token ID: ' . $default_token->get_id() );

		if ( class_exists( '\WCS_Payment_Tokens' ) ) {
			$tokens = \WCS_Payment_Tokens::get_customer_tokens( $wordpress_user_id, $this->id );
			unset( $tokens[ $default_token->get_id() ] );

			foreach ( $tokens as $old_token ) {
				foreach ( \WCS_Payment_Tokens::get_subscriptions_from_token( $old_token ) as $subscription ) {
					if ( ! empty( $subscription ) && \WCS_Payment_Tokens::update_subscription_token( $subscription, $default_token, $old_token ) ) {
						// translators: 1: previous token, 2: new token.
						$subscription->add_order_note( sprintf( _x( 'Payment method meta updated after customer changed their default token and opted to update their subscriptions. Payment meta changed from %1$s to %2$s', 'used in subscription note', 'funnelkit-stripe-woo-payment-gateway' ), $old_token->get_token(), $default_token->get_token() ) );
						Helper::log( 'Updated subscription ID: ' . $subscription->get_id() . ' with new token ID: ' . $default_token->get_id() );
					}
				}
			}
		}
	}

	/**
	 * Keeping the filters attached for the @hook woocommerce_scheduled_subscription_payment
	 * For all the unforeseen edge cases
	 *
	 * @return void
	 */
	public function attach_hooks_to_update_payment_method() {
		add_filter( 'woocommerce_order_get_payment_method', array( Stripe::get_instance(), 'change_payment_method' ), 99, 2 );
		add_filter( 'woocommerce_subscription_get_payment_method', array( Stripe::get_instance(), 'change_payment_method' ), 99, 2 );
	}

	/**
	 * Try alternative payment methods when current payment method fails.
	 *
	 * @param \WC_Order $renewal_order The renewal order.
	 * @param object    $prepared_source The prepared source object.
	 * @param object    $error The error object from the failed payment attempt.
	 *
	 * @return string|false The payment method ID to try next, or false if no alternatives available.
	 */
	private function try_alternative_payment_method( $renewal_order, $prepared_source, $error ) {
		if ( empty( $prepared_source->customer ) ) {
			return false;
		}

		try {
			// Get list of payment methods already tried for this order
			$tried_payment_methods = Helper::get_meta( $renewal_order, '_fkwcs_tried_payment_methods' );
			if ( empty( $tried_payment_methods ) || ! is_array( $tried_payment_methods ) ) {
				$tried_payment_methods = array();
			}

			// Add current payment method to tried list
			// Use a special marker for empty source (default customer source)
			$current_payment_method = ! empty( $prepared_source->source ) ? $prepared_source->source : '__default__';
			if ( ! in_array( $current_payment_method, $tried_payment_methods, true ) ) {
				$tried_payment_methods[] = $current_payment_method;
			}

			// Maximum 4 total attempts: 1 original + 3 alternatives
			// If we've already tried 4 methods (including the original), stop
			if ( count( $tried_payment_methods ) >= 4 ) {
				Helper::log( "Info: Maximum payment method retry limit reached (4 attempts) for order {$renewal_order->get_id()}" );
				$renewal_order->update_meta_data( '_fkwcs_tried_payment_methods', $tried_payment_methods );
				$renewal_order->save_meta_data();
				return false;
			}

			// Get payment method type (default to 'card')
			$payment_type = isset( $this->payment_method_types ) ? $this->payment_method_types : 'card';

			// Fetch all payment methods for the customer
			$all_payment_methods = $this->get_payment_methods( $prepared_source->customer, $payment_type );

			if ( empty( $all_payment_methods ) || ! is_array( $all_payment_methods ) ) {
				// No payment methods available, mark current as tried and return false
				$renewal_order->update_meta_data( '_fkwcs_tried_payment_methods', $tried_payment_methods );
				$renewal_order->save_meta_data();
				return false;
			}

			// Find the next untried payment method (limit to 3 alternatives after the original)
			$next_payment_method = false;
			$max_alternatives    = 3; // Maximum 3 alternative payment methods

			foreach ( $all_payment_methods as $payment_method ) {
				$payment_method_id = isset( $payment_method->id ) ? $payment_method->id : '';
				if ( ! empty( $payment_method_id ) && ! in_array( $payment_method_id, $tried_payment_methods, true ) ) {
					$next_payment_method = $payment_method_id;
					// Stop after finding first untried method
					break;
				}
			}

			// If no specific payment method found and default hasn't been tried, try default
			if ( false === $next_payment_method && ! in_array( '__default__', $tried_payment_methods, true ) ) {
				$next_payment_method = '';
			}

			// Check if we've exceeded the maximum alternative attempts (3)
			// Count only alternatives (excluding the original method)
			// After adding current method above, count alternatives = total - 1
			$alternative_attempts = count( $tried_payment_methods ) - 1; // Subtract 1 for the original method
			if ( $alternative_attempts >= $max_alternatives ) {
				Helper::log( "Info: Maximum alternative payment method retry limit reached (3 alternatives) for order {$renewal_order->get_id()}" );
				$renewal_order->update_meta_data( '_fkwcs_tried_payment_methods', $tried_payment_methods );
				$renewal_order->save_meta_data();
				return false;
			}

			// If we found a next payment method, mark it as tried and return it
			if ( false !== $next_payment_method ) {
				$marker                  = '' === $next_payment_method ? '__default__' : $next_payment_method;
				$tried_payment_methods[] = $marker;
				$renewal_order->update_meta_data( '_fkwcs_tried_payment_methods', $tried_payment_methods );
				$renewal_order->save_meta_data();
				$log_method = '' === $next_payment_method ? 'default customer source' : $next_payment_method;
				Helper::log( "Info: Found alternative payment method {$log_method} for order {$renewal_order->get_id()}. Total tried: " . count( $tried_payment_methods ) . ' (max 4: 1 original + 3 alternatives)' );
				return $next_payment_method;
			}

			// All payment methods have been tried
			Helper::log( "Info: All payment methods exhausted for order {$renewal_order->get_id()}. Total tried: " . count( $tried_payment_methods ) );
			return false;
		} catch ( \Exception $e ) {
			// Log the error but don't break the payment flow
			Helper::log( "Error: Failed to fetch alternative payment methods for order {$renewal_order->get_id()}. Error: " . $e->getMessage(), 'warning' );
			// Return false to allow parent method to continue with normal error handling
			return false;
		}
	}

	/**
	 * Update subscription payment method when payment succeeds with an alternative payment method.
	 *
	 * @param \WC_Order $renewal_order The renewal order.
	 * @param string    $payment_method_id The payment method ID that succeeded.
	 *
	 * @return void
	 */
	private function update_subscription_payment_method_on_success( $renewal_order, $payment_method_id ) {
		if ( empty( $payment_method_id ) ) {
			return;
		}

		// Save the successful payment method ID to renewal order meta
		// This ensures the renewal order reflects the actual payment method used
		$renewal_order->update_meta_data( '_fkwcs_source_id', $payment_method_id );
		$renewal_order->save_meta_data();
		Helper::log( "Info: Saved payment method {$payment_method_id} to renewal order {$renewal_order->get_id()} meta" );

		$subscriptions = wcs_get_subscriptions_for_renewal_order( $renewal_order->get_id() );
		if ( empty( $subscriptions ) ) {
			return;
		}

		// Get the original payment method from subscription meta
		foreach ( $subscriptions as $subscription ) {
			$original_source_id = Helper::get_meta( $subscription, '_fkwcs_source_id' );
			// Only update if the payment method used is different from what's stored
			if ( $original_source_id !== $payment_method_id ) {
				$subscription->update_meta_data( '_fkwcs_source_id', $payment_method_id );
				$subscription->save();
				Helper::log( "Info: Updated subscription {$subscription->get_id()} payment method from {$original_source_id} to {$payment_method_id}" );
			}

			// Always keep the mandate in step with the renewal order's captured mandate.
			$this->sync_subscription_mandate_from_order( $subscription, $renewal_order );
		}
	}

	/**
	 * Retrieve payment method object from Stripe API.
	 *
	 * @param string $payment_method_id The payment method ID to retrieve.
	 *
	 * @return object|false The payment method object on success, false on failure.
	 */
	private function retrieve_payment_method_object( $payment_method_id ) {
		if ( empty( $payment_method_id ) ) {
			return false;
		}

		$client = $this->get_client();
		if ( is_null( $client ) ) {
			Helper::log( __FUNCTION__ . ': Stripe Client not setup', 'warning' );
			return false;
		}

		$response = $client->payment_methods( 'retrieve', array( $payment_method_id ) );
		return $this->handle_client_response( $response, false );
	}

	/**
	 * Resolve the India e-mandate id that belongs to a given PaymentMethod.
	 *
	 * The mandate is recovered from the change-payment-method SetupIntent whose id
	 * is stored on the subscription at creation time (see Ajax::create_intent()). A
	 * Stripe PaymentMethod object carries no mandate, so the SetupIntent is the only
	 * object that exposes it. Guarded and silent-failing.
	 *
	 * @since 1.14.1
	 *
	 * @param \WC_Order|\WC_Subscription $subscription The subscription holding `_fkwcs_setup_intent`.
	 * @param string                     $source_id    The current PaymentMethod id to match.
	 *
	 * @return string|false The mandate id bound to the source, or false when not resolvable.
	 */
	private function get_mandate_id_for_source( $subscription, $source_id ) {
		try {
			if ( ! is_a( $subscription, 'WC_Order' ) || empty( $source_id ) ) {
				return false;
			}

			$intent_id = $subscription->get_meta( '_fkwcs_setup_intent', true );
			// The change-PM flow stores a bare id, the $0-checkout flow stores an array.
			if ( is_array( $intent_id ) ) {
				$intent_id = isset( $intent_id['id'] ) ? $intent_id['id'] : '';
			}
			if ( empty( $intent_id ) ) {
				return false;
			}

			$client = $this->get_client();
			if ( is_null( $client ) ) {
				return false;
			}

			$response = $client->setup_intents( 'retrieve', array( $intent_id ) );
			if ( empty( $response['success'] ) ) {
				return false;
			}

			$intent = $response['data'];
			// Only trust the mandate when the SetupIntent is confirmed against this card.
			if ( isset( $intent->object ) && 'setup_intent' === $intent->object && isset( $intent->payment_method ) && $intent->payment_method === $source_id ) {
				return ( isset( $intent->mandate ) && ! empty( $intent->mandate ) ) ? $intent->mandate : false;
			}

			return false;
		} catch ( \Throwable $e ) {
			Helper::log( 'get_mandate_id_for_source failed: ' . $e->getMessage(), 'warning' );

			return false;
		}
	}

	/**
	 * Check whether a Stripe Mandate is bound to the given PaymentMethod.
	 *
	 * Used on the renewal hot path to ensure the mandate attached to the renewal
	 * PaymentIntent belongs to the card actually being charged. Definitive verdicts
	 * are cached per request; an API/client failure is indeterminate (null) so the
	 * caller can tell "confirmed mismatch" apart from "could not verify" - a
	 * transient Stripe failure must never be treated as a confirmed non-match.
	 *
	 * @since 1.14.1
	 *
	 * @param string $mandate_id The Stripe mandate id to inspect.
	 * @param string $source_id  The PaymentMethod id the mandate must belong to.
	 *
	 * @return bool|null True when the mandate's payment_method matches the source,
	 *                   false on a confirmed mismatch, null when the lookup failed.
	 */
	private function mandate_belongs_to_source( $mandate_id, $source_id ) {
		static $cache = array();

		if ( empty( $mandate_id ) || empty( $source_id ) ) {
			return false;
		}

		$cache_key = $mandate_id . '|' . $source_id;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$result = null;
		try {
			$client = $this->get_client();
			if ( ! is_null( $client ) ) {
				$response = $client->mandates( 'retrieve', array( $mandate_id ) );
				if ( ! empty( $response['success'] ) ) {
					$mandate = $response['data'];
					$result  = ( isset( $mandate->payment_method ) && $mandate->payment_method === $source_id );
				} else {
					Helper::log( 'mandate_belongs_to_source: lookup of ' . $mandate_id . ' failed, verdict indeterminate.', 'warning' );
				}
			}
		} catch ( \Throwable $e ) {
			Helper::log( 'mandate_belongs_to_source failed: ' . $e->getMessage(), 'warning' );
			$result = null;
		}

		// Cache only definitive verdicts so a transient failure can be retried.
		if ( null !== $result ) {
			$cache[ $cache_key ] = $result;
		}

		return $result;
	}

	/**
	 * Keep a subscription's India e-mandate consistent with its current source.
	 *
	 * Clears any stale `_stripe_mandate_id` first (so a capture failure can never
	 * leave a mandate bound to a different card) then, when the new card's mandate
	 * can be recovered, persists it on the subscription where the renewal reads it.
	 * Card/India context only; guarded and silent-failing.
	 *
	 * @since 1.14.1
	 *
	 * @param \WC_Order|\WC_Subscription $subscription The subscription to update.
	 * @param string                     $source_id    The current PaymentMethod id.
	 *
	 * @return void
	 */
	private function sync_subscription_mandate( $subscription, $source_id ) {
		try {
			if ( ! is_a( $subscription, 'WC_Order' ) || 'card' !== $this->payment_method_types ) {
				return;
			}

			// Clear the stale mandate first, mirroring the delete_renewal_meta() idiom.
			$subscription->delete_meta_data( '_stripe_mandate_id' );

			$mandate_id = $this->get_mandate_id_for_source( $subscription, $source_id );
			if ( isset( $mandate_id ) && ! empty( $mandate_id ) ) {
				$subscription->update_meta_data( '_stripe_mandate_id', $mandate_id );
				Helper::log( 'Stored India mandate ' . $mandate_id . ' on subscription ' . $subscription->get_id() . ' for source ' . $source_id );
			}

			$subscription->save_meta_data();
		} catch ( \Throwable $e ) {
			Helper::log( 'sync_subscription_mandate failed: ' . $e->getMessage(), 'warning' );
		}
	}

	/**
	 * Copy the India e-mandate captured on a source order onto the subscription.
	 *
	 * Unlike sync_subscription_mandate() (used on the change-PM flow, where the
	 * mandate only exists on the SetupIntent), the renewal / checkout paths already
	 * have the fresh mandate persisted on the order that just paid - so read it from
	 * meta instead of calling Stripe. Card/India context only; guarded + silent.
	 *
	 * @since 1.14.1
	 *
	 * @param \WC_Order|\WC_Subscription $subscription The subscription to update.
	 * @param \WC_Order                  $source_order The order carrying `_stripe_mandate_id`.
	 *
	 * @return void
	 */
	private function sync_subscription_mandate_from_order( $subscription, $source_order ) {
		try {
			if ( ! is_a( $subscription, 'WC_Order' ) || ! is_a( $source_order, 'WC_Order' ) || 'card' !== $this->payment_method_types ) {
				return;
			}

			$mandate_id = $source_order->get_meta( '_stripe_mandate_id', true );
			if ( isset( $mandate_id ) && ! empty( $mandate_id ) ) {
				$subscription->update_meta_data( '_stripe_mandate_id', $mandate_id );
				$subscription->save_meta_data();
				Helper::log( 'Synced mandate ' . $mandate_id . ' from order ' . $source_order->get_id() . ' to subscription ' . $subscription->get_id() );
			}
		} catch ( \Throwable $e ) {
			Helper::log( 'sync_subscription_mandate_from_order failed: ' . $e->getMessage(), 'warning' );
		}
	}
}
