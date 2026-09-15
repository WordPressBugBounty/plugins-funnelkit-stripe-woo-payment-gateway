<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GooglePay extends CreditCard {

	use Funnelkit_Stripe_Smart_Buttons;

	private static $instance                   = null;
	public $id                                 = 'fkwcs_stripe_google_pay';
	public $payment_method_types               = 'card';
	public $merchant_id                        = '';
	public $merchant_name                      = '';
	public $btn_color                          = 'black';
	public $btn_theme                          = 'pay';
	private static $mini_cart_wrapper_rendered = false;
	private $place_order_wrapper_rendered      = false;

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		/**
		 * Validate if its setup correctly
		 */
		$this->set_api_keys();

		$this->init_supports();
		$this->init();
		if ( false === $this->is_configured() ) {
			return;
		}
		add_action( 'wc_ajax_fkwcs_gpay_update_shipping_address', array( $this, 'gpay_update_shipping_address' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'add_google_pay_data' ), 100 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'add_google_pay_data' ), 100 );
		add_filter( 'fkcart_fragments', array( $this, 'add_google_pay_data' ), 1000 );
		add_action( 'fkcart_before_checkout_button', array( $this, 'add_mini_cart_wrapper' ) );
		// Express Checkout mode: render the Google Pay button wrapper right after the Place
		// Order button (mirrors Apple Pay). The ECE mounts here and replaces Place Order when
		// Google Pay is the selected gateway.
		add_action( 'woocommerce_review_order_after_submit', array( $this, 'render_wrapper' ) );
		add_action( 'wfacp_woocommerce_review_order_after_submit', array( $this, 'render_wrapper' ) );

		// The order-pay page (checkout/form-pay.php) does not fire the review-order hook above,
		// so also render the ECE wrapper after its own submit button — otherwise Google Pay has
		// no surface to mount on and cannot pay the existing order.
		add_action( 'woocommerce_pay_order_after_submit', array( $this, 'render_wrapper' ) );
	}

	/**
	 * Output the Express Checkout Google Pay button wrapper after the Place Order button.
	 *
	 * @return void
	 */
	public function render_wrapper() {
		// GooglePay is instantiated twice on AJAX (WooCommerce gateway registration + the
		// GooglePay::get_instance() singleton booted in plugin.php), so both instances hook this
		// action. Guard against a duplicate wrapper so the ECE mounts to a single element.
		if ( $this->place_order_wrapper_rendered ) {
			return;
		}
		$this->place_order_wrapper_rendered = true;
		// fkwcs-gpay-button-container: shown by hidePlaceOrder() when Google Pay is selected.
		// fkwcs_wallet_gateways: hidden by hideGatewayWallets() when it is not. Hidden by default.
		echo "<div class='fkwcs_stripe_google_pay_button fkwcs-gpay-button-container fkwcs_wallet_gateways' style='display:none'></div>";
	}

	/**
	 * Returns all supported currencies for this payment method
	 *
	 * @return mixed|null
	 */
	public function get_supported_currency() {
		return apply_filters(
			'fkwcs_stripe_google_pay_supported_currencies',
			array(
				'USD', // United States Dollar
				'EUR', // Euro
				'GBP', // British Pound
				'AUD', // Australian Dollar
				'CAD', // Canadian Dollar
				'CHF', // Swiss Franc
				'DKK', // Danish Krone
				'HKD', // Hong Kong Dollar
				'JPY', // Japanese Yen
				'NZD', // New Zealand Dollar
				'NOK', // Norwegian Krone
				'SGD', // Singapore Dollar
				'SEK', // Swedish Krona
				'INR', // Indian Rupee
				'BRL', // Brazilian Real
				'MXN', // Mexican Peso
				'PLN', // Polish Złoty
				'ZAR', // South African Rand
				'KRW', // South Korean Won
				'THB',  // Thai Baht
			)
		);
	}

	/**
	 * Registers supported filters for payment gateway
	 *
	 * @return void
	 */
	public function init_supports() {
		$this->supports = apply_filters(
			'fkwcs_card_payment_supports',
			array_merge(
				$this->supports,
				array(
					'products',
					'refunds',
					'tokenization',
					'add_payment_method',
				)
			)
		);
	}

	protected function init() {
		$this->has_fields = true;
		$this->init_form_fields();
		$this->init_settings();
		$this->maybe_init_subscriptions();
		$this->method_title         = __( 'Google Pay', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description   = __( 'Enable Google Pay as an inline payment gateway.', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle             = __( 'Google Pay allows customers to make payments in your app or website using any credit or debit card saved to their Google Account, including those from Google Play, YouTube, Chrome, or an Android device.', 'funnelkit-stripe-woo-payment-gateway' );
		$this->title                = $this->get_option( 'title' );
		$this->description          = $this->get_option( 'description' );
		$this->merchant_name        = $this->get_option( 'merchant_name' );
		$this->merchant_id          = $this->get_option( 'merchant_id' );
		$this->description          = $this->get_option( 'description' );
		$this->statement_descriptor = $this->get_option( 'statement_descriptor' );
		$this->btn_color            = $this->get_option( 'button_color' );
		$this->btn_theme            = $this->get_option( 'button_type' );

		$this->capture_method = $this->get_option( 'charge_type' );
		if ( false === $this->is_configured() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'register_stripe_js' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_stripe_js' ), 11 );
		add_filter( 'fkwcs_localized_data', array( $this, 'localize_element_data' ), 999 );
		$this->filter_hooks();
	}

	/**
	 * Add hooks
	 *
	 * @return void
	 */
	protected function filter_hooks() {
		add_filter( 'woocommerce_payment_successful_result', array( $this, 'modify_successful_payment_result' ), 999, 2 );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_head', array( $this, 'maybe_hide_regular_gateway_css' ) );
	}

	/**
	 * Hides the Google Pay gateway row in the regular payment list unless show_as_regular is selected.
	 *
	 * Google Pay is always rendered by the Stripe Express Checkout Element (see FKWCS_GOOGLEPAY);
	 * JS hides the row again if Google Pay turns out to be unavailable (mirrors Apple Pay).
	 */
	public function maybe_hide_regular_gateway_css() {
		if ( ! is_checkout() || 'yes' !== $this->enabled ) {
			return;
		}
		$display_locations = (array) $this->get_option( 'display_locations', array() );

		if ( ! in_array( 'show_as_regular', $display_locations, true ) ) {
			echo '<style>' . esc_html( 'li.payment_method_fkwcs_stripe_google_pay{display:none!important}' ) . '</style>';
		}
	}

	public function get_method_description() {
		$description  = $this->method_description;
		$description .= '<p>' . __( 'The Google Pay button is rendered by Stripe (Express Checkout) and works in supported browsers like Chrome without a Google Merchant ID, so no additional setup is required.', 'funnelkit-stripe-woo-payment-gateway' ) . '</p>';
		$description .= $this->get_legacy_merchant_id_notice();

		return $description;
	}

	/**
	 * Notice for stores upgrading from the legacy Native Google Pay API integration.
	 *
	 * That mode was the only thing that ever used the Google Merchant ID, and it has been removed —
	 * Google Pay now runs entirely through Stripe's Express Checkout Element. Sites that configured a
	 * Merchant ID still carry the value in their saved settings, where it no longer does anything, and
	 * the field they set it in is gone from this screen. Say so explicitly rather than leaving the
	 * merchant to wonder whether their setup is now broken.
	 *
	 * Shown only when a Merchant ID is actually stored, so stores that never used the native mode
	 * (including fresh installs) never see it. The stored value is deliberately left in place: it is
	 * inert, and it is the only signal that this store came from the native integration.
	 *
	 * Dismissible through the plugin's shared notice handling, so a merchant who has read it once
	 * does not keep seeing it on every visit to this screen.
	 *
	 * @return string Notice markup, or an empty string when there is nothing to report.
	 */
	protected function get_legacy_merchant_id_notice() {
		$merchant_id = $this->get_option( 'merchant_id', '' );

		if ( '' === trim( (string) $merchant_id ) ) {
			return '';
		}

		$notice_id = 'fkwcs_gpay_legacy_merchant_id';

		if ( Admin::get_instance()->is_notice_dismissed( $notice_id ) ) {
			return '';
		}

		$notice = sprintf(
			/* translators: %s: the Google Merchant ID currently saved on the store. */
			__( 'Google Pay using  Stripe\'s Express Checkout Element no longer needs a Merchant ID. The ID saved here (%s) is unused and can be ignored.', 'funnelkit-stripe-woo-payment-gateway' ),
			'<code>' . esc_html( $merchant_id ) . '</code>'
		);

		/**
		 * The wrapper carries `fkwcs_dismiss_notice_wrap_{$notice_id}` and the button carries
		 * `fkwcs_dismiss_notice` + `data-notice`, which is what admin.js already listens for: it
		 * fires the `fkwcs_dismiss_notice` AJAX action and removes the wrapper.
		 *
		 * `is-dismissible` is intentionally omitted — core's common.js appends its own dismiss
		 * button to any such notice, which would render a second, non-persisting cross next to ours.
		 */
		return sprintf(
			'<div class="notice notice-info inline fkwcs-legacy-merchant-id-notice fkwcs_dismiss_notice_wrap_%1$s"><p>%2$s</p><button type="button" class="notice-dismiss fkwcs_dismiss_notice" data-notice="%1$s"><span class="screen-reader-text">%3$s</span></button></div>',
			esc_attr( $notice_id ),
			wp_kses( $notice, array( 'code' => array() ) ),
			esc_html__( 'Dismiss this notice.', 'funnelkit-stripe-woo-payment-gateway' )
		);
	}

	/**
	 * Initialise gateway settings form fields
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = apply_filters(
			'fkwcs_google_pay_payment_form_fields',
			array(
				'enabled'               => array(
					'label'   => ' ',
					'type'    => 'checkbox',
					'title'   => __( 'Enable Google Pay', 'funnelkit-stripe-woo-payment-gateway' ),
					'default' => 'no',
				),
				'display_locations'     => array(
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
				'product_page_position' => array(
					'title'   => __( 'Product Page Button Position', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'    => 'select',
					'options' => array(
						'above-add-to-cart' => __( 'Above Add to Cart', 'funnelkit-stripe-woo-payment-gateway' ),
						'below-add-to-cart' => __( 'Below Add to Cart', 'funnelkit-stripe-woo-payment-gateway' ),
						'inline'            => __( 'Inline Button', 'funnelkit-stripe-woo-payment-gateway' ),
					),
					'default' => 'below-add-to-cart',
				),
				'button_type'           => array(
					'title'   => __( 'Button Type', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'    => 'select',
					'options' => array(
						'plain'     => __( 'Plain', 'funnelkit-stripe-woo-payment-gateway' ),
						'buy'       => __( 'Buy', 'funnelkit-stripe-woo-payment-gateway' ),
						'pay'       => __( 'Pay', 'funnelkit-stripe-woo-payment-gateway' ),
						'checkout'  => __( 'Checkout', 'funnelkit-stripe-woo-payment-gateway' ),
						'donate'    => __( 'Donate', 'funnelkit-stripe-woo-payment-gateway' ),
						'book'      => __( 'Book', 'funnelkit-stripe-woo-payment-gateway' ),
						'order'     => __( 'Order', 'funnelkit-stripe-woo-payment-gateway' ),
						'subscribe' => __( 'Subscribe', 'funnelkit-stripe-woo-payment-gateway' ),
					),
					'default' => 'pay',
				),
				'button_color'          => array(
					'title'   => __( 'Button Color', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'    => 'select',
					'options' => array(
						'black' => __( 'Black', 'funnelkit-stripe-woo-payment-gateway' ),
						'white' => __( 'White', 'funnelkit-stripe-woo-payment-gateway' ),
					),
					'default' => 'black',
				),
				'disable_shipping_info' => array(
					'title'       => __( 'Disable Shipping Info in Payment Wallet', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'checkbox',
					'label'       => __( 'Hide shipping address and shipping methods in Google Pay payment wallet', 'funnelkit-stripe-woo-payment-gateway' ),
					'description' => __( 'When enabled, Google Pay will only handle payment authorization without requesting or displaying shipping information from the customer\'s wallet during checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
					'default'     => 'no',
					'desc_tip'    => false,
				),
				'icon_type'             => array(
					'title'   => __( 'Icon Style', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'    => 'select',
					'options' => array(
						'round-border' => __( 'With Rounded Border', 'funnelkit-stripe-woo-payment-gateway' ),
						'border'       => __( 'With Border', 'funnelkit-stripe-woo-payment-gateway' ),
						'standard'     => __( 'Standard', 'funnelkit-stripe-woo-payment-gateway' ),
					),
					'default' => 'round-border',
				),
				'separator_text'        => array(
					'title'   => __( 'Separator Text', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'    => 'text',
					'default' => __( 'Or', 'funnelkit-stripe-woo-payment-gateway' ),
				),
				'charge_type'           => array(
					'title'       => __( 'Charge Type', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'select',
					'description' => $this->get_charge_type_recommendation_text(),
					'default'     => 'automatic',
					'options'     => array(
						'automatic' => __( 'Charge', 'funnelkit-stripe-woo-payment-gateway' ),
						'manual'    => __( 'Authorize', 'funnelkit-stripe-woo-payment-gateway' ),
					),
					'desc_tip'    => false,
				),
				'title'                 => array(
					'title'       => __( 'Title', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'text',
					'description' => __( 'Change the payment gateway title that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
					'default'     => __( 'Google Pay', 'funnelkit-stripe-woo-payment-gateway' ),
					'desc_tip'    => true,
				),
				'description'           => array(
					'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'textarea',
					'css'         => 'width:25em',
					'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
					'default'     => __( 'Pay with your Google Pay', 'funnelkit-stripe-woo-payment-gateway' ),
					'desc_tip'    => true,
				),
				'button_dummy_button'   => array(
					'type'    => 'fkwcs_html',
					'title'   => '',
					'default' => 'black',
				),
			)
		);
	}

	public function enqueue_stripe_js() {

		if ( ! $this->is_available() ) {
			return;
		}

		/**
		 * Check if selected location is not the current location
		 * OR
		 * Allow devs to enqueue assets
		 */
		if ( ! ( $this->is_selected_location() || ( apply_filters( 'fkwcs_enqueue_express_button_assets', false, $this ) ) ) ) {
			return;
		}
		parent::enqueue_stripe_js();
		wp_enqueue_style( 'fkwcs-style' );
		wp_enqueue_script( Helper::get_stripesdk_handle() );
		if ( $this->is_checkout() ) {
			wp_enqueue_script( 'fkwcs-stripe-js' );
		}

		wp_enqueue_script( 'fkwcs-express-checkout-js' );
		if ( 0 === did_action( 'fkwcs_core_element_js_enqueued' ) && 0 === did_action( 'fkwcs_smart_buttons_js_enqueued' ) ) {
			wp_localize_script( 'fkwcs-express-checkout-js', 'fkwcs_data', $this->localize_data() );
		}
	}

	/**
	 * Checks if current location is chosen to display express checkout button
	 *
	 * @return boolean
	 */
	private function is_selected_location(): bool {
		$locations = isset( $this->settings['display_locations'] ) && is_array( $this->settings['display_locations'] )
			? $this->settings['display_locations']
			: array( 'product', 'cart', 'checkout' );

		if ( in_array( 'product', $locations, true ) && $this->is_product() ) {
			return true;
		}

		if ( in_array( 'cart', $locations, true ) && is_cart() ) {
			return true;
		}

		if ( in_array( 'checkout', $locations, true ) && is_checkout() ) {
			return true;
		}

		// show_as_regular means the gateway appears in the regular payment list on checkout.
		// Scripts must still be enqueued on the checkout page for the payment flow to work.
		if ( in_array( 'show_as_regular', $locations, true ) && is_checkout() ) {
			return true;
		}

		return false;
	}

	public function localize_element_data( $data ) {
		global $wp;
		if ( ! $this->is_available() ) {
			return $data;
		}
		$data['google_pay_disable_shipping'] = ( 'yes' === $this->get_option( 'disable_shipping_info', 'no' ) ) ? 'yes' : 'no';
		$data['checkout_nonce']              = wp_create_nonce( 'woocommerce-process_checkout' );

		if ( $this->is_product() ) {
			$data['gpay_single_product'] = $this->get_product_data();
		}

		// gpay_cart_data must be present wherever the GPay button can mount
		if ( WC()->cart instanceof \WC_Cart && ! WC()->cart->is_empty() ) {
			$data['gpay_cart_data'] = $this->ajax_get_cart_details( true );
		}

		if ( ! empty( $wp->query_vars['order-pay'] ) ) {
			$order = wc_get_order( absint( $wp->query_vars['order-pay'] ) );
			if ( ! $order instanceof \WC_Order ) {
				return $data;
			}

			$data['gpay_cart_data'] = array(
				'shipping_required'     => wc_bool_to_string( false ),
				'order_data'            => array(
					'currency'     => strtolower( $order->get_currency() ),
					'country_code' => substr( get_option( 'woocommerce_default_country' ), 0, 2 ),
					'displayItems' => $this->get_display_items_from_order( $order ),
					'total'        => array(
						'label'   => __( 'Total', 'funnelkit-stripe-woo-payment-gateway' ),
						'amount'  => max( 0, apply_filters( 'fkwcs_stripe_calculated_total', Helper::get_stripe_amount( $order->get_total(), '', 1 ), $order->get_total() ) ),
						'pending' => false,
					),
				),
				'is_fkwcs_need_payment' => true,
				'shipping_options'      => array(),
			);

			// The regular-gateway Express Checkout Element arms its amount from the
			// fkwcs_google_pay_data fragment, which is never emitted on the order-pay page
			// (no cart / fragment refresh). Localize it here so the JS can arm the ECE once
			// on init against the existing order total. Shape mirrors add_google_pay_data().
			$data['fkwcs_google_pay_data'] = array(
				'order_data' => array(
					'currency'             => strtolower( $order->get_currency() ),
					'total_amount_subunit' => max( 0, (int) apply_filters( 'fkwcs_stripe_calculated_total', Helper::get_stripe_amount( $order->get_total() ), $order->get_total() ) ),
				),
			);

		}
		if ( 'yes' === $this->enabled ) {
			$display_locations = (array) $this->get_option( 'display_locations', array() );

			// Native Google Pay API mode is removed — the native client must never boot.
			$data['google_pay_as_express'] = 'no';

			// 'yes' only when show_as_regular is explicitly selected in display_locations.
			$data['google_pay_as_regular'] = in_array( 'show_as_regular', $display_locations, true ) ? 'yes' : 'no';

			// Google Pay is always Express Checkout (ECE) powered now. The frontend reads this to
			// select the ECE rendering path (mirrors Apple Pay).
			$data['google_pay_integration_mode'] = 'express_checkout';
			$data['google_pay_positions']        = array_values( $display_locations );
		}
		$data['google_pay_btn_color'] = $this->btn_color;
		$data['google_pay_btn_theme'] = $this->btn_theme;

		return $data;
	}

	/**
	 * Print the gateway field
	 *
	 * @return void
	 */
	public function payment_fields() {
		do_action( $this->id . '_before_payment_field_checkout' );
		include __DIR__ . '/parts/google_pay.php';
		do_action( $this->id . '_after_payment_field_checkout' );
	}

	/**
	 * Updates shipping address
	 *
	 * @return void
	 */
	public function gpay_update_shipping_address() {

		check_ajax_referer( 'fkwcs_nonce', 'fkwcs_nonce' );

		if ( ! isset( $_POST['shipping_address'] ) || ! isset( $_POST['shipping_method'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above with check_ajax_referer
			$data = array(
				'result' => 'fail',
			);
			wp_send_json( $data );
		}
		$shipping_address = wc_clean( wp_unslash( $_POST['shipping_address'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above with check_ajax_referer

		wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );
		add_filter(
			'woocommerce_cart_ready_to_calc_shipping',
			function () {
				return true;
			},
			1000
		);
		try {
			$this->wc_stripe_update_customer_location( $shipping_address );
			$this->update_shipping_method( wc_clean( wp_unslash( $_POST['shipping_method'] ) ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Nonce verified above with check_ajax_referer

			$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', array() );
			/** update the WC cart with the new shipping options */
			if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
				WC()->cart->calculate_totals();
			}
			$this->maybe_restore_recurring_chosen_shipping_methods( $chosen_shipping_methods );
			/** if shipping address is not serviceable, throw an error */
			if ( ! $this->wc_stripe_shipping_address_serviceable( $this->get_shipping_packages() ) ) {
				$this->reason_code = 'SHIPPING_ADDRESS_UNSERVICEABLE';
				throw new \Exception( __( 'Your shipping address is not serviceable.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			$data = $this->get_payment_response_data( $shipping_address );
		} catch ( \Exception $e ) {
			$data = array(
				'result' => 'fail',
			);
		}

		wp_send_json( $data );
	}

	public function get_payment_response_data( $shipping_address ) {
		$shipping_options = $this->get_formatted_shipping_methods();
		$items            = $this->build_display_items()['displayItems'];

		$new_data = array(
			'newTransactionInfo'          => array(
				'currencyCode'     => get_woocommerce_currency(),
				'countryCode'      => WC()->countries->get_base_country(),
				'totalPriceStatus' => 'FINAL',
				'totalPrice'       => wc_format_decimal( WC()->cart instanceof \WC_Cart ? WC()->cart->total : 0, 2 ),
				'displayItems'     => $items,
				'totalPriceLabel'  => __( 'Total', 'funnelkit-stripe-woo-payment-gateway' ),
			),
			'newShippingOptionParameters' => array(
				'shippingOptions'         => $shipping_options,
				'defaultSelectedOptionId' => $this->get_mapped_default_shipping_method( $shipping_options ),
			),
		);

		return array(
			'shipping_methods'     => WC()->session->get( 'chosen_shipping_methods', array() ),
			'paymentRequestUpdate' => $new_data,
			'address'              => $shipping_address,
		);
	}

	/**
	 * Returns a default shipping method based on the chosen shipping methods.
	 *
	 * @param array $methods
	 *
	 * @return string
	 */
	private function get_mapped_default_shipping_method( $methods ) {
		$selected_methods     = WC()->session->get( 'chosen_shipping_methods', array() );
		$method_ids           = array_column( $methods, 'id' );
		$temp_shipping_method = false;
		foreach ( $selected_methods as $idx => $method ) {
			$method_id = sprintf( '%s:%s', $idx, $method );
			if ( in_array( $method_id, $method_ids, true ) ) {
				$temp_shipping_method = $method_id;
			}
		}
		if ( ! $temp_shipping_method ) {
			return current( $method_ids );
		}
	}

	/**
	 * @param $shipping_method array
	 *
	 * @return void
	 */
	public function update_shipping_method( $shipping_method ) {
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
		$posted_shipping_methods = wc_clean( wp_unslash( $shipping_method ) );
		if ( is_array( $posted_shipping_methods ) ) {
			foreach ( $posted_shipping_methods as $i => $value ) {
				$chosen_shipping_methods[ $i ] = $value;
			}
		}

		WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
	}

	protected function get_smart_button_line_item( $name, $amount, $type = 'LINE_ITEM' ) {

		return array(
			'label' => $name,
			'type'  => $type,
			'price' => (string) Helper::get_stripe_amount( $amount, '', 1, true ),
		);
	}

	/**
	 * Fetch cart details
	 *
	 * @return array
	 */
	public function ajax_get_cart_details( $is_localized = false ) {
		if ( 'wc_ajax_fkwcs_get_cart_details' === current_action() ) {
			check_ajax_referer( 'fkwcs_nonce', 'fkwcs_nonce' );

		}
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', array() );
		if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
			WC()->cart->calculate_totals();
		}
		$this->maybe_restore_recurring_chosen_shipping_methods( $chosen_shipping_methods );
		$currency = get_woocommerce_currency();
		/** Set mandatory payment details */
		$data = array(
			'shipping_required' => wc_bool_to_string( $this->shipping_required() ),
			'order_data'        => array(
				'currency'     => strtolower( $currency ),
				'country_code' => substr( get_option( 'woocommerce_default_country' ), 0, 2 ),
			),
		);

		/**
		 * disabled GPay button if stripe not need payments
		 */

		$data['is_fkwcs_need_payment'] = false;
		if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart && WC()->cart->needs_payment() ) {
			$data['is_fkwcs_need_payment'] = true;
		}
		$data['order_data']      += $this->build_display_items( true, $is_localized );
		$data['shipping_options'] = $this->get_formatted_shipping_methods();

		if ( 'wc_ajax_fkwcs_get_cart_details' === current_action() ) {
			wp_send_json_success( $data );
		}

		return $data;
	}

	public function get_formatted_shipping_method( $price, $rate, $i, $package, $incl_tax ) {
		$method = array(
			'id'          => $this->get_shipping_method_id( $rate->id ),
			'label'       => $this->get_formatted_shipping_label( $price, $rate, $incl_tax ),
			'description' => '',
		);

		if ( $incl_tax ) {
			if ( $rate->get_shipping_tax() > 0 && ! wc_prices_include_tax() ) {
				$method['description'] = WC()->countries->inc_tax_or_vat();
			}
		} elseif ( $rate->get_shipping_tax() > 0 && wc_prices_include_tax() ) {
				$method['description'] = WC()->countries->ex_tax_or_vat();
		}

		return $method;
	}

	public function get_item_order( $price, $label, $order, $type = 'LINE_ITEM' ) {

		return array(
			'label' => $label,
			'type'  => $type,
			'price' => (string) max( 0, Helper::get_stripe_amount( $price, $order->get_currency(), 1, true ) ),

		);
	}

	public function get_display_items_from_order( $order ) {

		$items = array();

		foreach ( $order->get_items() as $item ) {
			$qty     = $item->get_quantity();
			$label   = $qty > 1 ? sprintf( '%s X %s', $item->get_name(), $qty ) : $item->get_name();
			$items[] = $this->get_item_order( $item->get_subtotal(), $label, $order );

		}
		if ( 0 < $order->get_shipping_total() ) {
			$items[] = $this->get_item_order( $order->get_shipping_total(), __( 'Shipping', 'funnelkit-stripe-woo-payment-gateway' ), $order );
		}
		if ( 0 < $order->get_total_discount() ) {
			$items[] = $this->get_item_order( - 1 * $order->get_total_discount(), __( 'Discount', 'funnelkit-stripe-woo-payment-gateway' ), $order );
		}
		if ( 0 < $order->get_fees() ) {
			$fee_total = 0;
			foreach ( $order->get_fees() as $fee ) {
				$fee_total += $fee->get_total();
			}
			$items[] = $this->get_item_order( $fee_total, __( 'Fees', 'funnelkit-stripe-woo-payment-gateway' ), $order, 'LINE_ITEM' );
		}
		if ( 0 < $order->get_total_tax() ) {
			$items[] = $this->get_item_order( $order->get_total_tax(), __( 'Tax', 'woocommerce' ), $order, 'TAX' ); //phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		}

		return $items;
	}

	protected function build_display_items( $display_items = true, $is_localized = false ) {

		if ( false === $is_localized ) {
			wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );
		}

		$items     = array();
		$lines     = array();
		$subtotal  = 0;
		$discounts = 0;

		if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$amount         = $this->get_cart_item_subtotal( $item );
				$subtotal      += $amount;
				$quantity_label = isset( $item['quantity'] ) && 1 < $item['quantity'] ? ' (' . $item['quantity'] . ')' : '';
				$product_name   = isset( $item['data'] ) && $item['data'] instanceof \WC_Product ? $item['data']->get_name() : '';
				$items[]        = $this->get_smart_button_line_item( $product_name . $quantity_label, $amount );
			}

			if ( $display_items ) {
				$items = array_merge( $items, $lines );
			} else {
				/** Default show only subtotal instead of itemization */
				$items[] = $this->get_smart_button_line_item( 'Subtotal', $subtotal, 'SUBTOTAL' );
			}

			$applied_coupons = array_values( WC()->cart->get_coupon_discount_totals() );
			foreach ( $applied_coupons as $amount ) {
				$discounts += (float) $amount;
			}

			$discounts   = wc_format_decimal( $discounts, WC()->cart->dp );
			$tax         = wc_format_decimal( WC()->cart->tax_total + WC()->cart->shipping_tax_total, WC()->cart->dp );
			$shipping    = wc_format_decimal( WC()->cart->shipping_total, WC()->cart->dp );
			$order_total = WC()->cart->get_total( false );

			if ( wc_tax_enabled() ) {
				$items[] = $this->get_smart_button_line_item( esc_html( __( 'Tax', 'funnelkit-stripe-woo-payment-gateway' ) ), $tax, 'TAX' );
			}

			if ( WC()->cart->needs_shipping() ) {
				$items[] = $this->get_smart_button_line_item( esc_html( __( 'Shipping', 'funnelkit-stripe-woo-payment-gateway' ) ), $shipping );
			}

			if ( WC()->cart->has_discount() ) {
				$items[] = $this->get_smart_button_line_item( esc_html( __( 'Discount', 'funnelkit-stripe-woo-payment-gateway' ) ), $discounts );
			}

			$cart_fees = WC()->cart->get_fees();

			/** Include fees and taxes as display items */
			foreach ( $cart_fees as $fee ) {
				$items[] = $this->get_smart_button_line_item( $fee->name, $fee->amount );
			}

			$totals = $this->get_smart_button_line_totals(
				array(
					'label'   => __( 'Total', 'funnelkit-stripe-woo-payment-gateway' ),
					'amount'  => max( 0, apply_filters( 'fkwcs_stripe_calculated_total', Helper::get_stripe_amount( $order_total, '', 1, true ), $order_total, WC()->cart ) ),
					'pending' => false,
				),
				$order_total
			);

			return array(
				'displayItems'         => $items,
				'total'                => $totals,
				// Subunit (cents) total for the Stripe Express Checkout Element, which requires
				// an integer in the currency subunit. `total.amount` above stays in decimal
				// dollars for the native Google Pay API. Currency-safe (handles zero-decimal).
				'total_amount_subunit' => max( 0, (int) apply_filters( 'fkwcs_stripe_calculated_total', Helper::get_stripe_amount( $order_total ), $order_total, WC()->cart ) ),
			);
		}

		return array(
			'displayItems'         => array(),
			'total'                => array(
				'label'   => __( 'Total', 'funnelkit-stripe-woo-payment-gateway' ),
				'amount'  => 0,
				'pending' => false,
			),
			'total_amount_subunit' => 0,
		);
	}

	public function add_google_pay_data( $fragments ) {
		$data = $this->ajax_get_cart_details();

		// Ship fresh nonces so cached pages can swap in valid values on fragment refresh.
		// The Express Checkout regular-radio flow submits the WooCommerce checkout form.
		$data['nonces'] = array(
			'fkwcs_nonce'    => wp_create_nonce( 'fkwcs_nonce' ),
			'checkout_nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
		);

		$fragments['fkwcs_google_pay_data'] = $data;

		return $fragments;
	}

	public function get_icon() {
		$icon_type = $this->get_option( 'icon_type' );

		$icons = '<span class="fkwcs_stripe_gpay_icons">';
		if ( $icon_type === 'round-border' ) {
			$image_svg_path = 'standard_rounded.svg';
		} elseif ( $icon_type === 'border' ) {
			$image_svg_path = 'googlepay_outline.svg';
		} else {
			$image_svg_path = 'standard.svg';
		}
		$icons .= '<img src="' . \FKWCS_URL . 'assets/icons/google/' . $image_svg_path . '' . '" alt="Visa" title="google pay" /></span>';

		return $icons;
	}

	public function add_mini_cart_wrapper() {
		if ( self::$mini_cart_wrapper_rendered ) {
			return;
		}
		if ( class_exists( '\FKCart\Includes\Data' ) && ! \FKCart\Includes\Data::get_value( 'smart_buttons' ) ) {
			return;
		}

		?>
		<style id="fkwcs-fkcart-gpay-guard">
			/* Collapse the FKCart Google Pay slot when its inner wrapper hasn't received
			 * a rendered button (Stripe/Google iframe or a Google Pay <button>). FKCart's
			 * showButton() sets inline display:block on every cart open / fragment refresh,
			 * so we need !important here to win over the inline style. */
			#fkcart-modal #fkcart_fkwcs_smart_button_gpay:not(:has(iframe, button)) {
				display: none !important;
			}
			#fkcart-modal .fkwcs_fkcart_gpay_wrapper:not(:has(iframe, button)) {
				margin: 0 !important;
				padding: 0 !important;
			}
		</style>
		<div class="fkcart-checkout-wrap fkcart-panel fkwcs_fkcart_gpay_wrapper">
			<script>
				try {
					(function ($) {
						$(document.body).trigger('fkwcs_generate_fkcart_mini_button');
					})(jQuery)
				} catch (e) {

				}
			</script>
		</div>
		<?php
		self::$mini_cart_wrapper_rendered = true;
	}
}
