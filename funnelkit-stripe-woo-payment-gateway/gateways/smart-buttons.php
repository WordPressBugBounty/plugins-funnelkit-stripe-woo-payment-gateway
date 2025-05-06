<?php
/**
 * Stripe Gateway
 *
 * @package funnelkit-stripe-woo-payment-gateway
 */

namespace FKWCS\Gateway\Stripe;

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

	public $local_settings = [];

	public $button_type = '';

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


		$this->local_settings   = Helper::get_gateway_settings();
		$this->express_checkout = $this->local_settings['express_checkout_enabled'];
		$apple_pay              = Helper::get_gateway_settings( 'fkwcs_stripe_apple_pay' );

		if ( ( 'yes' !== $this->express_checkout || 'yes' !== $this->local_settings['enabled'] ) && isset( $apple_pay['enabled'] ) && 'yes' !== $apple_pay['enabled'] ) {
			return;
		}

		add_filter( 'fkwcs_localized_data', array( $this, 'add_js_params' ) );
		$this->capture_method = 'automatic';

		$product_page_action   = 'woocommerce_after_add_to_cart_quantity';
		$product_page_priority = 10;

		$settings = $this->local_settings;

		if ( isset( $settings['express_checkout_product_page_position'] ) && ( 'below' === $settings['express_checkout_product_page_position'] || 'inline' === $settings['express_checkout_product_page_position'] ) ) {
			$product_page_action   = 'woocommerce_after_add_to_cart_button';
			$product_page_priority = 1;
		}
		if ( $settings['express_checkout_checkout_page_position'] === 'above-checkout' ) {
			$checkout_page_hook = 'woocommerce_checkout_before_customer_details';
		} else {
			$checkout_page_hook = 'woocommerce_checkout_billing';
		}

		$single_product_hook    = apply_filters( 'fkwcs_express_button_single_product_position', $product_page_action, $settings );
		$cart_page_hook         = apply_filters( 'fkwcs_express_button_cart_position', 'woocommerce_proceed_to_checkout' );
		$checkout_page_hook     = apply_filters( 'fkwcs_express_button_checkout_position', $checkout_page_hook );
		$product_page_priority  = apply_filters( 'fkwcs_express_button_single_product_position_priority', $product_page_priority );
		$checkout_page_priority = apply_filters( 'fkwcs_express_button_checkout_position_priority', 5 );
		$cart_page_priority     = apply_filters( 'fkwcs_express_button_cart_position_priority', 1 );

		/**
		 * hook in correct actions
		 */
		add_action( $single_product_hook, [ $this, 'payment_request_button' ], $product_page_priority );
		add_action( $cart_page_hook, [ $this, 'payment_request_button' ], $cart_page_priority );
		add_action( $checkout_page_hook, [ $this, 'payment_request_button' ], $checkout_page_priority );

		add_filter( 'fkwcs_payment_request_localization', [ $this, 'localize_product_data' ] );
		add_filter( 'woocommerce_update_order_review_fragments', [ $this, 'merge_cart_details' ], 1000 );
		add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'merge_cart_details' ], 1000 );
		add_filter( 'fkcart_fragments', [ $this, 'merge_cart_details' ], 1000 );

		add_action( 'wp_enqueue_scripts', [ $this, 'register_stripe_js' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_stripe_js' ], 11 );
		$this->ajax_endpoints();
		$this->load_fk_checkout_compatibility();


	}

	/**
	 * Ajax callbacks declared
	 *
	 * @return void
	 */
	public function ajax_endpoints() {
		add_action( 'wc_ajax_fkwcs_button_payment_request', [ $this, 'process_smart_checkout' ] );
		add_action( 'wc_ajax_wc_stripe_create_order', [ $this, 'process_smart_checkout' ], - 1 );
		add_action( 'wc_ajax_fkwcs_get_cart_details', [ $this, 'ajax_get_cart_details' ] );
		add_action( 'wc_ajax_fkwcs_selected_product_data', [ $this, 'ajax_selected_product_data' ] );
		add_action( 'wc_ajax_fkwcs_update_shipping_address', [ $this, 'update_shipping_address' ] );
		add_action( 'wc_ajax_fkwcs_update_shipping_option', [ $this, 'update_shipping_option' ] );
		add_action( 'wc_ajax_fkwcs_add_to_cart', [ $this, 'ajax_add_to_cart' ] );
	}

	/**
	 * Register Stripe assets
	 *
	 * @return void
	 */
	public function register_stripe_js() {
		parent::register_stripe_js();
		wp_register_script( 'fkwcs-express-checkout-js', FKWCS_URL . 'assets/js/express-checkout' . Helper::is_min_suffix() . '.js', [ 'fkwcs-stripe-external' ], FKWCS_VERSION, true );
		wp_register_style( 'fkwcs-style', FKWCS_URL . 'assets/css/style.css', [], FKWCS_VERSION );
	}

	/**
	 * Enqueue Stripe assets
	 *
	 * @return void
	 */
	public function enqueue_stripe_js() {


		/**
		 * Check if selected location is not the current location
		 * OR
		 * Allow devs to enqueue assets
		 */
		if ( ! ( $this->is_selected_location() || ( apply_filters( 'fkwcs_enqueue_express_button_assets', false, $this ) ) ) ) {
			return;
		}

		wp_enqueue_style( 'fkwcs-style' );
		wp_enqueue_script( 'fkwcs-express-checkout-js' );

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
		$localize_data_added = [
			'express_pay_enabled' => ( 'yes' === $this->express_checkout && 'yes' === $this->local_settings['enabled'] ) ? 'yes' : 'no',
			'is_product'          => $this->is_product() ? 'yes' : 'no',
			'is_cart'             => $this->is_cart() ? 'yes' : 'no',
			'wc_endpoints'        => self::get_public_endpoints(),
			'currency'            => strtolower( $currency ),
			'country_code'        => substr( get_option( 'woocommerce_default_country' ), 0, 2 ),
			'shipping_required'   => wc_bool_to_string( $this->shipping_required() ),
			'icons'               => [
				'applepay_gray'  => FKWCS_URL . 'assets/icons/apple_pay_gray.svg',
				'applepay_light' => FKWCS_URL . 'assets/icons/apple_pay_light.svg',
				'gpay_light'     => FKWCS_URL . 'assets/icons/gpay_light.svg',
				'gpay_gray'      => FKWCS_URL . 'assets/icons/gpay_gray.svg',
				'link'           => FKWCS_URL . 'assets/icons/link.svg',
			],
			'debug_log'           => ! empty( get_option( 'fkwcs_debug_log' ) ) ? get_option( 'fkwcs_debug_log' ) : 'no',
			'debug_msg'           => __( 'Stripe enabled Payment Request is not available in this browser', 'funnelkit-stripe-woo-payment-gateway' ),
		];

		if ( $this->is_product() ) {
			$localize_data_added['single_product'] = $this->get_product_data();
		}

		if ( $this->is_cart() ) {
			$localize_data_added['cart_data'] = $this->ajax_get_cart_details( true );
		}
		$localize_data_added['link_button_enabled'] = isset( $this->local_settings['express_checkout_link_button_enabled'] ) ? $this->local_settings['express_checkout_link_button_enabled'] : 'no';
		$localize_data_added['style']               = [
			'theme'                 => ( ! empty( $this->local_settings['express_checkout_button_theme'] ) ? $this->local_settings['express_checkout_button_theme'] : '' ),
			'button_position'       => ( ! empty( $this->local_settings['express_checkout_product_page_position'] ) ? $this->local_settings['express_checkout_product_page_position'] : '' ),
			'checkout_button_width' => ( ! empty( $this->local_settings['express_checkout_button_width'] ) ? $this->local_settings['express_checkout_button_width'] : '' ),
			'button_length'         => strlen( $this->local_settings['express_checkout_button_text'] ),
		];

		return array_merge( $localize_data, $localize_data_added );
	}

	/**
	 * Checks if current location is chosen to display express checkout button
	 *
	 * @return boolean
	 */
	private function is_selected_location() {
		$location = $this->local_settings['express_checkout_location'];
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
	public function payment_request_button( $force_display = false ) {
		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		if ( ! isset( $gateways['fkwcs_stripe'] ) && ! isset( $gateways['fkwcs_stripe_apple_pay'] ) ) {
			return;
		}

		if ( ! ( $this->is_selected_location() || true === $force_display ) ) {
			return;
		}

		if ( 'yes' !== $this->express_checkout ) {
			return;
		}

		$options = $this->local_settings;

		$separator_below     = true;
		$alignment_class     = '';
		$button_width        = '';
		$container_max_width = '';
		$button_max_width    = '';
		$extra_class         = 'fkwcs_smart_checkout_button';
		$sec_classes         = [ 'fkwcs_stripe_smart_button_wrapper' ];
		if ( $this->is_product() ) {
			$extra_class   = 'fkwcs_smart_product_button';
			$sec_classes[] = 'fkwcs-product';

			if ( 'below' === $options['express_checkout_product_page_position'] ) {
				$separator_below = false;
				$sec_classes[]   = 'below';
			}

			if ( 'inline' === $options['express_checkout_product_page_position'] ) {
				$separator_below = false;
				$sec_classes[]   = 'inline';
			}
		} elseif ( $this->is_cart() || did_action( 'fkcart_before_cart_items' ) ) {
			$extra_class   = 'fkwcs_smart_cart_button';
			$sec_classes[] = 'cart';
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

		$button_theme    = ! empty( $this->local_settings['express_checkout_button_theme'] ) ? wc_clean( $this->local_settings['express_checkout_button_theme'] ) : 'dark';
		$button_theme    = "fkwcs_ec_payment_button-" . $button_theme;
		$only_buttons    = apply_filters( 'fkwcs_express_buttons_is_only_buttons', false );

		?>
        <div id="fkwcs_stripe_smart_button_wrapper" class="<?php echo esc_attr( implode( ' ', $sec_classes ) ); ?>">
            <div id="fkwcs_stripe_smart_button" style="<?php esc_attr_e( ! empty( $alignment_class ) ? "text-align:" . ( $alignment_class ) . ';' : '' ); ?><?php echo esc_attr( $container_max_width ); ?>">
				<?php
				if ( ! $separator_below && false === $only_buttons ) {
					$this->payment_request_button_separator();
				}

				if ( $this->is_checkout() && false === $only_buttons ) {
					echo "<fieldset id='fkwcs-expresscheckout-fieldset'>";
					if ( ! empty( trim( $options['express_checkout_title'] ) ) ) {
						?>
                        <legend><?php echo esc_html( $options['express_checkout_title'] ); ?></legend>
						<?php
					}
				}
				?>
                <div id="fkwcs_custom_express_button" class="fkwcs_custom_express_button">
                    <button type="button" class="fkwcs_smart_buttons <?php echo esc_attr( $extra_class . ' ' . $button_theme ) ?>" style="display:none;<?php echo esc_attr( $button_width . $button_max_width ); ?>">
                        <span class="fkwcs_express_checkout_button_content">
							<?php echo esc_html( $options['express_checkout_button_text'] ); ?>
                            <img alt="" style="display:none" src="" class="fkwcs_express_checkout_button_icon skip-lazy">
                        </span>
                    </button>
                </div>
				<?php
				if ( $this->is_checkout() && false === $only_buttons ) {
					echo "</fieldset>";
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

		$options        = $this->local_settings;
		$separator_text = $options['express_checkout_separator_product'];


		if ( 'checkout' === $container_class ) {
			if ( ! empty( $options['express_checkout_separator_checkout'] ) ) {
				$separator_text = $options['express_checkout_separator_checkout'];
			}
		}

		if ( 'cart' === $container_class && ! empty( $options['express_checkout_separator_cart'] ) ) {
			$separator_text = $options['express_checkout_separator_cart'];
		}

		if ( 'fkwcs-product' === $container_class && 'inline' === $options['express_checkout_product_page_position'] ) {
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
		$product_price = $product->get_price();
		/** Add subscription sign-up fees to product price */
		if ( 'subscription' === $product->get_type() && class_exists( 'WC_Subscriptions_Product' ) ) {
			$product_price = $product->get_price() + WC_Subscriptions_Product::get_sign_up_fee( $product );
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
			$attributes           = [];

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

		$data  = [];
		$items = [];

		if ( 'subscription' === $product->get_type() && class_exists( 'WC_Subscriptions_Product' ) ) {
			$items[] = [
				'label'  => $product->get_name(),
				'amount' => Helper::get_stripe_amount( $product->get_price() ),
			];

			$items[] = [
				'label'  => __( 'Sign up Fee', 'funnelkit-stripe-woo-payment-gateway' ),
				'amount' => Helper::get_stripe_amount( WC_Subscriptions_Product::get_sign_up_fee( $product ) ),
			];
		} else {
			$items[] = [
				'label'  => $product->get_name(),
				'amount' => Helper::get_stripe_amount( $this->get_product_price( $product ) ),
			];
		}

		if ( wc_tax_enabled() ) {
			$items[] = [
				'label'   => __( 'Tax', 'funnelkit-stripe-woo-payment-gateway' ),
				'amount'  => 0,
				'pending' => true,
			];
		}

		if ( wc_shipping_enabled() && $product->needs_shipping() ) {
			$items[] = [
				'label'   => __( 'Shipping', 'funnelkit-stripe-woo-payment-gateway' ),
				'amount'  => 0,
				'pending' => true,
			];

			$data['shippingOptions'] = [
				'id'     => 'pending',
				'label'  => __( 'Pending', 'funnelkit-stripe-woo-payment-gateway' ),
				'detail' => '',
				'amount' => 0,
			];
		}

		$data['displayItems']    = $items;
		$data['total']           = [
			'label'   => __( 'Total', 'funnelkit-stripe-woo-payment-gateway' ),
			'amount'  => Helper::get_stripe_amount( $this->get_product_price( $product ) ),
			'pending' => true,
		];
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
		return array_merge( $localized_data, [ 'product' => $this->get_product_data() ] );
	}


	public function load_fk_checkout_compatibility() {
		if ( class_exists( '\WFACP_Core' ) ) {
			include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/class-fk-checkout.php';

			new \FKWCS_Compat_FK_Checkout();
		}


	}

}
