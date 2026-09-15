<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Link extends CreditCard {

	private static $instance = null;

	public $id                   = 'fkwcs_stripe_link';
	public $payment_method_types = 'link';

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		$this->set_api_keys();
		$this->init();
	}

	protected function init() {
		$this->has_fields = true;
		$this->init_form_fields();
		$this->init_settings();
		$this->method_title       = __( 'Link by Stripe', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( 'Accept Payments using Link by Stripe. Either in Express checkout section or enhance credit card payment with Link', 'funnelkit-stripe-woo-payment-gateway' );
		$this->title              = $this->get_option( 'title' );
		$this->description        = $this->get_option( 'description' );
		add_filter( 'woocommerce_get_settings_checkout', array( $this, 'render_link_settings_section' ), 10, 2 );
		add_action( 'woocommerce_update_options_checkout_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function render_link_settings_section( $settings, $section ) {
		if ( $section !== $this->id ) {
			return $settings;
		}
		if ( doing_action( 'woocommerce_settings_save_checkout' ) ) {
			return array();
		}
		$this->admin_options();
		return array();
	}

	public function init_form_fields() {
		$this->form_fields = apply_filters(
			'fkwcs_link_payment_form_fields',
			array(
				'enabled'                     => array(
					'label'   => ' ',
					'type'    => 'checkbox',
					'title'   => __( 'Enable Link by Stripe', 'funnelkit-stripe-woo-payment-gateway' ),
					'default' => 'no',
				),
				'display_locations'           => array(
					'title'   => __( 'Display Locations', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'    => 'multiselect',
					'options' => array(
						'product'  => __( 'Product Page', 'funnelkit-stripe-woo-payment-gateway' ),
						'cart'     => __( 'Cart', 'funnelkit-stripe-woo-payment-gateway' ),
						'checkout' => __( 'Checkout (express button)', 'funnelkit-stripe-woo-payment-gateway' ),
					),
					'default' => array( 'product', 'cart', 'checkout' ),
				),
				'link_authentication_trigger' => array(
					'title'       => __( 'Enable Link On Card Payment', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'select',
					'options'     => array(
						'on_card' => __( 'Yes', 'funnelkit-stripe-woo-payment-gateway' ),
						'none'    => __( 'No', 'funnelkit-stripe-woo-payment-gateway' ),
					),
					'default'     => 'none',
					'description' => __( 'Show Stripe Link in the card payment form at checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
					'desc_tip'    => true,
				),
				'product_page_position'       => array(
					'title'   => __( 'Product Page Button Position', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'    => 'select',
					'options' => array(
						'above-add-to-cart' => __( 'Above Add to Cart', 'funnelkit-stripe-woo-payment-gateway' ),
						'below-add-to-cart' => __( 'Below Add to Cart', 'funnelkit-stripe-woo-payment-gateway' ),
						'inline'            => __( 'Inline Button', 'funnelkit-stripe-woo-payment-gateway' ),
					),
					'default' => 'below-add-to-cart',
				),
				'separator_text'              => array(
					'title'   => __( 'Separator Text', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'    => 'text',
					'default' => __( 'Or', 'funnelkit-stripe-woo-payment-gateway' ),
				),
			)
		);
	}

	public function get_icon() {
		$icons  = '<span class="fkwcs_stripe_link_icons">';
		$icons .= '<img src="' . \FKWCS_URL . 'assets/icons/link.svg" alt="Link" title="Link by Stripe" /></span>';

		return $icons;
	}
}

add_action( 'wp_loaded', 'FKWCS\Gateway\Stripe\Link::get_instance' );
