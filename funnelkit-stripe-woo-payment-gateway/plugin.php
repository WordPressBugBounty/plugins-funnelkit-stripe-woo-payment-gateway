<?php

namespace FKWCS\Gateway;

use FKWCS\Gateway\Stripe\Admin;
use FKWCS\Gateway\Stripe\Onboard;
use FKWCS\Gateway\Stripe\Webhook;

class Stripe {
	private static $instance = null;

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_core' ) );

		/**
		 * Load text domain from our local folder
		 */
		load_plugin_textdomain( 'funnelkit-stripe-woo-payment-gateway', false, plugin_basename( dirname( FKWCS_FILE ) ) . '/languages/' );
	}

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Load core
	 *
	 * @return void
	 */
	public function load_core() {
		if ( ! class_exists( 'woocommerce' ) ) {
			add_action( 'admin_notices', [ $this, 'wc_is_not_active' ] );

			return;
		}

		if ( ! class_exists( '\Stripe\Stripe', false ) ) {
			require_once plugin_dir_path( FKWCS_FILE ) . 'library/stripe-php/init.php';
		}
		spl_autoload_register( [ $this, 'autoload' ] );

		$this->admin();


		include plugin_dir_path( FKWCS_FILE ) . '/includes/ajax.php';


		$this->hooks();
		$this->webhook();
		$this->include_gateways();
	}

	function autoload( $class ) {

		$baseDir = __DIR__ . '/includes/';

		// Prefix of the namespace
		$prefix = 'FKWCS\Gateway\Stripe\\';

		// Does the class use the namespace prefix?
		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			// No, move to the next registered autoloader
			return;
		}

		// Get the relative class name
		$relativeClass = substr( $class, $len );
		$relativeClass = str_replace( '_', '-', strtolower( $relativeClass ) );
		// Replace namespace separators with directory separators in the relative class name, append with .php
		$file = $baseDir . str_replace( '\\', '/', strtolower( $relativeClass ) ) . '.php';
		// If the file exists, require it
		if ( file_exists( $file ) ) {
			include_once $file;  //phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		}
	}

	/**
	 * Registering gateways to WooCommerce
	 *
	 * @param array $methods List of registered gateways.
	 *
	 * @return array
	 */
	public function register_gateway( $methods ) {

		return array_merge( $methods, array_values( $this->get_gateways() ) );
	}

	public function get_gateways() {
		$methods                     = [];
		$methods['fkwcs_stripe']     = 'FKWCS\Gateway\Stripe\CreditCard';
		$methods['fkwcs_ideal']      = 'FKWCS\Gateway\Stripe\Ideal';
		$methods['fkwcs_bancontact'] = 'FKWCS\Gateway\Stripe\Bancontact';
		$methods['fkwcs_p24']        = 'FKWCS\Gateway\Stripe\P24';
		$methods['fkwcs_sepa']       = 'FKWCS\Gateway\Stripe\Sepa';
		$methods['fkwcs_affirm']     = 'FKWCS\Gateway\Stripe\Affirm';
		$methods['fkwcs_klarna']     = 'FKWCS\Gateway\Stripe\Klarna';
		$methods['fkwcs_afterpay']   = 'FKWCS\Gateway\Stripe\AfterPay';
		$methods['fkwcs_googlepay']  = 'FKWCS\Gateway\Stripe\GooglePay';
		$methods['fkwcs_applepay']   = 'FKWCS\Gateway\Stripe\ApplePay';
		$methods['fkwcs_alipay']     = 'FKWCS\Gateway\Stripe\Alipay';
		$methods['fkwcs_mobilepay']  = 'FKWCS\Gateway\Stripe\Mobilepay';


		return $methods;
	}

	/**
	 * Loading admin classes
	 *
	 * @return void
	 */
	public function admin() {
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || $this->is_rest_api_request() ) {
			include plugin_dir_path( FKWCS_FILE ) . '/admin/admin.php';
			include plugin_dir_path( FKWCS_FILE ) . '/admin/onboard.php';
			Admin::get_instance();
			Onboard::get_instance();
		}

	}

	public function include_gateways() {
		/**
		 * Include all the gateway classes
		 */
		include plugin_dir_path( FKWCS_FILE ) . '/includes/traits/wc-subscriptions-helper-trait.php';
		include plugin_dir_path( FKWCS_FILE ) . '/includes/traits/wc-subscriptions-trait.php';
		include plugin_dir_path( FKWCS_FILE ) . '/includes/traits/wc-smart-button-functions.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/localgateway.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/creditcard.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/smart-buttons.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/ideal.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/bancontact.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/p24.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/sepa.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/affirm.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/klarna.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/afterpay.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/googlepay.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/applepay.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/alipay.php';
		include plugin_dir_path( FKWCS_FILE ) . '/includes/paylater.php';
		include plugin_dir_path( FKWCS_FILE ) . '/gateways/mobilepay.php';

		do_action( 'fkwcs_gateways_included' );

		try {

			if ( class_exists( '\WFOCU_Core' ) ) {
				include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/class-abstract-wfocu-bnpl.php';

				include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/class-wfocu-plugin-integration-fkwcs-stripe.php';
				include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/class-wfocu-plugin-integration-fkwcs-sepa.php';
				include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/class-wfocu-affirm-upsell.php';
				include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/class-wfocu-klarna-upsell.php';
				include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/class-wfocu-afterpay-upsell.php';
				include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/class-wfocu-p24-upsell.php';
				include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/class-wfocu-bancontact-upsell.php';
				include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/class-wfocu-plugin-integration-fkwcs-stripe-apple-pay.php';
				include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/class-wfocu-plugin-integration-fkwcs-stripe-google-pay.php';
				include plugin_dir_path( FKWCS_FILE ) . 'compatibilities/plugins/class-wfocu-alipay-upsell.php';


			}


		} catch ( \Exception|\Error $e ) {
		}


		/**
		 * Load Smart buttons class separately as this is not the registered gateway itself, it simply extends the credit card gateway
		 */
		add_action( 'wp_loaded', 'FKWCS\Gateway\Stripe\SmartButtons' . '::get_instance' );

		/**
		 * Init Gpay Integration on ajax calls
		 */
		add_action( 'parse_request', function () {
			if ( wp_doing_ajax() ) {
				\FKWCS\Gateway\Stripe\GooglePay::get_instance();
			}
		}, 1 );
	}

	/**
	 * Loads classes on plugins_loaded hook.
	 *
	 * @return void
	 */
	public function wc_is_not_active() {
		?>
        <div class="error">
            <p>
				<?php
				echo __( '<strong> Attention: </strong>WooCommerce is not installed or activated. Funnelkit Stripe Plugin is a WooCommerce Payment Gateway and would only work if WooCommerce is activated. Please install the WooCommerce Plugin first.', 'funnelkit-stripe-woo-payment-gateway' ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
            </p>
        </div>
		<?php
	}

	/**
	 * Attach payment methods of FK Upsells
	 *
	 * @param $gateways
	 *
	 * @return mixed
	 */
	public function add_supported_gateways( $gateways ) {
		$gateways['fkwcs_stripe']            = 'WFOCU_Plugin_Integration_Fkwcs_Stripe';
		$gateways['fkwcs_stripe_sepa']       = 'WFOCU_Plugin_Integration_Fkwcs_Sepa';
		$gateways['fkwcs_stripe_affirm']     = 'WFOCU_Plugin_Integration_Fkwcs_Affirm';
		$gateways['fkwcs_stripe_klarna']     = 'WFOCU_Plugin_Integration_Fkwcs_Klarna';
		$gateways['fkwcs_stripe_afterpay']   = 'WFOCU_Plugin_Integration_Fkwcs_Afterpay';
		$gateways['fkwcs_stripe_p24']        = 'WFOCU_Plugin_Integration_Fkwcs_p24';
		$gateways['fkwcs_stripe_google_pay'] = 'WFOCU_Plugin_Integration_Fkwcs_Google_Pay';
		$gateways['fkwcs_stripe_apple_pay']  = 'WFOCU_Plugin_Integration_Fkwcs_Apple_Pay';
		$gateways['fkwcs_stripe_alipay']     = 'WFOCU_Plugin_Integration_Fkwcs_Alipay';

		return $gateways;
	}

	/**
	 * Attach payment methods of FK Upsells for subscription
	 *
	 * @param $gateways
	 *
	 * @return mixed
	 */
	public function enable_subscription_upsell_support( $gateways ) {
		$gateways[] = 'fkwcs_stripe';
		$gateways[] = 'fkwcs_stripe_sepa';
		$gateways[] = 'fkwcs_stripe_affirm';
		$gateways[] = 'fkwcs_stripe_klarna';
		$gateways[] = 'fkwcs_stripe_afterpay';
		$gateways[] = 'fkwcs_stripe_p24';
		$gateways[] = 'fkwcs_stripe_google_pay';
		$gateways[] = 'fkwcs_stripe_apple_pay';
		$gateways[] = 'fkwcs_stripe_alipay';

		return $gateways;
	}

	/**
	 * enable default support during setup
	 *
	 * @param $resp
	 *
	 * @return void WHEN no upsell functionality exists
	 */
	public function enable_upsell_default_gateway_on_setup( $resp ) {
		if ( ! class_exists( 'WFOCU_Core' ) ) {
			return;
		}

		$all_options = WFOCU_Core()->data->get_option();

		if ( isset( $resp['fkwcs_stripe'] ) && true === $resp['fkwcs_stripe'] ) {
			array_push( $all_options['gateways'], 'fkwcs_stripe' );
		}
		if ( isset( $resp['fkwcs_stripe_sepa'] ) && true === $resp['fkwcs_stripe_sepa'] ) {
			array_push( $all_options['gateways'], 'fkwcs_stripe_sepa' );
		}
		if ( isset( $resp['fkwcs_stripe_affirm'] ) && true === $resp['fkwcs_stripe_affirm'] ) {
			array_push( $all_options['gateways'], 'fkwcs_stripe_affirm' );
		}
		if ( isset( $resp['fkwcs_stripe_klarna'] ) && true === $resp['fkwcs_stripe_klarna'] ) {
			array_push( $all_options['gateways'], 'fkwcs_stripe_klarna' );
		}
		if ( isset( $resp['fkwcs_stripe_alipay'] ) && true === $resp['fkwcs_stripe_alipay'] ) {
			array_push( $all_options['gateways'], 'fkwcs_stripe_alipay' );
		}
		WFOCU_Core()->data->update_options( $all_options );
	}

	/**
	 * Load hooks
	 *
	 * @return void
	 */
	public function hooks() {

		add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ], 999 );

		/**
		 * Upsell compatible hooks
		 */
		add_filter( 'wfocu_wc_get_supported_gateways', [ $this, 'add_supported_gateways' ] );

		add_filter( 'wfocu_subscriptions_get_supported_gateways', array( $this, 'enable_subscription_upsell_support' ) );
		add_action( 'fkwcs_wizard_gateways_save', array( $this, 'enable_upsell_default_gateway_on_setup' ) );

		add_filter( 'option_woocommerce_gateway_order', [ $this, 'move_gateway_to_first_in_the_list' ], 9999 );
		add_filter( 'default_option_woocommerce_gateway_order', [ $this, 'move_gateway_to_first_in_the_list' ], 9999 );

		add_action( 'fkwcs_wizard_gateways_save', [ $this, 'disable_other_gateways' ] );
		add_action( 'before_woocommerce_init', [ $this, 'declare_hpos_compatibility' ] );

		add_action( 'woocommerce_payment_token_class', [ $this, 'modify_token_class' ], 15, 2 );

		add_action( 'woocommerce_api_wc_stripe', [ $this, 'control_webhook' ] );
		add_filter( 'rest_pre_dispatch', [ $this, 'control_webhook' ], 10, 3 );


		add_filter( 'woocommerce_order_get_payment_method', array( $this, 'change_payment_method' ), 99, 2 );
		add_filter( 'woocommerce_subscription_get_payment_method', array( $this, 'change_payment_method' ), 99, 2 );

		add_action( 'wp', function () {
			global $wp;

			if ( isset( $wp->query_vars['delete-payment-method'] ) ) {
				WC()->payment_gateways();
			}
		}, 19 );
	}


	/**
	 * Include Webhook class and initialize instance
	 *
	 * @return void
	 */
	public function webhook() {
		if ( $this->is_rest_api_request() ) {
			Webhook::get_instance();
		}

	}


	/**
	 * By default, new payment gateways are put at the bottom of the list on the admin "Payments" settings screen.
	 * Here we are simply moving our gateway on top.
	 *
	 * @param array $ordering Existing ordering of the payment gateways.
	 *
	 * @return array Modified ordering.
	 */
	public function move_gateway_to_first_in_the_list( $ordering ) {
		$ordering = (array) $ordering;


		$key = 'fkwcs_stripe';
		if ( ! isset( $ordering[ $key ] ) || ! is_numeric( $ordering[ $key ] ) ) {
			$is_empty         = empty( $ordering ) || ( count( $ordering ) === 1 && $ordering[0] === false );
			$ordering[ $key ] = $is_empty ? 0 : ( min( $ordering ) - 1 );
		}


		return $ordering;
	}


	/**
	 * Take an attempt to disable gateways which could cause multiple CC fields in the checkout,
	 *
	 * @param array $response
	 *
	 * @return void
	 */
	public function disable_other_gateways( $response ) {

		/**
		 * skip if our cc not enabled
		 */
		if ( ! isset( $response['fkwcs_stripe'] ) || false === $response['fkwcs_stripe'] ) {
			return;
		}

		$gateways = WC()->payment_gateways->payment_gateways();
		foreach ( array( 'stripe', 'stripe_cc', 'stripe_applepay', 'stripe_googlepay' ) as $id ) {
			if ( isset( $gateways[ $id ] ) && 'yes' === $gateways[ $id ]->enabled ) {
				$gateways[ $id ]->update_option( 'enabled', 'no' );
			}
		}
	}


	/**
	 * This method declared ours compat with the HPOS mechanism
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FKWCS_FILE, true );
		}
	}

	public function is_rest_api_request() {
		return ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( isset( $_SERVER['REQUEST_URI'] ) && ( strpos( $_SERVER['REQUEST_URI'], '/wp-json/' ) !== false || strpos( $_SERVER['REQUEST_URI'], 'rest_route' ) !== false ) ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Added token class
	 *
	 * @param string $class token class name.
	 * @param string $type gateway name.
	 *
	 * @return string
	 *
	 */
	public function modify_token_class( $class, $type ) {
		if ( 'fkwcs_stripe_sepa' === $type ) {
			return 'FKWCS\Gateway\Stripe\Token';
		}

		return $class;
	}

	/**
	 * This method simply overrides webhook for the WooCommerce stripe gateway, so that stripe will no longer notify sellers about webhook endpoint returning 400.
	 * @return null|void
	 */
	public function control_webhook( $return = null, $rest = null, $request = null ) { //  phpcs:ignore WordPressVIPMinimum.Hooks.AlwaysReturnInFilter.VoidReturn,WordPressVIPMinimum.Hooks.AlwaysReturnInFilter.MissingReturnStatement


		if ( current_action() === 'woocommerce_api_wc_stripe' ) {
			if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) || ! isset( $_GET['wc-api'] ) || ( 'wc_stripe' !== $_GET['wc-api'] && 'wt_stripe' !== $_GET['wc-api'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
		}

		if ( current_filter() === 'rest_pre_dispatch' ) {
			if ( $request->get_route() !== '/wc-stripe/v1/webhook' && $request->get_route() !== '/cpsw/v1/webhook' ) {
				return $return;
			}
		}

		http_response_code( 200 );
		exit();
	}

	/**
	 *
	 * @param string $payment_method Like stripe,stripe_cc
	 */
	public function change_payment_method( $payment_method ) {

		if ( true === $this->maybe_prevent_change_method() ) {
			return $payment_method;
		}
		switch ( $payment_method ) {
			case 'stripe':
			case 'stripe_cc':
			case 'stripe_applepay':
			case 'stripe_googlepay':
				if ( did_action( 'woocommerce_checkout_order_processed' ) ) {
					return $payment_method;
				}
				$payment_method = 'fkwcs_stripe';
				break;
			case 'stripe_sepa':
				if ( did_action( 'woocommerce_checkout_order_processed' ) ) {
					return $payment_method;
				}
				$payment_method = 'fkwcs_stripe_sepa';
				break;
		}

		return $payment_method;
	}


	private function maybe_prevent_change_method() {

		if ( current_action() === 'woocommerce_scheduled_subscription_payment' && ! WC()->payment_gateways()->payment_gateways()['fkwcs_stripe']->is_configured() ) {
			return true;
		}
		if ( isset( $_GET['wc-ajax'] ) && 'wc_stripe_frontend_request' === wc_clean( $_GET['wc-ajax'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended

			return true;
		}
		if ( isset( $_GET['wc-ajax'] ) && 'wc_stripe_verify_intent' === wc_clean( $_GET['wc-ajax'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended

			return true;
		}

		return false;
	}


}

Stripe::get_instance();
