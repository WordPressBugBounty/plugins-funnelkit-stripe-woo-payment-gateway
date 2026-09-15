<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Amazon Pay express checkout wallet.
 *
 * Rendered as a Stripe Express Checkout Element button (Aero checkout bridge + smart-button
 * controller). Payment is completed the same way the official Stripe plugin does it for the
 * Express Checkout Element: the browser calls stripe.createConfirmationToken({elements}) and
 * the server creates + confirms the PaymentIntent with that confirmation_token. Amazon Pay is
 * a redirect method, so the confirm returns requires_action -> redirect_to_url.
 *
 * Also shows as a regular payment-method option (the "Show as Regular Payment Gateway" display
 * location) mirroring Apple Pay: when selected, Place Order is hidden and the wallet button mounts
 * via the FKWCS_AmazonPay JS handler. Upsell and subscription support are wired separately.
 *
 * @since 1.14.0.4
 */
#[\AllowDynamicProperties]
class AmazonPay extends CreditCard {

	/**
	 * Singleton instance.
	 *
	 * @var AmazonPay|null
	 */
	private static $instance = null;

	/**
	 * Gateway id.
	 *
	 * @var string
	 */
	public $id = 'fkwcs_stripe_amazon_pay';

	/**
	 * Stripe payment method type.
	 *
	 * @var string
	 */
	public $payment_method_types = 'amazon_pay';

	private $place_order_wrapper_rendered = false;


	/**
	 * Get the singleton instance of the gateway.
	 *
	 * @return AmazonPay
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->set_api_keys();
		$this->init_supports();
		$this->init();
		// Register subscription renewal hooks so the saved Amazon mandate can be charged off-session.
		$this->maybe_init_subscriptions();
		// Express-only for now: keep available during the express smart-button AJAX, hide on the
		// normal checkout payment-method list (it would otherwise render an inert card form).
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'maybe_hide_from_checkout_list' ) );
		add_action( 'woocommerce_review_order_after_submit', array( $this, 'render_wrapper' ) );
		// FunnelKit Aero checkout uses its own payment template, which fires this hook instead of the
		// standard one above (mutually exclusive per template), so the wallet button mounts there too.
		add_action( 'wfacp_woocommerce_review_order_after_submit', array( $this, 'render_wrapper' ) );

		// The order-pay page (checkout/form-pay.php) does not fire the review-order hook above,
		// so also render the ECE wrapper after its own submit button — otherwise Amazon Pay has
		// no surface to mount on and cannot pay the existing order.
		add_action( 'woocommerce_pay_order_after_submit', array( $this, 'render_wrapper' ) );
		add_filter( 'fkwcs_localized_data', array( $this, 'localize_element_data' ), 999 );
	}

	/**
	 * Registers supported features for the gateway.
	 *
	 * Unlike Apple Pay / Google Pay (whose wallet wraps a card payment method) Amazon Pay has no
	 * underlying card object, so it deliberately omits 'tokenization' / 'add_payment_method' — the
	 * saved mandate is reused via the _fkwcs_source_id order meta, not a WC_Payment_Token.
	 * maybe_init_subscriptions() adds the subscription_* feature flags on top of this.
	 *
	 * @return void
	 */
	public function init_supports() {
		$this->supports = apply_filters(
			'fkwcs_amazon_pay_payment_supports',
			array_merge(
				$this->supports,
				array(
					'products',
					'refunds',
				)
			)
		);
	}

	/**
	 * Setup general properties and settings.
	 *
	 * @return void
	 */
	protected function init() {
		$this->has_fields = true;
		$this->init_form_fields();
		$this->init_settings();
		$this->method_title       = __( 'Amazon Pay', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Enable Amazon Pay as an express checkout button. Customers pay securely using the payment and shipping details already saved in their Amazon account.', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle           = __( 'Amazon Pay lets customers pay securely using the details already saved in their Amazon account', 'funnelkit-stripe-woo-payment-gateway' );
		$this->title              = $this->get_option( 'title' );
		$this->description        = $this->get_option( 'description' );
		$this->capture_method     = $this->get_option( 'charge_type', 'automatic' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Keep Amazon Pay out of the normal checkout payment-method list. It stays available during
	 * the express smart-button AJAX (which posts payment_request_type=amazon_pay) so process_checkout
	 * can route it.
	 *
	 * @param array $gateways Available gateways.
	 *
	 * @return array
	 */
	public function maybe_hide_from_checkout_list( $gateways ) {
		// Amazon Pay shows as a regular gateway on checkout and the subscription change-payment-method
		// page, but is unsupported on the "Add payment method" page (no order to authorize against).
		if ( is_add_payment_method_page() && isset( $gateways[ $this->id ] ) ) {
			unset( $gateways[ $this->id ] );
		}

		return $gateways;
	}

	/**
	 * Renders the gateway fields shown when Amazon Pay is selected in the regular payment list.
	 *
	 * Amazon Pay needs the customer to authorize through the Amazon Express Checkout Element (which
	 * yields the confirmation token), so we render that same button here instead of relying on the
	 * standard Place Order button. The JS mounts the wallet button into this container.
	 *
	 * @return void
	 */
	public function payment_fields() {
		do_action( $this->id . '_before_payment_field_checkout' );
		include __DIR__ . '/parts/amazon_pay.php';
		do_action( $this->id . '_after_payment_field_checkout' );
	}

	/**
	 * Renders the wallet button mount point after the Place Order button.
	 *
	 * Mirrors Apple Pay: the JS mounts the Amazon Express Checkout Element into this container and
	 * hides the standard Place Order button while Amazon Pay is the selected gateway.
	 *
	 * @return void
	 */
	public function render_wrapper() {
		// action. Guard against a duplicate wrapper so the ECE mounts to a single element.
		if ( $this->place_order_wrapper_rendered ) {
			return;
		}
		$this->place_order_wrapper_rendered = true;
		echo "<div class='fkwcs_stripe_amazon_pay_button'></div>";
	}

	/**
	 * Localize Amazon Pay settings for JS.
	 *
	 * Exposes the chosen display locations so the JS can detect the "Show as Regular Payment Gateway"
	 * option (mirrors Apple Pay's apple_pay_positions).
	 *
	 * @param array $localize_data
	 *
	 * @return array
	 */
	public function localize_element_data( $localize_data ) {
		$localize_data['amazon_pay_positions'] = $this->settings['display_locations'];

		// The regular-gateway Express Checkout Element arms its amount from the
		// fkwcs_cart_details fragment, which is never emitted on the order-pay page
		// (no cart / fragment refresh) — leaving the element on its placeholder amount.
		// Localize the existing order total here so the JS can arm the ECE once on init.
		// Shape mirrors SmartButtons::merge_cart_details() / Apple Pay's order-pay data.
		global $wp;
		if ( ! empty( $wp->query_vars['order-pay'] ) ) {
			$order = wc_get_order( absint( $wp->query_vars['order-pay'] ) );
			if ( $order instanceof \WC_Order ) {
				$localize_data['fkwcs_amazon_pay_order_pay_data'] = array(
					'order_data' => array(
						'currency' => strtolower( $order->get_currency() ),
						'total'    => array(
							'amount' => max( 0, (int) apply_filters( 'fkwcs_stripe_calculated_total', Helper::get_stripe_amount( $order->get_total(), $order->get_currency() ), $order->get_total() ) ),
						),
					),
				);
			}
		}

		return $localize_data;
	}

	/**
	 * Initialise gateway settings form fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = apply_filters(
			'fkwcs_amazon_pay_payment_form_fields',
			array(
				'enabled'                => array(
					'label'   => ' ',
					'type'    => 'checkbox',
					'title'   => __( 'Enable Amazon Pay', 'funnelkit-stripe-woo-payment-gateway' ),
					'default' => 'no',
				),
				'display_locations'      => array(
					'title'   => __( 'Display Locations', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'    => 'multiselect',
					'options' => array(
						'product'         => __( 'Product Page', 'funnelkit-stripe-woo-payment-gateway' ),
						'cart'            => __( 'Cart', 'funnelkit-stripe-woo-payment-gateway' ),
						'checkout'        => __( 'Checkout (express button)', 'funnelkit-stripe-woo-payment-gateway' ),
						'show_as_regular' => __( 'Show as Regular Payment Gateway', 'funnelkit-stripe-woo-payment-gateway' ),
					),
					'default' => array( 'product', 'cart', 'checkout', 'show_as_regular' ),
				),
				'checkout_page_position' => array(
					'title'   => __( 'Checkout Page Button Position', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'    => 'select',
					'options' => array(
						'above-checkout' => __( 'Above Checkout Form', 'funnelkit-stripe-woo-payment-gateway' ),
						'above-billing'  => __( 'Above Billing Details', 'funnelkit-stripe-woo-payment-gateway' ),
					),
					'default' => 'above-checkout',
				),
				'separator_text'         => array(
					'title'   => __( 'Separator Text', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'    => 'text',
					'default' => __( 'Or', 'funnelkit-stripe-woo-payment-gateway' ),
				),
				'charge_type'            => array(
					'title'    => __( 'Charge Type', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'     => 'select',
					'default'  => 'automatic',
					'options'  => array(
						'automatic' => __( 'Charge', 'funnelkit-stripe-woo-payment-gateway' ),
						'manual'    => __( 'Authorize', 'funnelkit-stripe-woo-payment-gateway' ),
					),
					'desc_tip' => true,
				),
				'title'                  => array(
					'title'       => __( 'Title', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'text',
					'description' => __( 'Change the payment gateway title that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
					'default'     => __( 'Amazon Pay', 'funnelkit-stripe-woo-payment-gateway' ),
					'desc_tip'    => true,
				),
				'description'            => array(
					'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'textarea',
					'css'         => 'width:25em',
					'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
					'default'     => __( 'Securely pay with your Amazon account', 'funnelkit-stripe-woo-payment-gateway' ),
					'desc_tip'    => true,
				),
			)
		);
	}

	/**
	 * Process the Amazon Pay express payment using a Stripe confirmation token.
	 *
	 * The browser sends `fkwcs_confirmation_token` (from stripe.createConfirmationToken). We create
	 * and confirm the PaymentIntent server-side with it; Amazon Pay returns requires_action with a
	 * redirect_to_url, which we hand back to the browser to redirect the buyer to Amazon. The order
	 * is completed on return via the verify/webhook flow.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id, $retry = true, $force_prevent_source_creation = false, $previous_error = false, $use_order_source = false ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedParameter
		$order = wc_get_order( $order_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Reading the Stripe confirmation token during frontend checkout payment processing
		$confirmation_token = isset( $_POST['fkwcs_confirmation_token'] ) ? sanitize_text_field( wp_unslash( $_POST['fkwcs_confirmation_token'] ) ) : '';
		if ( empty( $confirmation_token ) ) {
			wc_add_notice( __( 'Amazon Pay authorization is missing. Please try again.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );

			return array( 'result' => 'fail' );
		}

		// Free trial / $0-now (e.g. WC Subscriptions free trial): there is nothing to charge, so set
		// up the reusable Amazon mandate with a SetupIntent (no charge) instead of a $0 PaymentIntent,
		// which Stripe rejects. The browser sends a setup-mode confirmation token for this case. The
		// first real renewal then charges the saved mandate off-session.
		if ( 0 >= $order->get_total() ) {
			return $this->process_zero_amount_setup( $order, $confirmation_token );
		}

		try {
			$client = $this->get_client();

			$data = array(
				'amount'                    => Helper::get_formatted_amount( $order->get_total() ),
				'currency'                  => $this->get_currency(),
				'confirmation_token'        => $confirmation_token,
				'confirm'                   => 'true',
				'return_url'                => $this->get_return_url( $order ),
				'description'               => $this->get_order_description( $order ),
				'metadata'                  => $this->add_metadata( $order ),
				// Attach to a customer + save the Amazon mandate off-session so one-click upsells
				// and subscription renewals can reuse it (the WFOCU off-session charge needs a
				// reusable _fkwcs_source_id on the order).
				'customer'                  => $this->get_customer_id( $order ),
				// The confirmation token was collected via the Express Checkout Element using
				// automatic payment methods, so the intent must use automatic_payment_methods
				// (not payment_method_types) or Stripe rejects the confirm. allow_redirects for Amazon.
				'automatic_payment_methods' => array(
					'enabled'         => true,
					'allow_redirects' => 'always',
				),
				// setup_future_usage for Amazon goes under payment_method_options (not at root).
				'payment_method_options'    => array(
					'amazon_pay' => array(
						'setup_future_usage' => 'off_session',
					),
				),
			);

			// Amazon Pay only accepts capture_method under payment_method_options.
			if ( 'manual' === $this->capture_method ) {
				$data['payment_method_options']['amazon_pay']['capture_method'] = 'manual';
			}

			$response = $client->payment_intents( 'create', array( array( $data ), array( 'idempotency_key' => $order->get_order_key() . time() ) ) );
			$intent   = $response['success'] ? $response['data'] : false;

			if ( ! $intent ) {
				$message = ! empty( $response['message'] ) ? $response['message'] : __( 'Amazon Pay payment could not be processed.', 'funnelkit-stripe-woo-payment-gateway' );
				wc_add_notice( $message, 'error' );

				return array( 'result' => 'fail' );
			}

			$this->save_intent_to_order( $order, $intent );

			// Persist the reusable Amazon payment method + customer for off-session upsell /
			// subscription renewal charges. save_payment_method_to_order() writes _fkwcs_source_id +
			// _fkwcs_customer_id to the order AND propagates them onto any subscriptions in it (via
			// maybe_update_source_on_subscription_order), which renewals read to charge off-session.
			if ( ! empty( $intent->payment_method ) ) {
				$source_id   = is_object( $intent->payment_method ) ? $intent->payment_method->id : $intent->payment_method;
				$customer_id = ! empty( $intent->customer ) ? ( is_object( $intent->customer ) ? $intent->customer->id : $intent->customer ) : '';

				$this->save_payment_method_to_order(
					$order,
					(object) array(
						'customer' => $customer_id,
						'source'   => $source_id,
					)
				);
			}

			// Amazon Pay redirect.
			if ( 'requires_action' === $intent->status && isset( $intent->next_action->redirect_to_url->url ) ) {
				$order->update_status( 'pending', __( 'Awaiting Amazon Pay authorization.', 'funnelkit-stripe-woo-payment-gateway' ) );

				return array(
					'result'   => 'success',
					'redirect' => $intent->next_action->redirect_to_url->url,
				);
			}

			// Completed without redirect.
			if ( in_array( $intent->status, array( 'succeeded', 'requires_capture', 'processing' ), true ) && ! empty( $intent->charges->data ) ) {
				$this->process_final_order( end( $intent->charges->data ), $order_id );

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}

			wc_add_notice( __( 'Amazon Pay could not be processed. Please try again.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );

			return array( 'result' => 'fail' );
		} catch ( \Exception $e ) {
			Helper::log( 'Amazon Pay process_payment error: ' . $e->getMessage(), 'warning' );
			wc_add_notice( $e->getMessage(), 'error' );

			return array( 'result' => 'fail' );
		}
	}

	/**
	 * Set up the reusable Amazon mandate for a $0 order (free trial) without charging.
	 *
	 * Uses a SetupIntent (usage=off_session) confirmed with the Express Checkout confirmation token
	 * instead of a PaymentIntent, since Stripe rejects a $0 PaymentIntent. Amazon Pay is a redirect
	 * method, so confirm returns requires_action -> redirect_to_url; on completion the saved mandate
	 * (_fkwcs_source_id) lets the first subscription renewal charge off-session.
	 *
	 * @param \WC_Order $order              Order being placed.
	 * @param string    $confirmation_token Stripe confirmation token from the browser.
	 *
	 * @return array
	 */
	protected function process_zero_amount_setup( $order, $confirmation_token ) {
		try {
			$client = $this->get_client();

			$data = array(
				'confirmation_token'        => $confirmation_token,
				'confirm'                   => 'true',
				'usage'                     => 'off_session',
				'return_url'                => $this->get_return_url( $order ),
				'customer'                  => $this->get_customer_id( $order ),
				'metadata'                  => $this->add_metadata( $order ),
				// Collected via the Express Checkout Element using automatic payment methods, so the
				// SetupIntent must use automatic_payment_methods (not payment_method_types). Amazon
				// needs allow_redirects to send the buyer off to authorize the mandate.
				'automatic_payment_methods' => array(
					'enabled'         => true,
					'allow_redirects' => 'always',
				),
			);

			$response = $client->setup_intents( 'create', array( array( $data ), array( 'idempotency_key' => $order->get_order_key() . time() ) ) );
			$intent   = $response['success'] ? $response['data'] : false;

			if ( ! $intent ) {
				$message = ! empty( $response['message'] ) ? $response['message'] : __( 'Amazon Pay could not be set up. Please try again.', 'funnelkit-stripe-woo-payment-gateway' );
				wc_add_notice( $message, 'error' );

				return array( 'result' => 'fail' );
			}

			// Persist the SetupIntent + reusable mandate + customer to the order and any subscriptions
			// in it, so renewals can charge the saved Amazon mandate off-session.
			$order->update_meta_data(
				'_fkwcs_setup_intent',
				array(
					'id'            => $intent->id,
					'client_secret' => $intent->client_secret,
				)
			);
			if ( ! empty( $intent->payment_method ) ) {
				$source_id   = is_object( $intent->payment_method ) ? $intent->payment_method->id : $intent->payment_method;
				$customer_id = ! empty( $intent->customer ) ? ( is_object( $intent->customer ) ? $intent->customer->id : $intent->customer ) : '';

				$this->save_payment_method_to_order(
					$order,
					(object) array(
						'customer' => $customer_id,
						'source'   => $source_id,
					)
				);
			}
			$order->save();

			// Amazon Pay redirect to authorize the mandate.
			if ( 'requires_action' === $intent->status && isset( $intent->next_action->redirect_to_url->url ) ) {
				$order->update_status( 'pending', __( 'Awaiting Amazon Pay authorization.', 'funnelkit-stripe-woo-payment-gateway' ) );

				return array(
					'result'   => 'success',
					'redirect' => $intent->next_action->redirect_to_url->url,
				);
			}

			// Mandate set up without a redirect: complete the $0 order.
			if ( in_array( $intent->status, array( 'succeeded', 'processing' ), true ) ) {
				$order->payment_complete();

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}

			wc_add_notice( __( 'Amazon Pay could not be set up. Please try again.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );

			return array( 'result' => 'fail' );
		} catch ( \Exception $e ) {
			Helper::log( 'Amazon Pay zero-amount setup error: ' . $e->getMessage(), 'warning' );
			wc_add_notice( $e->getMessage(), 'error' );

			return array( 'result' => 'fail' );
		}
	}

	/**
	 * Amazon Pay always charges via the amazon_pay payment method type.
	 *
	 * Overrides the inherited CreditCard behaviour, which returns card/link for Link-supported
	 * accounts. The off-session upsell (WFOCU create_intent) and subscription renewal charges build
	 * the PaymentIntent from this list, so it must match the saved amazon_pay payment method or
	 * Stripe rejects the charge ("PaymentMethod provided (amazon_pay) is not allowed").
	 *
	 * @return array
	 */
	public function get_payment_method_types() {
		return array( 'amazon_pay' );
	}

	/**
	 * Returns the Amazon Pay brand icon markup.
	 *
	 * @return string
	 */
	public function get_icon() {
		$icons      = $this->payment_icons();
		$icons_str  = '';
		$icons_str .= ! empty( $icons['amazon_pay'] ) ? $icons['amazon_pay'] : '';
		return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
	}
}

add_action( 'wp_loaded', 'FKWCS\Gateway\Stripe\AmazonPay::get_instance' );
