<?php

use FKWCS\Gateway\Stripe\Helper;

if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Cashapp' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_Cashapp extends WFOCU_Gateway {
		protected static $instance = null;
		public $key                = 'fkwcs_stripe_cashapp';
		public $token              = false;
		public $current_intent;
		public $current_order_id = null;
		public $refund_supported = true;

		public function __construct() {
			parent::__construct();

			add_action( 'wfocu_footer_before_print_scripts', array( $this, 'maybe_render_in_offer_transaction_scripts' ), 999 );
			add_filter( 'wfocu_allow_ajax_actions_for_charge_setup', array( $this, 'allow_check_action' ) );
			add_action( 'wfocu_offer_new_order_created_' . $this->key, array( $this, 'add_stripe_payouts_to_new_order' ), 10, 1 );
			add_action( 'wfocu_offer_new_order_created_before_complete', array( $this, 'maybe_save_intent' ), 10 );
			add_action( 'wfocu_front_primary_order_cancelled', array( $this, 'remove_intent_meta_form_cancelled_order' ) );
		}

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		// --- Token Handling ---
		public function has_token( $order ) {
			$this->token = Helper::get_meta( $order, '_fkwcs_source_id' );
			return ! empty( $this->token );
		}

		public function get_token( $order ) {
			$this->token = Helper::get_meta( $order, '_fkwcs_source_id' );
			return ! empty( $this->token ) ? $this->token : false;
		}

		/**
		 * Strategic logging - only for critical points
		 */
		private function log_important( $message, $data = null ) {
			$log_message = '[CASHAPP] ' . $message;
			if ( $data !== null ) {
				$log_message .= ' | ' . wp_json_encode( $data );
			}
			WFOCU_Core()->log->log( $log_message );
		}

		// --- Main Payment Processing (Simplified Entry Point) ---
		public function process_client_payment() {
			$this->log_important( 'Payment processing started' );

			// Step 1: Initialize and validate
			$validation_result = $this->initialize_payment_request();
			if ( ! $validation_result['success'] ) {
				return $this->handle_validation_error( $validation_result['error'] );
			}

			$order              = $validation_result['order'];
			$intent_from_posted = filter_input( INPUT_POST, 'intent', FILTER_SANITIZE_NUMBER_INT );

			// Step 2: Handle authentication response or new payment
			if ( ! empty( $intent_from_posted ) ) {
				return $this->handle_authentication_response( $order );
			} else {
				return $this->process_new_payment( $order );
			}
		}

		/**
		 * Initialize and validate payment request
		 */
		private function initialize_payment_request() {
			$get_current_offer      = WFOCU_Core()->data->get( 'current_offer' );
			$get_current_offer_meta = WFOCU_Core()->offers->get_offer_meta( $get_current_offer );

			WFOCU_Core()->data->set( '_offer_result', true );
			$posted_data = WFOCU_Core()->process_offer->parse_posted_data( $_POST );

			// Validate charge request
			if ( false === WFOCU_AJAX_Controller::validate_charge_request( $posted_data ) ) {
				return array(
					'success' => false,
					'error'   => 'validation_failed',
				);
			}

			// Setup offer and get order
			WFOCU_Core()->process_offer->execute( $get_current_offer_meta );
			$order = WFOCU_Core()->data->get_parent_order();

			if ( ! $order ) {
				return array(
					'success' => false,
					'error'   => 'order_not_found',
				);
			}

			return array(
				'success' => true,
				'order'   => $order,
			);
		}

		/**
		 * Handle validation errors
		 */
		private function handle_validation_error( $error_type ) {
			$this->log_important( 'Validation failed', array( 'error' => $error_type ) );
			wp_send_json( array( 'result' => 'error' ) );
		}

		/**
		 * Handle authentication response from client
		 */
		private function handle_authentication_response( $order ) {
			$this->log_important( 'Processing authentication response' );

			$intent_secret = filter_input( INPUT_POST, 'intent_secret' );
			if ( empty( $intent_secret ) ) {
				return $this->send_error_response( 'Intent secret missing from authentication', $order );
			}

			// Get intent ID from session
			$intent_id = WFOCU_Core()->data->get( 'c_intent_secret_' . $intent_secret, '', 'gateway' );
			if ( empty( $intent_id ) ) {
				return $this->send_error_response( 'Unable to find matching intent ID', $order );
			}

			// Verify and process
			$intent = $this->verify_intent( $intent_id );
			if ( $intent === false ) {
				return $this->send_error_response( 'Intent authentication failed', $order );
			}

			return $this->complete_payment_processing( $intent, $order );
		}

		/**
		 * Process new payment
		 */
		private function process_new_payment( $order ) {
			$this->log_important( 'Processing new payment', array( 'order_id' => $order->get_id() ) );

			// Validate payment requirements
			$payment_data = $this->prepare_payment_data( $order );
			if ( ! $payment_data['success'] ) {
				wp_send_json(
					array(
						'result'  => 'error',
						'message' => $payment_data['message'],
					)
				);
				return;
			}

			// Create payment intent
			$intent_result = $this->create_payment_intent( $payment_data['request'], $order );
			if ( ! $intent_result['success'] ) {
				return $this->handle_api_error(
					$intent_result['error_message'],
					$intent_result['error_details'],
					$order,
					$intent_result['error_data']
				);
			}

			return $this->handle_payment_intent_response( $intent_result['intent'], $order );
		}

		/**
		 * Prepare payment data and validate requirements
		 */
		private function prepare_payment_data( $order ) {
			$token       = $this->get_token( $order );
			$customer_id = Helper::get_meta( $order, '_fkwcs_customer_id' );

			if ( empty( $token ) || empty( $customer_id ) ) {
				return array(
					'success' => false,
					'message' => __( 'No saved Cash App Pay token or customer found for upsell payment.', 'funnelkit-stripe-woo-payment-gateway' ),
				);
			}

			$offer_package = WFOCU_Core()->data->get( '_upsell_package' );
			$gateway       = $this->get_wc_gateway();

			$request = array(
				'amount'               => Helper::get_formatted_amount( $offer_package['total'] ),
				'currency'             => $gateway->get_currency(),
				'customer'             => $customer_id,
				'payment_method'       => $token,
				'payment_method_types' => array( 'cashapp' ),
				'off_session'          => true,
				'confirm'              => true,
				'description'          => sprintf(
					'%1$s - Order %2$s - 1 click upsell: %3$s',
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					$order->get_order_number(),
					WFOCU_Core()->data->get( 'current_offer' )
				),
				'metadata'             => array(
					'fk_upsell' => 'yes',
					'order_id'  => $order->get_id(),
				),
			);

			return array(
				'success' => true,
				'request' => $request,
			);
		}

		/**
		 * Create payment intent with Stripe
		 */
		private function create_payment_intent( $request, $order ) {
			try {
				$gateway    = $this->get_wc_gateway();
				$stripe_api = $gateway->get_client();
				$intent     = $stripe_api->payment_intents( 'create', array( $request ) );

				// Check for errors
				if ( ! empty( $intent['error'] ) ) {
					return array(
						'success'       => false,
						'error_message' => 'Cash App Pay upsell payment failed: ' . $intent['error']['message'],
						'error_details' => print_r( $intent['error'], true ),
						'error_data'    => $intent['error'],
					);
				}

				// Validate response structure
				if ( empty( $intent ) || ! is_array( $intent ) ) {
					return array(
						'success'       => false,
						'error_message' => 'Invalid response from Stripe',
						'error_details' => 'Empty or invalid intent response',
						'error_data'    => array( 'message' => 'Invalid Stripe response' ),
					);
				}

				$intent_data = isset( $intent['data'] ) ? $intent['data'] : $intent;
				if ( empty( $intent_data['id'] ) ) {
					return array(
						'success'       => false,
						'error_message' => 'No PaymentIntent ID returned',
						'error_details' => 'Intent response missing ID',
						'error_data'    => array( 'message' => 'Missing PaymentIntent ID' ),
					);
				}

				$this->log_important(
					'PaymentIntent created',
					array(
						'intent_id' => $intent_data['id'],
						'status'    => $intent_data['status'] ?? 'unknown',
					)
				);

				return array(
					'success' => true,
					'intent'  => $intent_data,
				);

			} catch ( Exception $e ) {
				return array(
					'success'       => false,
					'error_message' => 'Cash App Pay upsell payment exception: ' . $e->getMessage(),
					'error_details' => 'Exception details: ' . $e->getTraceAsString(),
					'error_data'    => array( 'message' => $e->getMessage() ),
				);
			}
		}

		/**
		 * Handle payment intent response based on status
		 */
		private function handle_payment_intent_response( $intent_data, $order ) {
			// Save intent secret for potential authentication
			if ( ! empty( $intent_data['client_secret'] ) ) {
				WFOCU_Core()->data->set( 'c_intent_secret_' . $intent_data['client_secret'], $intent_data['id'], 'gateway' );
				WFOCU_Core()->data->save( 'gateway' );
			}

			$this->current_intent = (object) $intent_data;
			$intent_status        = $intent_data['status'] ?? '';

			switch ( $intent_status ) {
				case 'requires_action':
					$this->log_important( 'Payment requires authentication' );
					wp_send_json(
						array(
							'result'        => 'success',
							'intent_secret' => $intent_data['client_secret'],
						)
					);
					break;

				case 'succeeded':
				case 'processing':
				case 'requires_capture':
					return $this->process_successful_payment( $intent_data, $order );

				default:
					return $this->send_error_response(
						'Unexpected payment status: ' . $intent_status,
						$order,
						array( 'status' => $intent_status )
					);
			}
		}

		/**
		 * Process successful payment and complete upsell
		 */
		private function process_successful_payment( $intent_data, $order ) {
			$charges_data = $intent_data['charges']['data'] ?? array();
			if ( empty( $charges_data ) ) {
				return $this->send_error_response( 'No charge data found in payment', $order );
			}

			$charge    = end( $charges_data );
			$charge_id = $charge['id'] ?? '';

			if ( empty( $charge_id ) ) {
				return $this->send_error_response( 'Missing charge ID', $order );
			}

			$this->log_important( 'Payment succeeded', array( 'charge_id' => $charge_id ) );

			// Set transaction ID and update fees
			WFOCU_Core()->data->set( '_transaction_id', $charge_id );

			if ( ! empty( $charge['balance_transaction'] ) ) {
				$balance_transaction_id = is_string( $charge['balance_transaction'] )
					? $charge['balance_transaction']
					: $charge['balance_transaction']['id'];
				$this->update_stripe_fees( $order, $balance_transaction_id );
			}

			// Complete upsell processing
			$upsell_result = WFOCU_Core()->process_offer->_handle_upsell_charge( true );
			$this->log_important( 'Upsell completed', array( 'redirect' => ! empty( $upsell_result['redirect_url'] ) ) );

			wp_send_json(
				array(
					'result'    => 'success',
					'response'  => $upsell_result,
					'charge_id' => $charge_id,
				)
			);
		}

		/**
		 * Complete payment processing for authenticated payments
		 */
		private function complete_payment_processing( $intent, $order ) {
			$charges       = $intent->data->charges;
			$charges_array = is_array( $charges->data ) ? $charges->data : array( $charges->data );
			$charge        = end( $charges_array );

			$charge_id           = is_object( $charge ) ? $charge->id : $charge['id'];
			$balance_transaction = is_object( $charge ) ? $charge->balance_transaction : $charge['balance_transaction'];

			$this->log_important( 'Authentication completed', array( 'charge_id' => $charge_id ) );

			WFOCU_Core()->data->set( '_transaction_id', $charge_id );

			if ( $balance_transaction ) {
				$balance_transaction_id = is_string( $balance_transaction )
					? $balance_transaction
					: ( is_object( $balance_transaction ) ? $balance_transaction->id : $balance_transaction['id'] );
				$this->update_stripe_fees( $order, $balance_transaction_id );
			}

			$upsell_result = WFOCU_Core()->process_offer->_handle_upsell_charge( true );

			wp_send_json(
				array(
					'result'    => 'success',
					'response'  => $upsell_result,
					'charge_id' => $charge_id,
				)
			);
		}

		/**
		 * Send standardized error response
		 */
		private function send_error_response( $message, $order, $additional_data = array() ) {
			$this->log_important( 'Error: ' . $message, $additional_data );
			$this->handle_api_error(
				'Cash App Pay upsell payment failed: ' . $message,
				$message,
				$order,
				true
			);
		}

		/**
		 * Verify payment intent (simplified)
		 */
		public function verify_intent( $intent_id ) {
			try {
				$gateway    = $this->get_wc_gateway();
				$stripe_api = $gateway->get_client();
				$intent     = (object) $stripe_api->payment_intents( 'retrieve', array( $intent_id ) );

				$this->current_intent = $intent;

				if ( empty( $intent->data ) ) {
					return false;
				}

				$status = $intent->data->status;
				return in_array( $status, array( 'succeeded', 'requires_capture' ), true ) ? $intent : false;

			} catch ( Exception $e ) {
				$this->log_important( 'Intent verification failed', array( 'error' => $e->getMessage() ) );
				return false;
			}
		}

		/**
		 * Update Stripe fees (simplified)
		 */
		public function update_stripe_fees( $order, $balance_transaction_id ) {
			try {
				$gateway    = $this->get_wc_gateway();
				$stripe_api = $gateway->get_client();
				$response   = $stripe_api->balance_transactions( 'retrieve', array( $balance_transaction_id ) );

				if ( ! $response['success'] || ! $response['data'] ) {
					return;
				}

				$balance_transaction = $response['data'];
				$fee                 = Helper::format_amount( $order->get_currency(), $balance_transaction->fee ?? 0 );
				$net                 = Helper::format_amount( $order->get_currency(), $balance_transaction->net ?? 0 );

				$order_behavior = WFOCU_Core()->funnels->get_funnel_option( 'order_behavior' );
				$data           = array(
					'fee'      => $fee,
					'net'      => $net,
					'currency' => strtoupper( $balance_transaction->currency ?? $order->get_currency() ),
				);

				if ( 'batching' === $order_behavior ) {
					$data['fee'] += Helper::get_stripe_fee( $order );
					$data['net'] += Helper::get_stripe_net( $order );
					Helper::update_stripe_transaction_data( $order, $data );
				} else {
					WFOCU_Core()->data->set( 'wfocu_stripe_fee', $fee );
					WFOCU_Core()->data->set( 'wfocu_stripe_net', $net );
					WFOCU_Core()->data->set( 'wfocu_stripe_currency', $data['currency'] );
				}
			} catch ( Exception $e ) {
				$this->log_important( 'Fee update failed', array( 'error' => $e->getMessage() ) );
			}
		}

		// --- Existing methods (unchanged) ---
		public function maybe_save_intent( $order ) {
			if ( empty( $this->current_intent ) ) {
				return;
			}
			$this->get_wc_gateway()->save_intent_to_order( $order, $this->current_intent );
			$order->update_meta_data( '_fkwcs_stripe_charge_captured', 'yes' );
		}

		public function remove_intent_meta_form_cancelled_order( $cancelled_order ) {
			if ( ! $cancelled_order instanceof WC_Order ) {
				return;
			}
			$cancelled_order->delete_meta_data( '_fkwcs_webhook_paid' );
			$cancelled_order->delete_meta_data( '_fkwcs_intent_id' );
			$cancelled_order->save_meta_data();
		}

		public function allow_check_action( $actions ) {
			array_push( $actions, 'wfocu_front_handle_fkwcs_cashapp_payments' );
			return $actions;
		}

		public function add_stripe_payouts_to_new_order( $order ) {
			$data = array(
				'fee'      => WFOCU_Core()->data->get( 'wfocu_stripe_fee' ),
				'net'      => WFOCU_Core()->data->get( 'wfocu_stripe_net' ),
				'currency' => WFOCU_Core()->data->get( 'wfocu_stripe_currency' ),
			);
			Helper::update_stripe_transaction_data( $order, $data );
			$order->save_meta_data();
		}

		public function maybe_render_in_offer_transaction_scripts() {
			$order = WFOCU_Core()->data->get_current_order();
			if ( ! $order instanceof WC_Order || $this->key !== $order->get_payment_method() ) {
				return;
			}
			?>
			<script src="https://js.stripe.com/v3/?ver=3.0" data-cookieconsent="ignore"></script>
			<script>
				(function ($) {
					"use strict";

					function initializeCashAppPayment() {
						var wfocuStripe = Stripe('<?php echo esc_js( $this->get_wc_gateway()->get_client_key() ); ?>');
						var ajax_link = 'wfocu_front_handle_fkwcs_cashapp_payments';

						var wfocuStripeJS = {
							bucket: null,
							initCharge: function () {
								var getBucketData = this.bucket.getBucketSendData();
								var postData = $.extend(getBucketData, {
									action: ajax_link,
									'fkwcs_gateway': '<?php echo esc_js( $this->key ); ?>'
								});

								$.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', ajax_link), postData)
									.done(this.handleResponse.bind(this))
									.fail(this.handleFailure.bind(this));
							},

							handleResponse: function(data) {
								if (data.result !== "success") {
									return this.showError(data);
								}

								if (data.intent_secret) {
									return this.handleAuthentication(data.intent_secret);
								}

								this.showSuccess();
								this.redirect(data.response?.redirect_url || data.redirect_url);
							},

							handleAuthentication: function(clientSecret) {
								wfocuStripe.confirmPayment({
									clientSecret: clientSecret,
									confirmParams: { return_url: window.location.href }
								}).then((result) => {
									var isSuccess = !result.error && result.paymentIntent &&
										['succeeded', 'processing', 'requires_capture'].includes(result.paymentIntent.status);
									$(document).trigger('wfocuStripeOnAuthentication', [result, isSuccess]);
								}).catch(() => {
									$(document).trigger('wfocuStripeOnAuthentication', [false, false]);
								});
							},

							showSuccess: function() {
								this.bucket.swal.show({
									'text': wfocu_vars.messages.offer_success_message_pop,
									'type': 'success'
								});
							},

							showError: function(data) {
								this.bucket.swal.show({
									'text': wfocu_vars.messages.offer_msg_pop_failure,
									'type': 'warning'
								});
								this.redirect(data.response?.redirect_url || (wfocu_vars.order_received_url + '&ec=fkwcs_stripe_error'));
							},

							handleFailure: function() {
								this.showError({});
							},

							redirect: function(url) {
								if (url) {
									setTimeout(() => window.location = url, 1500);
								} else if (wfocu_vars.order_received_url) {
									window.location = wfocu_vars.order_received_url;
								}
							}
						};

						$(document).off('wfocuStripeOnAuthentication.cashapp');
						$(document).off('wfocuBucketCreated.cashapp');
						$(document).off('wfocu_external.cashapp');
						$(document).off('wfocuBucketConfirmationRendered.cashapp');
						$(document).off('wfocuBucketLinksConverted.cashapp');

						$(document).on('wfocuStripeOnAuthentication.cashapp', function (e, response, isSuccess) {
							var postData = $.extend(wfocuStripeJS.bucket.getBucketSendData(), {
								action: ajax_link,
								intent: 1,
								intent_secret: isSuccess ? response.paymentIntent.client_secret : ''
							});

							$.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', ajax_link), postData)
								.done(function (data) {
									if (data.result === "success") {
										wfocuStripeJS.showSuccess();
									} else {
										wfocuStripeJS.showError(data);
									}
									wfocuStripeJS.redirect(data.response?.redirect_url || (wfocu_vars.order_received_url + '&ec=cashapp_complete'));
								});
						});

						$(document).on('wfocuBucketCreated.cashapp', function (e, Bucket) {
							wfocuStripeJS.bucket = Bucket;
						});

						$(document).on('wfocuBucketConfirmationRendered.cashapp', function (e, Bucket) {
							wfocuStripeJS.bucket = Bucket;
						});

						$(document).on('wfocuBucketLinksConverted.cashapp', function (e, Bucket) {
							wfocuStripeJS.bucket = Bucket;
						});

						$(document).on('wfocu_external.cashapp', function (e, Bucket) {
							if (0 !== Bucket.getTotal()) {
								wfocuStripeJS.bucket = Bucket;
								Bucket.inOfferTransaction = true;
								wfocuStripeJS.initCharge();
							}
						});

						window.wfocuStripeJS = wfocuStripeJS;
					}

					// Prevent double initialization - check if already initialized
					if (window.fkwcsCashAppStripeInitialized || window.wfocuStripeJS) {
						return;
					}

					// Initialize based on document state to ensure single execution
					function initCashAppStripePayment() {
						// Double-check to prevent race conditions
						if (window.fkwcsCashAppStripeInitialized || window.wfocuStripeJS) {
							return;
						}
						// Check if Stripe library is available
						if (typeof Stripe === 'undefined') {
							console.error('Stripe library failed to load');
							return;
						}
						initializeCashAppPayment();
						window.fkwcsCashAppStripeInitialized = true;
					}

					// Use document.readyState to determine which event to use
					// This ensures we only attach to one event handler
					// 'complete' = page fully loaded (window.load already fired, ready executes immediately)
					// 'interactive' = DOM ready (ready executes immediately)
					// 'loading' = still loading (ready will fire when DOM is ready)
					if (document.readyState === 'complete' || document.readyState === 'interactive' || document.readyState === 'loading') {
						$(document).ready(function () {
							initCashAppStripePayment();
						});
					} else {
						// Fallback: if readyState is in unexpected state, use window.load
						$(window).on('load', function () {
							initCashAppStripePayment();
						});
					}

				})(jQuery);
			</script>
			<?php
		}

		/**
		 * Handle refund offer request from admin
		 */
		public function process_refund_offer( $order ) {
			$refund_data   = wc_clean( $_POST );
			$txn_id        = $refund_data['txn_id'] ?? '';
			$amount        = $refund_data['amt'] ?? '';
			$refund_reason = $refund_data['refund_reason'] ?? '';

			$get_client     = $this->get_wc_gateway()->set_client_by_order_payment_mode( $order );
			$client_details = $get_client->get_clients_details();

			$refund_request = array(
				'amount'   => Helper::get_stripe_amount( $amount, $order->get_currency() ),
				'reason'   => 'requested_by_customer',
				'metadata' => array(
					'customer_ip'       => $client_details['ip'],
					'agent'             => $client_details['agent'],
					'referer'           => $client_details['referer'],
					'reason_for_refund' => $refund_reason,
				),
			);

			// Set payment intent or charge ID
			if ( 0 === strpos( $txn_id, 'pi_' ) ) {
				$refund_request['payment_intent'] = $txn_id;
			} else {
				$refund_request['charge'] = $txn_id;
			}

			$refund_params = apply_filters( 'fkwcs_refund_request_args', $refund_request );
			$response      = $this->get_wc_gateway()->execute_refunds( $refund_params, $get_client );

			if ( $response['success'] && $response['data'] ) {
				$refund_response = $response['data'];
				if ( isset( $refund_response->balance_transaction ) ) {
					Helper::update_balance( $order, $refund_response->balance_transaction, true );
				}
				return $refund_response->id ?? true;
			}

			// Log failure and add order note
			$order->add_order_note(
				sprintf(
					__( 'Refund failed - Reason: %1$s, Amount: %2$s%3$s', 'funnelkit-stripe-woo-payment-gateway' ),
					$refund_reason,
					get_woocommerce_currency_symbol(),
					$amount
				)
			);
			Helper::log( $response['message'] );
			return false;
		}
	}

	WFOCU_Plugin_Integration_Fkwcs_Cashapp::get_instance();
}