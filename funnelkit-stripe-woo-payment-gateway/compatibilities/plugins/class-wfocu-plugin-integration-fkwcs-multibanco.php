<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FKWCS\Gateway\Stripe\Multibanco;
use FKWCS\Gateway\Stripe\Helper;

if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Multibanco' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_Multibanco extends FKWCS_LocalGateway_Upsell {
		protected static $instance = null;
		public $key = 'fkwcs_stripe_multibanco';
		public $token = false;
		public $has_intent_secret = [];
		public $current_intent;
		public $current_order_id = null;
		protected $payment_method_type = 'multibanco';
		protected $need_shipping_address = false;

		/**
		 * Initialize the Multibanco upsell integration.
		 *
		 * Sets up WordPress hooks and actions for handling Multibanco upsell payments,
		 * including redirect hooks, JavaScript rendering, AJAX actions, and order processing.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function __construct() {
			parent::__construct();
			add_action( 'fkwcs_' . $this->key . '_before_redirect', array( $this, 'maybe_setup_upsell_on_multibanco' ), 99, 1 );
			add_action( 'wfocu_footer_before_print_scripts', array( $this, 'maybe_render_in_offer_transaction_scripts' ), 999 );
			add_filter( 'wfocu_allow_ajax_actions_for_charge_setup', array( $this, 'allow_check_action' ) );
			add_action( 'wfocu_offer_new_order_created_before_complete', array( $this, 'maybe_save_intent' ), 10 );
			add_action( 'wfocu_front_primary_order_cancelled', array( $this, 'remove_intent_meta_form_cancelled_order' ) );
			add_filter( 'woocommerce_payment_successful_result', array( $this, 'maybe_flag_has_intent_secret' ), 9999, 2 );
			add_filter( 'wfocu_front_order_status_after_funnel', array( $this, 'replace_recorded_status_with_ipn_response' ), 10, 2 );

		}

		/**
		 * Get singleton instance of the Multibanco upsell integration.
		 *
		 * Implements singleton pattern to ensure only one instance of the class exists.
		 * Creates new instance if none exists, otherwise returns existing instance.
		 *
		 * @return WFOCU_Plugin_Integration_Fkwcs_Multibanco Singleton instance
		 * @since 1.0.0
		 */
		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Flag payment results that contain intent secrets for special handling.
		 *
		 * Identifies WooCommerce payment results that include intent secrets (payment_intent,
		 * setup_intent, or payment_intent_secret) and stores them for potential modification
		 * during the upsell flow.
		 *
		 * @param array $result Payment result array from WooCommerce
		 * @param int $order_id Order ID (unused but required by filter)
		 *
		 * @return array Unmodified payment result array
		 * @since 1.0.0
		 */
		public function maybe_flag_has_intent_secret( $result, $order_id ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			// Only redirects with intents need to be modified.
			if ( isset( $result['intent_secret'] ) || isset( $result['setup_intent_secret'] ) || isset( $result['payment_intent_secret'] ) ) {
				$this->has_intent_secret = $result;
			}


			return $result;
		}

		/**
		 * Setup upsell flow when Multibanco payment requires action.
		 *
		 * Triggered after Multibanco main payment reaches 'requires_action' status.
		 * Initializes upsell sequence and sets funnel running status for the order.
		 * Only executes if the gateway integration is enabled.
		 *
		 * @param int $order_id WooCommerce order ID
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function maybe_setup_upsell_on_multibanco( $order_id ) {
			if ( $this->is_enabled() ) {
				$this->current_order_id = $order_id;
				WFOCU_Core()->public->maybe_setup_upsell( $this->current_order_id );
				WFOCU_Core()->orders->maybe_set_funnel_running_status( wc_get_order( $order_id ) );
			}
		}

		/**
		 * Process upsell charge for Multibanco payment method.
		 *
		 * Creates and confirms payment intent for upsell products. Handles the complete
		 * payment flow including intent creation, confirmation, error handling, and
		 * fee calculation. Throws exceptions on payment failures.
		 *
		 * @param WC_Order $order WooCommerce order object
		 *
		 * @return array Result array with success/failure status
		 * @throws \Exception When payment intent creation/confirmation fails
		 * @since 1.0.0
		 */
		public function process_charge( $order ) {
			$is_successful = false;
			$stripe        = new Multibanco();
			$source        = $stripe->prepare_order_source( $order );

			// Create fresh payment intent
			$intent = $this->create_intent( $order, $source );

			if ( empty( $intent->error ) ) {
				$intent = $this->confirm_intent( $intent, $order );
			}

			if ( ! empty( $intent->error ) ) {
				$localized_message = '';
				if ( 'card_error' === $intent->error->type ) {
					$localized_message = $intent->error->message;
				}
				throw new \Exception( "fkwcs Stripe : " . $localized_message, 102, $this->key );
			}

			if ( ! empty( $intent ) ) {
				if ( 'requires_action' === $intent->status ) {
					throw new \Exception( "fkwcs Stripe : Auth required for the charge but unable to complete.", 102, $this->key );
				}
			}

			$response = end( $intent->charges->data );
			if ( is_wp_error( $response ) ) {
				WFOCU_Core()->log->log( 'Order #' . WFOCU_WC_Compatibility::get_order_id( $order ) . ': Payment Failed For Stripe' );
			} else {
				if ( ! empty( $response->error ) ) {
					throw new \Exception( $response->error->message, 102, $this->key );
				} else {
					WFOCU_Core()->data->set( '_transaction_id', $response->id );
					$is_successful = true;
				}
			}

			if ( true === $is_successful ) {
				$this->update_stripe_fees( $order, is_string( $response->balance_transaction ) ? $response->balance_transaction : $response->balance_transaction->id );
			}

			return $this->handle_result( $is_successful );
		}

		/**
		 * Create Stripe payment intent for Multibanco upsell.
		 *
		 * Generates payment intent with automatic confirmation method and Multibanco
		 * payment type. Includes customer data, amount, currency, and metadata.
		 * Logs creation attempts and validates response structure.
		 *
		 * @param WC_Order $order WooCommerce order object
		 * @param object $prepared_source Prepared payment source with customer/source data
		 *
		 * @return object Payment intent object or error object on failure
		 * @since 1.0.0
		 */
		protected function create_intent( $order, $prepared_source ) {
			$full_request = $this->generate_payment_request( $order, $prepared_source );
			$gateway      = $this->get_wc_gateway();

			$request = array(
				'amount'               => $full_request['amount'],
				'currency'             => $full_request['currency'],
				'description'          => $full_request['description'],
				'metadata'             => $full_request['metadata'],
				'capture_method'       => 'automatic',
				'payment_method_types' => [ $this->payment_method_type ],
				'confirmation_method'  => 'automatic',
				'confirm'              => false,
			);

			if ( $prepared_source->customer ) {
				$request['customer'] = $prepared_source->customer;
			}

			// Create an intent that awaits an action.
			$stripe_api = $gateway->get_client();

			$response = $stripe_api->payment_intents( 'create', array( $request ) );
			$intent   = isset( $response['data'] ) ? $response['data'] : (object) $response;

			if ( ! empty( $intent->error ) ) {
				WFOCU_Core()->log->log( 'Order #' . $order->get_id() . " - Offer payment intent create failed, Reason: " . print_r( $intent->error, true ) );

				return $intent;
			}

			if ( ! isset( $intent->status ) ) {
				WFOCU_Core()->log->log( 'Order #' . $order->get_id() . " - Invalid intent response: " . print_r( $intent, true ) );

				return (object) array( 'error' => array( 'message' => 'Invalid intent response' ) );
			}

			$this->current_intent = $intent;

			return $intent;
		}

		/**
		 * Confirm Stripe payment intent with Multibanco payment method data.
		 *
		 * Confirms previously created payment intent by providing Multibanco payment
		 * method data including billing details. Handles confirmation response and
		 * validates the resulting intent status.
		 *
		 * @param object $intent Payment intent object to confirm
		 * @param WC_Order $order WooCommerce order object
		 *
		 * @return object Confirmed payment intent object or error object on failure
		 * @since 1.0.0
		 */
		protected function confirm_intent( $intent, $order ) {

			$gateway    = $this->get_wc_gateway();
			$stripe_api = $gateway->get_client();

			$confirm_args = array(
				'payment_method_types' => [ $this->payment_method_type ],
				'payment_method_data'  => array(
					'type'            => $this->payment_method_type,
					'billing_details' => array(
						'name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
						'email' => $order->get_billing_email(),
					),
				),
				'return_url'           => $this->get_wc_gateway()->get_return_url( $order ),
			);

			$response = $stripe_api->payment_intents( 'confirm', array( $intent->id, $confirm_args ) );
			$c_intent = isset( $response['data'] ) ? $response['data'] : (object) $response;

			if ( ! empty( $c_intent->error ) ) {
				WFOCU_Core()->log->log( 'Order #' . $order->get_id() . " - Confirm intent failed, Reason: " . print_r( $c_intent->error, true ) );

				return $c_intent;
			}

			if ( ! isset( $c_intent->status ) ) {
				WFOCU_Core()->log->log( 'Order #' . $order->get_id() . " - Invalid confirm intent response: " . print_r( $c_intent, true ) );

				return (object) array( 'error' => array( 'message' => 'Invalid confirm intent response' ) );
			}

			$this->current_intent = $c_intent;

			return $c_intent;
		}

		/**
		 * Generate payment request data for Stripe payment intent.
		 *
		 * Builds comprehensive payment request array including amount, currency, description,
		 * metadata, customer information, and billing details. Validates minimum amount
		 * requirements and applies filters for customization.
		 *
		 * @param WC_Order $order WooCommerce order object
		 * @param object $source Payment source object
		 *
		 * @return array Payment request data array
		 * @throws \Exception When order total is below minimum amount requirement
		 * @since 1.0.0
		 */
		protected function generate_payment_request( $order, $source ) {
			$get_package = WFOCU_Core()->data->get( '_upsell_package' );

			$post_data             = array();
			$post_data['currency'] = strtolower( $order->get_currency() );
			$total                 = Helper::get_stripe_amount( $get_package['total'], $post_data['currency'] );

			if ( $get_package['total'] * 100 < Helper::get_minimum_amount() ) {
				throw new \Exception( sprintf( __( 'Sorry, the minimum allowed order total is %1$s to use this payment method.', 'funnelkit-stripe-woo-payment-gateway' ), wc_price( Helper::get_minimum_amount() / 100 ) ), 101, $this->key );
			}

			$post_data['amount']      = $total;
			$post_data['description'] = sprintf( __( '%1$s - Order %2$s - 1 click upsell: %3$s', 'funnelkit-stripe-woo-payment-gateway' ), wp_specialchars_decode( get_bloginfo( 'name' ) ), $order->get_order_number(), WFOCU_Core()->data->get( 'current_offer' ) );

			$billing_first_name = $order->get_billing_first_name();
			$billing_last_name  = $order->get_billing_last_name();
			$billing_email      = $order->get_billing_email();

			if ( ! empty( $billing_email ) ) {
				$post_data['receipt_email'] = $billing_email;
			}

			$metadata = array(
				__( 'customer_name', 'funnelkit-stripe-woo-payment-gateway' )  => sanitize_text_field( $billing_first_name ) . ' ' . sanitize_text_field( $billing_last_name ),
				__( 'customer_email', 'funnelkit-stripe-woo-payment-gateway' ) => sanitize_email( $billing_email ),
				'order_id'                                                     => $this->get_order_number( $order ),
			);

			$post_data['expand[]'] = 'balance_transaction';
			$post_data['metadata'] = apply_filters( 'wc_fkwcs_stripe_payment_metadata', $metadata, $order, $source );

			if ( $source->customer ) {
				$post_data['customer'] = $source->customer;
			}

			if ( $source->source ) {
				$post_data['source'] = $source->source;
			}

			return apply_filters( 'fkwcs_upsell_stripe_generate_payment_request', $post_data, $get_package, $order, $source );
		}

		/**
		 * Update order with Stripe transaction fees and net amounts.
		 *
		 * Retrieves balance transaction from Stripe API and calculates fees and net amounts.
		 * Handles currency conversion if needed and updates order metadata based on
		 * funnel behavior (batching vs individual orders).
		 *
		 * @param WC_Order $order WooCommerce order object
		 * @param string $balance_transaction_id Stripe balance transaction ID
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function update_stripe_fees( $order, $balance_transaction_id ) {
			$stripe              = $this->get_wc_gateway();
			$stripe_api          = $stripe->get_client();
			$response            = $stripe_api->balance_transactions( 'retrieve', [ $balance_transaction_id ] );
			$balance_transaction = $response['success'] ? $response['data'] : false;

			if ( $balance_transaction === false ) {
				return;
			}

			if ( isset( $balance_transaction ) && isset( $balance_transaction->fee ) ) {
				$fee = ! empty( $balance_transaction->fee ) ? Helper::format_amount( $order->get_currency(), $balance_transaction->fee ) : 0;
				$net = ! empty( $balance_transaction->net ) ? Helper::format_amount( $order->get_currency(), $balance_transaction->net ) : 0;

				$order_behavior = WFOCU_Core()->funnels->get_funnel_option( 'order_behavior' );
				$is_batching_on = ( 'batching' === $order_behavior ) ? true : false;

				$data = [];
				if ( ( 'yes' === get_option( 'fkwcs_currency_fee', 'no' ) && ! empty( $balance_transaction->exchange_rate ) ) ) {
					$data['currency'] = $order->get_currency();
					$fee              = $fee / $balance_transaction->exchange_rate;
					$net              = $net / $balance_transaction->exchange_rate;
				} else {
					$data['currency'] = ! empty( $balance_transaction->currency ) ? strtoupper( $balance_transaction->currency ) : null;
				}

				$data['fee'] = $fee;
				$data['net'] = $net;

				if ( true === $is_batching_on ) {
					$fee         = $fee + Helper::get_stripe_fee( $order );
					$net         = $net + Helper::get_stripe_net( $order );
					$data['fee'] = $fee;
					$data['net'] = $net;
					Helper::update_stripe_transaction_data( $order, $data );
				} else {
					WFOCU_Core()->data->set( 'wfocu_stripe_fee', $fee );
					WFOCU_Core()->data->set( 'wfocu_stripe_net', $net );
					WFOCU_Core()->data->set( 'wfocu_stripe_currency', $data['currency'] );
				}
			}
		}

		/**
		 * Render JavaScript for handling Multibanco upsell transactions on frontend.
		 *
		 * Outputs Stripe.js integration code that handles payment intent confirmation,
		 * voucher display, and success/failure states. Includes special handling for
		 * Multibanco's requires_action status and voucher-based payment flow.
		 * Only renders for Multibanco payment method orders.
		 *
		 * @return void Outputs JavaScript directly to browser
		 * @since 1.0.0
		 */
		public function maybe_render_in_offer_transaction_scripts() {
			$order = WFOCU_Core()->data->get_current_order();

			if ( ! $order instanceof WC_Order ) {
				return;
			}

			if ( $this->key !== $order->get_payment_method() ) {
				return;
			}
			?>
            <script src="https://js.stripe.com/v3/?ver=3.0" data-cookieconsent="ignore"></script>
            <script>
                (function ($) {
                    "use strict";

                    function initializeStripePayment() {
                        var wfocuStripe = Stripe('<?php echo esc_js( $this->get_wc_gateway()->get_client_key() ); ?>');
                        var wfocuStripeJS = {
                            bucket: null,
                            initCharge: function () {
                                var getBucketData = this.bucket.getBucketSendData();
                                var postData = $.extend(getBucketData, {
                                    action: 'wfocu_front_handle_fkwcs_multibanco_payments'
                                });

                                var action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'wfocu_front_handle_fkwcs_multibanco_payments'), postData);

                                action.done(function (data) {

                                    if (data.result !== "success") {
                                        wfocuStripeJS.bucket.swal.show({
                                            'text': wfocu_vars.messages.offer_msg_pop_failure,
                                            'type': 'warning'
                                        });
                                        if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
                                            setTimeout(function () {
                                                window.location = data.response.redirect_url;
                                            }, 1500);
                                        } else {
                                            if (typeof wfocu_vars.order_received_url !== 'undefined') {
                                                window.location = wfocu_vars.order_received_url + '&ec=multibanco_error';
                                            }
                                        }
                                    } else {
                                        // Check for Multibanco special handling flag
                                        if (typeof data.multibanco_treat_all_as_success !== "undefined" && data.multibanco_treat_all_as_success === true) {
                                            if (typeof data.intent_secret !== "undefined" && '' !== data.intent_secret) {
                                                wfocuStripe.confirmMultibancoPayment(data.intent_secret)
                                                    .then(function (response) {
                                                        $(document).trigger('wfocuStripeOnAuthentication', [response, true]);
                                                        return;
                                                    })
                                                    .catch(function (error) {
                                                        console.log('Multibanco popup canceled/error - still treating as success:', error);
                                                        $(document).trigger('wfocuStripeOnAuthentication', [{paymentIntent: {client_secret: data.intent_secret}}, true]);
                                                        return;
                                                    });
                                                return;
                                            }
                                        }

                                        // Standard Multibanco flow (original logic)
                                        if (typeof data.intent_secret !== "undefined" && '' !== data.intent_secret) {
                                            wfocuStripe.confirmMultibancoPayment(data.intent_secret)
                                                .then(function (response) {
                                                    if (response.error) {
                                                        throw response.error;
                                                    }
                                                    if ('requires_capture' !== response.paymentIntent.status && 'succeeded' !== response.paymentIntent.status) {
                                                        return;
                                                    }
                                                    $(document).trigger('wfocuStripeOnAuthentication', [response, true]);
                                                    return;
                                                })
                                                .catch(function (error) {
                                                    $(document).trigger('wfocuStripeOnAuthentication', [false, false]);
                                                    return;
                                                });
                                            return;
                                        }

                                        // No intent secret - immediate success
                                        wfocuStripeJS.bucket.swal.show({
                                            'text': wfocu_vars.messages.offer_success_message_pop,
                                            'type': 'success'
                                        });
                                        if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
                                            setTimeout(function () {
                                                window.location = data.response.redirect_url;
                                            }, 1500);
                                        } else {
                                            if (typeof wfocu_vars.order_received_url !== 'undefined') {
                                                window.location = wfocu_vars.order_received_url;
                                            }
                                        }
                                    }
                                });

                                action.fail(function (data) {
                                    console.error('Multibanco AJAX request failed:', data);
                                    wfocuStripeJS.bucket.swal.show({
                                        'text': wfocu_vars.messages.offer_msg_pop_failure,
                                        'type': 'warning'
                                    });
                                    if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
                                        setTimeout(function () {
                                            window.location = data.response.redirect_url;
                                        }, 1500);
                                    } else {
                                        if (typeof wfocu_vars.order_received_url !== 'undefined') {
                                            window.location = wfocu_vars.order_received_url + '&ec=multibanco_error2';
                                        }
                                    }
                                });
                            }
                        };

                        $(document).off('wfocuStripeOnAuthentication.multibanco');
                        $(document).off('wfocuBucketCreated.multibanco');
                        $(document).off('wfocu_external.multibanco');
                        $(document).off('wfocuBucketConfirmationRendered.multibanco');
                        $(document).off('wfocuBucketLinksConverted.multibanco');
                        $(document).on('wfocuStripeOnAuthentication.multibanco', function (e, response, is_success) {
                            // For Multibanco upsells, always process as success once voucher is shown
                            var postData = $.extend(wfocuStripeJS.bucket.getBucketSendData(), {
                                action: 'wfocu_front_handle_fkwcs_multibanco_payments',
                                intent: 1,
                                intent_secret: response && response.paymentIntent ? response.paymentIntent.client_secret : '',
                                multibanco_voucher_shown: true // Flag that voucher was displayed
                            });

                            var action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'wfocu_front_handle_fkwcs_multibanco_payments'), postData);
                            action.done(function (data) {
                                // Always show success message
                                wfocuStripeJS.bucket.swal.show({
                                    'text': wfocu_vars.messages.offer_success_message_pop,
                                    'type': 'success'
                                });

                                // Redirect to success page
                                if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
                                    setTimeout(function () {
                                        window.location = data.response.redirect_url;
                                    }, 1500);
                                } else {
                                    if (typeof wfocu_vars.order_received_url !== 'undefined') {
                                        window.location = wfocu_vars.order_received_url;
                                    }
                                }
                            }).fail(function (data) {
                                console.log('Multibanco follow-up failed, but still treating as success');
                                // Even if follow-up fails, treat as success since user saw voucher
                                wfocuStripeJS.bucket.swal.show({
                                    'text': wfocu_vars.messages.offer_success_message_pop,
                                    'type': 'success'
                                });
                                setTimeout(function () {
                                    if (typeof wfocu_vars.order_received_url !== 'undefined') {
                                        window.location = wfocu_vars.order_received_url;
                                    }
                                }, 1500);
                            });
                        });

                        $(document).on('wfocuBucketCreated.multibanco', function (e, Bucket) {
                            wfocuStripeJS.bucket = Bucket;
                        });

                        $(document).on('wfocu_external.multibanco', function (e, Bucket) {
                            if (0 !== Bucket.getTotal()) {
                                wfocuStripeJS.bucket = Bucket;
                                Bucket.inOfferTransaction = true;
                                wfocuStripeJS.initCharge();
                            }
                        });

                        $(document).on('wfocuBucketConfirmationRendered.multibanco', function (e, Bucket) {
                            wfocuStripeJS.bucket = Bucket;
                        });

                        $(document).on('wfocuBucketLinksConverted.multibanco', function (e, Bucket) {
                            wfocuStripeJS.bucket = Bucket;
                        });
                        window.wfocuStripeJS = wfocuStripeJS;
                    }

                    $(document).ready(function () {
                        if (typeof Stripe !== 'undefined') {
                            initializeStripePayment();
                            window.fkwcsMultibancoStripeInitialized = true;
                        } else {
                            console.log('Stripe not ready on DOM ready, waiting for window load...');
                        }
                    });

                    $(window).on('load', function () {
                        if (!window.fkwcsMultibancoStripeInitialized) {
                            if (typeof Stripe !== 'undefined') {
                                initializeStripePayment();
                                window.fkwcsMultibancoStripeInitialized = true;
                            } else {
                                console.error('Stripe library failed to load');
                            }
                        }
                    });
                })(jQuery);
            </script>
			<?php
		}

		/**
		 * Allow Multibanco-specific AJAX actions for charge setup.
		 *
		 * Adds the Multibanco payment handling action to the list of allowed
		 * AJAX actions for upsell charge processing. Required for AJAX security validation.
		 *
		 * @param array $actions Array of allowed AJAX action names
		 *
		 * @return array Modified actions array with Multibanco action added
		 * @since 1.0.0
		 */
		public function allow_check_action( $actions ) {
			array_push( $actions, 'wfocu_front_handle_fkwcs_multibanco_payments' );

			return $actions;
		}

		/**
		 * Save payment intent to order metadata before order completion.
		 *
		 * Stores the current payment intent data to the order and marks the charge
		 * as captured. Called during order creation process to preserve intent
		 * information for webhook processing.
		 *
		 * @param WC_Order $order WooCommerce order object
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function maybe_save_intent( $order ) {
			if ( empty( $this->current_intent ) ) {
				return;
			}
			$this->get_wc_gateway()->save_intent_to_order( $order, $this->current_intent );
			$order->update_meta_data( '_fkwcs_stripe_charge_captured', 'yes' );
		}

		/**
		 * Remove intent metadata from cancelled orders.
		 *
		 * Cleans up payment intent related metadata when primary order is cancelled.
		 * Removes webhook paid status and intent ID to prevent conflicts with
		 * future payment attempts.
		 *
		 * @param WC_Order|mixed $cancelled_order WooCommerce order object or other data
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function remove_intent_meta_form_cancelled_order( $cancelled_order ) {
			if ( ! $cancelled_order instanceof WC_Order ) {
				return;
			}
			$cancelled_order->delete_meta_data( '_fkwcs_webhook_paid' );
			$cancelled_order->delete_meta_data( '_fkwcs_intent_id' );
			$cancelled_order->save_meta_data();
		}

		/**
		 * Get WooCommerce payment gateway instance for Multibanco.
		 *
		 * Retrieves the registered Multibanco gateway instance from WooCommerce
		 * payment gateways registry. Used for accessing gateway methods and configuration.
		 *
		 * @since 1.0.0
		 */
		public function get_wc_gateway() {
			return WC()->payment_gateways()->payment_gateways()[ $this->key ];
		}

		/**
		 * Process client-side payment for Multibanco upsells.
		 *
		 * Main AJAX handler for upsell payment processing. Handles both initial payment
		 * intent creation and post-voucher confirmation. Implements special logic for
		 * Multibanco where voucher display equals payment success. Validates requests,
		 * creates/confirms intents, and processes successful upsells.
		 *
		 * @return void Outputs JSON response and exits
		 * @throws Exception When payment processing fails
		 * @since 1.0.0
		 */
		public function process_client_payment() {
			$get_current_offer      = WFOCU_Core()->data->get( 'current_offer' );
			$get_current_offer_meta = WFOCU_Core()->offers->get_offer_meta( $get_current_offer );
			WFOCU_Core()->data->set( '_offer_result', true );
			$posted_data = WFOCU_Core()->process_offer->parse_posted_data( $_POST );

			if ( false === WFOCU_AJAX_Controller::validate_charge_request( $posted_data ) ) {
				wp_send_json( array(
					'result' => 'error',
				) );
			}

			WFOCU_Core()->process_offer->execute( $get_current_offer_meta );
			$get_order = WFOCU_Core()->data->get_parent_order();
			if ( ! $get_order ) {
				$this->handle_api_error( __( 'Offer payment failed. Reason: Order not found', 'funnelkit-stripe-woo-payment-gateway' ), 'Order not found', null, true );
			}

			$gateway = $this->get_wc_gateway();
			$source  = $gateway->prepare_order_source( $get_order );

			$intent_from_posted = filter_input( INPUT_POST, 'intent', FILTER_SANITIZE_NUMBER_INT );

			if ( ! empty( $intent_from_posted ) ) {
				// Handle post-voucher-display processing
				$intent_secret_from_posted = filter_input( INPUT_POST, 'intent_secret' );
				$voucher_shown             = filter_input( INPUT_POST, 'multibanco_voucher_shown', FILTER_VALIDATE_BOOLEAN );

				// If voucher was shown to user, treat as immediate success
				if ( $voucher_shown ) {
					WFOCU_Core()->log->log( 'Multibanco voucher was shown - processing upsell as successful' );

					if ( ! empty( $intent_secret_from_posted ) ) {
						$get_intent_id_from_posted_secret = WFOCU_Core()->data->get( 'c_intent_secret_' . $intent_secret_from_posted, '', 'gateway' );
						if ( ! empty( $get_intent_id_from_posted_secret ) ) {
							// Set transaction ID for tracking
							WFOCU_Core()->data->set( '_transaction_id', $get_intent_id_from_posted_secret );
							$get_order->update_meta_data( '_fkwcs_localgateway_upsell_payment_intent', $get_intent_id_from_posted_secret );
							$get_order->save();
						}
					}

					// Process upsell as successful since user has voucher details
					wp_send_json( array(
						'result'   => 'success',
						'response' => WFOCU_Core()->process_offer->_handle_upsell_charge( true ),
					) );
				}

				// Fallback processing for standard intents
				if ( empty( $intent_secret_from_posted ) ) {
					$this->handle_api_error( __( 'Offer payment failed. Reason: Intent secret missing from auth', 'funnelkit-stripe-woo-payment-gateway' ), 'Intent secret missing from auth', $get_order, true );
				}

				$get_intent_id_from_posted_secret = WFOCU_Core()->data->get( 'c_intent_secret_' . $intent_secret_from_posted, '', 'gateway' );
				if ( empty( $get_intent_id_from_posted_secret ) ) {
					$this->handle_api_error( __( 'Offer payment failed. Reason: Unable to find matching ID for the secret', 'funnelkit-stripe-woo-payment-gateway' ), 'Unable to find matching ID for the secret', $get_order, true );
				}

				$get_order->update_meta_data( '_fkwcs_localgateway_upsell_payment_intent', $get_intent_id_from_posted_secret );
				$get_order->save();
				$intent = $this->verify_intent();

				if ( $intent && ! empty( $intent->charges ) && ! empty( $intent->charges->data ) ) {
					$response = end( $intent->charges->data );
					if ( $response ) {
						WFOCU_Core()->data->set( '_transaction_id', $response->id );
						$this->update_stripe_fees( $get_order, is_string( $response->balance_transaction ) ? $response->balance_transaction : $response->balance_transaction->id );
						wp_send_json( array(
							'result'   => 'success',
							'response' => WFOCU_Core()->process_offer->_handle_upsell_charge( true ),
						) );
					}
				}
				$this->handle_api_error( __( 'Offer payment failed. Reason: Intent was not authenticated properly.', 'funnelkit-stripe-woo-payment-gateway' ), 'Intent was not authenticated properly.', $get_order, true );
			} else {
				try {
					$intent = $this->create_intent( $get_order, $source );

					if ( ! empty( $intent ) && isset( $intent->client_secret ) ) {
						WFOCU_Core()->data->set( 'c_intent_secret_' . $intent->client_secret, $intent->id, 'gateway' );
						WFOCU_Core()->data->save( 'gateway' );
					}

					if ( empty( $intent->error ) ) {
						$intent = $this->confirm_intent( $intent, $get_order );
					}

					if ( ! empty( $intent->error ) ) {
						$note = 'Offer payment failed. Reason: ';
						if ( isset( $intent->error->message ) && ! empty( $intent->error->message ) ) {
							$note .= $intent->error->message;
						}
						$this->handle_api_error( $note, $intent->error, $get_order, true );
					}

					if ( ! empty( $intent ) ) {
						$get_order->update_meta_data( '_fkwcs_source_id', $source->source );
						$get_order->set_payment_method( $gateway->id );
						$get_order->save();

						if ( ! empty( $intent->status ) && 'requires_action' === $intent->status ) {
							wp_send_json( array(
								'result'                          => 'success',
								'intent_secret'                   => $intent->client_secret,
								'multibanco_treat_all_as_success' => true
							) );
						}

						if ( ! empty( $intent->charges ) && ! empty( $intent->charges->data ) ) {
							$response = end( $intent->charges->data );
							if ( $response ) {
								WFOCU_Core()->data->set( '_transaction_id', $response->id );
								$this->update_stripe_fees( $get_order, is_string( $response->balance_transaction ) ? $response->balance_transaction : $response->balance_transaction->id );
							}
						}
					}
				} catch ( Exception $e ) {
					$this->handle_api_error( __( 'Offer payment failed. Reason: ' . $e->getMessage() . '', 'funnelkit-stripe-woo-payment-gateway' ), 'Error Captured: ' . print_r( $e->getMessage() . " <-- Generated on" . $e->getFile() . ":" . $e->getLine(), true ), $get_order, true );
				}
			}

			$data = WFOCU_Core()->process_offer->_handle_upsell_charge( true );

			wp_send_json( array(
				'result'   => 'success',
				'response' => $data,
			) );
		}

		/**
		 * Verify payment intent status and return intent object if valid.
		 *
		 * Retrieves payment intent from Stripe API using stored intent ID and validates
		 * the status. For Multibanco, accepts multiple valid statuses including
		 * requires_action (normal for voucher-based payments).
		 *
		 * @return object|false Payment intent object if valid, false otherwise
		 * @since 1.0.0
		 */
		public function verify_intent() {
			$order = WFOCU_Core()->data->get_parent_order();
			if ( ! $order ) {
				return false;
			}

			$payment_intent = $order->get_meta( '_fkwcs_localgateway_upsell_payment_intent' );
			if ( empty( $payment_intent ) ) {
				return false;
			}

			$gateway  = $this->get_wc_gateway();
			$client   = $gateway->get_client();
			$response = $client->payment_intents( 'retrieve', [ $payment_intent ] );
			$intent   = $gateway->handle_client_response( $response );

			if ( empty( $intent ) ) {
				return false;
			}

			$intent_status = isset( $intent->status ) ? $intent->status : '';
			if ( in_array( $intent_status, [ 'succeeded', 'requires_capture', 'processing', 'requires_action' ] ) ) {
				$this->current_intent = $intent;

				return $intent;
			}

			return false;
		}
		
		/**
		 * @param $status
		 * @param WC_Order $order
		 */
		public function replace_recorded_status_with_ipn_response( $status, $order ) {
			
			$get_meta = Helper::get_meta( wc_get_order( $order->get_id() ), '_wfocu_payment_complete_on_hold' );
			if($get_meta){
				return apply_filters( 'woocommerce_payment_complete_order_status', $order->needs_processing() ? 'processing' : 'completed', $order->get_id(), $order );
			}
			return $status;
		}

	}

	WFOCU_Plugin_Integration_Fkwcs_Multibanco::get_instance();
} 