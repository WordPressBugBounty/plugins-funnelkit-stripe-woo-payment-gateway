<?php

use FKWCS\Gateway\Stripe\Helper;

if ( class_exists( 'WFOCU_Gateway' ) ) {
	abstract class FKWCS_LocalGateway_Upsell extends WFOCU_Gateway {
		public $key = '';
		public $token = false;
		protected $stripe_verify_js_callback = '';
		protected $ajax_action = 'wfocu_front_handle_';
		protected $payment_method_type = '';
		protected $need_shipping_address = false;
		public $refund_supported = true;

		public function __construct() {
			parent::__construct();
			add_action( 'wfocu_footer_before_print_scripts', array( $this, 'maybe_render_in_offer_transaction_scripts' ), 999 );
			add_filter( 'wfocu_allow_ajax_actions_for_charge_setup', array( $this, 'allow_check_action' ) );
			add_action( 'wc_ajax_wfocu_front_handle_fkwcs_upsell_verify_intent', array( $this, 'verify_intent' ), 10 );
			add_action( 'wfocu_offer_new_order_created_' . $this->key, array( $this, 'add_stripe_payouts_to_new_order' ), 10, 1 );

		}

		public function ajax_action() {
			return $this->ajax_action . $this->key . '_localgateway_payment';
		}


		public function allow_check_action( $actions ) {
			array_push( $actions, $this->ajax_action() );
			array_push( $actions, 'wfocu_front_handle_fkwcs_upsell_verify_intent' );

			return $actions;
		}

		/**
		 * Try and get the payment token saved by the gateway
		 *
		 * @param WC_Order $order
		 *
		 * @return true on success false otherwise
		 */
		public function has_token( $order ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedParameter,VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

			return $this->token;

		}

		/**
		 * Tell the system to run without a token or not
		 * @return bool
		 */
		public function is_run_without_token() {
			return true;
		}
		/**
		 * If this gateway is used in the payment for the primary order that means we can run our funnels and we do not need to check for further enable.
		 * @return true
		 */
		/**
		 * @param WC_Order $order
		 *
		 * @return bool
		 */
		public function is_enabled( $order = false ) {

			$get_chosen_gateways = WFOCU_Core()->data->get_option( 'gateways' );
			if ( is_array( $get_chosen_gateways ) && in_array( $this->key, $get_chosen_gateways, true ) ) {

				return apply_filters( 'wfocu_front_payment_gateway_integration_enabled', true, $order );
			}

			return false;
		}

		public function process_charge( $order ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

			return $this->handle_result( true, '' );
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
			$data            = [
				'amount'               => Helper::get_formatted_amount( $offer_package['total'] ),
				'currency'             => $gateway->get_currency(),
				'description'          => sprintf( __( '%1$s - Order %2$s - 1 click upsell: %3$s', 'funnelkit-stripe-woo-payment-gateway' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number(), WFOCU_Core()->data->get( 'current_offer' ) ),
				'payment_method_types' => [ $this->payment_method_type ],
				'customer'             => $customer_id,
				'capture_method'       => $gateway->capture_method,
			];

			$data        = $gateway->set_shipping_data( $data, $order, $this->need_shipping_address );
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
				/**
				 * @see modify_successful_payment_result()
				 * This modifies the final response return in WooCommerce process checkout request
				 */
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
				$payment_Details = [
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

				$response = array(
					'result'         => 'success',
					'payment_method' => $payment_Details,
					'intent_secret'  => $intent_data->client_secret,
					'response'       => $upsell_charge_data,
				);

				wp_send_json( $response );
			} else {
				wp_send_json( array(
					'result'        => 'fail',
					'intent_secret' => '',
					'response'      => WFOCU_Core()->process_offer->_handle_upsell_charge( false ),
				) );

			}


		}

		public function process_refund_offer( $order ) {
			$refund_data = wc_clean( $_POST );  // phpcs:ignore WordPress.Security.NonceVerification.Missing

			$txn_id        = isset( $refund_data['txn_id'] ) ? $refund_data['txn_id'] : '';
			$amnt          = isset( $refund_data['amt'] ) ? $refund_data['amt'] : '';
			$refund_reason = isset( $refund_data['refund_reason'] ) ? $refund_data['refund_reason'] : '';

			$order_currency = WFOCU_WC_Compatibility::get_order_currency( $order );

			$get_client     = $this->get_wc_gateway()->set_client_by_order_payment_mode( $order );
			$client_details = $get_client->get_clients_details();


			$refund_data = [
				'amount'   => Helper::get_stripe_amount( $amnt, $order_currency ),
				'reason'   => 'requested_by_customer',
				'metadata' => [
					'customer_ip'       => $client_details['ip'],
					'agent'             => $client_details['agent'],
					'referer'           => $client_details['referer'],
					'reason_for_refund' => $refund_reason,
				],
			];
			if ( 0 === strpos( $txn_id, 'pi_' ) ) {

				$refund_data['payment_intent'] = $txn_id;

			} else {

				$refund_data['charge'] = $txn_id;

			}
			$refund_params = apply_filters( 'fkwcs_refund_request_args', $refund_data );

			$response = $this->get_wc_gateway()->execute_refunds( $refund_params, $get_client );


			$refund_response = $response['success'] ? $response['data'] : false;
			if ( $refund_response ) {
				if ( isset( $refund_response->balance_transaction ) ) {
					Helper::update_balance( $order, $refund_response->balance_transaction, true );

					return isset( $refund_response->id ) ? $refund_response->id : true;
				}

				return true;

			} else {
				$order->add_order_note( __( 'Reason : ', 'funnelkit-stripe-woo-payment-gateway' ) . $refund_reason . '.<br>' . __( 'Amount : ', 'funnelkit-stripe-woo-payment-gateway' ) . get_woocommerce_currency_symbol() . $amnt . '.<br>' . __( ' Status : Failed ', 'funnelkit-stripe-woo-payment-gateway' ) );
				Helper::log( $response['message'] );

				return false;
			}


		}

		/**
		 * @param WC_Order $order
		 * @param $balance_transaction_id
		 *
		 * @return void
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

				/**
				 * Handling for Stripe Fees
				 */
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
					$fee = $fee + Helper::get_stripe_fee( $order );
					$net = $net + Helper::get_stripe_net( $order );

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


		public function verify_intent() {

			try {
				if ( false === WFOCU_Core()->data->has_valid_session() ) {
					return;
				}
				/**
				 * Setting up necessary data for this api call
				 */
				add_filter( 'wfocu_valid_state_for_data_setup', '__return_true' );
				WFOCU_Core()->template_loader->set_offer_id( WFOCU_Core()->data->get_current_offer() );
				$offer = WFOCU_Core()->data->get_current_offer();
				if ( empty( $offer ) ) {
					throw new Exception( 'Offer not found' );
				}
				WFOCU_Core()->template_loader->maybe_setup_offer();

				$existing_package = WFOCU_Core()->data->get( 'upsell_package', '', 'gateway' );
				WFOCU_Core()->data->set( '_upsell_package', $existing_package );


				$order_id       = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$order          = wc_get_order( $order_id );
				$payment_intent = $order->get_meta( '_fkwcs_localgateway_upsell_payment_intent' );
				if ( empty( $payment_intent ) ) {
					throw new Exception( 'Payment intent not found' );
				}
				$gateway  = $this->get_wc_gateway();
				$client   = $gateway->get_client();
				$response = $client->payment_intents( 'retrieve', [ $payment_intent ] );
				$intent   = $gateway->handle_client_response( $response );
				if ( 'payment_intent' === $intent->object && 'succeeded' === $intent->status ) {
					// Remove cart.
					$response = end( $intent->charges->data );
					WFOCU_Core()->data->set( '_transaction_id', $response->id );

					$upsell_charge_data = WFOCU_Core()->process_offer->_handle_upsell_charge( true );
					$redirect_url       = $upsell_charge_data['redirect_url'];

					$this->update_stripe_fees( $order, is_string( $response->balance_transaction ) ? $response->balance_transaction : $response->balance_transaction->id );

				} else {
					throw new Exception( 'Payment intent not verified' );
				}

			} catch ( Exception $e ) {
				/* translators: Error message text */
				$upsell_charge_data = WFOCU_Core()->process_offer->_handle_upsell_charge( false );
				$redirect_url       = $upsell_charge_data['redirect_url'];
			}
			wp_safe_redirect( $redirect_url );
			exit;
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
            <script src="https://js.stripe.com/v3/?ver=3.0" data-cookieconsent="ignore"></script> <?php //phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>

            <script>
                (function ($) {
                    "use strict";
                    let wfocuStripe = Stripe('<?php echo esc_js( $this->get_wc_gateway()->get_client_key() ); ?>');
                    let stripeHandleconfirmCallBack = '<?php echo esc_js( $this->stripe_verify_js_callback ) ?>';
                    let homeURL = '<?php echo esc_url( site_url() )?>';
                    let ajax_link = '<?php echo esc_attr( $this->ajax_action() )?>';
                    let wfocuStripeJS = {
                        bucket: null,
                        initCharge: function () {
                            let getBucketData = this.bucket.getBucketSendData();
                            let postData = $.extend(getBucketData, {action: ajax_link, 'fkwcs_gateway': '<?php echo esc_js( $this->get_key() ) ?>'});
                            let action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', ajax_link), postData);
                            action.done(function (data) {
                                console.log(JSON.stringify(data));
                                /**
                                 * Process the response for the call to handle client stripe payments
                                 * first handle error state to show failure notice and redirect to thank you
                                 * */
                                if (data.result !== "success") {

                                    wfocuStripeJS.bucket.swal.show({'text': wfocu_vars.messages.offer_msg_pop_failure, 'type': 'warning'});
                                    if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
                                        setTimeout(function () {
                                            window.location = data.response.redirect_url;
                                        }, 1500);
                                    } else {
                                        /** move to order received page */
                                        if (typeof wfocu_vars.order_received_url !== 'undefined') {
                                            window.location = wfocu_vars.order_received_url + '&ec=fkwcs_stripe_error';

                                        }
                                    }
                                } else {

                                    /**
                                     * There could be two states --
                                     * 1. intent confirmed
                                     * 2. requires action
                                     * */
                                    /**
                                     * handle scenario when authentication requires for the payment intent
                                     * In this case we need to trigger stripe payment intent popups
                                     * */
                                    if (typeof data.intent_secret !== "undefined" && '' !== data.intent_secret) {

                                        /**
                                         * Stripe doesn't accept data payment method data to be blank assigned it undefined instead
                                         */
                                        for (let key in data.payment_method['billing_details']['address']) {
                                            if (data.payment_method['billing_details']['address'].hasOwnProperty(key) && (data.payment_method['billing_details']['address'][key] === null || data.payment_method['billing_details']['address'][key] === '')) {
                                                data.payment_method['billing_details']['address'][key] = undefined;
                                            }
                                        }
                                        data.payment_method['billing_details']['name'] = data.payment_method['billing_details']['name'] === '' ? undefined : data.payment_method['billing_details']['name'];
                                        data.payment_method['billing_details']['email'] = data.payment_method['billing_details']['email'] === '' ? undefined : data.payment_method['billing_details']['email'];

                                        let payment_options = {
                                            payment_method: data.payment_method,
                                            return_url: homeURL + data.response.redirect_url,
                                        };


                                        wfocuStripe[stripeHandleconfirmCallBack](data.intent_secret, payment_options).then((response) => {
                                            if (response.error) {
                                                throw response.error;
                                            }
                                            $(document).trigger('fkwcs_stripe_localgateway_upsell_on_authentication', [response, true]);
                                        }).catch(() => {
                                            wfocuStripeJS.bucket.swal.show({'text': wfocu_vars.messages.offer_msg_pop_failure, 'type': 'warning'});

                                            setTimeout(function () {
                                                window.location = data.response.redirect_url;
                                            }, 1500);

                                        });
                                        return;
                                    }
                                    /**
                                     * If code reaches here means it no longer require any authentication from the client and we process success
                                     * */

                                    wfocuStripeJS.bucket.swal.show({'text': wfocu_vars.messages.offer_success_message_pop, 'type': 'success'});
                                    if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {

                                        setTimeout(function () {
                                            window.location = data.response.redirect_url;
                                        }, 1500);
                                    } else {
                                        /** move to order received page */
                                        if (typeof wfocu_vars.order_received_url !== 'undefined') {

                                            window.location = wfocu_vars.order_received_url + '&ec=stripe_error';

                                        }
                                    }
                                }
                            });
                            action.fail(function (data) {
                                console.log(JSON.stringify(data));
                                /**
                                 * In case of failure of ajax, process failure
                                 * */
                                wfocuStripeJS.bucket.swal.show({'text': wfocu_vars.messages.offer_msg_pop_failure, 'type': 'warning'});
                                if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
                                    setTimeout(function () {
                                        window.location = data.response.redirect_url;
                                    }, 1500);
                                } else {
                                    /** move to order received page */
                                    if (typeof wfocu_vars.order_received_url !== 'undefined') {
                                        window.location = wfocu_vars.order_received_url + '&ec=stripe_error';
                                    }
                                }
                            });
                        }
                    };


                    /**
                     * Save the bucket instance at several
                     */
                    $(document).on('wfocuBucketCreated', function (e, Bucket) {
                        wfocuStripeJS.bucket = Bucket;

                    });
                    $(document).on('wfocu_external', function (e, Bucket) {
                        /**
                         * Check if we need to mark inoffer transaction to prevent default behavior of page
                         */
                        if (0 !== Bucket.getTotal()) {
                            Bucket.inOfferTransaction = true;
                            wfocuStripeJS.initCharge();
                        }
                    });

                    $(document).on('wfocuBucketConfirmationRendered', function (e, Bucket) {
                        wfocuStripeJS.bucket = Bucket;

                    });
                    $(document).on('wfocuBucketLinksConverted', function (e, Bucket) {
                        wfocuStripeJS.bucket = Bucket;

                    });
                })(jQuery);
            </script>
			<?php
		}

		/**
		 * Add payout inf to the order created by upsell accept
		 *
		 * @param WC_Order $order
		 * @param Integer $transaction
		 */
		public function add_stripe_payouts_to_new_order( $order ) {

			$data        = [];
			$data['fee'] = WFOCU_Core()->data->get( 'wfocu_stripe_fee' );
			$data['net'] = WFOCU_Core()->data->get( 'wfocu_stripe_net' );

			$data['currency'] = WFOCU_Core()->data->get( 'wfocu_stripe_currency' );
			$data['exchange'] = WFOCU_Core()->data->get( 'wfocu_stripe_exchange' );
			Helper::update_stripe_transaction_data( $order, $data );
			$order->save_meta_data();
		}
	}
}