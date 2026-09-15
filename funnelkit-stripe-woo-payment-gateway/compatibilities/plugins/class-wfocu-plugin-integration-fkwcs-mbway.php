<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FKWCS\Gateway\Stripe\Helper;

if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Mbway' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_Mbway extends FKWCS_LocalGateway_Upsell {
		protected static $instance           = null;
		public $key                          = 'fkwcs_stripe_mbway';
		protected $payment_method_type       = 'mb_way';
		protected $stripe_verify_js_callback = 'confirmMbWayPayment';

		public function __construct() {
			parent::__construct();
			add_action( 'wfocu_footer_before_print_scripts', array( $this, 'maybe_render_in_offer_transaction_scripts' ), 999 );
		}

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		public function process_client_payment() {
			check_ajax_referer( 'wfocu_front_charge', 'nonce' );

			$get_current_offer      = WFOCU_Core()->data->get( 'current_offer' );
			$get_current_offer_meta = WFOCU_Core()->offers->get_offer_meta( $get_current_offer );
			WFOCU_Core()->data->set( '_offer_result', true );
			$posted_data = WFOCU_Core()->process_offer->parse_posted_data( $_POST ); //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing

			/**
			 * return if found error in the charge request
			 */
			if ( false === WFOCU_AJAX_Controller::validate_charge_request( $posted_data ) ) {
				wp_send_json(
					array(
						'result' => 'error',
					)
				);
			}

			/**
			 * Setup the upsell to initiate the charge process
			 */
			WFOCU_Core()->process_offer->execute( $get_current_offer_meta );

			$offer_package = WFOCU_Core()->data->get( '_upsell_package' );
			WFOCU_Core()->data->set( 'upsell_package', $offer_package, 'gateway' );
			WFOCU_Core()->data->save( 'gateway' );

			$order   = WFOCU_Core()->data->get_parent_order();
			$gateway = $this->get_wc_gateway();
			$gateway->validate_minimum_order_amount( $order );
			$customer_id     = $gateway->get_customer_id( $order );
			$idempotency_key = $order->get_order_key() . time();

			// Get saved phone number from primary order (required for MB WAY)
			// Try multiple sources: order billing_phone, saved meta, or payment intent
			$saved_phone_number = $order->get_billing_phone();

			// If not in billing_phone, check saved meta from primary payment
			if ( empty( $saved_phone_number ) ) {
				$saved_phone_number = $order->get_meta( '_fkwcs_mbway_phone' );
			}

			// If still not found, try to get from payment intent/charge
			if ( empty( $saved_phone_number ) ) {
				$intent_meta = Helper::get_meta( $order, '_fkwcs_intent_id' );
				if ( ! empty( $intent_meta ) && isset( $intent_meta['id'] ) ) {
					try {
						$stripe_api = $gateway->get_client();
						$response   = $stripe_api->payment_intents( 'retrieve', array( $intent_meta['id'] ) );
						$intent     = $gateway->handle_client_response( $response );

						if ( $intent && isset( $intent->charges->data[0]->billing_details->phone ) && ! empty( $intent->charges->data[0]->billing_details->phone ) ) {
							$saved_phone_number = $intent->charges->data[0]->billing_details->phone;
							// Save it for future use
							$order->update_meta_data( '_fkwcs_mbway_phone', $saved_phone_number );
							$order->save();
							Helper::log( 'Retrieved and saved MB WAY phone number from payment intent for order ' . $order->get_id() . ' - ' . $saved_phone_number );
						}
					} catch ( \Exception $e ) {
						Helper::log( 'Error retrieving phone number from payment intent: ' . $e->getMessage() );
					}
				}
			}

			if ( empty( $saved_phone_number ) ) {
				Helper::log( 'ERROR: No billing phone number found for MB WAY upsell on order ' . $order->get_id() );
				wp_send_json(
					array(
						'result'  => 'error',
						'message' => __( 'Phone number not found. Please contact support.', 'funnelkit-stripe-woo-payment-gateway' ),
					)
				);

				return;
			}

			$data = array(
				'amount'               => Helper::get_formatted_amount( $offer_package['total'] ),
				'currency'             => $gateway->get_currency(),
				// translators: 1: site name, 2: order number, 3: offer ID.
				'description'          => sprintf( __( '%1$s - Order %2$s - 1 click upsell: %3$s', 'funnelkit-stripe-woo-payment-gateway' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number(), WFOCU_Core()->data->get( 'current_offer' ) ),
				'payment_method_types' => array( $this->payment_method_type ),
				'customer'             => $customer_id,
				'capture_method'       => $gateway->capture_method,
			);

			if ( $order->has_shipping_address() ) {
				$data['shipping'] = array(
					'name'    => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
					'address' => array(
						'line1'       => $order->get_shipping_address_1(),
						'city'        => $order->get_shipping_city(),
						'postal_code' => $order->get_shipping_postcode(),
						'state'       => $order->get_shipping_state(),
						'country'     => $order->get_shipping_country(),
					),
				);
			}

			$stripe_api  = $gateway->get_client();
			$args        = apply_filters( 'fkwcs_payment_intent_data', $data, $order );
			$args        = array(
				array( $args ),
				array( 'idempotency_key' => $idempotency_key ),
			);
			$response    = $stripe_api->payment_intents( 'create', $args );
			$intent_data = $gateway->handle_client_response( $response );

			// translators: 1: payment method title, 2: order ID, 3: order total.
			Helper::log( sprintf( __( 'Begin processing payment with %1$s for order %2$s for the amount of %3$s', 'funnelkit-stripe-woo-payment-gateway' ), $order->get_payment_method_title(), $order->get_id(), $order->get_total() ) );

			if ( $intent_data ) {
				$output                             = array(
					'order'     => $order->get_id(),
					'order_key' => $order->get_order_key(),
					'gateway'   => $this->key,
				);
				$upsell_charge_data                 = array();
				$verification_url                   = add_query_arg( $output, WC_AJAX::get_endpoint( 'wfocu_front_handle_fkwcs_upsell_verify_intent' ) );
				$verification_url                   = WFOCU_Core()->public->maybe_add_wfocu_session_param( $verification_url );
				$upsell_charge_data['redirect_url'] = $verification_url;
				$order->update_meta_data( '_fkwcs_localgateway_upsell_payment_intent', $intent_data['id'] );
				$order->save();

				$response = array(
					'result'        => 'success',
					'intent_secret' => $intent_data->client_secret,
					'response'      => $upsell_charge_data,
				);

				// MB WAY requires phone number in billing_details
				if ( $this->payment_method_type === 'mb_way' ) {
					if ( ! empty( $saved_phone_number ) ) {
						// MB WAY with saved phone number - direct payment
						$response['mbway_payment_method'] = array(
							'type'            => 'mb_way',
							'billing_details' => array(
								'name'    => trim( $order->get_formatted_billing_full_name() ),
								'email'   => $order->get_billing_email(),
								'phone'   => $saved_phone_number,
								'address' => array(
									'line1'       => $order->get_billing_address_1(),
									'state'       => $order->get_billing_state(),
									'country'     => $order->get_billing_country(),
									'city'        => $order->get_billing_city(),
									'postal_code' => $order->get_billing_postcode(),
								),
							),
						);
						Helper::log( 'Using saved phone number for MB WAY upsell: ' . $saved_phone_number . ' for order ' . $order->get_id() );
					} else {
						// This shouldn't happen, but fallback to error
						Helper::log( 'ERROR: No saved phone number found for MB WAY upsell on order ' . $order->get_id() );
						wp_send_json(
							array(
								'result'  => 'error',
								'message' => __( 'Phone number not found. Please contact support.', 'funnelkit-stripe-woo-payment-gateway' ),
							)
						);

						return;
					}
				}

				// Add payment method type to help frontend identify payment type
				$response['payment_method_type'] = $this->payment_method_type;

				wp_send_json( $response );
			} else {
				wp_send_json(
					array(
						'result'        => 'fail',
						'intent_secret' => '',
						'response'      => WFOCU_Core()->process_offer->_handle_upsell_charge( false ),
					)
				);
			}
		}

		public function maybe_render_in_offer_transaction_scripts() {
			$order = WFOCU_Core()->data->get_current_order();

			if ( ! $order instanceof WC_Order ) {
				return;
			}

			if ( $this->get_key() !== $order->get_payment_method() ) {
				return;
			}
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Stripe.js loaded directly; cannot use wp_enqueue_script for cross-origin PCI scripts
			?>
			<script src="https://js.stripe.com/v3/?ver=3.0" data-cookieconsent="ignore"></script>
			<?php
			// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
			?>

			<script>
				(function ($) {
					"use strict";

					function initializeStripePayment() {
						let wfocuStripe = Stripe('<?php echo esc_js( $this->get_wc_gateway()->get_client_key() ); ?>');
						let homeURL = '<?php echo esc_url( site_url() ); ?>';
						let ajax_link = '<?php echo esc_attr( $this->ajax_action() ); ?>';

						let wfocuStripeJS = {
							bucket: null,

							initCharge: function () {
								let getBucketData = this.bucket.getBucketSendData();
								let postData = $.extend(getBucketData, {action: ajax_link, 'fkwcs_gateway': '<?php echo esc_js( $this->get_key() ); ?>'});
								let action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', ajax_link), postData);

								action.done(function (data) {
									if (data.result !== "success") {
										wfocuStripeJS.bucket.swal.show({'text': wfocu_vars.messages.offer_msg_pop_failure, 'type': 'warning'});
										if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
											setTimeout(() => window.location = data.response.redirect_url, 1500);
										} else {
											if (typeof wfocu_vars.order_received_url !== 'undefined') {
												window.location = wfocu_vars.order_received_url + '&ec=fkwcs_stripe_error';
											}
										}
									} else {
										if (typeof data.intent_secret !== "undefined" && '' !== data.intent_secret) {

											if (data.mbway_payment_method && data.payment_method_type === 'mb_way') {
												wfocuStripe.confirmMbWayPayment(data.intent_secret, {
													payment_method: data.mbway_payment_method,
													return_url: homeURL + data.response.redirect_url,
												}).then((result) => {
													if (result.paymentIntent.status === 'requires_source' ||
														result.paymentIntent.status === 'requires_payment_method' ||
														result.paymentIntent.status === 'canceled' ||
														result.paymentIntent.last_payment_error) {

														wfocuStripeJS.bucket.swal.show({'text': wfocu_vars.messages.offer_msg_pop_failure, 'type': 'warning'});

														setTimeout(() => {
															if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
																window.location = data.response.redirect_url;
															} else if (typeof wfocu_vars.order_received_url !== 'undefined') {
																window.location = wfocu_vars.order_received_url + '&ec=mbway_failed';
															}
														}, 2000);
														return;
													}

													if (result.paymentIntent.status === 'succeeded' || result.paymentIntent.status === 'processing') {
														wfocuStripeJS.bucket.swal.show({
															'text': wfocu_vars.messages.offer_success_message_pop,
															'type': 'success'
														});

														setTimeout(() => {
															if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
																window.location = data.response.redirect_url;
															} else if (typeof wfocu_vars.order_received_url !== 'undefined') {
																window.location = wfocu_vars.order_received_url;
															}
														}, 1500);
														return;
													}

													if (result.paymentIntent.status === 'requires_action') {
														wfocuStripeJS.bucket.swal.show({'text': wfocu_vars.messages.offer_msg_pop_failure, 'type': 'warning'});

														setTimeout(() => {
															if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
																window.location = data.response.redirect_url;
															} else if (typeof wfocu_vars.order_received_url !== 'undefined') {
																window.location = wfocu_vars.order_received_url + '&ec=mbway_pending';
															}
														}, 2000);
													}

												}).catch((error) => {
													console.log('MB WAY payment exception:', error);
													wfocuStripeJS.bucket.swal.show({'text': wfocu_vars.messages.offer_msg_pop_failure, 'type': 'warning'});
													setTimeout(() => window.location = data.response.redirect_url, 2000);
												});
											}

										}

									}
								});

								action.fail(function (data) {
									console.log('AJAX request failed:', JSON.stringify(data));
									wfocuStripeJS.bucket.swal.show({'text': wfocu_vars.messages.offer_msg_pop_failure, 'type': 'warning'});
									if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
										setTimeout(() => window.location = data.response.redirect_url, 1500);
									} else {
										if (typeof wfocu_vars.order_received_url !== 'undefined') {
											window.location = wfocu_vars.order_received_url + '&ec=stripe_error';
										}
									}
								});
							}
						};

						$(document).off('wfocuBucketCreated.mbwayStripe');
						$(document).off('wfocu_external.mbwayStripe');
						$(document).off('wfocuBucketConfirmationRendered.mbwayStripe');
						$(document).off('wfocuBucketLinksConverted.mbwayStripe');
						// Event handlers
						$(document).on('wfocuBucketCreated.mbwayStripe', function (e, Bucket) {
							wfocuStripeJS.bucket = Bucket;
						});
						$(document).on('wfocu_external.mbwayStripe', function (e, Bucket) {
							if (0 !== Bucket.getTotal()) {
								wfocuStripeJS.bucket = Bucket;
								Bucket.inOfferTransaction = true;
								wfocuStripeJS.initCharge();
							}
						});
						$(document).on('wfocuBucketConfirmationRendered.mbwayStripe', function (e, Bucket) {
							wfocuStripeJS.bucket = Bucket;
						});
						$(document).on('wfocuBucketLinksConverted.mbwayStripe', function (e, Bucket) {
							wfocuStripeJS.bucket = Bucket;
						});
						// Store globally
						window.wfocuStripeJS = wfocuStripeJS;
					}

					$(document).ready(function () {
						if (typeof Stripe !== 'undefined') {
							initializeStripePayment();
							window.fkwcsMbwayStripeInitialized = true;
						} else {
							console.log('Stripe not ready on DOM ready, waiting for window load...');
						}
					});

					$(window).on('load', function () {
						if (!window.fkwcsMbwayStripeInitialized) {
							if (typeof Stripe !== 'undefined') {
								initializeStripePayment();
								window.fkwcsMbwayStripeInitialized = true;
							} else {
								console.error('Stripe library failed to load');
							}
						}
					});
				})(jQuery);
			</script>
			<?php
		}
	}

	WFOCU_Plugin_Integration_Fkwcs_Mbway::get_instance();
}

