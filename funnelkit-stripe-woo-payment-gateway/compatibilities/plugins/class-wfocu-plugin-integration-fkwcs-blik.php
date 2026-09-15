<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FKWCS\Gateway\Stripe\Helper;

if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Blik' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_Blik extends FKWCS_LocalGateway_Upsell {
		protected static $instance           = null;
		public $key                          = 'fkwcs_stripe_blik';
		protected $payment_method_type       = 'blik';
		protected $stripe_verify_js_callback = 'confirmBlikPayment';
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
		 * Process client-side payment for BLIK upsells.
		 * Handles BLIK code collection and payment intent creation/confirmation.
		 *
		 * @return void Outputs JSON response and exits
		 */
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

			// Get BLIK code from POST data
			$blik_code = isset( $_POST['blik_code'] ) ? sanitize_text_field( wp_unslash( $_POST['blik_code'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing

			// Validate BLIK code
			if ( empty( $blik_code ) || strlen( $blik_code ) !== 6 || ! ctype_digit( $blik_code ) ) {
				wp_send_json(
					array(
						'result'  => 'error',
						'message' => __( 'Please enter a valid 6-digit BLIK code.', 'funnelkit-stripe-woo-payment-gateway' ),
					)
				);
			}

			Helper::log( '[BLIK Upsell] Creating payment intent for order ' . $order->get_id() . ', offer: ' . WFOCU_Core()->data->get( 'current_offer' ) . ', amount: ' . $offer_package['total'] );

			/* translators: %1$s: Site name, %2$s: Order number, %3$s: Offer name */
			$data = array(
				'amount'               => Helper::get_formatted_amount( $offer_package['total'] ),
				'currency'             => $gateway->get_currency(),
				/* translators: %1$s: Site name, %2$s: Order number, %3$s: Offer name */
				'description'          => sprintf( __( '%1$s - Order %2$s - 1 click upsell: %3$s', 'funnelkit-stripe-woo-payment-gateway' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number(), WFOCU_Core()->data->get( 'current_offer' ) ),
				'payment_method_types' => array( $this->payment_method_type ),
				'customer'             => $customer_id,
				// BLIK does not support capture_method - it's always automatic
			);

			Helper::log( '[BLIK Upsell] Payment intent data prepared: ' . wp_json_encode( $data ) );

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

			$stripe_api = $gateway->get_client();
			$args       = apply_filters( 'fkwcs_payment_intent_data', $data, $order );
			$args       = array(
				array( $args ),
				array( 'idempotency_key' => $idempotency_key ),
			);
			Helper::log( '[BLIK Upsell] Calling Stripe API to create payment intent for order ' . $order->get_id() );
			$response = $stripe_api->payment_intents( 'create', $args );
			Helper::log( '[BLIK Upsell] Stripe API response received for order ' . $order->get_id() . ': ' . ( $response['success'] ? 'success' : 'failed' ) );
			$intent_data = $gateway->handle_client_response( $response );

			if ( ! $intent_data ) {
				Helper::log( '[BLIK Upsell] ERROR: Failed to create payment intent for order ' . $order->get_id() );
				wp_send_json(
					array(
						'result'  => 'error',
						'message' => __( 'Failed to create payment intent. Please try again.', 'funnelkit-stripe-woo-payment-gateway' ),
					)
				);
			}

			if ( $intent_data ) {
				Helper::log( '[BLIK Upsell] Payment intent created successfully for order ' . $order->get_id() . ', intent ID: ' . $intent_data->id );
				/* translators: %1$s: Payment method title, %2$s: Order ID, %3$s: Amount */
				Helper::log( sprintf( __( 'Begin processing payment with %1$s for order %2$s for the amount of %3$s', 'funnelkit-stripe-woo-payment-gateway' ), $order->get_payment_method_title(), $order->get_id(), $offer_package['total'] ) );
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

				$response = array(
					'result'        => 'success',
					'intent_secret' => $intent_data->client_secret,
					'response'      => $upsell_charge_data,
					'blik_code'     => $blik_code, // Pass BLIK code to frontend for confirmation
				);

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

		/**
		 * Render JavaScript for handling BLIK upsell transactions on frontend.
		 * Includes popup for BLIK code collection before processing payment.
		 *
		 * @return void Outputs JavaScript directly to browser
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

						let wfocuStripeJS = {
							bucket: null,

							/**
							 * Show popup to collect BLIK code
							 */
							showBlikCodePopup: function() {
								let self = this;
								$('body').addClass('wfocu_credit_card_open');

								this.bucket.swal.show({
									'html': `<div class="wfocu_fkwcs_wrapper">
									<div class="wfocu_fkwcs_error_div"></div>
									<div class="wfocu_fkwcs_warning_div"></div>
									<div class="wfocu_fkwcs_blik_wrapper">
										<p style="margin-bottom: 16px; color: #353030; font-size: 14px; line-height: 20px;"><?php echo esc_js( __( 'Please enter the 6-digit BLIK code from your mobile banking app.', 'funnelkit-stripe-woo-payment-gateway' ) ); ?></p>
										<input type="text" id="fkwcs-blik-code-input" maxlength="6" pattern="[0-9]{6}" placeholder="000000" style="width: 100%; padding: 12px; font-size: 18px; text-align: center; letter-spacing: 4px; border: 2px solid #ddd; border-radius: 8px; margin-bottom: 0; font-weight: 500;" />
									</div>
									</div>`,
									'title': '<?php echo esc_js( __( 'Enter BLIK Code', 'funnelkit-stripe-woo-payment-gateway' ) ); ?>',
									'reverseButtons': false,
									'showCloseButton': true,
									confirmButtonText: '<?php echo esc_js( __( 'Continue', 'funnelkit-stripe-woo-payment-gateway' ) ); ?>',
									cancelButtonText: '<?php echo esc_js( __( 'Cancel', 'funnelkit-stripe-woo-payment-gateway' ) ); ?>',
									showCancelButton: false,
									showConfirmButton: true,
									allowOutsideClick: false,
									onOpen: function (el) {
										el.classList.add('wfocu_fkwcs_popup');
										const style = document.createElement('style');
										style.innerText = `
											.wfocu_fkwcs_popup {
												color: #353030;
												border-radius: 12px;
												padding: 24px;
											}
											.wfocu_fkwcs_popup .wfocuswal-header {
												margin-bottom: 16px;
											}
											.wfocu_fkwcs_popup .wfocuswal-title {
												margin: 0;
												color: #353030;
											}
											.wfocu_fkwcs_popup .wfocuswal-title {
												font-size: 18px;
												line-height: 20px;
												font-weight: 500;
												align-self: flex-start;
											}
											.wfocu_fkwcs_popup .wfocuswal-close {
												line-height: 24px;
												top: 24px;
												right: 24px;
												min-width: 24px;
												height: 24px;
												width: 24px;
												color: #353030;
											}
											.wfocu_fkwcs_popup .wfocuswal-actions {
												margin: 12px 0 0;
											}
											.wfocuswal-popup.wfocuswal-modal.wfocu_fkwcs_popup .wfocuswal-actions .wfocuswal-styled.wfocuswal-confirm {
												width: 100%;
												padding: 12px 16px;
												line-height: 24px;
												margin: 0;
												background: #09B29C !important;
												background-color: #09B29C !important;
											}
											.wfocuswal-popup.wfocuswal-modal.wfocu_fkwcs_popup .wfocuswal-actions .wfocuswal-styled:focus {
												box-shadow: 0 0 0 2px #fff,0 0 0 4px #09B29C !important;
											}
											.wfocu_fkwcs_popup .wfocu_fkwcs_error_div.is-visible,
											.wfocu_fkwcs_popup .wfocu_fkwcs_warning_div.is-visible {
												font-size: 12px;
												line-height: 16px;
												font-weight: 500;
												padding: 8px 12px;
												border-radius: 8px;
												text-align: left;
												margin-bottom: 16px;
											}
											.wfocu_fkwcs_popup .wfocu_fkwcs_error_div.is-visible {
												background: #FFE9E9;
												color: #E15334;
											}
											.wfocu_fkwcs_popup .wfocu_fkwcs_warning_div.is-visible {
												background: #FCF6EB;
												color: #353030;
											}
											.wfocu_fkwcs_popup .wfocu_fkwcs_error_div.is-visible svg,
											.wfocu_fkwcs_popup .wfocu_fkwcs_warning_div.is-visible svg {
												vertical-align: middle;
												margin-inline-end: 8px;
											}
											.wfocu_fkwcs_popup .wfocu_fkwcs_warning_div.is-visible svg path {
												fill: #353030;
											}
											.wfocu_fkwcs_popup .wfocu_fkwcs_blik_wrapper {
												margin-bottom: 0;
											}
											.wfocu_fkwcs_popup #fkwcs-blik-code-input:focus {
												outline: none;
												border-color: #09B29C;
												box-shadow: 0 0 0 2px rgba(9, 178, 156, 0.1);
											}
										`;
										el.appendChild(style);

								let $input = $('#fkwcs-blik-code-input');
										let $error = $('.wfocu_fkwcs_error_div');

										// Focus on input
										setTimeout(function() {
								$input.focus();
										}, 300);

								// Only allow numbers
								$input.on('input', function() {
									this.value = this.value.replace(/[^0-9]/g, '');
											$error.removeClass('is-visible');
											$error.html('');
										});

										// Handle Enter key
										$input.on('keypress', function(e) {
											if (e.which === 13) {
												e.preventDefault();
												$('.wfocuswal-confirm').click();
											}
										});
									},
									onClose: () => {
										this.bucket.HasEventRunning = false;
										$('body').removeClass('wfocu_credit_card_open');
									},
									preConfirm: () => {
										let blikCode = $('#fkwcs-blik-code-input').val().trim();
										let $error = $('.wfocu_fkwcs_error_div');

									// Validate BLIK code
									if (!blikCode || blikCode.length !== 6 || !/^\d{6}$/.test(blikCode)) {
											const infoIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 21" fill="none">
												<path d="M10.0014 2.29883C14.6045 2.29883 18.336 6.03037 18.336 10.6335C18.336 15.2365 14.6045 18.9681 10.0014 18.9681C5.39829 18.9681 1.66675 15.2365 1.66675 10.6335C1.66675 6.03037 5.39829 2.29883 10.0014 2.29883ZM10.0014 3.54883C6.08864 3.54883 2.91675 6.72072 2.91675 10.6335C2.91675 14.5462 6.08864 17.7181 10.0014 17.7181C13.9141 17.7181 17.086 14.5462 17.086 10.6335C17.086 6.72072 13.9141 3.54883 10.0014 3.54883ZM9.99834 9.38265C10.3148 9.38244 10.5764 9.6174 10.618 9.92243L10.6237 10.0072L10.6267 14.5919C10.627 14.9371 10.3473 15.2171 10.0022 15.2173C9.68574 15.2175 9.42409 14.9826 9.38251 14.6775L9.37675 14.5927L9.37375 10.0081C9.37352 9.66288 9.65316 9.38287 9.99834 9.38265ZM10.0017 6.46784C10.4614 6.46784 10.834 6.84044 10.834 7.30006C10.834 7.75968 10.4614 8.13228 10.0017 8.13228C9.54213 8.13228 9.16953 7.75968 9.16953 7.30006C9.16953 6.84044 9.54213 6.46784 10.0017 6.46784Z" fill="#E15334"/>
												</svg>`;
											$error.addClass('is-visible');
											$error.html(infoIcon + '<?php echo esc_js( __( 'Please enter a valid 6-digit BLIK code.', 'funnelkit-stripe-woo-payment-gateway' ) ); ?>');
											$('#fkwcs-blik-code-input').focus();
											return false; // Keep modal open on validation error
										}

										// Return Promise to proceed with payment after modal closes
										return new Promise((resolve) => {
											$('body').removeClass('wfocu_credit_card_open');
											// Process payment after modal closes
											setTimeout(() => {
										self.initCharge(blikCode);
												resolve(blikCode);
											}, 100);
										});
									}
								});
							},

							initCharge: function (blikCode) {
								let self = this;

								if (!blikCode) {
									// If no BLIK code provided, show popup first
									this.showBlikCodePopup();
									return;
								}

								// Close BLIK code popup first if it's visible
								if (typeof wfocuSweetalert2 !== 'undefined' && wfocuSweetalert2.isVisible()) {
									if (this.bucket && typeof this.bucket.swal !== 'undefined' && typeof this.bucket.swal.close === 'function') {
										this.bucket.swal.close();
									}
									setTimeout(() => {
										self.initCharge(blikCode);
									}, 100);
									return;
								}

								// Set HasEventRunning to prevent multiple clicks
								if (this.bucket) {
									this.bucket.HasEventRunning = true;
								}

								// Show processing modal using bucket's loader pattern - same as bucket.sendBucket()
								if (typeof wfocuSweetalert2 !== 'undefined' && false === wfocuSweetalert2.isVisible()) {
									this.bucket.swal.show({
										html: this.bucket.getLoader() + '<div id="wfocu-swal-content" style="margin:10px 0 24px;color:#353030;font-size:18px;line-height:28px;font-weight:500;">' + this.bucket.globalVars.loading_text + '</div>',
										width: 360
									});
								}

								// Send AJAX request
								let getBucketData = this.bucket.getBucketSendData();
								let postData = $.extend(getBucketData, {
									action: ajax_link,
									'fkwcs_gateway': '<?php echo esc_js( $this->get_key() ); ?>',
									blik_code: blikCode
								});

								// Helper function to redirect on failure
								let redirectOnFailure = function(redirectUrl, errorCode) {
									// Close processing modal
									if (self.bucket && typeof self.bucket.swal !== 'undefined' && typeof self.bucket.swal.close === 'function') {
										self.bucket.swal.close();
									}
									self.bucket.HasEventRunning = false;
									if (typeof redirectUrl !== "undefined" && redirectUrl) {
										setTimeout(() => window.location = redirectUrl, 1500);
									} else if (typeof wfocu_vars.order_received_url !== 'undefined') {
										setTimeout(() => window.location = wfocu_vars.order_received_url + (errorCode ? '&ec=' + errorCode : ''), 1500);
									} else {
										// Fallback: try to get redirect URL from bucket or proceed to next offer
										setTimeout(() => {
											if (typeof self.bucket !== 'undefined' && typeof self.bucket.getNextOfferUrl === 'function') {
												let nextUrl = self.bucket.getNextOfferUrl();
												if (nextUrl) {
													window.location = nextUrl;
												}
											}
										}, 1500);
									}
								};

								let action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', ajax_link), postData);

								action.done(function (data) {
									// Close processing modal
									if (self.bucket && typeof self.bucket.swal !== 'undefined' && typeof self.bucket.swal.close === 'function') {
										self.bucket.swal.close();
									}

									if (data.result !== "success") {
										// Show error but always redirect - don't block user
										if (self.bucket && typeof self.bucket.swal !== 'undefined' && typeof self.bucket.swal.show === 'function') {
											self.bucket.swal.show({
											'text': data.message || wfocu_vars.messages.offer_msg_pop_failure,
											'type': 'warning'
										});
										}
										redirectOnFailure(
											(typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') ? data.response.redirect_url : undefined,
											'fkwcs_stripe_error'
										);
										return;
									}

										if (typeof data.intent_secret !== "undefined" && '' !== data.intent_secret) {
											// Confirm BLIK payment with the code
											wfocuStripe.confirmBlikPayment(data.intent_secret, {
												payment_method: {
													blik: {},
													billing_details: {
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
												payment_method_options: {
													blik: {
														code: data.blik_code || blikCode
													}
												},
												return_url: homeURL + data.response.redirect_url
											}).then((result) => {
												// Close processing modal if still open
												if (self.bucket && typeof self.bucket.swal !== 'undefined' && typeof self.bucket.swal.close === 'function') {
													self.bucket.swal.close();
												}

												if (result.error) {
												// Show error but always redirect
												if (self.bucket && typeof self.bucket.swal !== 'undefined' && typeof self.bucket.swal.show === 'function') {
													self.bucket.swal.show({
														'text': result.error.message || wfocu_vars.messages.offer_msg_pop_failure,
														'type': 'warning'
													});
														}
												redirectOnFailure(
													(typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') ? data.response.redirect_url : undefined,
													'blik_failed'
												);
													return;
												}

												if (result.paymentIntent && (result.paymentIntent.status === 'succeeded' || result.paymentIntent.status === 'processing')) {
												// Success - show message and redirect
												if (self.bucket && typeof self.bucket.swal !== 'undefined' && typeof self.bucket.swal.show === 'function') {
													self.bucket.swal.show({
														'text': wfocu_vars.messages.offer_success_message_pop,
														'type': 'success'
													});
												}
													setTimeout(() => {
														if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
															window.location = data.response.redirect_url;
														} else if (typeof wfocu_vars.order_received_url !== 'undefined') {
															window.location = wfocu_vars.order_received_url;
														}
													}, 1500);
													return;
												}

												// Handle redirect to mobile banking app
												if (result.paymentIntent &&
													result.paymentIntent.next_action &&
													result.paymentIntent.next_action.type === 'redirect_to_url' &&
													result.paymentIntent.next_action.redirect_to_url &&
													result.paymentIntent.next_action.redirect_to_url.url) {
													window.location.href = result.paymentIntent.next_action.redirect_to_url.url;
												} else if (result.paymentIntent && result.paymentIntent.status === 'requires_action') {
												// Requires action - show message and redirect
												if (self.bucket && typeof self.bucket.swal !== 'undefined' && typeof self.bucket.swal.show === 'function') {
													self.bucket.swal.show({
														'text': wfocu_vars.messages.offer_msg_pop_failure,
														'type': 'warning'
													});
												}
												redirectOnFailure(
													(typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') ? data.response.redirect_url : undefined,
													'blik_pending'
												);
											} else {
												// Unknown status - redirect anyway
												redirectOnFailure(
													(typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') ? data.response.redirect_url : undefined,
													'blik_unknown'
												);
												}

											}).catch((error) => {
											// Close processing modal if still open
											if (self.bucket && typeof self.bucket.swal !== 'undefined' && typeof self.bucket.swal.close === 'function') {
												self.bucket.swal.close();
											}
											// Catch any errors and redirect - don't block user
											console.error('BLIK payment error:', error);
											if (self.bucket && typeof self.bucket.swal !== 'undefined' && typeof self.bucket.swal.show === 'function') {
												self.bucket.swal.show({
													'text': wfocu_vars.messages.offer_msg_pop_failure,
													'type': 'warning'
												});
											}
											redirectOnFailure(
												(typeof data !== 'undefined' && typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') ? data.response.redirect_url : undefined,
												'blik_error'
											);
										});
									} else {
										// No intent secret - close processing modal and redirect
										if (self.bucket && typeof self.bucket.swal !== 'undefined' && typeof self.bucket.swal.close === 'function') {
											self.bucket.swal.close();
										}
										redirectOnFailure(
											(typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') ? data.response.redirect_url : undefined,
											'blik_no_intent'
										);
									}
								});

								action.fail(function (data) {
									// Close processing modal
									if (self.bucket && typeof self.bucket.swal !== 'undefined' && typeof self.bucket.swal.close === 'function') {
										self.bucket.swal.close();
									}
									// AJAX failure - always redirect, don't block
									if (self.bucket && typeof self.bucket.swal !== 'undefined' && typeof self.bucket.swal.show === 'function') {
										self.bucket.swal.show({
										'text': wfocu_vars.messages.offer_msg_pop_failure,
										'type': 'warning'
									});
									}
									redirectOnFailure(
										(typeof data !== 'undefined' && typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') ? data.response.redirect_url : undefined,
										'stripe_error'
									);
								});
							}
						};

						$(document).off('wfocuBucketCreated.blikStripe');
						$(document).off('wfocu_external.blikStripe');
						$(document).off('wfocuBucketConfirmationRendered.blikStripe');
						$(document).off('wfocuBucketLinksConverted.blikStripe');

						// Event handlers
						$(document).on('wfocuBucketCreated.blikStripe', function (e, Bucket) {
							wfocuStripeJS.bucket = Bucket;
						});

						$(document).on('wfocu_external.blikStripe', function (e, Bucket) {
							if (0 !== Bucket.getTotal()) {
								wfocuStripeJS.bucket = Bucket;
								Bucket.inOfferTransaction = true;
								// Show BLIK code popup first
								wfocuStripeJS.showBlikCodePopup();
							}
						});

						$(document).on('wfocuBucketConfirmationRendered.blikStripe', function (e, Bucket) {
							wfocuStripeJS.bucket = Bucket;
						});

						$(document).on('wfocuBucketLinksConverted.blikStripe', function (e, Bucket) {
							wfocuStripeJS.bucket = Bucket;
						});

						// Store globally
						window.wfocuStripeJS = wfocuStripeJS;
					}

					$(document).ready(function () {
						if (typeof Stripe !== 'undefined') {
							initializeStripePayment();
							window.fkwcsBlikStripeInitialized = true;
						}
					});

					$(window).on('load', function () {
						if (!window.fkwcsBlikStripeInitialized && typeof Stripe !== 'undefined') {
							initializeStripePayment();
							window.fkwcsBlikStripeInitialized = true;
						}
					});
				})(jQuery);
			</script>
			<?php
		}
	}

	WFOCU_Plugin_Integration_Fkwcs_Blik::get_instance();
}
