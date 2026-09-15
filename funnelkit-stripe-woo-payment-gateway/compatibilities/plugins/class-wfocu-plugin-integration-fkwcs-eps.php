<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FKWCS\Gateway\Stripe\Helper;

if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Eps' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_Eps extends FKWCS_LocalGateway_Upsell {
		protected static $instance           = null;
		public $key                          = 'fkwcs_stripe_eps';
		protected $payment_method_type       = 'eps';
		protected $stripe_verify_js_callback = 'confirmEpsPayment';
		public $current_order_id             = null;

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

		/**
		 * Process client-side payment for EPS upsells
		 * Creates payment intent with saved bank selection
		 *
		 * @return void Outputs JSON response and exits
		 */
		public function process_client_payment() {
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
						'result'  => 'error',
						'message' => __( 'Invalid charge request', 'funnelkit-stripe-woo-payment-gateway' ),
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

			// Get saved EPS bank selection from primary order
			$saved_bank = $order->get_meta( '_fkwcs_eps_bank_selection' );

			$data = array(
				'amount'               => Helper::get_formatted_amount( $offer_package['total'] ),
				'currency'             => $gateway->get_currency(),
				/* translators: %1$s: Site name, %2$s: Order number, %3$s: Offer name */
				'description'          => sprintf( __( '%1$s - Order %2$s - 1 click upsell: %3$s', 'funnelkit-stripe-woo-payment-gateway' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number(), WFOCU_Core()->data->get( 'current_offer' ) ),
				'payment_method_types' => array( $this->payment_method_type ),
				'customer'             => $customer_id,
				'capture_method'       => $gateway->capture_method,
			);

			// Note: Bank selection is NOT passed in payment_method_options when creating intent
			// It will be passed in payment_method.eps when confirming with confirmEpsPayment()
			if ( ! empty( $saved_bank ) ) {
				Helper::log( sprintf( '[EPS Upsell] Saved bank will be used in confirmation: %s for order %s', $saved_bank, $order->get_id() ) );
			}

			// Add shipping if available (similar to PIX)
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

			/* translators: %1$s: Order ID, %2$s: Amount */
			Helper::log( sprintf( __( 'Begin processing EPS upsell payment for order %1$s for the amount of %2$s', 'funnelkit-stripe-woo-payment-gateway' ), $order->get_id(), $offer_package['total'] ) );

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
				$order->update_meta_data( '_fkwcs_localgateway_upsell_payment_intent', $intent_data->id );
				$order->save();

				$payment_Details = array(
					'billing_details' => array(
						'name'    => trim( $order->get_formatted_billing_full_name() ),
						'email'   => $order->get_billing_email(),
						'address' => array(
							'line1'       => $order->get_billing_address_1(),
							'state'       => $order->get_billing_state(),
							'country'     => $order->get_billing_country(),
							'city'        => $order->get_billing_city(),
							'postal_code' => $order->get_billing_postcode(),
						),
					),
				);

				// Add bank to payment_method.eps (not payment_method_options)
				// This is how Stripe expects it for confirmEpsPayment()
				if ( ! empty( $saved_bank ) ) {
					$payment_Details['eps'] = $saved_bank;
				}

				$response = array(
					'result'              => 'success',
					'intent_secret'       => $intent_data->client_secret,
					'response'            => $upsell_charge_data,
					'payment_method'      => $payment_Details,
					'payment_method_type' => $this->payment_method_type,
					'bank'                => $saved_bank, // Pass bank for frontend reference
				);

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

		/**
		 * Render JavaScript for EPS upsell payment
		 * Handles Payment Element with pre-selected bank and redirect flow
		 */
		public function maybe_render_in_offer_transaction_scripts() {
			$order = WFOCU_Core()->data->get_current_order();

			if ( ! $order instanceof WC_Order ) {
				return;
			}

			if ( $this->get_key() !== $order->get_payment_method() ) {
				return;
			}
			?>
			<script src="https://js.stripe.com/v3/?ver=3.0" data-cookieconsent="ignore"></script> <?php //phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- External Stripe script loaded directly ?>

			<script>
				(function ($) {
					"use strict";

					function initializeStripePayment() {
						let wfocuStripe = Stripe('<?php echo esc_js( $this->get_wc_gateway()->get_client_key() ); ?>');
						let homeURL = '<?php echo esc_url( site_url() ); ?>';
						let ajax_link = '<?php echo esc_attr( $this->ajax_action() ); ?>';
						let savedBank = '<?php echo esc_js( $order->get_meta( '_fkwcs_eps_bank_selection' ) ); ?>';

						let wfocuStripeJS = {
							bucket: null,

							initCharge: function () {
								let getBucketData = this.bucket.getBucketSendData();
								let postData = $.extend(getBucketData, {action: ajax_link, 'fkwcs_gateway': '<?php echo esc_js( $this->get_key() ); ?>'});
								let action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', ajax_link), postData);

								action.done(function (data) {
									if (data.result !== "success") {
										wfocuStripeJS.bucket.swal.show({'text': data.message || wfocu_vars.messages.offer_msg_pop_failure, 'type': 'warning'});
										if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
											setTimeout(() => window.location = data.response.redirect_url, 1500);
										} else {
											if (typeof wfocu_vars.order_received_url !== 'undefined') {
												window.location = wfocu_vars.order_received_url + '&ec=fkwcs_stripe_error';
											}
										}
									} else {
										if (typeof data.intent_secret !== "undefined" && '' !== data.intent_secret) {
											// EPS requires confirmEpsPayment with bank in payment_method.eps as an object
											// Bank is passed in payment_method.eps.bank (as per Stripe docs)
											let bankCode = data.bank || savedBank || '';
											
											if (!bankCode) {
												wfocuStripeJS.bucket.swal.show({
													'text': 'Bank selection is required for EPS payment',
													'type': 'warning'
												});
												setTimeout(() => {
													if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
														window.location = data.response.redirect_url;
													} else if (typeof wfocu_vars.order_received_url !== 'undefined') {
														window.location = wfocu_vars.order_received_url + '&ec=eps_failed';
													}
												}, 2000);
												return;
											}

											let confirmOptions = {
												payment_method: {
													eps: {
														bank: bankCode
													},
													billing_details: data.payment_method ? data.payment_method.billing_details : {
														name: '<?php echo esc_js( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ); ?>',
														email: '<?php echo esc_js( $order->get_billing_email() ); ?>',
														address: {
															line1: '<?php echo esc_js( $order->get_billing_address_1() ); ?>',
															city: '<?php echo esc_js( $order->get_billing_city() ); ?>',
															postal_code: '<?php echo esc_js( $order->get_billing_postcode() ); ?>',
															state: '<?php echo esc_js( $order->get_billing_state() ); ?>',
															country: '<?php echo esc_js( $order->get_billing_country() ); ?>'
														}
													}
												},
												return_url: homeURL + data.response.redirect_url
											};

											wfocuStripe.confirmEpsPayment(data.intent_secret, confirmOptions).then((result) => {
												if (result.error) {
													wfocuStripeJS.bucket.swal.show({
														'text': result.error.message || wfocu_vars.messages.offer_msg_pop_failure,
														'type': 'warning'
													});
													setTimeout(() => {
														if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
															window.location = data.response.redirect_url;
														} else if (typeof wfocu_vars.order_received_url !== 'undefined') {
															window.location = wfocu_vars.order_received_url + '&ec=eps_failed';
														}
													}, 2000);
													return;
												}

												// Handle redirect to bank or success
												if (result.paymentIntent && 
													result.paymentIntent.next_action &&
													result.paymentIntent.next_action.type === 'redirect_to_url' &&
													result.paymentIntent.next_action.redirect_to_url &&
													result.paymentIntent.next_action.redirect_to_url.url) {
													// Redirect to bank for authentication
													window.location.href = result.paymentIntent.next_action.redirect_to_url.url;
												} else if (result.paymentIntent && (result.paymentIntent.status === 'succeeded' || result.paymentIntent.status === 'processing')) {
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
												} else if (result.paymentIntent && result.paymentIntent.status === 'requires_action') {
													// Payment requires action but no redirect URL
													wfocuStripeJS.bucket.swal.show({
														'text': wfocu_vars.messages.offer_msg_pop_failure,
														'type': 'warning'
													});
													setTimeout(() => {
														if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
															window.location = data.response.redirect_url;
														} else if (typeof wfocu_vars.order_received_url !== 'undefined') {
															window.location = wfocu_vars.order_received_url + '&ec=eps_pending';
														}
													}, 2000);
												}
											}).catch((error) => {
												wfocuStripeJS.bucket.swal.show({
													'text': error.message || wfocu_vars.messages.offer_msg_pop_failure,
													'type': 'warning'
												});
												setTimeout(() => {
													if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
														window.location = data.response.redirect_url;
													} else if (typeof wfocu_vars.order_received_url !== 'undefined') {
														window.location = wfocu_vars.order_received_url + '&ec=eps_error';
													}
												}, 2000);
											});
										}
									}
								});

								action.fail(function (jqXHR, textStatus, errorThrown) {
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

						$(document).off('wfocuBucketCreated.epsStripe');
						$(document).off('wfocu_external.epsStripe');
						$(document).off('wfocuBucketConfirmationRendered.epsStripe');
						$(document).off('wfocuBucketLinksConverted.epsStripe');
						// Event handlers
						$(document).on('wfocuBucketCreated.epsStripe', function (e, Bucket) {
							wfocuStripeJS.bucket = Bucket;
						});
						$(document).on('wfocu_external.epsStripe', function (e, Bucket) {
							if (0 !== Bucket.getTotal()) {
								wfocuStripeJS.bucket = Bucket;
								Bucket.inOfferTransaction = true;
								wfocuStripeJS.initCharge();
							}
						});
						$(document).on('wfocuBucketConfirmationRendered.epsStripe', function (e, Bucket) {
							wfocuStripeJS.bucket = Bucket;
						});
						$(document).on('wfocuBucketLinksConverted.epsStripe', function (e, Bucket) {
							wfocuStripeJS.bucket = Bucket;
						});
						// Store globally
						window.wfocuStripeJS = wfocuStripeJS;
					}

					$(document).ready(function () {
						if (typeof Stripe !== 'undefined') {
							initializeStripePayment();
							window.fkwcsEpsStripeInitialized = true;
						} else {
							console.log('Stripe not ready on DOM ready, waiting for window load...');
						}
					});

					$(window).on('load', function () {
						if (!window.fkwcsEpsStripeInitialized) {
							if (typeof Stripe !== 'undefined') {
								initializeStripePayment();
								window.fkwcsEpsStripeInitialized = true;
							} else {
								console.log('Stripe library failed to load');
							}
						}
					});
				})(jQuery);
			</script>
			<?php
		}
	}

	WFOCU_Plugin_Integration_Fkwcs_Eps::get_instance();
}
