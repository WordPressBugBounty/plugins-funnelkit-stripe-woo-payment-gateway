<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FKWCS\Gateway\Stripe\Traits\WC_Subscriptions_Trait;

class ApplePay extends CreditCard {
	use WC_Subscriptions_Trait;
	use Funnelkit_Stripe_Smart_Buttons;

	private static $instance              = null;
	public $id                            = 'fkwcs_stripe_apple_pay';
	public $payment_method_types          = 'card';
	public $merchant_id                   = '';
	public $merchant_name                 = '';
	private $place_order_wrapper_rendered = false;


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
		$this->maybe_init_subscriptions();
		if ( false === $this->is_configured() ) {
			return;
		}
		add_action( 'wc_ajax_fkwcs_gpay_update_shipping_address', array( $this, 'gpay_update_shipping_address' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'add_apple_pay_data' ), 100 );
		add_action( 'woocommerce_review_order_after_submit', array( $this, 'render_wrapper' ) );
		add_action( 'wfacp_woocommerce_review_order_after_submit', array( $this, 'render_wrapper' ) );

		// The order-pay page (checkout/form-pay.php) does not fire the review-order hook above,
		// so also render the ECE wrapper after its own submit button — otherwise Apple Pay has
		// no surface to mount on and cannot pay the existing order.
		add_action( 'woocommerce_pay_order_after_submit', array( $this, 'render_wrapper' ) );
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
		$this->method_title       = __( 'Apple Pay', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Enable Apple Pay as Inline Payment Gateway.', 'funnelkit-stripe-woo-payment-gateway' );

		$this->title                = $this->get_option( 'title' );
		$this->subtitle             = __( 'Apple Pays allows customers to securely make payments using Apple Pay on their iPhone, iPad, or Apple Watch.', 'funnelkit-stripe-woo-payment-gateway' );
		$this->description          = $this->get_option( 'description' );
		$this->merchant_name        = $this->get_option( 'merchant_name' );
		$this->merchant_id          = $this->get_option( 'merchant_id' );
		$this->description          = $this->get_option( 'description' );
		$this->statement_descriptor = $this->get_option( 'statement_descriptor' );
		$this->capture_method       = $this->get_option( 'charge_type' );
		$this->button_type          = $this->get_option( 'button_type', 'plain' );
		$this->button_theme         = $this->get_option( 'button_theme', 'black' );
		if ( false === $this->is_configured() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_stripe_js' ) );
		$this->filter_hooks();
		add_filter( 'fkwcs_localized_data', array( $this, 'localize_element_data' ), 999 );
	}

	/**
	 * Add hooks
	 *
	 * @return void
	 */
	protected function filter_hooks() {
		add_filter( 'woocommerce_payment_successful_result', array( $this, 'modify_successful_payment_result' ), 999, 2 );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Initialise gateway settings form fields
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = apply_filters(
			'fkwcs_apple_pay_payment_form_fields',
			array(
				'enabled'               => array(
					'label'   => ' ',
					'type'    => 'checkbox',
					'title'   => __( 'Enable Apple Pay', 'funnelkit-stripe-woo-payment-gateway' ),
					'default' => 'no',
				),
				'verify_domain_apple'   => array(
					'title' => __( 'Re-verify Domain', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'  => 'fkwcs_apple_pay_verify',
					'desc'  => __( 'Click the button above to re-verify domain for Apple Pay.', 'funnelkit-stripe-woo-payment-gateway' ),
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
						'plain'      => __( 'Plain', 'funnelkit-stripe-woo-payment-gateway' ),
						'buy'        => __( 'Buy', 'funnelkit-stripe-woo-payment-gateway' ),
						'donate'     => __( 'Donate', 'funnelkit-stripe-woo-payment-gateway' ),
						'check-out'  => __( 'Check Out', 'funnelkit-stripe-woo-payment-gateway' ),
						'book'       => __( 'Book', 'funnelkit-stripe-woo-payment-gateway' ),
						'subscribe'  => __( 'Subscribe', 'funnelkit-stripe-woo-payment-gateway' ),
						'add-money'  => __( 'Add Money', 'funnelkit-stripe-woo-payment-gateway' ),
						'order'      => __( 'Order', 'funnelkit-stripe-woo-payment-gateway' ),
						'rent'       => __( 'Rent', 'funnelkit-stripe-woo-payment-gateway' ),
						'support'    => __( 'Support', 'funnelkit-stripe-woo-payment-gateway' ),
						'tip'        => __( 'Tip', 'funnelkit-stripe-woo-payment-gateway' ),
						'contribute' => __( 'Contribute', 'funnelkit-stripe-woo-payment-gateway' ),
					),
					'default' => 'plain',
				),
				'button_theme'          => array(
					'title'   => __( 'Button Theme', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'    => 'select',
					'options' => array(
						'black'         => __( 'Black', 'funnelkit-stripe-woo-payment-gateway' ),
						'white'         => __( 'White', 'funnelkit-stripe-woo-payment-gateway' ),
						'white-outline' => __( 'White Outline', 'funnelkit-stripe-woo-payment-gateway' ),
					),
					'default' => 'black',
				),
				'separator_text'        => array(
					'title'   => __( 'Separator Text', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'    => 'text',
					'default' => __( 'Or', 'funnelkit-stripe-woo-payment-gateway' ),
				),
				'disable_shipping_info' => array(
					'title'       => __( 'Disable Shipping Info in Payment Wallet', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'checkbox',
					'label'       => __( 'Hide shipping address and shipping methods in Apple Pay payment wallet', 'funnelkit-stripe-woo-payment-gateway' ),
					'description' => __( 'When enabled, Apple Pay will only handle payment authorization without requesting or displaying shipping information from the customer\'s wallet during checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
					'default'     => 'no',
					'desc_tip'    => false,
				),
				'charge_type'           => array(
					'title'       => __( 'Charge Type', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'select',
					'description' => __( $this->get_charge_type_recommendation_text(), 'funnelkit-stripe-woo-payment-gateway' ), //phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingTranslation -- This is a dynamic string that is being used to display the charge type recommendation text
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
					'default'     => __( 'Apple Pay', 'funnelkit-stripe-woo-payment-gateway' ),
					'desc_tip'    => true,
				),
				'description'           => array(
					'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'textarea',
					'css'         => 'width:25em',
					'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
					'default'     => __( 'Pay with Apple Pay', 'funnelkit-stripe-woo-payment-gateway' ),
					'desc_tip'    => true,
				),
			)
		);
	}

	/**
	 * Renders the Apple Pay "Re-verify Domain" button inside the gateway settings form.
	 * Moved here from the deprecated Express Checkout settings page (the migration left
	 * this control without a home). Reuses the existing admin.js handler + AJAX action.
	 *
	 * @param string $key
	 * @param array  $data
	 *
	 * @return string
	 */
	public function generate_fkwcs_apple_pay_verify_html( $key, $data ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<a class="button fkwcs_apple_pay_domain_verification" href="javascript:void(0)">
						<span><?php esc_html_e( 'Re-verify Domain', 'funnelkit-stripe-woo-payment-gateway' ); ?></span>
					</a>
				</fieldset>
				<p class="description"><?php echo esc_html( $data['desc'] ); ?></p>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	public function enqueue_stripe_js() {
		if ( ! $this->is_available() ) {
			return;
		}
		// Check if selected location is not the current location OR allow devs to enqueue assets
		if ( ! ( $this->is_selected_location() || ( apply_filters( 'fkwcs_enqueue_express_button_assets', false, $this ) ) ) ) {
			return;
		}
		parent::enqueue_stripe_js();
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

	/**
	 * Print the gateway field
	 *
	 * @return void
	 */
	public function payment_fields() {
		do_action( $this->id . '_before_payment_field_checkout' );
		include __DIR__ . '/parts/apple_pay.php';
		do_action( $this->id . '_after_payment_field_checkout' );
	}

	public function add_apple_pay_data( $fragments ) {

		$fragments['fkwcs_apple_pay_data'] = $this->ajax_get_cart_details();

		return $fragments;
	}

	public function get_method_description() {

		$description = $this->method_description;

		/* translators: %s: Documentation URL */
		$description .= sprintf( __( '<p>Note: Apple Pay Gateway visibility is dependent on Apple Pay supported browsers. <a href=%s>Learn more</a>', 'funnelkit-stripe-woo-payment-gateway' ), 'https://funnelkit.com/docs/stripe-gateway-for-woocommerce/troubleshooting/express-payment-buttons-not-showing/#apple-pay' );

		return $description;
	}

	public function get_icon() {

		$icons  = '<span class="fkwcs_stripe_apple_pay_icons">';
		$icons .= '<img src="' . \FKWCS_URL . 'assets/icons/apple_pay.svg' . '" alt="Visa" title="apple pay" /></span>';

		return $icons;
	}

	public function render_wrapper() {
		// GooglePay is instantiated twice on AJAX (WooCommerce gateway registration + the
		// GooglePay::get_instance() singleton booted in plugin.php), so both instances hook this
		// action. Guard against a duplicate wrapper so the ECE mounts to a single element.
		if ( $this->place_order_wrapper_rendered ) {
			return;
		}
		$this->place_order_wrapper_rendered = true;
		echo "<div class='fkwcs_stripe_apple_pay_button'></div>";
	}

	/**
	 * Localize Apple Pay settings for JS
	 *
	 * @param array $localize_data
	 *
	 * @return array
	 */
	public function localize_element_data( $localize_data ) {
		$localize_data['apple_pay_button_type']  = $this->button_type;
		$localize_data['apple_pay_button_theme'] = $this->button_theme;
		$localize_data['apple_pay_positions']    = $this->settings['display_locations'];

		// The regular-gateway Express Checkout Element arms its amount from the
		// fkwcs_cart_details fragment, which is never emitted on the order-pay page
		// (no cart / fragment refresh). Localize the existing order total here so the JS
		// can arm the ECE once on init. Shape mirrors SmartButtons::merge_cart_details().
		global $wp;
		if ( ! empty( $wp->query_vars['order-pay'] ) ) {
			$order = wc_get_order( absint( $wp->query_vars['order-pay'] ) );
			if ( $order instanceof \WC_Order ) {
				$localize_data['fkwcs_apple_pay_order_pay_data'] = array(
					'order_data' => array(
						'currency' => strtolower( $order->get_currency() ),
						'total'    => array(
							'amount' => max( 0, (int) apply_filters( 'fkwcs_stripe_calculated_total', Helper::get_stripe_amount( $order->get_total() ), $order->get_total() ) ),
						),
					),
				);
			}
		}

		return $localize_data;
	}
}
