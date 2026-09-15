<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


use FKWCS\Gateway\Stripe\SmartButtons;

if ( ! class_exists( 'FKWCS_Compat_FK_Checkout' ) ) {

	class FKWCS_Compat_FK_Checkout {

		public function __construct() {
			add_filter( 'wfacp_smart_buttons', array( $this, 'add_buttons' ) );
			add_action( 'wfacp_smart_button_container_fkwcs_apple_pay', array( $this, 'add_fkwcs_gpay_apay_buttons' ) );
			add_action( 'wfacp_smart_button_container_fkwcs_stripe_google_pay', array( $this, 'add_fkwcs_gpay_apay_buttons' ) );
			add_action( 'wfacp_smart_button_container_fkwcs_link', array( $this, 'add_fkwcs_gpay_apay_buttons' ) );
			add_action( 'wfacp_smart_button_container_fkwcs_amazon_pay', array( $this, 'add_fkwcs_gpay_apay_buttons' ) );

			// Google Pay Integration
			add_action( 'wfacp_smart_button_container_fkwcs_google_pay', array( $this, 'add_fkwcs_gpay_apay_buttons' ) );

			add_action( 'wfacp_internal_css', array( $this, 'add_internal_css' ) );
			add_action( 'wfacp_template_load', array( $this, 'checkout_hook' ) );
			add_filter( 'wfacp_template_localize_data', array( $this, 'add_dynamic_selector' ) );
		}

		public function add_dynamic_selector( $localize_data ) {
			$localize_data['smart_button_wrappers']['dynamic_buttons']['#wfacp_smart_button_fkwcs_apple_pay .fkwcs_smart_button_trigger']         = '#wfacp_smart_button_fkwcs_apple_pay';
			$localize_data['smart_button_wrappers']['dynamic_buttons']['#wfacp_smart_button_fkwcs_stripe_google_pay .fkwcs_smart_button_trigger'] = '#wfacp_smart_button_fkwcs_stripe_google_pay';
			// Native Google Pay uses a separate button key.
			$localize_data['smart_button_wrappers']['dynamic_buttons']['#wfacp_smart_button_fkwcs_google_pay .fkwcs_smart_button_trigger'] = '#wfacp_smart_button_fkwcs_google_pay';

			$link_settings    = \FKWCS\Gateway\Stripe\Helper::get_gateway_settings( 'fkwcs_stripe_link' );
			$link_enabled_new = isset( $link_settings['enabled'] ) && wc_string_to_bool( $link_settings['enabled'] );

			if ( $link_enabled_new ) {
				$localize_data['smart_button_wrappers']['dynamic_buttons']['#wfacp_smart_button_fkwcs_link .fkwcs_smart_button_trigger'] = '#wfacp_smart_button_fkwcs_link';
			}

			$amazon_settings = \FKWCS\Gateway\Stripe\Helper::get_gateway_settings( 'fkwcs_stripe_amazon_pay' );
			if ( isset( $amazon_settings['enabled'] ) && wc_string_to_bool( $amazon_settings['enabled'] ) ) {
				$localize_data['smart_button_wrappers']['dynamic_buttons']['#wfacp_smart_button_fkwcs_amazon_pay .fkwcs_smart_button_trigger'] = '#wfacp_smart_button_fkwcs_amazon_pay';
			}

			return $localize_data;
		}

		/**
		 * Decides whether a per-method express gateway renders on the FK Checkout page.
		 *
		 * Mirrors SmartButtons::is_display_location_enabled(): a saved display_locations
		 * key (even []) is authoritative, while a genuinely-absent key defers to the
		 * legacy shared express_checkout_location. array_key_exists (not ! empty) is used
		 * because empty([]) is true for both the unset and the explicitly-cleared states.
		 *
		 * @since 1.14.0.4
		 *
		 * @param array $settings            Per-method gateway settings.
		 * @param bool  $shared_has_checkout Whether the legacy shared location lists checkout.
		 *
		 * @return bool True when the method should render on checkout.
		 */
		private function method_shows_on_checkout( $settings, $shared_has_checkout ) {
			if ( ! is_array( $settings ) || ! array_key_exists( 'display_locations', $settings ) ) {
				// Key never saved — preserve legacy shared-location behaviour.
				return (bool) $shared_has_checkout;
			}

			$locations = is_array( $settings['display_locations'] ) ? $settings['display_locations'] : array();

			return in_array( 'checkout', $locations, true );
		}

		public function add_buttons( $buttons ) {

			$instance = SmartButtons::get_instance();

			// Per-method settings (post-1.15 source of truth).
			$apple_settings  = \FKWCS\Gateway\Stripe\Helper::get_gateway_settings( 'fkwcs_stripe_apple_pay' );
			$google_settings = \FKWCS\Gateway\Stripe\Helper::get_gateway_settings( 'fkwcs_stripe_google_pay' );
			$link_settings   = \FKWCS\Gateway\Stripe\Helper::get_gateway_settings( 'fkwcs_stripe_link' );
			$amazon_settings = \FKWCS\Gateway\Stripe\Helper::get_gateway_settings( 'fkwcs_stripe_amazon_pay' );

			$apple_on  = isset( $apple_settings['enabled'] ) && 'yes' === $apple_settings['enabled'];
			$gpay_on   = isset( $google_settings['enabled'] ) && 'yes' === $google_settings['enabled'];
			$link_on   = isset( $link_settings['enabled'] ) && 'yes' === $link_settings['enabled'];
			$amazon_on = isset( $amazon_settings['enabled'] ) && 'yes' === $amazon_settings['enabled'];

			$shared_location = $instance->local_settings['express_checkout_location'] ?? array();

			// For each method, honour its own display_locations when the key was saved
			// (post-1.15), falling back to the legacy shared location only when the key is
			// genuinely absent (pre-migration sites). array_key_exists (not ! empty) is
			// required: an explicitly-cleared list ([]) must resolve to "show nowhere",
			// whereas an unset key defers to the shared setting. empty([]) is true for both
			// states, which is the bug this mirrors the SmartButtons fix for.
			$shared_has_checkout = is_array( $shared_location ) && in_array( 'checkout', $shared_location, true );

			$apple_checkout_on  = $this->method_shows_on_checkout( $apple_settings, $shared_has_checkout );
			$google_checkout_on = $this->method_shows_on_checkout( $google_settings, $shared_has_checkout );
			$link_checkout_on   = $this->method_shows_on_checkout( $link_settings, $shared_has_checkout );
			$amazon_checkout_on = $this->method_shows_on_checkout( $amazon_settings, $shared_has_checkout );

			$link_enabled_old = isset( $instance->local_settings['express_checkout_link_button_enabled'] ) && wc_string_to_bool( $instance->local_settings['express_checkout_link_button_enabled'] );

			if ( ! ( $apple_checkout_on || $google_checkout_on || $link_checkout_on || $amazon_checkout_on || $link_enabled_old ) ) {
				return $buttons;
			}

			remove_action( 'woocommerce_checkout_before_customer_details', array( $instance, 'payment_request_button' ), 5 );

			if ( $apple_on && $apple_checkout_on ) {
				$buttons['fkwcs_apple_pay'] = array(
					'iframe' => true,
					'name'   => __( 'Stripe Payment Request', 'funnelkit-stripe-woo-payment-gateway' ),
				);
			}

			if ( $gpay_on && $google_checkout_on ) {
				$gpay_instance = FKWCS\Gateway\Stripe\GooglePay::get_instance();
				if ( $gpay_instance->is_available() ) {
					$buttons['fkwcs_stripe_google_pay'] = array(
						'iframe' => true,
						'name'   => __( 'Stripe Payment Request', 'funnelkit-stripe-woo-payment-gateway' ),
					);
				}
			}

			if ( ( $link_on && $link_checkout_on ) ) {
				$buttons['fkwcs_link'] = array(
					'iframe' => true,
					'name'   => __( 'Stripe Payment Request', 'funnelkit-stripe-woo-payment-gateway' ),
				);
			}

			if ( $amazon_on && $amazon_checkout_on ) {
				$buttons['fkwcs_amazon_pay'] = array(
					'iframe' => true,
					'name'   => __( 'Stripe Payment Request', 'funnelkit-stripe-woo-payment-gateway' ),
				);
			}

			return $buttons;
		}

		public function checkout_hook() {
			add_filter( 'wfacp_smart_button_or_text', array( $this, 'change_separator_txt' ) );
			add_filter( 'wfacp_smart_button_legend_title', array( $this, 'change_express_checkout_txt' ) );
			add_action( 'woocommerce_before_checkout_process', array( $this, 'remove_phone_process' ) );

			$page_settings = \WFACP_Common::get_page_settings( \WFACP_Common::get_id() );
			if ( false === wc_string_to_bool( $page_settings['enable_smart_buttons'] ) ) {
				$smart_instance = SmartButtons::get_instance();
				remove_action( 'woocommerce_checkout_before_customer_details', array( $smart_instance, 'payment_request_button' ), 5 );
				// Render the express buttons on the shared checkout hook (mirrors SmartButtons).
				$checkout_hook = $smart_instance->get_resolved_checkout_hook();
				add_action( $checkout_hook, array( $this, 'render_native_express_buttons' ), 5 );
			}
		}

		public function render_native_express_buttons() {
			$instance = SmartButtons::get_instance();
			$instance->_payment_request_button( true );
		}

		public function remove_phone_process() {
			if ( ! isset( $_POST['payment_request_type'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing
				return;
			}
			$instance = wfacp_template();
			if ( is_null( $instance ) ) {
				return;
			}
			remove_action( 'woocommerce_checkout_process', array( $instance, 'process_phone_field' ) );
		}


		public function add_fkwcs_gpay_apay_buttons() {
			add_filter( 'fkwcs_express_buttons_is_only_buttons', '__return_true' );
			$instance       = SmartButtons::get_instance();
			$current_action = current_action();

			// Each container only renders its wrapper when its own gateway is enabled,
			// so a disabled method never leaves behind an empty wrapper.
			if ( 'wfacp_smart_button_container_fkwcs_apple_pay' === $current_action ) {
				if ( $this->is_gateway_enabled( 'fkwcs_stripe_apple_pay' ) ) {
					$instance->_simple_button_wrapper( true, 'fkwcs_apple_pay_button' );
				}
			}
			if ( 'wfacp_smart_button_container_fkwcs_stripe_google_pay' === $current_action ) {
				if ( $this->is_gateway_enabled( 'fkwcs_stripe_google_pay' ) ) {
					$instance->_simple_button_wrapper( true, 'fkwcs_google_pay_button' );
				}
			}
			if ( 'wfacp_smart_button_container_fkwcs_link' === $current_action ) {
				if ( $this->is_gateway_enabled( 'fkwcs_stripe_link' ) ) {
					$instance->_simple_button_wrapper( true, 'fkwcs_link_pay_button' );
				}
			}
			if ( 'wfacp_smart_button_container_fkwcs_amazon_pay' === $current_action ) {
				$instance->_simple_button_wrapper( true, 'fkwcs_amazon_pay_button' );
			}
			if ( 'wfacp_smart_button_container_fkwcs_google_pay' === $current_action ) {
				if ( $this->is_gateway_enabled( 'fkwcs_stripe_google_pay' ) ) {
					$instance->_simple_button_wrapper( true, 'fkwcs_google_pay_button' );
				}
			}
		}

		/**
		 * Whether an express gateway is enabled in its own settings.
		 *
		 * @param string $gateway_id Gateway settings key (e.g. fkwcs_stripe_google_pay).
		 *
		 * @return bool
		 */
		public function is_gateway_enabled( $gateway_id ) {
			$settings = \FKWCS\Gateway\Stripe\Helper::get_gateway_settings( $gateway_id );

			return isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];
		}

		public function change_separator_txt( $text ) {
			// Per-method separator (synced across all methods since 1.15); fall back to legacy shared setting.
			foreach ( array( 'fkwcs_stripe_apple_pay', 'fkwcs_stripe_google_pay', 'fkwcs_stripe_link' ) as $gid ) {
				$s = \FKWCS\Gateway\Stripe\Helper::get_gateway_settings( $gid );
				if ( isset( $s['enabled'] ) && 'yes' === $s['enabled'] && ! empty( $s['separator_text'] ) ) {
					return $s['separator_text'];
				}
			}

			$instance   = SmartButtons::get_instance();
			$legacy_sep = $instance->local_settings['express_checkout_separator_checkout'] ?? '';
			if ( ! empty( $legacy_sep ) ) {
				return $legacy_sep;
			}

			return $text;
		}

		public function change_express_checkout_txt( $text ) {
			$instance = SmartButtons::get_instance();
			if ( ! empty( $instance->local_settings['express_checkout_title'] ) ) {
				return $instance->local_settings['express_checkout_title'];
			}

			return $text;
		}

		public function add_internal_css() {
			if ( ! defined( 'WC_STRIPE_VERSION' ) ) {
				return;
			}

			$instance = wfacp_template();
			if ( ! $instance instanceof \WFACP_Template_Common ) {
				return;
			}
			$bodyClass = 'body';
			if ( 'pre_built' !== $instance->get_template_type() ) {
				$bodyClass = 'body #fkwcs-e-form';
			}
			if ( version_compare( WC_STRIPE_VERSION, '5.6.0', '<' ) ) {
				return;
			}

			echo '<style>';

			echo '#wfacp_smart_buttons .fkwcs_google_pay_wrapper{margin: 0}';
			echo esc_html( $bodyClass ) . ' #payment ul.payment_methods li .card-brand-icons img{position: absolute;}';

			echo '</style>';
		}
	}
}
