<?php

namespace FKWCS\Gateway\Stripe;
#[\AllowDynamicProperties]
class PayLater_Helper {
	private static $instance = null;
	private $paylater_gateways = [ 'single' => [], 'cart' => [], 'shop' => [] ];
	private $paylater_gateway_count = 0;

	private function __construct() {
		add_action( 'template_redirect', [ $this, 'attach_actions' ] );
		$this->fkcart_actions();

	}

	/**
	 * @return PayLater_Helper gateway instance
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function attach_actions() {
		$this->prepare_paylater_gateway();
		if ( false === $this->paylater_gateway_count ) {

			return;
		}

		$need_js = false;

		if ( ! empty( $this->paylater_gateways['single'] ) && is_product() ) {
			$single_product_hook   = apply_filters( 'fkwcs_paylater_message_product_position', 'woocommerce_after_add_to_cart_form' );
			$product_page_priority = apply_filters( 'fkwcs_paylater_message_button_product_position_priority', 10 );
			add_filter( 'woocommerce_available_variation', [ $this, 'add_variation_product_price' ] );
			add_action( $single_product_hook, [ $this, 'single_button_wrapper' ], $product_page_priority );
			$need_js = true;
		}

		if ( ! empty( $this->paylater_gateways['cart'] ) && ( is_cart() || class_exists( 'FKCart\Plugin' ) && \FKCart\Includes\Data::is_cart_enabled() ) ) {
			$cart_page_hook     = apply_filters( 'fkwcs_paylater_message_cart_position', 'woocommerce_proceed_to_checkout' );
			$cart_page_priority = apply_filters( 'fkwcs_paylater_message_cart_position_priority', 10 );
			add_action( $cart_page_hook, [ $this, 'cart_button_wrapper' ], $cart_page_priority );
			$need_js = true;
		}

		if ( ! empty( $this->paylater_gateways['shop'] ) && is_archive() ) {
			$cart_page_hook     = apply_filters( 'fkwcs_paylater_message_shop_position', 'woocommerce_after_shop_loop_item' );
			$cart_page_priority = apply_filters( 'fkwcs_paylater_message_shop_position_priority', 999 );
			add_action( $cart_page_hook, [ $this, 'shop_button_wrapper' ], $cart_page_priority );
			$need_js = true;
		}

		if ( $need_js ) {

			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_stripe_js' ], 11 );
			add_action( 'wp_footer', [ $this, 'print_css' ] );
		}

	}

	public function fkcart_actions() {
		if ( ! class_exists( 'FKCart\Plugin' ) ) {
			return;
		}
		$cart_page_hook     = apply_filters( 'fkwcs_paylater_message_button_fkcart_mini_cart_position', 'fkcart_before_smart_button' );
		$cart_page_priority = apply_filters( 'fkwcs_paylater_message_button_fkcart_mini_cart_priority', - 1 );
		add_action( $cart_page_hook, [ $this, 'fkcart_mini_cart_wrapper' ], $cart_page_priority );
	}


	public function enqueue_stripe_js() {

		wp_register_script( 'fkwcs-stripe-external', 'https://js.stripe.com/v3/', [], false, true );

		wp_enqueue_script( 'fkwcs-stripe-paylater', FKWCS_URL . 'assets/js/paylater.js', [ 'fkwcs-stripe-external' ] );
		wp_localize_script( 'fkwcs-stripe-paylater', 'fkwcs_paylater', $this->update_localize_data() );

	}

	public function update_localize_data() {
		$data      = Helper::stripe_localize_data();
		$test_mode = get_option( 'fkwcs_mode', 'test' );

		$data['paylater_messaging'] = $this->paylater_gateways;
		$data['pub_key']            = 'test' === $test_mode ? get_option( 'fkwcs_test_pub_key', '' ) : get_option( 'fkwcs_pub_key', '' );
		$data['currency']           = strtolower( get_woocommerce_currency() );
		$data['locale']             = \FKWCS\Gateway\Stripe\Abstract_Payment_Gateway::convert_wc_locale_to_stripe_locale( get_locale() );
		if ( ! is_null( WC()->customer ) ) {
			$country = WC()->customer->get_billing_country();
		} else {
			$country = substr( get_option( 'woocommerce_default_country' ), 0, 2 );
		}
		$data['country_code'] = $country;
		return apply_filters( 'fkwcs_paylater_localized_data', $data );
	}

	public function prepare_paylater_gateway() {

		$avilable_gateway = WC()->payment_gateways()->payment_gateways();

		foreach ( $avilable_gateway as $gateway ) {

			if ( 'yes' !== $gateway->enabled || ! $gateway instanceof \FKWCS\Gateway\Stripe\LocalGateway || ! $gateway->is_available() ) {
				continue;
			}

			$positions = $gateway->get_option( 'paylater_section' );
			if ( empty( $positions ) ) {
				continue;
			}
			foreach ( $positions as $position ) {

				if ( 'product' === $position ) {
					$this->paylater_gateways['single'][] = $gateway->payment_method_types;
					$this->paylater_gateway_count        = true;
				}
				if ( 'cart' === $position ) {
					$this->paylater_gateways['cart'][] = $gateway->payment_method_types;
					$this->paylater_gateway_count      = true;
				}
				if ( 'shop' === $position ) {
					$this->paylater_gateways['shop'][] = $gateway->payment_method_types;
					$this->paylater_gateway_count      = true;
				}
			}
		}

	}

	public function cart_button_wrapper() {
		$paylater_data = [ 'amount' => Helper::get_formatted_amount( WC()->cart->get_total( 'edit' ) ) ];
		echo "<div class='fkwcs_paylater_messaging fkwcs_cart_page' paylater-data='" . wp_json_encode( $paylater_data ) . "'></div>";
	}

	public function single_button_wrapper() {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$paylater_data = [ 'amount' => Helper::get_formatted_amount( $product->get_price( 'edit' ) ), 'product_type' => $product->get_type() ];
		echo "<div class='fkwcs_paylater_messaging fkwcs_single_product'  paylater-data='" . wp_json_encode( $paylater_data ) . "'></div>";
	}

	protected function init() {
		// TODO: Implement init() method.
	}

	public function add_variation_product_price( $data ) {
		if ( isset( $data['display_price'] ) ) {
			$data['fkwcs_stripe_amount'] = Helper::get_formatted_amount( $data['display_price'] );
		}

		return $data;
	}

	public function fkcart_mini_cart_wrapper() {
		if ( is_null( WC()->cart ) ) {
			return;
		}
		$paylater_data = [ 'amount' => Helper::get_formatted_amount( WC()->cart->get_total( 'edit' ) ) ];
		echo "<div class='fkwcs_paylater_messaging fkwcs_fkcart_drawer fkcart-checkout-wrap fkcart-panel' paylater-data='" . esc_attr( wp_json_encode( $paylater_data ) ) . "'></div>";
	}

	public function shop_button_wrapper() {
		global $product;

		if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}

		$paylater_data = [ 'amount' => Helper::get_formatted_amount( $product->get_price( 'edit' ) ) ];
		$product_id    = $product->get_id();
		$json_data     = wp_json_encode( $paylater_data );
		echo "<div class='fkwcs_paylater_messaging fkwcs_shop_page fkwcs_shop_pro_" . esc_attr( $product_id ) . "' paylater-product-id='" . esc_attr( $product_id ) . "' paylater-data='" . esc_attr( $json_data ) . "'></div>";
	}

	public function print_css() {
		?>
        <style type="text/css">
            .fkwcs_paylater_messaging {
                clear: both;
                margin-bottom: 12px;
            }

            .fkwcs_paylater_messaging.fkwcs_shop_page div > iframe {
                min-height: 40px;
            }

            .fkwcs_paylater_messaging.fkwcs_fkcart_drawer {
                margin-bottom: 12px;
                margin-top: 12px;
            }
        </style>
		<?php

	}
}

PayLater_Helper::get_instance();