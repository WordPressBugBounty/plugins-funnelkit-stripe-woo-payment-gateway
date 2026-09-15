<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe for WooCommerce by FunnelKit
 */

use FKWCS\Gateway\Stripe\SmartButtons;
use FKWCS\Gateway\Stripe\GooglePay;

if ( ! class_exists( 'FkCart_Compatibilities_FunnelKit_Stripe' ) ) {

	class FKWCS_Compat_FK_Cart {
		public function __construct() {

			add_filter( 'fkcart_smart_buttons', array( $this, 'register_smart_button' ), 99 );
			add_action( 'fkcart_fkwcs_smart_button_apple_pay', array( $this, 'print_smart_button' ) );
			add_action( 'fkcart_fkwcs_smart_button_link', array( $this, 'print_smart_button' ) );
			add_action( 'fkcart_fkwcs_smart_button_google_pay', array( $this, 'print_smart_button' ) );
			add_action( 'fkcart_fkwcs_smart_button_amazon_pay', array( $this, 'print_smart_button' ) );
			add_action( 'fkcart_fkwcs_smart_button_gpay', array( $this, 'print_smart_button_gpay' ) );
			add_filter( 'fkcart_smart_buttons_wrappers', array( $this, 'add_css_wrapper' ) );
			add_filter( 'fkwcs_enqueue_express_button_assets', array( $this, 'maybe_enqueue_assets' ) );
			add_filter( 'fkwcs_gateway_settings', array( $this, 'present_card_settings_as_express_enabled' ), 10, 2 );
		}

		/**
		 * FunnelKit Cart's Data::is_smart_button_enabled() still gates the mini-cart smart
		 * buttons on the credit card gateway's own settings (express_checkout_enabled + enabled).
		 * Apple Pay / Google Pay / Link are independent gateways now, so present the card
		 * settings as express-enabled whenever any express wallet is enabled. This keeps the
		 * FunnelKit Cart buttons visible even when the card gateway is switched off, without
		 * modifying the FunnelKit Cart plugin.
		 *
		 * Scope is deliberately narrow: only the card gateway settings, only when an express
		 * wallet is actually enabled, and never on real admin-screen requests (AJAX still
		 * overrides, since fragment/slide-cart refreshes run through admin-ajax). WooCommerce
		 * resolves gateway availability from the raw option — not this helper — so the card
		 * gateway is not re-exposed at checkout.
		 *
		 * @param array  $settings Resolved gateway settings.
		 * @param string $gateway  Gateway id being fetched.
		 *
		 * @return array
		 */
		public function present_card_settings_as_express_enabled( $settings, $gateway = 'fkwcs_stripe' ) {
			if ( 'fkwcs_stripe' !== $gateway || ( is_admin() && ! wp_doing_ajax() ) ) {
				return $settings;
			}

			$express_on = ( isset( $settings['express_checkout_enabled'] ) && 'yes' === $settings['express_checkout_enabled'] );
			$card_on    = ( isset( $settings['enabled'] ) && 'yes' === $settings['enabled'] );
			if ( $express_on && $card_on ) {
				return $settings; // Already satisfies the FunnelKit Cart check.
			}

			if ( ! $this->is_any_express_wallet_enabled() ) {
				return $settings;
			}

			$settings['express_checkout_enabled'] = 'yes';
			$settings['enabled']                  = 'yes';

			return $settings;
		}

		/**
		 * Whether any independent express wallet gateway (Apple Pay / Google Pay / Link) is enabled.
		 *
		 * @return bool
		 */
		private function is_any_express_wallet_enabled() {
			foreach ( array( 'fkwcs_stripe_apple_pay', 'fkwcs_stripe_google_pay', 'fkwcs_stripe_link' ) as $gid ) {
				$settings = \FKWCS\Gateway\Stripe\Helper::get_gateway_settings( $gid );
				if ( isset( $settings['enabled'] ) && 'yes' === $settings['enabled'] ) {
					return true;
				}
			}

			return false;
		}

		public function register_smart_button( $buttons ) {
			if ( isset( $buttons['funnelkit_stripe'] ) ) {
				unset( $buttons['funnelkit_stripe'] );
			}
			if ( isset( $buttons['funnelkit_stripe_gpay'] ) ) {
				unset( $buttons['funnelkit_stripe_gpay'] );
			}

			$buttons['funnelkit_stripe_apple_pay'] = array(
				'hook' => 'fkcart_fkwcs_smart_button_apple_pay',
				'show' => false,
			);
			$buttons['funnelkit_google_pay']       = array( 'hook' => 'fkcart_fkwcs_smart_button_google_pay' );
			$buttons['funnelkit_stripe_link']      = array(
				'hook' => 'fkcart_fkwcs_smart_button_link',
				'show' => false,
			);

			if ( class_exists( '\FKWCS\Gateway\Stripe\AmazonPay' ) ) {
				$amazon = \FKWCS\Gateway\Stripe\AmazonPay::get_instance();
				if ( 'yes' === $amazon->get_option( 'enabled' ) && $amazon->is_configured() ) {
					$buttons['funnelkit_stripe_amazon_pay'] = array(
						'hook' => 'fkcart_fkwcs_smart_button_amazon_pay',
						'show' => false,
					);
				}
			}

			return $buttons;
		}

		public function maybe_enqueue_assets( $allow ) {
			if ( true === $allow ) {
				return $allow;
			}

			if ( class_exists( '\FKCart\Includes\Data' ) && \FKCart\Includes\Data::is_smart_button_enabled() ) {
				return true;
			}

			return $allow;
		}

		public function print_smart_button_gpay() {
			if ( ! class_exists( 'FKWCS\Gateway\Stripe\GooglePay', false ) ) {
				return false;
			}

			if ( GooglePay::get_instance()->enabled == 'yes' && GooglePay::get_instance()->is_configured() ) {
				remove_action( 'fkcart_before_checkout_button', array( GooglePay::get_instance(), 'add_mini_cart_wrapper' ) );
				GooglePay::get_instance()->add_mini_cart_wrapper();
				do_action( 'fkcart_after_smart_payment_buttons', $this );
			}
		}

		public function print_smart_button() {
			if ( ! class_exists( '\FKWCS\Gateway\Stripe\SmartButtons' ) ) {
				return;
			}
			$instance = \FKWCS\Gateway\Stripe\SmartButtons::get_instance();
			// Credit card enabled checking already Handled in Payment_request_button settings below
			add_filter( 'fkwcs_express_buttons_is_only_buttons', '__return_true', 20 );
			$current_action = current_action();
			if ( $current_action === 'fkcart_fkwcs_smart_button_apple_pay' ) {
				$instance->_simple_button_wrapper( true, 'fkcart_fkwcs_smart_button_apple_pay' );
			}
			if ( $current_action === 'fkcart_fkwcs_smart_button_google_pay' ) {
				$instance->_simple_button_wrapper( true, 'fkcart_fkwcs_smart_button_google_pay' );
			}
			if ( $current_action === 'fkcart_fkwcs_smart_button_link' ) {
				$instance->_simple_button_wrapper( true, 'fkcart_fkwcs_smart_button_link' );
			}
			if ( $current_action === 'fkcart_fkwcs_smart_button_amazon_pay' ) {
				$instance->_simple_button_wrapper( true, 'fkcart_fkwcs_smart_button_amazon_pay' );
			}
		}

		/**
		 * Remove Smart Button when Quick View OPen
		 *
		 * @return void
		 */
		public function remove_smart_buttons() {
			$product_page_action   = 'woocommerce_after_add_to_cart_quantity';
			$product_page_priority = 10;
			$instance              = \FKWCS\Gateway\Stripe\SmartButtons::get_instance();
			if ( 'below' === $instance->product_page_position || 'inline' === $instance->product_page_position ) {
				$product_page_action   = 'woocommerce_after_add_to_cart_button';
				$product_page_priority = 1;
			}
			remove_action( $product_page_action, array( $instance, 'payment_request_button' ), $product_page_priority );
		}


		public function add_css_wrapper( $css_wrapper ) {

			$css_wrapper['dynamic_buttons']['#fkcart_fkwcs_smart_button_google_pay'] = '#fkcart_fkwcs_smart_button_google_pay';
			$css_wrapper['dynamic_buttons']['#fkcart_fkwcs_smart_button_apple_pay']  = '#fkcart_fkwcs_smart_button_apple_pay';
			$css_wrapper['dynamic_buttons']['#fkcart_fkwcs_smart_button_link']       = '#fkcart_fkwcs_smart_button_link';
			$css_wrapper['dynamic_buttons']['#fkcart_fkwcs_smart_button_gpay']       = '#fkcart_fkwcs_smart_button_gpay';
			$css_wrapper['dynamic_buttons']['#fkcart_fkwcs_smart_button_amazon_pay'] = '#fkcart_fkwcs_smart_button_amazon_pay';

			return $css_wrapper;
		}
	}
}
