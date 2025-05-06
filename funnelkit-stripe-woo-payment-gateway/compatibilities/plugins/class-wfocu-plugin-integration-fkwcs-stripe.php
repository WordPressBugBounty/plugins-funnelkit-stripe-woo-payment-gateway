<?php

use FKWCS\Gateway\Stripe\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WFOCU_Plugin_Integration_Fkwcs_Stripe' ) && class_exists( 'WFOCU_Gateway' ) ) {

	class WFOCU_Plugin_Integration_Fkwcs_Stripe extends WFOCU_Gateway {
		protected static $instance = null;
		public $key = 'fkwcs_stripe';
		public $token = false;
		public $current_intent;
		public $refund_supported = true;

		public $supports = [ 'no-gateway-upsells' ];

		public function __construct() {
			parent::__construct();

			/**
			 * tell the core gateway to tokenize the user and handle display of checkbox
			 */
			add_filter( 'fkwcs_stripe_display_save_payment_method_checkbox', array( $this, 'control_checkbox_visibility' ), 999 );


			/**
			 * Add payouts to new order
			 */
			add_action( 'wfocu_offer_new_order_created_fkwcs_stripe', array( $this, 'add_stripe_payouts_to_new_order' ), 10, 1 );


			/**
			 * Render JS script to handle client flow
			 */
			add_action( 'wfocu_footer_before_print_scripts', array( $this, 'maybe_render_in_offer_transaction_scripts' ), 999 );


			/**
			 * Add allowed actions
			 */
			add_filter( 'wfocu_allow_ajax_actions_for_charge_setup', array( $this, 'allow_check_action' ) );

			/**
			 * Save intent in the new order
			 */
			add_action( 'wfocu_offer_new_order_created_before_complete', array( $this, 'maybe_save_intent' ), 10 );

			/**
			 *
			 */
			add_filter( 'woocommerce_available_payment_gateways', array( $this, 'enable_gateway_for_the_zero_amount' ) );
			add_filter( 'woocommerce_cart_needs_payment', array( $this, 'cart_needs_payment' ), 10, 2 );

			add_action( 'wfocu_subscription_created_for_upsell', array( $this, 'save_stripe_source_to_subscription' ), 10, 3 );
			add_filter( 'wfocu_order_copy_meta_keys', array( $this, 'set_stripe_keys_to_copy' ), 10, 1 );

			add_action( 'wfocu_front_primary_order_cancelled', array( $this, 'remove_intent_meta_form_cancelled_order' ) );


			add_action( 'wc_ajax_wfocu_front_handle_fkwcs_stripe_create_payments', [ $this, 'create_payment' ] );
			add_action( 'fkwcs_webhook_payment_succeed', [ $this, 'maybe_save_webhook_status' ] );
			add_action( 'fkwcs_webhook_payment_on-hold', [ $this, 'maybe_save_webhook_status' ] );
			add_action( 'fkwcs_webhook_payment_failed', [ $this, 'maybe_save_webhook_status' ] );

			add_filter( 'wfocu_front_order_status_after_funnel', array( $this, 'replace_recorded_status_with_ipn_response' ), 10, 2 );


		}

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		public function control_checkbox_visibility( $is_show ) {
			if ( $this->should_tokenize() && $this->is_enabled() ) {
				return false;
			}

			return $is_show;
		}


		/**
		 * Try and get the payment token saved by the gateway
		 *
		 * @param WC_Order $order
		 *
		 * @return boolean on success false otherwise
		 */
		public function has_token( $order ) {
			$this->token = $this->get_wc_gateway()->get_order_stripe_data( '_fkwcs_source_id', $order );
			if ( ! empty( $this->token ) ) {
				return true;
			}

			return false;

		}


		/**
		 * Try and get the payment token saved by the gateway
		 *
		 * @param WC_Order $order
		 *
		 * @return boolean on success false otherwise
		 */
		public function get_token( $order ) {
			$this->token = $this->get_wc_gateway()->get_order_stripe_data( '_fkwcs_source_id', $order );
			if ( ! empty( $this->token ) ) {
				return $this->token;
			}

			return false;

		}


		/**
		 * Generate the request for the payment.
		 *
		 * @param WC_Order $order
		 * @param object $source
		 *
		 * @return array()
		 */
		protected function generate_payment_request( $order, $source ) {
			$get_package = WFOCU_Core()->data->get( '_upsell_package' );

			$gateway               = $this->get_wc_gateway();
			$post_data             = array();
			$post_data['currency'] = strtolower( $order->get_currency() );
			$total                 = Helper::get_stripe_amount( $get_package['total'], $post_data['currency'] );

			if ( $get_package['total'] * 100 < Helper::get_minimum_amount() ) {
				/* translators: 1) dollar amount */
				throw new \Exception( sprintf( __( 'Sorry, the minimum allowed order total is %1$s to use this payment method.', 'funnelkit-stripe-woo-payment-gateway' ), wc_price( Helper::get_minimum_amount() / 100 ) ), 101 ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$post_data['amount']      = $total;
			$post_data['description'] = sprintf( __( '%1$s - Order %2$s - 1 click upsell: %3$s', 'funnelkit-stripe-woo-payment-gateway' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number(), WFOCU_Core()->data->get( 'current_offer' ) );
			$post_data['capture']     = $gateway->capture_method ? 'true' : 'false';

			$billing_email = $order->get_billing_email();
			if ( Helper::should_customize_statement_descriptor() ) {
				$post_data['statement_descriptor_suffix'] = $gateway->clean_statement_descriptor( Helper::get_gateway_descriptor_suffix( $order ) );
			}
			if ( ! empty( $billing_email ) ) {
				$post_data['receipt_email'] = $billing_email;
			}


			$post_data['expand[]']              = 'balance_transaction';
			$post_data['metadata']              = apply_filters( 'wc_fkwcs_stripe_payment_metadata', $this->get_wc_gateway()->add_metadata( $order, $this->get_offer_items( $get_package ) ), $order, $source );
			$post_data['metadata']['fk_upsell'] = 'yes';
			if ( $source->customer ) {
				$post_data['customer'] = $source->customer;
			}

			if ( $source->source ) {

				$post_data['source'] = $source->source;
			}

			return apply_filters( 'fkwcs_upsell_stripe_generate_payment_request', $post_data, $get_package, $order, $source );
		}

		protected function create_intent( $order, $prepared_source ) {
			// The request for a charge contains metadata for the intent.
			$full_request = $this->generate_payment_request( $order, $prepared_source );
			$gateway      = $this->get_wc_gateway();

			$request = array(
				'payment_method'       => $prepared_source->source,
				'amount'               => $full_request['amount'],
				'currency'             => $full_request['currency'],
				'description'          => $full_request['description'],
				'metadata'             => $full_request['metadata'],
				'capture_method'       => ( 'true' === $full_request['capture'] ) ? 'automatic' : 'manual',
				'payment_method_types' => $gateway->get_payment_method_types(),
				'setup_future_usage'   => 'off_session',
			);
			if ( isset( $full_request['statement_descriptor_suffix'] ) ) {
				$request['statement_descriptor_suffix'] = $full_request['statement_descriptor_suffix'];
			}
			if ( $prepared_source->customer ) {
				$request['customer'] = $prepared_source->customer;
			}

			// Create an intent that awaits an action.
			$stripe_api = $gateway->get_client();
			$intent     = (object) $stripe_api->payment_intents( 'create', [ $request ] );

			if ( ! empty( $intent->error ) ) {
				WFOCU_Core()->log->log( 'Order #' . $order->get_id() . " - Offer payment intent create failed, Reason: " . print_r( $intent->error, true ) ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

				return $intent;
			}

			$order_id = $order->get_id();

			WFOCU_Core()->log->log( '#Order: ' . $order_id . ' Stripe payment intent created. ' . print_r( $intent, true ) ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			$this->current_intent = $intent;

			return $intent;
		}

		protected function confirm_intent( $intent, $order ) {
			if ( 'requires_confirmation' !== $intent->data->status ) {
				return $intent;
			}

			$gateway    = $this->get_wc_gateway();
			$stripe_api = $gateway->get_client();

			/**
			 * Check may mandate data required in confirm call
			 */
			$mandate_data = array();
			if ( method_exists( $gateway, 'maybe_mandate_data_required' ) ) {
				$mandate_data = $gateway->maybe_mandate_data_required( array(), $order );
			}

			if ( is_array( $mandate_data ) && count( $mandate_data ) > 0 ) {
				$c_intent = (object) $stripe_api->payment_intents( 'confirm', [ $intent->data->id, $mandate_data ] );
			} else {
				$c_intent = (object) $stripe_api->payment_intents( 'confirm', [ $intent->data->id ] );
			}

			if ( false === $c_intent->success ) {
				return $c_intent;
			}
			$confirmed_intent = $c_intent->data;


			// Save a note about the status of the intent.
			$order_id = $order->get_id();
			if ( 'succeeded' === $confirmed_intent->status ) {

				WFOCU_Core()->log->log( '#Order: ' . $order_id . 'Stripe PaymentIntent ' . $intent->data->id . ' succeeded for order' );

			} elseif ( 'requires_action' === $confirmed_intent->status ) {

				WFOCU_Core()->log->log( '#Order: ' . $order_id . " Stripe PaymentIntent" . $intent->data->id . " requires authentication for order" );
			} else {
				WFOCU_Core()->log->log( '#Order: ' . $order_id . " Stripe PaymentIntent" . $intent->data->id . " confirmIntent Response: " . print_r( $confirmed_intent, true ) ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			}
			$this->current_intent = $confirmed_intent;

			return $confirmed_intent;
		}

		public function update_stripe_fees( $order, $balance_transaction_id ) {
			$stripe              = $this->get_wc_gateway();
			$stripe_api          = $stripe->get_client();
			$response            = $stripe_api->balance_transactions( 'retrieve', [ $balance_transaction_id ] );
			$balance_transaction = $response['success'] ? $response['data'] : false;

			if ( $balance_transaction === false ) {
				return;
			}

			if ( isset( $balance_transaction ) && isset( $balance_transaction->fee ) ) {


				$fee      = ! empty( $balance_transaction->fee ) ? Helper::format_amount( $order->get_currency(), $balance_transaction->fee ) : 0;
				$net      = ! empty( $balance_transaction->net ) ? Helper::format_amount( $order->get_currency(), $balance_transaction->net ) : 0;
				$currency = ! empty( $balance_transaction->currency ) ? strtoupper( $balance_transaction->currency ) : null;

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
					$fee  = $fee + Helper::get_stripe_fee( $order );
					$net  = $net + Helper::get_stripe_net( $order );
					$data = [
						'fee'      => $fee,
						'net'      => $net,
						'currency' => $currency,
					];
					Helper::update_stripe_transaction_data( $order, $data );
				}
				WFOCU_Core()->data->set( 'wfocu_stripe_fee', $fee );
				WFOCU_Core()->data->set( 'wfocu_stripe_net', $net );
				WFOCU_Core()->data->set( 'wfocu_stripe_currency', $currency );
			}
		}

		/**
		 * @param $order WC_Order
		 *
		 * @return mixed|null
		 */
		protected function get_upe_elements( $order ) {
			$stripe  = $this->get_wc_gateway();
			$data    = array(
				"locale"                => $stripe->convert_wc_locale_to_stripe_locale( get_locale() ),
				"mode"                  => "payment",
				"paymentMethodCreation" => "manual",
				"currency"              => strtolower( $order->get_currency() ),
				"amount"                => Helper::get_formatted_amount( 1 ), //keeping it as sample
			);
			$methods = [ 'card' ];

			$data['payment_method_types'] = apply_filters( 'fkwcs_available_payment_element_types', $methods );
			$data['appearance']           = array(
				"theme" => "stripe"
			);
			$options                      = [
				'fields' => [
					'billingDetails' => 'never'
				]
			];
			$address_data                 = [
				'email' => $order->get_billing_email(),
				'name'  => $order->get_formatted_billing_full_name(),
			];

			$address_data['phone'] = $order->get_billing_phone();


			$address_data['address'] = [
				'line1'       => $order->get_billing_address_1(),
				'line2'       => $order->get_billing_address_2(),
				'city'        => $order->get_billing_city(),
				'postal_code' => $order->get_billing_postcode(),
				'country'     => $order->get_billing_country(),
				'state'       => $order->get_billing_state(),
			];

			return apply_filters( 'fkwcs_stripe_payment_element_data', [ 'element_data' => $data, 'element_options' => $options, 'billing_details' => $address_data ], $this );


		}


		public function allow_check_action( $actions ) {
			array_push( $actions, 'wfocu_front_handle_fkwcs_stripe_payments' );
			array_push( $actions, 'wfocu_front_handle_fkwcs_stripe_create_payments' );

			return $actions;
		}

		/**
		 * Handle API Error during the client integration
		 *
		 * @param $order_note string Order note to add
		 * @param $log string
		 * @param $order WC_Order
		 */
		public function handle_api_error( $order_note, $log, $order, $create_failed_order_or_stripe_error = false ) {


			/**
			 * This case tells us that some stripe error occured during the charge process from the credit card popup
			 */
			if ( $create_failed_order_or_stripe_error instanceof \Stripe\ErrorObject ) {
				$err_msg = 'Order #' . $order->get_id() . " - Upsell transaction Failed From the Credit Card Form. Failure Reason : " . $create_failed_order_or_stripe_error->message;
				WFOCU_Core()->log->log( $err_msg ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
				$order->add_order_note( $err_msg );
				wp_send_json( apply_filters( 'wfocu_modify_error_json_response', array(
					'result'   => 'error',
					'response' => $create_failed_order_or_stripe_error,
				), $order ) );
			}

			/**
			 * This case tells we attempted payment through token and failed
			 */
			if ( 3 === $create_failed_order_or_stripe_error ) {
				$order_note .= __( '</br> </br> Upsell recovery triggered - Showing Credit Card Form.', 'woofunnels-upstroke-one-click-upsell' );

				if ( method_exists( $this, 'format_failed_note' ) ) {
					$order_note = $this->format_failed_note( $order_note );

				}


				$order->add_order_note( $order_note );

				WFOCU_Core()->log->log( 'Order #' . $order->get_id() . " - Upsell transaction Failed Showing Credit Card Form" ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
				wp_send_json( apply_filters( 'wfocu_modify_error_json_response', array(
					'result'   => 'error',
					'response' => [ 'show_payment_options' => 'yes' ],
				), $order ) );
			}
			parent::handle_api_error( $order_note, $log, $order, $create_failed_order_or_stripe_error );


		}

		public function process_client_payment() {


			/**
			 * Prepare and populate client collected data to process further.
			 */
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

			$get_order = WFOCU_Core()->data->get_parent_order();

			$stripe = $this->get_wc_gateway();
			$source = $stripe->prepare_order_source( $get_order );

			$intent_from_posted = filter_input( INPUT_POST, 'intent', FILTER_SANITIZE_NUMBER_INT );

			/**
			 * If intent flag set found in the posted data from the client then it means we just need to verify the intent status
			 *
			 */
			if ( ! empty( $intent_from_posted ) ) {


				/**
				 * process response when user either failed or approve the auth.
				 */
				$intent_secret_from_posted = filter_input( INPUT_POST, 'intent_secret' );

				/**
				 * If not found the intent secret with the flag then fail, there could be few security issues
				 */
				if ( empty( $intent_secret_from_posted ) ) {
					$this->handle_api_error( esc_attr__( 'Offer payment failed. Reason: Intent secret missing from auth', 'funnelkit-stripe-woo-payment-gateway' ), 'Intent secret missing from auth', $get_order, true );
				}

				/**
				 * get intent ID from the data session
				 */
				$get_intent_id_from_posted_secret = WFOCU_Core()->data->get( 'c_intent_secret_' . $intent_secret_from_posted, '', 'gateway' );
				if ( empty( $get_intent_id_from_posted_secret ) ) {
					$this->handle_api_error( esc_attr__( 'Offer payment failed. Reason: Unable to find matching ID for the secret', 'funnelkit-stripe-woo-payment-gateway' ), 'Unable to find matching ID for the secret', $get_order, true );
				}

				/**
				 * Verify the intent from stripe API resource to check if its paid or not
				 */

				$intent = $this->verify_intent( $get_intent_id_from_posted_secret );
				if ( false !== $intent ) {
					$response = end( $intent->data->charges->data );
					WFOCU_Core()->data->set( '_transaction_id', $response->id );

					$get_order->update_meta_data( '_fkwcs_source_id', $source->source );
					$get_order->set_payment_method( $stripe->id );
					$get_order->save();
					$this->update_stripe_fees( $get_order, is_string( $response->balance_transaction ) ? $response->balance_transaction : $response->balance_transaction->id );
					wp_send_json( array(
						'result'   => 'success',
						'response' => WFOCU_Core()->process_offer->_handle_upsell_charge( true ),
					) );
				}
				$this->handle_api_error( esc_attr__( 'Offer payment failed. Reason: Intent was not authenticated properly.', 'funnelkit-stripe-woo-payment-gateway' ), 'Intent was not authenticated properly.', $get_order, true );

			} else {

				try {

					$intent = $this->create_intent( $get_order, $source );

				} catch ( \Exception|\Error $e ) {


					/**
					 * If error captured during charge process, then handle as failure
					 */

					// May be Show Credit card in case failed charge
					$this->handle_api_error( esc_attr__( 'Offer payment failed. Reason: ' . $e->getMessage() . '', 'funnelkit-stripe-woo-payment-gateway' ), 'Error Captured: ' . print_r( $e->getMessage() . " <-- Generated on" . $e->getFile() . ":" . $e->getLine(), true ), $get_order, 3 ); // @codingStandardsIgnoreLine

				}

				/**
				 * Save the is in the session
				 */
				if ( isset( $intent->data->client_secret ) ) {
					WFOCU_Core()->data->set( 'c_intent_secret_' . $intent->data->client_secret, $intent->data->id, 'gateway' );
				}

				WFOCU_Core()->data->save( 'gateway' );

				/**
				 * If all good, go ahead and confirm the intent
				 */
				if ( empty( $intent->error ) ) {
					$intent = $this->confirm_intent( $intent, $get_order );
				}
				if ( isset( $intent->success ) && false === $intent->success ) {
					$note = 'Offer payment failed. Reason: ';
					if ( isset( $intent->message ) && ! empty( $intent->message ) ) {
						$note .= $intent->message;
					}

					$this->handle_api_error( $note, $intent->message, $get_order, 3 );
				}


				/**
				 * Proceed and check intent status
				 */
				if ( ! empty( $intent ) ) {

					// If the intent requires a 3DS flow, redirect to it.
					if ( 'requires_action' === $intent->status ) {

						/**
						 * return intent_secret as the data to the client so that necessary next operations could have taken care.
						 */
						wp_send_json( array(
							'result'        => 'success',
							'intent_secret' => $intent->client_secret,
						) );

					}

					// Use the last charge within the intent to proceed.
					$response = end( $intent->charges->data );

					WFOCU_Core()->data->set( '_transaction_id', $response->id );
					$get_order->update_meta_data( '_fkwcs_source_id', $source->source );
					$get_order->set_payment_method( $stripe->id );
					$get_order->save();
					$this->update_stripe_fees( $get_order, is_string( $response->balance_transaction ) ? $response->balance_transaction : $response->balance_transaction->id );

				}
			}


			$data = WFOCU_Core()->process_offer->_handle_upsell_charge( true );

			wp_send_json( array(
				'result'   => 'success',
				'response' => $data,
			) );
		}

		public function create_payment() {

			/**
			 * Prepare and populate client collected data to process further.
			 */
			$get_current_offer      = WFOCU_Core()->data->get( 'current_offer' );
			$get_current_offer_meta = WFOCU_Core()->offers->get_offer_meta( $get_current_offer );
			WFOCU_Core()->data->set( '_offer_result', true );
			$posted_data = WFOCU_Core()->process_offer->parse_posted_data( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			$get_order = WFOCU_Core()->data->get_parent_order();
			WFOCU_Core()->log->log( 'Order #' . $get_order->get_id() . " - Start Processing Upsell Payment Using credit card fields" ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

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


			$stripe = $this->get_wc_gateway();


			try {
				$prepared_source = $stripe->prepare_source( $get_order, true );
				$intent          = $this->create_intent( $get_order, $prepared_source );

			} catch ( Exception $e ) {


				$stripe_errors = $stripe->get_client()->get_last_error();

				/**
				 * If error captured during charge process, then handle as failure
				 */
				$this->handle_api_error( esc_attr__( 'Offer payment failed. Reason: ' . $e->getMessage() . '', 'funnelkit-stripe-woo-payment-gateway' ), 'Error Captured: ' . print_r( $e->getMessage() . " <-- Generated on" . $e->getFile() . ":" . $e->getLine(), true ), $get_order, empty( $stripe_errors ) ? true : $stripe_errors ); // @codingStandardsIgnoreLine

			}

			/**
			 * Save the is in the session
			 */
			if ( isset( $intent->data->client_secret ) ) {
				WFOCU_Core()->data->set( 'c_intent_secret_' . $intent->data->client_secret, $intent->data->id, 'gateway' );
			}

			WFOCU_Core()->data->save( 'gateway' );

			/**
			 * If all good, go ahead and confirm the intent
			 */
			if ( empty( $intent->error ) ) {
				$intent = $this->confirm_intent( $intent, $get_order );
			}
			if ( isset( $intent->success ) && false === $intent->success ) {
				$note = 'Offer payment failed. Reason: ';
				if ( isset( $intent->message ) && ! empty( $intent->message ) ) {
					$note .= $intent->message;
				}
				$stripe_errors = $stripe->get_client()->get_last_error();
				$this->handle_api_error( $note, $intent->message, $get_order, empty( $stripe_errors ) ? true : $stripe_errors );
			}


			/**
			 * Proceed and check intent status
			 */
			if ( ! empty( $intent ) ) {
				$get_order->update_meta_data( '_fkwcs_source_id', $prepared_source->source );
				$get_order->set_payment_method( $stripe->id );
				$get_order->save();
				// If the intent requires a 3DS flow, redirect to it.
				if ( 'requires_action' === $intent->status ) {

					/**
					 * return intent_secret as the data to the client so that necessary next operations could have taken care.
					 */
					wp_send_json( array(
						'result'        => 'success',
						'intent_secret' => $intent->client_secret,
					) );

				}

				// Use the last charge within the intent to proceed.
				$response = end( $intent->charges->data );
				WFOCU_Core()->data->set( '_transaction_id', $response->id );
				$this->update_stripe_fees( $get_order, is_string( $response->balance_transaction ) ? $response->balance_transaction : $response->balance_transaction->id );

			}
			WFOCU_Core()->log->log( 'Order #' . $get_order->get_id() . " - Showing Next Offer after Processing Failed Upsell Payment Using credit card" ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			$data = WFOCU_Core()->process_offer->_handle_upsell_charge( true );

			wp_send_json( array(
				'result'   => 'success',
				'response' => $data,
			) );
		}

		public function verify_intent( $intent_id ) {
			$stripe     = $this->get_wc_gateway();
			$stripe_api = $stripe->get_client();
			$intent     = (object) $stripe_api->payment_intents( 'retrieve', [ $intent_id ] );

			if ( empty( $intent ) ) {
				return false;
			}
			if ( 'succeeded' === $intent->data->status || 'requires_capture' === $intent->data->status ) {
				$this->current_intent = $intent->data;

				return $intent;
			}

			return false;
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
			Helper::update_stripe_transaction_data( $order, $data );
			$order->save_meta_data();
		}


		public function maybe_save_intent( $order ) {

			if ( empty( $this->current_intent ) ) {
				return;
			}
			$this->get_wc_gateway()->save_intent_to_order( $order, $this->current_intent );
			$order->update_meta_data( '_fkwcs_stripe_charge_captured', 'yes' );

		}


		/**
		 * Filter gateways for the zero dollar cart, allow our gateway to come
		 *
		 * @param $gateways
		 *
		 * @return array
		 */
		public function enable_gateway_for_the_zero_amount( $gateways ) {

			if ( false === apply_filters( 'fkwcs_enable_gateway_for_zero', false ) ) {
				return $gateways;
			}
			if ( class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
				return $gateways;
			}

			if ( $this->should_tokenize() && $this->is_enabled() && ! is_null( WC()->cart ) && '0.00' === WC()->cart->get_total( 'edit' ) && isset( $gateways['fkwcs_stripe'] ) ) {
				$gateway_to_filter                 = [];
				$gateway_to_filter['fkwcs_stripe'] = $gateways['fkwcs_stripe'];

				return $gateway_to_filter;
			}

			return $gateways;
		}

		public function cart_needs_payment( $bool ) {
			if ( false === apply_filters( 'fkwcs_enable_gateway_for_zero', false ) ) {
				return $bool;
			}
			if ( $this->should_tokenize() && $this->is_enabled() ) {
				return true;
			}

			return $bool;
		}


		/**
		 * Save Subscription details
		 *
		 * @param WC_Subscription $subscription
		 * @param $key
		 * @param WC_Order $order
		 */
		public function save_stripe_source_to_subscription( $subscription, $key, $order ) {

			$source_id = Helper::get_meta( $order, '_fkwcs_source_id' );

			// For BW compat will remove in the future.
			if ( empty( $source_id ) ) {
				$source_id = Helper::get_meta( $order, '_fkwcs_card_id' );

				// Take this opportunity to update the key name.
				$subscription->update_meta_data( '_fkwcs_source_id', $source_id );
				$subscription->delete_meta_data( '_fkwcs_card_id' );
			}
			$customer_key = Helper::get_customer_key();

			$subscription->update_meta_data( $customer_key, Helper::get_meta( $order, $customer_key ) );
			$subscription->update_meta_data( '_fkwcs_source_id', $source_id );
			$subscription->save_meta_data();

		}

		public function set_stripe_keys_to_copy( $meta_keys ) {
			array_push( $meta_keys, '_fkwcs_customer_id', '_fkwcs_source_id' );

			return $meta_keys;
		}

		/**
		 * Handling refund offer request from admin
		 *
		 * @throws WC_Stripe_Exception
		 */
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

		public function get_offer_items( $package ) {
			$items = [];

			if ( empty( $package ) ) {
				return $items;
			}

			foreach ( $package['products'] as $item ) {
				$items[] = sprintf( '%s x %s', $item['data']->get_title(), $item['qty'] );


			};

			return $items;

		}


		/**
		 *
		 * Removed stripe meta for restricted any stripe process on upsell cancelled order
		 *
		 * @param $cancelled_order
		 *
		 * @return void
		 */
		public function remove_intent_meta_form_cancelled_order( $cancelled_order ) {
			if ( ! $cancelled_order instanceof WC_Order ) {
				return;
			}
			$cancelled_order->delete_meta_data( '_fkwcs_webhook_paid' );
			$cancelled_order->delete_meta_data( '_fkwcs_intent_id' );
			$cancelled_order->save_meta_data();
		}

		public function maybe_render_in_offer_transaction_scripts() {

			$order = WFOCU_Core()->data->get_current_order();

			if ( ! $order instanceof WC_Order ) {
				return;
			}

			/**
			 * If we have no payment method set & key is not stripe simply return from here
			 */
			if ( $order->get_payment_method() === '' && $this->key !== 'fkwcs_stripe' ) {
				return;
			}

			/**
			 * if we have payment method then only supports
			 * 1. Dynamic payment method from wc matches current key
			 * Will pass for  -
			 * fkwcs_stripe
			 * fkwcs_stripe_google_pay
			 * fkwcs_stripe_apple_pay
			 * stripe
			 * stripe_cc
			 */
			if ( $order->get_payment_method() !== '' && $this->key !== $order->get_payment_method() ) {
				return;
			}


			?>
            <script src="https://js.stripe.com/v3/?ver=3.0"></script> <?php //phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
            <script>
                (function ($) {
                    "use strict";

                    class ProcessUPEPayment {
                        constructor() {
                            this.swal = null;
                            this.is_valid_card = false;
                            this.process_submit_card = null;
                            this.bucket = null;
                            this.events();
                            this.payment_data =<?php echo wp_json_encode( $this->get_upe_elements( $order ) ); ?>;
                            this.credit_card_form_title = '<?php echo esc_js( WFOCU_Core()->funnels->get_funnel_option( 'upsell_failed_recovery_heading' ) ); ?>';
                            this.credit_card_failed_msg = '<?php echo esc_js( WFOCU_Core()->funnels->get_funnel_option( 'upsell_failed_recovery_btn_fail_msg' ) ); ?>';
                            this.element_data = this.payment_data.element_data;
                            this.element_options = this.payment_data.element_options;
                            this.billing_details = this.payment_data.billing_details;
                            this.elements = wfocuStripe.elements(this.element_data);
                            this.payment = this.elements.create('payment', this.element_options);
                            this.setup();
                            this.resolve = () => {
                            };
                            this.reject = () => {
                            };

                        }

                        events() {

                            this.zero_upsell = '<?php echo esc_js( $order->get_payment_method() );?>';
                            $(document).on('wfocuStripeOnAuthentication', this.stripeOnAuthentication.bind(this));

                            $(document).on('wfocu_external', (e, Bucket) => {
                                /**
                                 * Check if we need to mark inoffer transaction to prevent default behavior of page
                                 */
                                if (0 !== Bucket.getTotal()) {
                                    Bucket.inOfferTransaction = true;
                                    this.initCharge();
                                }
                            });

                            $(document).on('wfocuBucketConfirmationRendered', (e, Bucket) => {
                                this.bucket = Bucket;

                            });
                            $(document).on('wfocuBucketLinksConverted', function (e, Bucket) {
                                this.bucket = Bucket;
                            });

                        }

                        setup() {
                            let current_upe_gateway = 'cart';
                            let self = this;
                            this.payment.on('change', function (event) {
                                self.is_valid_card = event.complete;
                                current_upe_gateway = event.value.type;
                            });

                        }

                        showCreditCard(bucket, failedCase = false) {
                            const self = this;
                            this.bucket = bucket;
                            $('body').addClass('wfocu_credit_card_open');
                            bucket.swal.show({
                                'html': `<div class="wfocu_fkwcs_wrapper">
                                <div class="wfocu_fkwcs_error_div"></div>
                                <div class="wfocu_fkwcs_warning_div"></div>
                                <div class="wfocu_fkwcs_card_wrapper"></div>
                                </div>`,
                                'iconHtml': '',
                                'title': this.credit_card_form_title,
                                'reverseButtons': false,
                                'showCloseButton': true,
                                confirmButtonText: '<?php echo esc_js( WFOCU_Core()->funnels->get_funnel_option( 'upsell_failed_recovery_btn_text' ) ); ?>',
                                cancelButtonText: 'Close',
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
									`;
                                    el.appendChild(style);
                                    self.payment.mount('.wfocu_fkwcs_wrapper .wfocu_fkwcs_card_wrapper');
                                    const infoIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 21" fill="none">
										<path d="M10.0014 2.29883C14.6045 2.29883 18.336 6.03037 18.336 10.6335C18.336 15.2365 14.6045 18.9681 10.0014 18.9681C5.39829 18.9681 1.66675 15.2365 1.66675 10.6335C1.66675 6.03037 5.39829 2.29883 10.0014 2.29883ZM10.0014 3.54883C6.08864 3.54883 2.91675 6.72072 2.91675 10.6335C2.91675 14.5462 6.08864 17.7181 10.0014 17.7181C13.9141 17.7181 17.086 14.5462 17.086 10.6335C17.086 6.72072 13.9141 3.54883 10.0014 3.54883ZM9.99834 9.38265C10.3148 9.38244 10.5764 9.6174 10.618 9.92243L10.6237 10.0072L10.6267 14.5919C10.627 14.9371 10.3473 15.2171 10.0022 15.2173C9.68574 15.2175 9.42409 14.9826 9.38251 14.6775L9.37675 14.5927L9.37375 10.0081C9.37352 9.66288 9.65316 9.38287 9.99834 9.38265ZM10.0017 6.46784C10.4614 6.46784 10.834 6.84044 10.834 7.30006C10.834 7.75968 10.4614 8.13228 10.0017 8.13228C9.54213 8.13228 9.16953 7.75968 9.16953 7.30006C9.16953 6.84044 9.54213 6.46784 10.0017 6.46784Z" fill="#E15334"/>
										</svg>`;
                                    if (failedCase === true) {
                                        $('.wfocu_fkwcs_warning_div').addClass('is-visible');
                                        $('.wfocu_fkwcs_warning_div').html(infoIcon + self.credit_card_failed_msg);
                                    }
                                },
                                onClose: () => {
                                    this.bucket.HasEventRunning = false;
                                    $('body').removeClass('wfocu_credit_card_open');
                                },
                                preConfirm: () => {
                                    this.process_submit_card = this.submit_card();
                                    this.process_submit_card.catch((e) => {
                                    });
                                    if (false === this.is_valid_card) {
                                        return false;
                                    }
                                    $('.wfocu_fkwcs_error_div').removeClass('is-visible');
                                    $('.wfocu_fkwcs_error_div').html('');
                                    return new Promise((resolve, reject) => {
                                        this.resolve = function () {
                                            $('body').removeClass('wfocu_credit_card_open');
                                        };
                                        this.reject = reject;
                                        this.processPayment();
                                    });
                                }
                            });


                        }

                        processPayment() {
                            this.process_submit_card.then((payment_method) => {
                                this.create_intent(payment_method, this.bucket.getBucketSendData());
                            }).catch((error) => {
                                if (error) {
                                   console.log(error);
                                }
                            });
                        }

                        submit_card() {
                            return new Promise((resolve, reject) => {
                                let payment_submit = this.elements.submit();
                                payment_submit.then((response) => {
                                    wfocuStripe.createPaymentMethod({
                                        elements: this.elements,
                                        params: {
                                            billing_details: this.billing_details
                                        }
                                    }).then((result) => {
                                        if (result.error) {
                                            this.reject(result.error); // Pass error to this.reject()
                                            reject(result.error);  // Ensure the error is propagated
                                            return;
                                        }

                                        resolve(result.paymentMethod.id);
                                    }).catch((error) => {
                                        this.reject(error); // Pass error to this.reject()
                                        reject(error);  // Ensure the error is propagated
                                    });
                                }).catch((error) => {
                                    this.reject(error);  // Catch any errors from payment_submit
                                    reject(error);
                                });
                            });
                        }


                        /**
                         * this method triggered when intent setup using Payment Elements
                         * @param data
                         */
                        confirmUPEPayment(data) {
                            let self = this;
                            let confirm_data = {
                                'elements': this.elements,
                                'clientSecret': data.intent_secret,
                                'confirmParams': {
                                    return_url: wfocu_vars.order_received_url,
                                },
                                'redirect': 'if_required'
                            };
                            let handle_card = wfocuStripe.confirmPayment(confirm_data);
                            handle_card.then(function (response) {
                                if (response.error) {
                                    throw response.error;
                                }

                                if ('requires_capture' !== response.paymentIntent.status && 'succeeded' !== response.paymentIntent.status) {
                                    return;
                                }
                                $(document).trigger('wfocuStripeOnAuthentication', [response, true]);
                            });
                            handle_card.catch(function () {
                                self.reject();
                                $(document).trigger('wfocuStripeOnAuthentication', [false, false]);
                            });
                        }

                        /**
                         * this method triggered when intent setup using Card Field
                         * @param data
                         */
                        confirmCardPayments(data) {
                            let handle_card = wfocuStripe.confirmCardPayment(data.intent_secret);
                            handle_card.then(function (response) {
                                if (response.error) {
                                    throw response.error;
                                }
                                if ('requires_capture' !== response.paymentIntent.status && 'succeeded' !== response.paymentIntent.status) {
                                    return;
                                }
                                $(document).trigger('wfocuStripeOnAuthentication', [response, true]);
                            });
                            handle_card.catch(function (error) {
                                $(document).trigger('wfocuStripeOnAuthentication', [false, false]);

                            });
                        }

                        create_intent(payment_method, getBucketData) {
                            let postData = $.extend(getBucketData, {action: 'wfocu_front_handle_fkwcs_stripe_create_payments'});
                            postData.fkwcs_source = payment_method;
                            let action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'wfocu_front_handle_fkwcs_stripe_create_payments'), postData);
                            const self = this;
                            action.done((data) => {

                                /**
                                 * Process the response for the call to handle client stripe payments
                                 * first handle error state to show failure notice and redirect to thank you
                                 * */
                                if (data.result !== "success") {
                                    if (data.response?.type === 'card_error') {

                                        const infoIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 21" fill="none">
										<path d="M10.0014 2.29883C14.6045 2.29883 18.336 6.03037 18.336 10.6335C18.336 15.2365 14.6045 18.9681 10.0014 18.9681C5.39829 18.9681 1.66675 15.2365 1.66675 10.6335C1.66675 6.03037 5.39829 2.29883 10.0014 2.29883ZM10.0014 3.54883C6.08864 3.54883 2.91675 6.72072 2.91675 10.6335C2.91675 14.5462 6.08864 17.7181 10.0014 17.7181C13.9141 17.7181 17.086 14.5462 17.086 10.6335C17.086 6.72072 13.9141 3.54883 10.0014 3.54883ZM9.99834 9.38265C10.3148 9.38244 10.5764 9.6174 10.618 9.92243L10.6237 10.0072L10.6267 14.5919C10.627 14.9371 10.3473 15.2171 10.0022 15.2173C9.68574 15.2175 9.42409 14.9826 9.38251 14.6775L9.37675 14.5927L9.37375 10.0081C9.37352 9.66288 9.65316 9.38287 9.99834 9.38265ZM10.0017 6.46784C10.4614 6.46784 10.834 6.84044 10.834 7.30006C10.834 7.75968 10.4614 8.13228 10.0017 8.13228C9.54213 8.13228 9.16953 7.75968 9.16953 7.30006C9.16953 6.84044 9.54213 6.46784 10.0017 6.46784Z" fill="#E15334"/>
										</svg>`;
                                        $('.wfocu_fkwcs_error_div').addClass('is-visible');
                                        $('.wfocu_fkwcs_error_div').html(infoIcon + data.response.message);
                                        $('.wfocu_credit_card_open .wfocuswal-confirm').prop('disabled', false);
                                        return;
                                    }
                                    this.handleFailedCase(data, '');
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

                                        this.confirmUPEPayment(data);
                                        return;
                                    }
                                    /**
                                     * If code reaches here means it no longer require any authentication from the client and we process success
                                     * */

                                    this.bucket.swal.show({'html': this.bucket.successMessage});
                                    if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
                                        self.resolve();
                                        this.mayBeRedirect(data.response.redirect_url);
                                    } else {
                                        /** move to order received page */
                                        if (typeof wfocu_vars.order_received_url !== 'undefined') {
                                            self.reject();
                                            this.mayBeRedirect(wfocu_vars.order_received_url + '&ec=stripe_error' + strip_error, 0);
                                        }
                                    }
                                }
                            });
                            action.fail((data) => {
                                this.handleFailedCase(data, '3');
                            });
                        }

                        handleChargeFailed() {

                        }

                        handleFailedCase(data, strip_error = '') {

                            /**
                             * In case of failure of ajax, process failure
                             * */
                            this.bucket.swal.show({html: this.bucket.warningMessage});
                            if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
                                this.mayBeRedirect(data.response.redirect_url);

                            } else {
                                /** move to order received page */
                                if (typeof wfocu_vars.order_received_url !== 'undefined') {
                                    this.mayBeRedirect(wfocu_vars.order_received_url + '&ec=stripe_error' + strip_error, 0)
                                }
                            }
                        }

                        mayBeRedirect(url, timeout = 1500) {
                            setTimeout((url) => {
                                window.location = url;
                            }, timeout, url);
                        }


                        setBucket(bucket) {
                            this.bucket = bucket;
                        }


                        initCharge() {
                            let getBucketData = this.bucket.getBucketSendData();
                            if ('' === this.zero_upsell) {
                                this.showCreditCard(this.bucket);
                                return;
                            }


                            let postData = $.extend(getBucketData, {action: 'wfocu_front_handle_fkwcs_stripe_payments'});
                            let action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'wfocu_front_handle_fkwcs_stripe_payments'), postData);
                            action.done((data) => {

                                /**
                                 * Process the response for the call to handle client stripe payments
                                 * first handle error state to show failure notice and redirect to thank you
                                 * */
                                if (data.result !== "success") {

                                    if (typeof data.response !== "undefined" && data.response.hasOwnProperty('show_payment_options')) {
                                        this.showCreditCard(this.bucket, true);
                                        return;
                                    }
                                    this.bucket.swal.show({'html': this.bucket.warningMessage});
                                    /** move to order received page */
                                    if (typeof wfocu_vars.order_received_url !== 'undefined') {
                                        this.mayBeRedirect(wfocu_vars.order_received_url + '&ec=stripe_error', 0);
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
                                        this.confirmCardPayments(data);
                                        return;
                                    }
                                    /**
                                     * If code reaches here means it no longer require any authentication from the client and we process success
                                     * */
                                    this.bucket.swal.show({'html': this.bucket.successMessage});
                                    if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {
                                        this.mayBeRedirect(data.response.redirect_url);
                                    } else {
                                        /** move to order received page */
                                        if (typeof wfocu_vars.order_received_url !== 'undefined') {
                                            this.mayBeRedirect(wfocu_vars.order_received_url + '&ec=stripe_error2', 0);
                                        }
                                    }
                                }
                            });
                            action.fail((data) => {
                                this.handleFailedCase(data)
                            });
                        }

                        stripeOnAuthentication(e, response, is_success) {
                            console.trace();
                            let postData = {};
                            if (is_success) {
                                postData = $.extend(this.bucket.getBucketSendData(), {
                                    action: 'wfocu_front_handle_fkwcs_stripe_payments',
                                    intent: 1,
                                    intent_secret: response.paymentIntent.client_secret
                                });

                            } else {
                                postData = $.extend(this.bucket.getBucketSendData(), {action: 'wfocu_front_handle_fkwcs_stripe_payments', intent: 1, intent_secret: ''});
                            }
                            let action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'wfocu_front_handle_fkwcs_stripe_payments'), postData);
                            action.done((data) => {
                                if (data.result !== "success") {
                                    Bucket.swal.show({'html': Bucket.warningMessage});
                                } else {
                                    this.bucket.swal.show({'html': this.bucket.successMessage});
                                }
                                if (typeof data.response !== "undefined" && typeof data.response.redirect_url !== 'undefined') {

                                    this.mayBeRedirect(data.response.redirect_url);
                                } else {
                                    /** move to order received page */
                                    if (typeof wfocu_vars.order_received_url !== 'undefined') {
                                        this.mayBeRedirect(wfocu_vars.order_received_url + '&ec=stripe_error4');

                                    }
                                }
                            });
                        }

                    }

                    window.wfocuStripe = Stripe('<?php echo esc_js( $this->get_wc_gateway()->get_client_key() ); ?>');
                    let wfocu_upsell_payment = new ProcessUPEPayment();

                    $(document).on('wfocuBucketCreated', (e, Bucket) => {
                        wfocu_upsell_payment.setBucket(Bucket)
                    });


                })(jQuery);
            </script>
			<?php
		}

		/**
		 * @param WC_Order $order
		 *
		 * @return void
		 *
		 */
		public function maybe_save_webhook_status( $order ) {

			$current_action     = current_action();
			$is_case_of_webhook = Helper::get_meta( wc_get_order( $order->get_id() ), '_wfocu_payment_complete_on_hold' );

			if ( empty( $is_case_of_webhook ) ) {

				return;
			}

			if ( $current_action === 'fkwcs_webhook_payment_succeed' ) {
				$order->update_meta_data( 'wfocu_stripe_ipn_status', 'succeeded' );

			} elseif ( $current_action === 'fkwcs_webhook_payment_on-hold' ) {
				$order->update_meta_data( 'wfocu_stripe_ipn_status', 'on-hold' );

			} else {
				$order->update_meta_data( 'wfocu_stripe_ipn_status', 'failed' );

			}
			$order->save_meta_data();
		}


		/**
		 * @param $status
		 * @param WC_Order $order
		 */
		public function replace_recorded_status_with_ipn_response( $status, $order ) {

			$get_meta = Helper::get_meta( $order, 'wfocu_stripe_ipn_status' );

			if ( empty( $get_meta ) ) {
				return $status;
			}


			switch ( $get_meta ) {
				case 'succeeded':

					return apply_filters( 'woocommerce_payment_complete_order_status', $order->needs_processing() ? 'processing' : 'completed', $order->get_id(), $order );
				case 'on-hold':
					return 'on-hold';
				case 'failed':
				case 'Failed':
				case 'denied':
				case 'Denied':
				case 'Expired':
				case 'expired':
					return 'failed';

			}

			return $status;
		}

	}

	WFOCU_Plugin_Integration_Fkwcs_Stripe::get_instance();
}