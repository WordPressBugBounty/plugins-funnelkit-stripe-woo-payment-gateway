<?php

use FKWCS\Gateway\Stripe\Helper;

if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Pix' ) && class_exists( 'WFOCU_Gateway' ) ) {
	class WFOCU_Plugin_Integration_Fkwcs_Pix extends FKWCS_LocalGateway_Upsell {
		protected static $instance = null;
		public $key = 'fkwcs_stripe_pix';
		protected $payment_method_type = 'pix';
		protected $stripe_verify_js_callback = 'confirmPixPayment';

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
			$get_current_offer      = WFOCU_Core()->data->get( 'current_offer' );
			$get_current_offer_meta = WFOCU_Core()->offers->get_offer_meta( $get_current_offer );
			WFOCU_Core()->data->set( '_offer_result', true );
			$posted_data = WFOCU_Core()->process_offer->parse_posted_data( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			/**
			 * return if found error in the charge request
			 */
			if ( false === WFOCU_AJAX_Controller::validate_charge_request( $posted_data ) ) {
				wp_send_json( array(
					'result' => 'error',
				) );
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

			// Get saved PIX tax ID (should always exist for upsells)
			$saved_pix_tax_id = $order->get_meta( '_fkwcs_pix_tax_id' );

			$data = [
				'amount'               => Helper::get_formatted_amount( $offer_package['total'] ),
				'currency'             => $gateway->get_currency(),
				'description'          => sprintf( __( '%1$s - Order %2$s - 1 click upsell: %3$s', 'funnelkit-stripe-woo-payment-gateway' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number(), WFOCU_Core()->data->get( 'current_offer' ) ),
				'payment_method_types' => [ $this->payment_method_type ],
				'customer'             => $customer_id,
				'capture_method'       => $gateway->capture_method,
			];

			if ( $order->has_shipping_address() ) {
				$data['shipping'] = [
					'name'    => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
					'address' => [
						'line1'       => $order->get_shipping_address_1(),
						'city'        => $order->get_shipping_city(),
						'postal_code' => $order->get_shipping_postcode(),
						'state'       => $order->get_shipping_state(),
						'country'     => $order->get_shipping_country(),
					]
				];
			}

			$stripe_api  = $gateway->get_client();
			$args        = apply_filters( 'fkwcs_payment_intent_data', $data, $order );
			$args        = [
				[ $args ],
				[ 'idempotency_key' => $idempotency_key ],
			];
			$response    = $stripe_api->payment_intents( 'create', $args );
			$intent_data = $gateway->handle_client_response( $response );

			Helper::log( sprintf( __( 'Begin processing payment with %s for order %s for the amount of %s', 'funnelkit-stripe-woo-payment-gateway' ), $order->get_payment_method_title(), $order->get_id(), $order->get_total() ) );

			if ( $intent_data ) {
				$output                             = [
					'order'     => $order->get_id(),
					'order_key' => $order->get_order_key(),
					'gateway'   => $this->key,
				];
				$upsell_charge_data                 = [];
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

				// Check payment method type and handle accordingly
				if ( $this->payment_method_type === 'pix' ) {
					if ( ! empty( $saved_pix_tax_id ) ) {
						// PIX with saved tax ID - direct payment
						$response['pix_payment_method'] = [
							'type'            => 'pix',
							'billing_details' => [
								'name'   => trim( $order->get_formatted_billing_full_name() ),
								'email'  => $order->get_billing_email(),
								'tax_id' => $saved_pix_tax_id
							]
						];
						Helper::log( 'Using saved PIX tax ID for upsell: ' . $saved_pix_tax_id . ' for order ' . $order->get_id() );
					} else {
						// This shouldn't happen for upsells, but fallback to error
						Helper::log( 'ERROR: No saved PIX tax ID found for upsell on order ' . $order->get_id() );
						wp_send_json( array(
							'result'  => 'error',
							'message' => 'PIX tax ID not found. Please contact support.',
						) );

						return;
					}
				} else {
					// Non-PIX payments (cards, etc.)
					$payment_Details            = [
						'billing_details' => [
							'name'    => trim( $order->get_formatted_billing_full_name() ),
							'email'   => $order->get_billing_email(),
							'address' => [
								'line1'       => $order->get_billing_address_1(),
								'state'       => $order->get_billing_state(),
								'country'     => $order->get_billing_country(),
								'city'        => $order->get_billing_city(),
								'postal_code' => $order->get_billing_postcode(),
							]
						]
					];
					$response['payment_method'] = $payment_Details;
				}

				// Add payment method type to help frontend identify payment type
				$response['payment_method_type'] = $this->payment_method_type;

				wp_send_json( $response );
			} else {
				wp_send_json( array(
					'result'        => 'fail',
					'intent_secret' => '',
					'response'      => WFOCU_Core()->process_offer->_handle_upsell_charge( false ),
				) );
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
			?>
            <script src="https://js.stripe.com/v3/?ver=3.0" data-cookieconsent="ignore"></script>

            <script>
                (function ($) {
                    "use strict";

                    function initializeStripePayment() {
                        let wfocuStripe = Stripe('<?php echo esc_js( $this->get_wc_gateway()->get_client_key() ); ?>');
                        let homeURL = '<?php echo esc_url( site_url() )?>';
                        let ajax_link = '<?php echo esc_attr( $this->ajax_action() )?>';

                        let wfocuStripeJS = {
                            bucket: null,

                            initCharge: function () {
                                let getBucketData = this.bucket.getBucketSendData();
                                let postData = $.extend(getBucketData, {action: ajax_link, 'fkwcs_gateway': '<?php echo esc_js( $this->get_key() ) ?>'});
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

                                            if (data.pix_payment_method && data.payment_method_type === 'pix') {
                                                wfocuStripe.confirmPixPayment(data.intent_secret, {
                                                    payment_method: data.pix_payment_method,
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
                                                                window.location = wfocu_vars.order_received_url + '&ec=pix_failed';
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
                                                                window.location = wfocu_vars.order_received_url + '&ec=pix_pending';
                                                            }
                                                        }, 2000);
                                                    }

                                                }).catch((error) => {
                                                    console.log('PIX payment exception:', error);
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

                        $(document).off('wfocuBucketCreated.pixStripe');
                        $(document).off('wfocu_external.pixStripe');
                        $(document).off('wfocuBucketConfirmationRendered.pixStripe');
                        $(document).off('wfocuBucketLinksConverted.pixStripe');
                        // Event handlers
                        $(document).on('wfocuBucketCreated.pixStripe', function (e, Bucket) {
                            wfocuStripeJS.bucket = Bucket;
                        });
                        $(document).on('wfocu_external.pixStripe', function (e, Bucket) {
                            if (0 !== Bucket.getTotal()) {
                                wfocuStripeJS.bucket = Bucket;
                                Bucket.inOfferTransaction = true;
                                wfocuStripeJS.initCharge();
                            }
                        });
                        $(document).on('wfocuBucketConfirmationRendered.pixStripe', function (e, Bucket) {
                            wfocuStripeJS.bucket = Bucket;
                        });
                        $(document).on('wfocuBucketLinksConverted.pixStripe', function (e, Bucket) {
                            wfocuStripeJS.bucket = Bucket;
                        });
                        // Store globally
                        window.wfocuStripeJS = wfocuStripeJS;
                    }

                    $(document).ready(function () {
                        if (typeof Stripe !== 'undefined') {
                            initializeStripePayment();
                            window.fkwcsPixStripeInitialized = true;
                        } else {
                            console.log('Stripe not ready on DOM ready, waiting for window load...');
                        }
                    });

                    $(window).on('load', function () {
                        if (!window.fkwcsPixStripeInitialized) {
                            if (typeof Stripe !== 'undefined') {
                                initializeStripePayment();
                                window.fkwcsPixStripeInitialized = true;
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

	WFOCU_Plugin_Integration_Fkwcs_Pix::get_instance();
}