<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe Gateway
 *
 * @package funnelkit-stripe-woo-payment-gateway
 */

use Exception;
use WC_Data_Store;
use WC_Subscriptions_Product;
use WC_Validation;

/**
 * Payment Request Api.
 */
#[\AllowDynamicProperties]
class SmartButtons extends CreditCard {
	private static $instance = null;

	public $local_settings = array();

	public $button_type           = '';
	public $product_page_position = '';
	protected $checkout_page_hook = '';

	/**
	 * Whether the express wrapper has already been printed this request.
	 *
	 * @var bool
	 */
	protected $express_wrapper_rendered = false;

	/**
	 * Hook/priority pairs payment_request_button() is registered on.
	 *
	 * @var array
	 */
	protected $express_button_hooks = array();

	/**
	 * Get the instance of the SmartButtons class
	 *
	 * @return SmartButtons
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {

		/**
		 * Validate if its setup correctly
		 */
		$this->set_api_keys();
		if ( false === $this->is_configured() ) {
			return;
		}

		// Sync hooks run unconditionally — position/separator must stay in sync even when express is off.
		add_action( 'woocommerce_update_options_payment_gateways_fkwcs_stripe_apple_pay', array( __CLASS__, 'sync_shared_position_settings' ), 20 );
		add_action( 'woocommerce_update_options_payment_gateways_fkwcs_stripe_google_pay', array( __CLASS__, 'sync_shared_position_settings' ), 20 );
		add_action( 'woocommerce_update_options_checkout_fkwcs_stripe_link', array( __CLASS__, 'sync_shared_position_settings' ), 20 );
		add_action( 'woocommerce_update_options_checkout_fkwcs_stripe_amazon_pay', array( __CLASS__, 'sync_shared_position_settings' ), 20 );

		$this->local_settings = Helper::get_gateway_settings();
		$apple_pay            = Helper::get_gateway_settings( 'fkwcs_stripe_apple_pay' );
		$google_pay_settings  = Helper::get_gateway_settings( 'fkwcs_stripe_google_pay' );
		$link_settings        = Helper::get_gateway_settings( 'fkwcs_stripe_link' );
		$amazon_pay_settings  = Helper::get_gateway_settings( 'fkwcs_stripe_amazon_pay' );

		$apple_pay_on      = isset( $apple_pay['enabled'] ) && 'yes' === $apple_pay['enabled'];
		$google_pay_ece_on = isset( $google_pay_settings['enabled'] ) && 'yes' === $google_pay_settings['enabled'];
		$link_on           = isset( $link_settings['enabled'] ) && 'yes' === $link_settings['enabled'];
		$amazon_pay_on     = isset( $amazon_pay_settings['enabled'] ) && 'yes' === $amazon_pay_settings['enabled'];

		// The legacy flag may be stale on migrated sites (was 'no' in old shared settings even though
		// individual methods are now enabled). Treat express as active if any method is on.
		$this->express_checkout = ( 'yes' === $this->local_settings['express_checkout_enabled'] || $apple_pay_on || $google_pay_ece_on || $link_on || $amazon_pay_on )
			? 'yes'
			: 'no';

		if ( 'yes' !== $this->express_checkout ) {
			return;
		}

		add_filter( 'fkwcs_localized_data', array( $this, 'add_js_params' ) );
		$this->capture_method = 'automatic';

		$product_page_action   = 'woocommerce_after_add_to_cart_quantity';
		$product_page_priority = 10;

		$settings = $this->local_settings;

		// Derive product page position from per-method settings (post-migration source of truth).
		// Per-method uses 'above-add-to-cart'/'below-add-to-cart'; map to legacy short values for hook/JS compat.
		// All methods share one container — first ENABLED method's position setting governs (Apple Pay → Google Pay → Link).
		$per_method_pos = '';
		foreach ( array( $apple_pay, $google_pay_settings, $link_settings, $amazon_pay_settings ) as $m ) {
			if ( isset( $m['enabled'] ) && 'yes' === $m['enabled'] && ! empty( $m['product_page_position'] ) ) {
				$per_method_pos = $m['product_page_position'];
				break;
			}
		}
		if ( 'below-add-to-cart' === $per_method_pos ) {
			$this->product_page_position = 'below';
		} elseif ( 'above-add-to-cart' === $per_method_pos ) {
			$this->product_page_position = 'above';
		} elseif ( 'inline' === $per_method_pos ) {
			$this->product_page_position = 'inline';
		} else {
			$this->product_page_position = $settings['express_checkout_product_page_position'] ?? '';
		}

		// No explicit per-method or legacy position saved — default to 'below' for parity with
		// FunnelKit PayPal (wallets render beneath the Add to Cart button). Explicit merchant
		// choices ('above'/'inline'/saved legacy values) resolved above are preserved.
		if ( empty( $this->product_page_position ) ) {
			$this->product_page_position = 'below';
		}

		if ( 'below' === $this->product_page_position || 'inline' === $this->product_page_position ) {
			$product_page_action   = 'woocommerce_after_add_to_cart_button';
			$product_page_priority = 1;
		}
		// Derive checkout page position from first enabled per-method setting; fall back to shared.
		$checkout_page_hook = $this->get_resolved_checkout_hook();

		$single_product_hook    = apply_filters( 'fkwcs_express_button_single_product_position', $product_page_action, $settings );
		$cart_page_hook         = apply_filters( 'fkwcs_express_button_cart_position', 'woocommerce_proceed_to_checkout' );
		$checkout_page_hook     = apply_filters( 'fkwcs_express_button_checkout_position', $checkout_page_hook );
		$product_page_priority  = apply_filters( 'fkwcs_express_button_single_product_position_priority', $product_page_priority );
		$checkout_page_priority = apply_filters( 'fkwcs_express_button_checkout_position_priority', 5 );
		$cart_page_priority     = apply_filters( 'fkwcs_express_button_cart_position_priority', 1 );

		/**
		 * hook in correct actions
		 */
		add_action( $single_product_hook, array( $this, 'payment_request_button' ), $product_page_priority );
		add_action( $cart_page_hook, array( $this, 'payment_request_button' ), $cart_page_priority );
		add_action( $checkout_page_hook, array( $this, 'payment_request_button' ), $checkout_page_priority );

		$this->express_button_hooks = array(
			array( $single_product_hook, $product_page_priority ),
			array( $cart_page_hook, $cart_page_priority ),
			array( $checkout_page_hook, $checkout_page_priority ),
		);

		add_filter( 'fkwcs_payment_request_localization', array( $this, 'localize_product_data' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'merge_cart_details' ), 1000 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'merge_cart_details' ), 1000 );
		add_filter( 'fkcart_fragments', array( $this, 'merge_cart_details' ), 1000 );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_stripe_js' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_stripe_js' ), 101 );
		$this->ajax_endpoints();
		$this->load_fk_checkout_compatibility();
	}

	/**
	 * Ajax callbacks declared
	 *
	 * @return void
	 */
	public function ajax_endpoints() {
		add_action( 'wc_ajax_fkwcs_button_payment_request', array( $this, 'process_smart_checkout' ) );
		add_action( 'wc_ajax_wc_stripe_create_order', array( $this, 'process_smart_checkout' ), - 1 );
		add_action( 'wc_ajax_fkwcs_get_cart_details', array( $this, 'ajax_get_cart_details' ) );
		add_action( 'wc_ajax_fkwcs_selected_product_data', array( $this, 'ajax_selected_product_data' ) );
		add_action( 'wc_ajax_fkwcs_update_shipping_address', array( $this, 'update_shipping_address' ) );
		add_action( 'wc_ajax_fkwcs_update_shipping_option', array( $this, 'update_shipping_option' ) );
		add_action( 'wc_ajax_fkwcs_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
	}

	/**
	 * Register Stripe assets
	 *
	 * @return void
	 */
	public function register_stripe_js() {
		parent::register_stripe_js();
		wp_register_script( 'fkwcs-express-checkout-js', FKWCS_URL . 'assets/js/express-checkout' . Helper::is_min_suffix() . '.js', array( Helper::get_stripesdk_handle() ), FKWCS_VERSION, array( 'in_footer' => false ) );
		wp_register_style( 'fkwcs-style', FKWCS_URL . 'assets/css/style.css', array(), FKWCS_VERSION );
	}

	/**
	 * Enqueue Stripe assets
	 *
	 * @return void
	 */
	public function enqueue_stripe_js() {
		$apple_pay = Helper::get_gateway_settings( 'fkwcs_stripe_apple_pay' );

		// Express buttons (Apple Pay / Google Pay / Link) are independent gateways and must not depend
		// on the Credit Card gateway (fkwcs_stripe) being enabled. Enqueue the express bundle whenever
		// Express Checkout is active on a supported location, regardless of the card gateway's `enabled`.
		$express_active = ( 'yes' === $this->express_checkout ) && $this->is_selected_location();

		$allow = $express_active || ( apply_filters( 'fkwcs_enqueue_express_button_assets', false, $this ) ) || ( isset( $apple_pay['enabled'] ) && 'yes' === $apple_pay['enabled'] && 'yes' !== $this->express_checkout && is_checkout() );
		if ( ! $allow ) {
			return;
		}

		wp_enqueue_style( 'fkwcs-style' );
		wp_enqueue_script( 'fkwcs-express-checkout-js' );

		// Localize fkwcs_data (full fkwcs_localized_data chain, incl. pub_key) onto the express handle
		// exactly once, and only when the core card element path did not already fire it. This keeps the
		// wallet payload complete when the card gateway is disabled and never enqueued its own data.
		if ( 0 === did_action( 'fkwcs_core_element_js_enqueued' ) ) {

			wp_localize_script( 'fkwcs-express-checkout-js', 'fkwcs_data', $this->localize_data() );
		}
		do_action( 'fkwcs_smart_buttons_js_enqueued' );
	}

	/**
	 * Localize important data
	 *
	 * @param $localize_data
	 *
	 * @return array
	 */
	public function add_js_params( $localize_data ) {
		$currency            = get_woocommerce_currency();
		$apple_pay           = Helper::get_gateway_settings( 'fkwcs_stripe_apple_pay' );
		$google_pay          = Helper::get_gateway_settings( 'fkwcs_stripe_google_pay' );
		$localize_data_added = array(
			// Express buttons (Apple Pay / Google Pay / Link) are independent gateways and must
			// not depend on the credit card gateway (local_settings['enabled']) being enabled.
			'express_pay_enabled'         => ( 'yes' === $this->express_checkout ) ? 'yes' : 'no',
			'checkout_nonce'              => wp_create_nonce( 'woocommerce-process_checkout' ),
			'apple_pay_individual'        => ( isset( $apple_pay['enabled'] ) && 'yes' === $apple_pay['enabled'] && 'yes' !== $this->express_checkout ) ? 'yes' : 'no',
			'apple_pay_disable_shipping'  => ( isset( $apple_pay['disable_shipping_info'] ) && 'yes' === $apple_pay['disable_shipping_info'] ) ? 'yes' : 'no',
			'google_pay_disable_shipping' => ( isset( $google_pay['disable_shipping_info'] ) && 'yes' === $google_pay['disable_shipping_info'] ) ? 'yes' : 'no',
			'is_product'                  => $this->is_product() ? 'yes' : 'no',
			'is_cart'                     => $this->is_cart() ? 'yes' : 'no',
			'wc_endpoints'                => self::get_public_endpoints(),
			'currency'                    => strtolower( $currency ),
			'country_code'                => substr( get_option( 'woocommerce_default_country' ), 0, 2 ),
			'shipping_required'           => wc_bool_to_string( $this->shipping_required() ),
			'icons'                       => array(
				'applepay_gray'  => FKWCS_URL . 'assets/icons/apple_pay_gray.svg',
				'applepay_light' => FKWCS_URL . 'assets/icons/apple_pay_light.svg',
				'gpay_light'     => FKWCS_URL . 'assets/icons/gpay_light.svg',
				'gpay_gray'      => FKWCS_URL . 'assets/icons/gpay_gray.svg',
				'link'           => FKWCS_URL . 'assets/icons/link.svg',
			),
			'debug_log'                   => ! empty( get_option( 'fkwcs_debug_log' ) ) ? get_option( 'fkwcs_debug_log' ) : 'no',
			'debug_msg'                   => __( 'Stripe enabled Payment Request is not available in this browser', 'funnelkit-stripe-woo-payment-gateway' ),
		);

		if ( $this->is_product() ) {
			$localize_data_added['single_product'] = $this->get_product_data();
		}

		if ( $this->is_cart() ) {
			$localize_data_added['cart_data'] = $this->ajax_get_cart_details( true );
		}

		$link_settings                              = Helper::get_gateway_settings( 'fkwcs_stripe_link' );
		$link_enabled                               = isset( $link_settings['enabled'] ) ? $link_settings['enabled'] : 'no';
		$localize_data_added['link_button_enabled'] = wc_string_to_bool( $link_enabled ) ? 'yes' : 'no';

		$amazon_pay_settings                              = Helper::get_gateway_settings( 'fkwcs_stripe_amazon_pay' );
		$amazon_pay_enabled                               = isset( $amazon_pay_settings['enabled'] ) ? $amazon_pay_settings['enabled'] : 'no';
		$localize_data_added['amazon_pay_button_enabled'] = wc_string_to_bool( $amazon_pay_enabled ) ? 'yes' : 'no';

		$localize_data_added['style'] = array(
			'theme'                  => $this->get_resolved_button_theme(),
			'button_position'        => $this->product_page_position,
			'checkout_button_width'  => ( ! empty( $this->local_settings['express_checkout_button_width'] ) ? $this->local_settings['express_checkout_button_width'] : '' ),
			'apple_button_type'      => isset( $apple_pay['button_type'] ) ? $apple_pay['button_type'] : 'plain',
			'google_pay_button_type' => isset( $google_pay['button_type'] ) ? $google_pay['button_type'] : 'plain',
		);
		return array_merge( $localize_data, $localize_data_added );
	}

	/**
	 * Decides whether a per-method express gateway should render at the current location.
	 *
	 * Distinguishes three states of the per-method `display_locations` setting:
	 * - key absent / not an array (never configured — pre-migration or gateway never
	 *   saved) → legacy default: return true and let the shared fallback decide.
	 * - key present but empty array (admin explicitly cleared & saved) → show nowhere,
	 *   so strict membership over `[]` yields false.
	 * - key present and non-empty → strict membership against the saved locations.
	 *
	 * `empty()` is deliberately NOT used here: `empty( [] )` is true for both the unset
	 * and the explicitly-cleared states, which is the exact bug this method fixes.
	 *
	 * @since 1.14.0.4
	 *
	 * @param array  $settings         Per-method gateway settings from Helper::get_gateway_settings().
	 * @param string $current_location Location slug for the current page ('product'|'cart'|'checkout').
	 *
	 * @return bool True when the button should render at $current_location.
	 */
	private function is_display_location_enabled( array $settings, $current_location ) {
		if ( ! array_key_exists( 'display_locations', $settings ) ) {
			// Key never saved — preserve legacy behaviour (defer to shared fallback).
			return true;
		}

		$locations = is_array( $settings['display_locations'] ) ? $settings['display_locations'] : array();

		return in_array( $current_location, $locations, true );
	}

	/**
	 * Checks if current location is chosen to display express checkout button
	 *
	 * @return boolean
	 */
	private function is_selected_location() {
		// Aggregate display locations from all enabled per-method settings (post-1.15 source of truth).
		$location       = array();
		$has_per_method = false;
		foreach ( array( 'fkwcs_stripe_apple_pay', 'fkwcs_stripe_google_pay', 'fkwcs_stripe_link', 'fkwcs_stripe_amazon_pay' ) as $gid ) {
			$s = Helper::get_gateway_settings( $gid );
			if ( ! ( isset( $s['enabled'] ) && 'yes' === $s['enabled'] ) ) {
				continue;
			}
			// An enabled method that saved the key (even as []) opts out of the legacy fallback.
			if ( array_key_exists( 'display_locations', $s ) ) {
				$has_per_method = true;
			}
			if ( is_array( $s['display_locations'] ?? null ) ) {
				$location = array_unique( array_merge( $location, $s['display_locations'] ) );
			}
		}
		// Fall back to legacy shared location only when NO enabled method configured its own
		// locations. An explicitly-cleared list ([]) must resolve to "show nowhere", not leak
		// back to the shared setting.
		if ( empty( $location ) && ! $has_per_method ) {
			$location = $this->local_settings['express_checkout_location'] ?? array();
		}

		if ( is_array( $location ) && ! empty( $location ) ) {
			if ( $this->is_product() && in_array( 'product', $location, true ) ) {
				return true;
			}
			if ( is_cart() && in_array( 'cart', $location, true ) ) {
				return true;
			}
			if ( is_checkout() && in_array( 'checkout', $location, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Creates container for payment request button
	 *
	 * @return void
	 */
	/**
	 * Whether at least one Stripe gateway able to power the express buttons is
	 * available for the current request.
	 *
	 * Apple Pay / Google Pay / Link are independent gateways now, so the express
	 * buttons must not depend on the credit card gateway (fkwcs_stripe) being
	 * enabled. Returns true when the card gateway OR any enabled express wallet
	 * gateway is available.
	 *
	 * @return bool
	 */
	protected function has_available_express_gateway() {
		$gateways = WC()->payment_gateways->get_available_payment_gateways();

		if ( isset( $gateways['fkwcs_stripe'] )
			|| isset( $gateways['fkwcs_stripe_apple_pay'] )
			|| isset( $gateways['fkwcs_stripe_google_pay'] ) ) {
			return true;
		}

		// Link is not registered via woocommerce_payment_gateways, so it can never be a key in
		// the available-gateways list. Its express orders are processed by the card gateway,
		// which opens itself for wallet/link requests during checkout even when disabled
		// (CreditCard::is_payment_request_for_supported_method()), so an enabled Link setting
		// alone is enough to power the express buttons.
		$link_settings = Helper::get_gateway_settings( 'fkwcs_stripe_link' );

		return isset( $link_settings['enabled'] ) && 'yes' === $link_settings['enabled'];
	}

	/**
	 * Claims the one express-wrapper render allowed per request.
	 *
	 * The wrapper is emitted from two places (payment_request_button() and
	 * _payment_request_button()), reached from three registered hook positions
	 * plus a direct call in the FunnelKit Checkout compatibility. Whichever gets
	 * here first owns the render; every later caller is refused, so the page can
	 * never end up with more than one #fkwcs_stripe_smart_button_wrapper and the
	 * duplicate element ids that came with it.
	 *
	 * The remaining registrations are unhooked as well, so later positions do not
	 * even enter the callback. The flag is what guarantees correctness - the
	 * unhook cannot cover the direct call - but removing them keeps the hook list
	 * honest for anything that inspects it with has_action().
	 *
	 * @return bool True when the caller may print the wrapper.
	 */
	protected function claim_express_render() {
		if ( $this->express_wrapper_rendered ) {
			return false;
		}
		$this->express_wrapper_rendered = true;

		foreach ( $this->express_button_hooks as $hook ) {
			remove_action( $hook[0], array( $this, 'payment_request_button' ), $hook[1] );
		}

		return true;
	}

	public function payment_request_button( $force_display = false ) {
		if ( ! $this->has_available_express_gateway() ) {
			return;
		}
		$apple_pay = Helper::get_gateway_settings( 'fkwcs_stripe_apple_pay' );
		$allow     = ( $this->is_selected_location() || true === $force_display ) && ( 'yes' === $this->express_checkout || ( isset( $apple_pay['enabled'] ) && 'yes' === $apple_pay['enabled'] && true === $force_display ) );
		if ( ! $allow ) {
			return;
		}
		if ( $this->is_checkout() && did_action( 'wfacp_after_checkout_page_found' ) === 0 ) {

			$this->_payment_request_button( $force_display );

			return;
		}

		if ( ! $this->claim_express_render() ) {
			return;
		}

		$div_wrapper = false;
		try {
			if ( $this->is_product() || $this->is_cart() || ( $this->is_checkout() && function_exists( 'wfacp_template' ) && false === wc_string_to_bool( \WFACP_Common::get_page_settings( \WFACP_Common::get_id() )['enable_smart_buttons'] ) ) ) {

				echo '<div class="fkwcs_stripe_smart_button_wrapper">';
				$div_wrapper = true;
			}

			// Below add-to-cart: separator goes above the buttons (between the ATC button and express options).
			if ( $div_wrapper && $this->is_product() && 'below' === $this->product_page_position ) {
				$this->payment_request_button_separator();
			}

			$current_location = 'checkout';
			if ( $this->is_product() ) {
				$current_location = 'product';
			} elseif ( $this->is_cart() ) {
				$current_location = 'cart';
			}

			$apple_here = $this->is_display_location_enabled( $apple_pay, $current_location );
			if ( isset( $apple_pay['enabled'] ) && 'yes' === $apple_pay['enabled'] && $apple_here ) {
				$this->_simple_button_wrapper( $force_display, 'fkwcs_apple_pay_button' );
			}

			$google_settings = Helper::get_gateway_settings( 'fkwcs_stripe_google_pay' );
			$google_enabled  = isset( $google_settings['enabled'] ) ? $google_settings['enabled'] : 'no';
			$google_here     = $this->is_display_location_enabled( $google_settings, $current_location );
			if ( 'yes' === $google_enabled && $google_here ) {
				$this->_simple_button_wrapper( $force_display, 'fkwcs_google_pay_button' );
			}

			$link_settings = Helper::get_gateway_settings( 'fkwcs_stripe_link' );
			$link_enabled  = isset( $link_settings['enabled'] ) ? $link_settings['enabled'] : 'no';
			$link_here     = $this->is_display_location_enabled( $link_settings, $current_location );
			if ( wc_string_to_bool( $link_enabled ) && $link_here ) {
				$this->_simple_button_wrapper( $force_display, 'fkwcs_link_pay_button' );
			}
			$amazon_pay_settings = Helper::get_gateway_settings( 'fkwcs_stripe_amazon_pay' );
			$amazon_pay_enabled  = isset( $amazon_pay_settings['enabled'] ) ? $amazon_pay_settings['enabled'] : 'no';
			$amazon_here         = $this->is_display_location_enabled( $amazon_pay_settings, $current_location );
			if ( wc_string_to_bool( $amazon_pay_enabled ) && $amazon_here ) {
				$this->_simple_button_wrapper( $force_display, 'fkwcs_amazon_pay_button' );
			}

			if ( $div_wrapper ) {
				// Above add-to-cart: separator goes below the buttons (standard position).
				if ( ! ( $this->is_product() && 'below' === $this->product_page_position ) ) {
					$this->payment_request_button_separator();
				}
				echo '</div>';
			}
		} catch ( \Exception | \Error $e ) {

			return;
		}
	}

	public function _simple_button_wrapper( $force_display = false, $context = '' ) {
		if ( ! $this->has_available_express_gateway() ) {
			return;
		}

		if ( ! ( $this->is_selected_location() || true === $force_display ) ) {
			return;
		}

		if ( 'yes' !== $this->express_checkout ) {
			return;
		}
		$options          = $this->local_settings;
		$button_width     = '';
		$button_max_width = '';
		$extra_class      = 'fkwcs_smart_checkout_button';
		$sec_classes      = array( 'fkwcs_stripe_smart_button_wrapper' );
		if ( $this->is_product() ) {
			$extra_class   = 'fkwcs_smart_product_button';
			$sec_classes[] = 'fkwcs-product';
			if ( 'below' === $this->product_page_position ) {
				$sec_classes[] = 'below';
			}
			if ( 'inline' === $this->product_page_position ) {
				$sec_classes[] = 'inline';
			}
		} elseif ( $this->is_cart() || did_action( 'fkcart_before_cart_items' ) ) {
			$extra_class   = 'fkwcs_smart_cart_button';
			$sec_classes[] = 'cart';
		}
		// FKCart drawer renders through an admin-ajax fragment refresh where neither
		// is_cart() nor the fkcart_before_cart_items action is available (e.g. drawer
		// opened from shop/home). Without the cart classes the inner div would keep
		// fkwcs_smart_checkout_button and the drawer's JS selector
		// (.fkwcs_smart_cart_button) never matches. Force the cart classes for any
		// FKCart drawer slot so the button mounts on non-cart-page fragment renders.
		if ( 0 === strpos( (string) $context, 'fkcart_fkwcs_smart_button_' ) ) {
			$extra_class = 'fkwcs_smart_cart_button';
			if ( ! in_array( 'cart', $sec_classes, true ) ) {
				$sec_classes[] = 'cart';
			}
		}
		if ( ! empty( $context ) ) {
			$sec_classes[] = $context;
		}
		?>
		<div class="fkwcs_express_smart_button_wrapper <?php echo esc_attr( implode( ' ', $sec_classes ) ); ?> " id="<?php echo esc_attr( implode( '_', $sec_classes ) ); ?>">
			<div class="fkwcs_smart_buttons <?php echo esc_attr( $extra_class ); ?>" style="display:none;<?php echo esc_attr( $button_width . $button_max_width ); ?>">
			</div>

			<span class="fkwcs_smart_button_trigger"></span> <!-- use for trigger funnelkit checkout smart buttons-->

		</div>
		<?php
	}

	public function _payment_request_button( $force_display = false, $context = '' ) {
		if ( ! $this->has_available_express_gateway() ) {
			return;
		}

		if ( ! ( $this->is_selected_location() || true === $force_display ) ) {
			return;
		}

		if ( 'yes' !== $this->express_checkout ) {
			return;
		}

		if ( ! $this->claim_express_render() ) {
			return;
		}

		$options = $this->local_settings;

		$separator_below     = true;
		$alignment_class     = '';
		$button_width        = '';
		$container_max_width = '';
		$button_max_width    = '';
		$extra_class         = 'fkwcs_smart_checkout_button';
		$sec_classes         = array( 'fkwcs_stripe_smart_button_wrapper' );
		if ( $this->is_product() ) {
			$extra_class   = 'fkwcs_smart_product_button';
			$sec_classes[] = 'fkwcs-product';

			if ( 'below' === $this->product_page_position ) {
				$separator_below = false;
				$sec_classes[]   = 'below';
			}

			if ( 'inline' === $this->product_page_position ) {
				$separator_below = false;
				$sec_classes[]   = 'inline';
			}
		} elseif ( $this->is_cart() || did_action( 'fkcart_before_cart_items' ) ) {
			$extra_class   = 'fkwcs_smart_cart_button';
			$sec_classes[] = 'cart';
		}
		if ( ! empty( $context ) ) {
			$sec_classes[] = $context;
		}

		if ( $this->is_checkout() ) {
			$sec_classes[]   = 'checkout';
			$alignment_class = $options['express_checkout_button_alignment'];
			if ( ! empty( $options['express_checkout_button_width'] && absint( $options['express_checkout_button_width'] ) > 0 ) ) {
				$button_width = 'min-width:' . (int) $options['express_checkout_button_width'] . 'px';

				if ( (int) $options['express_checkout_button_width'] > 500 ) {
					$button_width = 'max-width:' . (int) $options['express_checkout_button_width'] . 'px;';
				}
			} else {
				$button_width = 'width: 100%';
			}
		}

		$button_theme = 'fkwcs_ec_payment_button-' . $this->get_resolved_button_theme();
		$only_buttons = apply_filters( 'fkwcs_express_buttons_is_only_buttons', false );

		?>
		<div id="fkwcs_stripe_smart_button_wrapper" class="<?php echo esc_attr( implode( ' ', $sec_classes ) ); ?>">
			<div id="fkwcs_stripe_smart_button" style="<?php echo esc_attr( ! empty( $alignment_class ) ? 'text-align:' . $alignment_class . ';' : '' ); ?><?php echo esc_attr( $container_max_width ); ?>">
				<?php
				if ( ! $separator_below && false === $only_buttons ) {
					$this->payment_request_button_separator();
				}

				if ( $this->is_checkout() && false === $only_buttons ) {
					echo "<fieldset id='fkwcs-expresscheckout-fieldset'>";
					/**
					 * Filters whether the Express Checkout heading (<legend>) is rendered on checkout.
					 *
					 * Returning false hides only the <legend>; the <fieldset> box and the "Or"
					 * separator are kept intact. Used to de-duplicate the heading when another
					 * express-checkout section (e.g. FunnelKit PayPal) already shows it.
					 *
					 * @since 1.14.0.4
					 *
					 * @param bool         $show     Whether to render the heading. Default true.
					 * @param SmartButtons $instance The SmartButtons gateway instance.
					 */
					$show_heading = apply_filters( 'fkwcs_show_express_checkout_heading', true, $this );
					if ( $show_heading && ! empty( trim( $options['express_checkout_title'] ) ) ) {
						?>
						<legend><?php echo esc_html( $options['express_checkout_title'] ); ?></legend>
						<?php
					}
				}
				$apple_pay_settings = Helper::get_gateway_settings( 'fkwcs_stripe_apple_pay' );
				$apple_on_checkout  = $this->is_display_location_enabled( $apple_pay_settings, 'checkout' );
				if ( isset( $apple_pay_settings['enabled'] ) && 'yes' === $apple_pay_settings['enabled'] && $apple_on_checkout ) {
					$this->_simple_button_wrapper( $force_display, 'fkwcs_apple_pay_button' );
				}

				$google_pay_settings = Helper::get_gateway_settings( 'fkwcs_stripe_google_pay' );
				$google_enabled      = isset( $google_pay_settings['enabled'] ) ? $google_pay_settings['enabled'] : 'no';
				$google_on_checkout  = $this->is_display_location_enabled( $google_pay_settings, 'checkout' );
				if ( 'yes' === $google_enabled && $google_on_checkout ) {
					$this->_simple_button_wrapper( $force_display, 'fkwcs_google_pay_button' );
				}

				$link_settings    = Helper::get_gateway_settings( 'fkwcs_stripe_link' );
				$link_enabled     = isset( $link_settings['enabled'] ) ? $link_settings['enabled'] : 'no';
				$link_on_checkout = $this->is_display_location_enabled( $link_settings, 'checkout' );
				if ( wc_string_to_bool( $link_enabled ) && $link_on_checkout ) {
					$this->_simple_button_wrapper( $force_display, 'fkwcs_link_pay_button' );
				}
				$amazon_pay_settings = Helper::get_gateway_settings( 'fkwcs_stripe_amazon_pay' );
				$amazon_pay_enabled  = isset( $amazon_pay_settings['enabled'] ) ? $amazon_pay_settings['enabled'] : 'no';
				$amazon_on_checkout  = $this->is_display_location_enabled( $amazon_pay_settings, 'checkout' );
				if ( wc_string_to_bool( $amazon_pay_enabled ) && $amazon_on_checkout ) {
					$this->_simple_button_wrapper( $force_display, 'fkwcs_amazon_pay_button' );
				}

				if ( $this->is_checkout() && false === $only_buttons ) {
					echo '</fieldset>';
				}

				if ( $separator_below && false === $only_buttons ) {
					$this->payment_request_button_separator();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Creates separator for payment request button
	 *
	 * @return void
	 */
	public function payment_request_button_separator() {
		if ( 'yes' !== $this->express_checkout ) {
			return;
		}
		$display_separator = false;
		$container_class   = '';
		if ( $this->is_product() ) {
			$display_separator = true;
			$container_class   = 'fkwcs-product';
		} elseif ( is_checkout() ) {
			$container_class   = 'checkout';
			$display_separator = true;
		} elseif ( is_cart() ) {
			$container_class = 'cart';
		}

		// Read separator from per-method settings (synced across all methods since 1.15).
		$per_method_sep = '';
		foreach ( array( 'fkwcs_stripe_apple_pay', 'fkwcs_stripe_google_pay', 'fkwcs_stripe_link', 'fkwcs_stripe_amazon_pay' ) as $gid ) {
			$s = Helper::get_gateway_settings( $gid );
			if ( isset( $s['enabled'] ) && 'yes' === $s['enabled'] && ! empty( $s['separator_text'] ) ) {
				$per_method_sep = $s['separator_text'];
				break;
			}
		}

		$options = $this->local_settings;
		if ( ! empty( $per_method_sep ) ) {
			$separator_text = $per_method_sep;
			if ( 'cart' === $container_class ) {
				$display_separator = true;
			}
		} else {
			// Legacy fallback.
			$separator_text = $options['express_checkout_separator_product'] ?? 'Or';
			if ( 'checkout' === $container_class && ! empty( $options['express_checkout_separator_checkout'] ?? '' ) ) {
				$separator_text = $options['express_checkout_separator_checkout'];
			}
			if ( 'cart' === $container_class && ! empty( $options['express_checkout_separator_cart'] ?? '' ) ) {
				$separator_text    = $options['express_checkout_separator_cart'];
				$display_separator = true;
			}
		}

		if ( 'fkwcs-product' === $container_class && 'inline' === $this->product_page_position ) {
			$display_separator = false;
		}
		$display_separator = apply_filters( 'fkwcs_show_or_separator', $display_separator, $separator_text );

		if ( ! empty( $separator_text ) && $display_separator ) {
			?>
			<div id="fkwcs-payment-request-separator" class="<?php echo esc_attr( $container_class ); ?>">
				<label><?php echo esc_html( $separator_text ); ?></label>
			</div>
			<?php
		}
	}

	/**
	 * Get price of selected product
	 *
	 * @param object $product Selected product data.
	 *
	 * @return string
	 */
	public function get_product_price( $product ) {
		$product_price = floatval( $product->get_price() );
		/** Add subscription sign-up fees to product price */
		if ( 'subscription' === $product->get_type() && class_exists( 'WC_Subscriptions_Product' ) ) {
			$product_price = floatval( $product->get_price() ) + floatval( WC_Subscriptions_Product::get_sign_up_fee( $product ) );
		}

		return $product_price;
	}

	/**
	 * Get data of selected product
	 *
	 * @return false|mixed|null
	 * @throws Exception
	 */
	public function get_product_data() {
		if ( ! $this->is_product() ) {
			return false;
		}

		$product = $this->get_product();

		if ( empty( $product ) ) {
			return false;
		}

		if ( 'variable' === $product->get_type() ) {
			$variation_attributes = $product->get_variation_attributes();
			$attributes           = array();

			foreach ( $variation_attributes as $attribute_name => $attribute_values ) {
				$attribute_key = 'attribute_' . sanitize_title( $attribute_name );

				$attributes[ $attribute_key ] = isset( $_GET[ $attribute_key ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					? wc_clean( wp_unslash( $_GET[ $attribute_key ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					: $product->get_variation_default_attribute( $attribute_name );
			}

			$data_store   = WC_Data_Store::load( 'product' );
			$variation_id = $data_store->find_matching_product_variation( $product, $attributes );

			if ( ! empty( $variation_id ) ) {
				$product = wc_get_product( $variation_id );
			}
		}

		$data  = array();
		$items = array();

		if ( 'subscription' === $product->get_type() && class_exists( 'WC_Subscriptions_Product' ) ) {
			$items[] = array(
				'label'  => $product->get_name(),
				'amount' => Helper::get_stripe_amount( floatval( $product->get_price() ) ),
			);

			$items[] = array(
				'label'  => __( 'Sign up Fee', 'funnelkit-stripe-woo-payment-gateway' ),
				'amount' => Helper::get_stripe_amount( floatval( WC_Subscriptions_Product::get_sign_up_fee( $product ) ) ),
			);
		} else {
			$items[] = array(
				'label'  => $product->get_name(),
				'amount' => Helper::get_stripe_amount( $this->get_product_price( $product ) ),
			);
		}

		if ( wc_tax_enabled() ) {
			$items[] = array(
				'label'   => __( 'Tax', 'funnelkit-stripe-woo-payment-gateway' ),
				'amount'  => 0,
				'pending' => true,
			);
		}

		if ( wc_shipping_enabled() && $product->needs_shipping() ) {
			$items[] = array(
				'label'   => __( 'Shipping', 'funnelkit-stripe-woo-payment-gateway' ),
				'amount'  => 0,
				'pending' => true,
			);

			$data['shippingOptions'] = array(
				'id'     => 'pending',
				'label'  => __( 'Pending', 'funnelkit-stripe-woo-payment-gateway' ),
				'detail' => '',
				'amount' => 0,
			);
		}

		$data['displayItems']    = $items;
		$data['total']           = array(
			'label'   => __( 'Total', 'funnelkit-stripe-woo-payment-gateway' ),
			'amount'  => Helper::get_stripe_amount( $this->get_product_price( $product ) ),
			'pending' => true,
		);
		$data['requestShipping'] = wc_bool_to_string( wc_shipping_enabled() && $product->needs_shipping() && 0 !== wc_get_shipping_method_count( true ) );

		return apply_filters( 'fkwcs_payment_request_product_data', $data, $product );
	}

	/**
	 * Adds product data to localized data via filter
	 *
	 * @param $localized_data
	 *
	 * @return array|false[]|null[]
	 * @throws Exception
	 */
	public function localize_product_data( $localized_data ) {
		return array_merge( $localized_data, array( 'product' => $this->get_product_data() ) );
	}

	public function load_fk_checkout_compatibility() {

		try {
			if ( class_exists( '\WFACP_Core' ) ) {
				include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/class-fk-checkout.php';

				new \FKWCS_Compat_FK_Checkout();
			}
		} catch ( \Exception | \Error $e ) {

		}
		if ( class_exists( '\FKCart\Plugin' ) ) {
			include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/fk-cart.php';
			new \FKWCS_Compat_FK_Cart();
		}
	}

	/**
	 * The WooCommerce hook the express buttons render on for the checkout page. Kept as a
	 * single source of truth shared by SmartButtons and the FunnelKit Checkout compatibility
	 * so placement stays consistent.
	 *
	 * Resolution order: the checkout_page_position of the first enabled express method
	 * (Apple Pay, Google Pay, Link, Amazon Pay), then the legacy shared setting, then
	 * 'above-checkout'. All express methods share one button container, so the first
	 * enabled method's position governs the whole group.
	 *
	 * @return string
	 */
	public function get_resolved_checkout_hook(): string {
		$per_method_checkout_pos = '';

		foreach ( array( 'fkwcs_stripe_apple_pay', 'fkwcs_stripe_google_pay', 'fkwcs_stripe_link', 'fkwcs_stripe_amazon_pay' ) as $gid ) {
			$method_settings = Helper::get_gateway_settings( $gid );
			if ( isset( $method_settings['enabled'] ) && 'yes' === $method_settings['enabled'] && ! empty( $method_settings['checkout_page_position'] ) ) {
				$per_method_checkout_pos = $method_settings['checkout_page_position'];
				break;
			}
		}

		$checkout_pos = ! empty( $per_method_checkout_pos ) ? $per_method_checkout_pos : ( $this->local_settings['express_checkout_checkout_page_position'] ?? 'above-checkout' );

		return ( 'above-checkout' === $checkout_pos ) ? 'woocommerce_checkout_before_customer_details' : 'woocommerce_checkout_billing';
	}

	/**
	 * Maps per-method Apple Pay button_theme (black/white/white-outline) back to the legacy
	 * global dark/light value expected by JS and CSS. Falls back to the shared setting.
	 */
	private function get_resolved_button_theme(): string {
		$apple     = Helper::get_gateway_settings( 'fkwcs_stripe_apple_pay' );
		$raw_theme = $apple['button_theme'] ?? '';
		if ( 'white-outline' === $raw_theme ) {
			return 'light-outline';
		}
		if ( 'white' === $raw_theme ) {
			return 'light';
		}
		if ( 'black' === $raw_theme ) {
			return 'dark';
		}

		// Legacy shared fallback. Normalise to the values the JS understands ('dark'/'light'/'light-outline')
		// so a stale stored value can't reintroduce the same white-outline mismatch.
		$legacy = ! empty( $this->local_settings['express_checkout_button_theme'] ) ? $this->local_settings['express_checkout_button_theme'] : 'dark';
		if ( 'white-outline' === $legacy ) {
			return 'light-outline';
		}
		if ( 'white' === $legacy ) {
			return 'light';
		}
		if ( 'black' === $legacy ) {
			return 'dark';
		}
		return in_array( $legacy, array( 'dark', 'light', 'light-outline' ), true ) ? $legacy : 'dark';
	}

	/**
	 * Propagates product_page_position, checkout_page_position and separator_text to the other
	 * express methods whenever one is saved. They all share one button container, so these
	 * settings must stay in sync across Apple Pay, Google Pay, Link and Amazon Pay.
	 *
	 * checkout_page_position in particular is load-bearing: get_resolved_checkout_hook() reads
	 * it from the first enabled method, so an out-of-sync value would make button placement
	 * depend on which method happens to be enabled first.
	 */
	public static function sync_shared_position_settings() {
		$hook_to_gateway = array(
			'woocommerce_update_options_payment_gateways_fkwcs_stripe_apple_pay' => 'fkwcs_stripe_apple_pay',
			'woocommerce_update_options_payment_gateways_fkwcs_stripe_google_pay' => 'fkwcs_stripe_google_pay',
			'woocommerce_update_options_checkout_fkwcs_stripe_link'               => 'fkwcs_stripe_link',
			'woocommerce_update_options_checkout_fkwcs_stripe_amazon_pay'         => 'fkwcs_stripe_amazon_pay',
		);

		$source_id = $hook_to_gateway[ current_filter() ] ?? null;
		if ( ! $source_id ) {
			return;
		}

		$source      = get_option( 'woocommerce_' . $source_id . '_settings', array() );
		$sync_fields = array( 'product_page_position', 'checkout_page_position', 'separator_text' );
		$targets     = array( 'fkwcs_stripe_apple_pay', 'fkwcs_stripe_google_pay', 'fkwcs_stripe_link', 'fkwcs_stripe_amazon_pay' );

		foreach ( $targets as $target_id ) {
			if ( $target_id === $source_id ) {
				continue;
			}
			$target  = get_option( 'woocommerce_' . $target_id . '_settings', array() );
			$changed = false;
			foreach ( $sync_fields as $field ) {
				if ( isset( $source[ $field ] ) && ( ! isset( $target[ $field ] ) || $target[ $field ] !== $source[ $field ] ) ) {
					$target[ $field ] = $source[ $field ];
					$changed          = true;
				}
			}
			if ( $changed ) {
				update_option( 'woocommerce_' . $target_id . '_settings', $target );
			}
		}
	}
}
