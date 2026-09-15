<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;
use Stripe\OAuth;

#[\AllowDynamicProperties]
class Admin {
	private static $instance = null;

	const APPLEPAY_FILE = 'apple-developer-merchantid-domain-association';
	const APPLEPAY_DIR  = '.well-known';

	const FKWCS_LIVE_WEBHOOK_HEALTH_ALERT = 'fkwcs_live_webhook_health_alert';

	/**
	 * Navigation links for the payment method pages.
	 *
	 * @var $navigation array
	 */
	public $navigation   = array();
	public $account_data = array(
		'statement_descriptor_prefix' => '',
		'statement_descriptor_full'   => '',
	);
	/**
	 * Option keys
	 *
	 * @var string[]
	 */
	private $options_keys = array(
		'fkwcs_test_pub_key'                       => '',
		'fkwcs_test_secret_key'                    => '',
		'fkwcs_pub_key'                            => '',
		'fkwcs_secret_key'                         => '',
		'fkwcs_con_status'                         => '',
		'fkwcs_mode'                               => 'live',
		'fkwcs_live_webhook_secret'                => '',
		'fkwcs_test_webhook_secret'                => '',
		'fkwcs_debug_log'                          => '',
		'fkwcs_currency_fee'                       => '',
		'fkwcs_account_id'                         => '',
		'fkwcs_auto_connect'                       => '',
		'fkwcs_setup_status'                       => '',
		'fkwcs_live_created_webhook'               => '',
		'fkwcs_test_created_webhook'               => '',
		'fkwcs_apple_pay_verified_domain'          => '',
		'fkwcs_apple_pay_domain_is_verified'       => '',
		'fkwcs_stripe_statement_descriptor_full'   => '',
		'fkwcs_stripe_statement_descriptor_should_customize' => '',
		'fkwcs_stripe_statement_descriptor_prefix' => '',
		'fkwcs_stripe_statement_descriptor_suffix' => '{{WOO_ORDER_ID}}',
		'fkwcs_line_items_enabled'                 => 'no',
	);
	private $settings     = array();
	private $domain;
	private $allow_scripts_methods = array();

	/**
	 * Initiator
	 *
	 * @return Admin
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->get_option_settings();
		$this->load_navigation();
		if ( did_action( 'init' ) === 0 ) {
			add_action( 'init', array( $this, 'init' ) );
		} else {
			$this->init();
		}
	}

	/**
	 * Initialize
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_tab' ), 50 );
		add_filter( 'woocommerce_get_sections_checkout', array( $this, 'sub_links' ), 300 );
		add_filter( 'woocommerce_get_sections_fkwcs_api_settings', array( $this, 'sub_links' ), 300 );
		add_action( 'woocommerce_sections_fkwcs_api_settings', array( $this, 'add_breadcrumb' ) );
		add_filter( 'woocommerce_get_settings_checkout', array( $this, 'stripe_express_checkout_settings' ), 10, 2 );

		$this->allow_scripts_methods = apply_filters( 'fkwcs_allow_admin_scripts_methods', array_keys( $this->navigation ) );

		add_action( 'admin_head', array( $this, 'add_custom_css' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_js' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_order_pay_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_redirect' ) );
		add_action( 'admin_init', array( $this, 'init_notices' ) );

		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_order_fee' ) );
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_order_payout' ), 20 );
		add_filter( 'plugin_action_links_' . FKWCS_BASE, array( $this, 'add_plugin_settings_link' ) );
		add_action( 'woocommerce_admin_field_fkwcs_express_checkout_preview', array( $this, 'express_checkout_preview' ) );

		add_filter( 'fkwcs_settings', array( $this, 'filter_settings_fields' ), 1 );

		/**
		 * Admin settings custom fields
		 */
		add_action( 'woocommerce_admin_field_fkwcs_stripe_connect', array( $this, 'stripe_connect' ) );
		add_action( 'woocommerce_admin_field_fkwcs_account_id', array( $this, 'account_id' ) );
		add_action( 'woocommerce_admin_field_fkwcs_webhook_url', array( $this, 'webhook_url' ) );
		add_action( 'woocommerce_admin_field_wc_fkwcs_connection_test', array( $this, 'wc_fkwcs_connection_test' ) );

		$this->admin_page_settings();

		$this->domain_is_verfied = get_option( 'fkwcs_apple_pay_domain_is_verified' );
		$this->verified_domain   = get_option( 'fkwcs_apple_pay_verified_domain' );
		$this->failure_message   = '';
		$this->domain            = isset( $_SERVER['HTTP_HOST'] ) ? wc_clean( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : str_replace(
			array(
				'https://',
				'http://',
			),
			'',
			get_site_url()
		); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 10, 2 );

		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_capture_charge_on_status' ), 10, 4 );

		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_pay_order_button' ) );

		if ( is_ajax() ) {

			/**
			 * WooCommerce Order screen AJAX endpoints
			 */
			add_action( 'wp_ajax_get_transaction_data_admin', array( $this, 'get_transaction_data' ) );
			add_action( 'wp_ajax_capture_charge', array( $this, 'capture_charge' ) );
			add_action( 'wp_ajax_void_charge', array( $this, 'void_charge' ) );
			add_action( 'wp_ajax_fkwcs_admin_pay_order', array( $this, 'pay_order' ) );

			/**
			 * Settings screen AJAX endpoint
			 */
			add_action( 'wp_ajax_fkwcs_test_stripe_connection', array( $this, 'connection_test' ) );
			add_action( 'wp_ajax_fkwcs_disconnect_account', array( $this, 'disconnect_account' ) );
			add_action( 'wp_ajax_fkwcs_create_webhook', array( $this, 'connect_webhook' ) );
			add_action( 'wp_ajax_fkwcs_delete_webhook', array( $this, 'disconnect_webhook' ) );
			add_action( 'wp_ajax_fkwcs_apple_pay_domain_verification', array( $this, 'apple_pay_domain_verification' ) );
			add_action( 'wp_ajax_fkwcs_dismiss_notice', array( $this, 'dismiss_notice' ) );
			add_action( 'wp_ajax_fkwcs_blocks_incompatible_switch_to_classic', array( $this, 'blocks_incompatible_switch_to_classic_cart_checkout' ) );

		}
		add_action( 'admin_init', array( $this, 'maybe_setup_account_info' ), - 1 );

		add_filter( 'woocommerce_generate_fkwcs_radio_html', array( $this, 'fkwcs_radio_html_field' ), 10, 4 );
		add_filter( 'woocommerce_generate_fkwcs_admin_fields_start_html', array( $this, 'fkwcs_admin_fields_start_html_field' ), 10, 4 );
		add_filter( 'woocommerce_generate_fkwcs_admin_fields_end_html', array( $this, 'fkwcs_admin_fields_end_html_field' ), 10, 4 );

		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'wc/v3',
					'/wc_stripe/orders/(?P<order_id>\w+)/capture_terminal_payment',
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'fkwcs_capture_terminal_payment' ),
						'permission_callback' => array( $this, 'check_permission' ),
						'args'                => array(
							'payment_intent_id' => array(
								'required' => true,
							),
						),
					)
				);
			},
			- 1
		);

		add_action( 'admin_footer', array( $this, 'add_custom_admin_footer_script' ) );
		add_filter( 'admin_footer_text', array( $this, 'add_support_link' ) );

		add_action( 'wp_ajax_fkwcs_check_live_webhook_url', array( $this, 'ajax_check_live_webhook_url' ) );
	}

	public function check_permission() {
		return current_user_can( 'manage_woocommerce' );
	}

	public function ajax_check_live_webhook_url() {

		check_ajax_referer( 'fkwcs_admin_request', '_security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'msg' => 'Permission denied.' ) );
		}

		$webhook = get_option( 'fkwcs_live_created_webhook' );
		if ( empty( $webhook ) ) {
			wp_send_json_success( array( 'exists' => false ) );
		}
		$webhook = $webhook['id'];

		$secret_key = $this->get_api_option( 'fkwcs_secret_key' );
		if ( empty( $secret_key ) ) {
			wp_send_json_error( array( 'exists' => false ) );
		}

		try {
			$client         = new \Stripe\StripeClient( $secret_key );
			$stripe_webhook = $client->webhookEndpoints->retrieve( $webhook );
			$expected_url   = esc_url( rest_url( 'fkwcs/v1/webhook' ) );
			$actual_url     = $stripe_webhook->url;

			if ( $actual_url !== $expected_url || $stripe_webhook['status'] === 'disabled' ) {
				wp_send_json_success(
					array(
						'exists'       => true,
						'mismatch'     => true,
						'expected_url' => $expected_url,
						'actual_url'   => $actual_url,
						'webhook_id'   => $webhook,
					)
				);
			} else {
				wp_send_json_success(
					array(
						'exists'   => true,
						'mismatch' => false,
					)
				);
			}
		} catch ( \Exception $e ) {
			wp_send_json_error(
				array(
					'msg'        => $e->getMessage(),
					'mismatch'   => true,
					'webhook_id' => $webhook,
				)
			);
		}
	}

	/**
	 * Admin hooks
	 *
	 * @return void
	 */
	private function admin_page_settings() {
		add_action( 'woocommerce_update_options_checkout', array( $this, 'express_checkout_option_updates' ) );
		add_action( 'woocommerce_settings_tabs_fkwcs_api_settings', array( $this, 'api_settings_tab' ) );
		add_action( 'woocommerce_update_options_fkwcs_api_settings', array( $this, 'update_api_settings' ) );
		add_action( 'woocommerce_admin_field_fkwcs_stripe_connect', array( $this, 'stripe_connect' ) );
		add_action( 'woocommerce_admin_field_fkwcs_webhook_url', array( $this, 'webhook_url' ) );
		add_action( 'woocommerce_admin_field_fkwcs_create_webhook_button', array( $this, 'webhook_connect' ) );
		add_action( 'woocommerce_admin_field_fkwcs_delete_webhook_button', array( $this, 'webhook_delete' ) );
		add_action( 'woocommerce_admin_field_fkwcs_stripe_statement_preview', array( $this, 'generate_wc_fkwcs_stripe_statement_preview_html' ) );
		add_action( 'woocommerce_admin_field_fkwcs_stripe_wrap_fields_start', array( $this, 'generate_wc_fkwcs_stripe_wrap_fields_start_html' ) );
		add_action( 'woocommerce_admin_field_fkwcs_stripe_wrap_fields_end', array( $this, 'generate_wc_fkwcs_stripe_wrap_fields_end_html' ) );
	}

	/**
	 * Plugin settings
	 *
	 * @return void
	 */
	public function api_settings_tab() {
		woocommerce_admin_fields( $this->get_api_settings() );
	}

	/**
	 * Update API settings
	 *
	 * @return void
	 */
	public function update_api_settings() {
		woocommerce_update_options( $this->get_api_settings() );
		$this->get_option_settings();
	}

	/**
	 * @return void
	 */
	private function load_navigation() {
		if ( did_action( 'init' ) === 0 ) {
			add_action( 'init', array( $this, 'define_navigation' ) );
		} else {
			$this->define_navigation();
		}
	}

	/**
	 * WC Payment tab settings
	 *
	 * @return void
	 */
	public function define_navigation() {
		$legacy_nav = ( 'yes' === get_option( \FKWCS\Gateway\Stripe\Migration::HAS_LEGACY_FLAG ) ) ? array( 'fkwcs_express_checkout' => __( 'Express Checkout', 'funnelkit-stripe-woo-payment-gateway' ) ) : array();

		$this->navigation = apply_filters(
			'fkwcs_settings_navigation',
			array(
				'fkwcs_api_settings' => __( 'Stripe Settings', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe'       => __( 'Credit Cards', 'funnelkit-stripe-woo-payment-gateway' ),
			) + $legacy_nav + array(
				'fkwcs_stripe_google_pay' => __( 'Google Pay', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_apple_pay'  => __( 'Apple Pay', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_link'       => __( 'Link', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_amazon_pay' => __( 'Amazon Pay', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_affirm'     => __( 'Affirm', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_afterpay'   => __( 'Afterpay', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_mobilepay'  => __( 'Mobilepay', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_mbway'      => __( 'MB WAY', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_klarna'     => __( 'Klarna', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_ideal'      => __( 'iDeal', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_bancontact' => __( 'Bancontact', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_p24'        => __( 'P24', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_ach'        => __( 'ACH', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_sepa'       => __( 'SEPA', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_alipay'     => __( 'Alipay', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_cashapp'    => __( 'Cashapp', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_pix'        => __( 'Pix', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_multibanco' => __( 'Multibanco', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_eps'        => __( 'EPS', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_twint'      => __( 'TWINT', 'funnelkit-stripe-woo-payment-gateway' ),
				'fkwcs_stripe_blik'       => __( 'BLIK', 'funnelkit-stripe-woo-payment-gateway' ),

			)
		);
	}

	/**
	 * Get option settings
	 *
	 * @return void
	 */
	private function get_option_settings() {

		foreach ( $this->options_keys as $option => $val ) {

			/**
			 * if value is saved in the database
			 */
			$value_from_db = get_option( $option, 'fkwcs_default_value' );

			/**
			 * if not found in database
			 */
			if ( $value_from_db === 'fkwcs_default_value' ) {
				update_option( $option, $val );
				$value_from_db = $val;
			}

			$this->options_keys[ $option ] = $value_from_db;
		}
	}

	/**
	 * Get Stripe API settings
	 *
	 * @return mixed|null
	 */
	public function get_api_settings() {
		$settings = array(
			'section_title'                         => array(
				'name' => __( 'Stripe Settings', 'funnelkit-stripe-woo-payment-gateway' ),
				'type' => 'title',
				'id'   => 'fkwcs_title',
			),
			'fkwcs_stripe_wrap_fields_start'        => array(
				'type'       => 'fkwcs_stripe_wrap_fields_start',
				'customdata' => array(),
			),
			'connection_status'                     => array(
				'name'  => __( 'Stripe Connect', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'  => 'fkwcs_stripe_connect',
				'value' => '--',
				'class' => 'wc_fkwcs_connect_btn',
				'id'    => 'fkwcs_stripe_connect',
			),
			'account_id'                            => array(
				'name'     => __( 'Connection Status', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'     => 'fkwcs_account_id',
				'value'    => '--',
				'class'    => 'account_id',
				'desc_tip' => __( 'This is your Stripe Connect ID and serves as a unique identifier.', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc'     => __( 'This is your Stripe Connect ID and serves as a unique identifier.', 'funnelkit-stripe-woo-payment-gateway' ),
				'id'       => 'fkwcs_account_id',
			),
			'account_keys'                          => array(
				'name'  => __( 'Stripe Account Keys', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'  => 'fkwcs_account_keys',
				'class' => 'wc_stripe_acc_keys',
				'desc'  => __( 'This will disable any connection to Stripe.', 'funnelkit-stripe-woo-payment-gateway' ),
				'id'    => 'fkwcs_account_keys',
			),
			'connect_button'                        => array(
				'name'  => __( 'Connect Stripe Account', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'  => 'fkwcs_connect_btn',
				'class' => 'wc_fkwcs_connect_btn',
				'desc'  => __( 'We make it easy to connect Stripe to your site. Click the Connect button to go through our connect flow.', 'funnelkit-stripe-woo-payment-gateway' ),
				'id'    => 'fkwcs_connect_btn',
			),

			'test_mode'                             => array(
				'name'     => __( 'Mode', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'     => 'select',
				'options'  => array(
					'test'            => 'Test Mode',
					'test_admin_only' => 'Test Mode (For administrators)',
					'live'            => 'Live Mode',
				),
				'desc'     => __( 'No live transactions are processed in test mode. To fully use test mode, you must have a sandbox (test) account for the payment gateway you are testing.<br/> ', 'funnelkit-stripe-woo-payment-gateway' ),
				'id'       => 'fkwcs_mode',
				'desc_tip' => true,
			),
			'live_pub_key'                          => array(
				'name'     => __( 'Live Publishable Key', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'     => 'text',
				'desc_tip' => __( 'Your publishable key is used to initialize Stripe assets.', 'funnelkit-stripe-woo-payment-gateway' ),
				'id'       => 'fkwcs_pub_key',
			),
			'live_secret_key'                       => array(
				'name'     => __( 'Live Secret Key', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'     => 'password',
				'desc_tip' => __( 'Your secret key is used to authenticate Stripe requests.', 'funnelkit-stripe-woo-payment-gateway' ),
				'id'       => 'fkwcs_secret_key',
			),
			'test_pub_key'                          => array(
				'name'     => __( 'Test Publishable Key', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'     => 'text',
				'desc_tip' => __( 'Your test publishable key is used to initialize Stripe assets.', 'funnelkit-stripe-woo-payment-gateway' ),
				'id'       => 'fkwcs_test_pub_key',
			),
			'test_secret_key'                       => array(
				'name'     => __( 'Test Secret Key', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'     => 'password',
				'desc_tip' => __( 'Your test secret key is used to authenticate Stripe requests for testing purposes.', 'funnelkit-stripe-woo-payment-gateway' ),
				'id'       => 'fkwcs_test_secret_key',
			),

			'do_connection'                         => array(
				'name'  => __( 'Test Connection', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'  => 'wc_fkwcs_connection_test',
				'class' => 'wc_fkwcs_connection_test',
				'desc'  => __( 'Click this button to test a connection. If successful, your site is connected to Stripe.', 'funnelkit-stripe-woo-payment-gateway' ),
				'id'    => 'wc_fkwcs_connection_test',
			),

			'currency_fee'                          => array(
				'title'    => __( 'Stripe Fees Currency', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'     => 'checkbox',
				'default'  => 'no',
				'desc'     => __( 'Show Stripe Fee in Order Currency', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc_tip' => __( 'When enabled, the Stripe fee and payout will be shown in the order\'s currency. By default, Stripe provides the fee and payout in the currency of the Stripe account.', 'funnelkit-stripe-woo-payment-gateway' ),
				'id'       => 'fkwcs_currency_fee',
			),

			'statement_descriptor_full'             => array(
				'title'             => __( 'Statement Descriptor', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'              => 'text',
				'desc'              => __( 'You can change the Statement descriptor your customers see on their bank statement in your <a href="https://dashboard.stripe.com/settings/public" target="_blank">Stripe account settings</a>. It recommended to keep it to your domain address so your buyers can reach out to you and avoid potential disputes and chargebacks.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'           => self::get_instance()->account_data['statement_descriptor_full'],
				'custom_attributes' => array( 'readonly' => 'readonly' ),
				'id'                => 'fkwcs_stripe_statement_descriptor_full',

			),
			'statement_descriptor_should_customize' => array(
				'name'     => __( 'Enable Custom Descriptor', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Enable Custom Descriptor', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc_tip' => __( 'Enable this setting to pass dynamic statement descriptor.', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'  => 'no',
				'id'       => 'fkwcs_stripe_statement_descriptor_should_customize',
			),
			'statement_descriptor_prefix'           => array(
				'title'             => __( 'Shortened Descriptor', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc'              => __( 'You can change the Shortened Descriptor your customers see on their bank statement in your <a href="https://dashboard.stripe.com/settings/public" target="_blank">Stripe account settings</a>.', 'funnelkit-stripe-woo-payment-gateway' ),
				'class'             => 'fkwcs_statement_desc_options',
				'custom_attributes' => array( 'readonly' => 'readonly' ),
				'type'              => 'text',
				'default'           => self::get_instance()->account_data['statement_descriptor_prefix'],
				'id'                => 'fkwcs_stripe_statement_descriptor_prefix',
			),
			'statement_descriptor_suffix'           => array(
				'title'   => __( 'Shortened Descriptor Suffix', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'    => 'text',
				'class'   => 'fkwcs_statement_desc_options',
				'default' => '{{WOO_ORDER_ID}}',
				'id'      => 'fkwcs_stripe_statement_descriptor_suffix',
				'desc'    => __( 'You can pass custom payment descriptor suffix along with shortened descriptor in your <a href="https://dashboard.stripe.com/settings/public" target="_blank">Stripe account settings</a>. You can change the shortened descriptor or use dynamic Order ID using merge tag {{WOO_ORDER_ID}}', 'funnelkit-stripe-woo-payment-gateway' ),

			),
			'fkwcs_stripe_statement_preview'        => array(
				'type'       => 'fkwcs_stripe_statement_preview',
				'customdata' => self::get_instance()->account_data,
			),

			'line_items_enabled'                    => array(
				'name'     => __( 'Send Line Items', 'funnelkit-stripe-woo-payment-gateway' ),
				'desc'     => __( 'Send detailed line item data to Stripe for each purchase.', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'     => 'checkbox',
				'desc_tip' => __( 'Enabling line items helps improve acceptance and renewal rates on some payment methods. <a href="https://docs.stripe.com/payments/payment-line-items" target="_blank" rel="noopener noreferrer">Learn more about Stripe line items.</a>', 'funnelkit-stripe-woo-payment-gateway' ),
				'default'  => 'no',
				'id'       => 'fkwcs_line_items_enabled',
			),

			'create_webhook_button'                 => array(
				'name'  => __( 'Create Webhook', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'  => 'fkwcs_create_webhook_button',
				'class' => 'wc_fkwcs_create_webhook_button',
				'desc'  => __( 'We make it easy to connect Stripe to your site. Click the Connect button to go through our connect flow.', 'funnelkit-stripe-woo-payment-gateway' ),
				'id'    => 'fkwcs_create_webhook_button',
			),
			'delete_webhook_button'                 => array(
				'name'  => __( 'Delete Webhook', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'  => 'fkwcs_delete_webhook_button',
				'class' => 'wc_fkwcs_delete_webhook_button',
				'desc'  => '',
				'id'    => 'fkwcs_delete_webhook_button',
			),
			'webhook_url'                           => array(
				'name'  => __( 'Webhook URL', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'  => 'fkwcs_webhook_url',
				'class' => 'wc_fkwcs_webhook_url',
				/* translators: %1$1s - %2$2s HTML markup */
				'desc'  => sprintf( __( 'Important: the webhook URL is called by Stripe when events occur in your account, like a source becomes chargeable. %1$1sWebhook Guide%2$2s', 'funnelkit-stripe-woo-payment-gateway' ), '<a href="https://funnelkit.com/docs/stripe-gateway-for-woocommerce/webhooks/" target="_blank">', '</a>' ),
				'id'    => 'fkwcs_webhook_url',
			),
			'live_webhook_secret'                   => array(
				'name' => __( 'Live Webhook Secret', 'funnelkit-stripe-woo-payment-gateway' ),
				'type' => 'password',
				/* translators: %1$1s Webhook Status */
				'desc' => sprintf( __( 'The webhook secret is used to authenticate webhooks sent from Stripe. It ensures nobody else can send you events pretending to be Stripe. %1$1s', 'funnelkit-stripe-woo-payment-gateway' ), '</br>' . Webhook::get_webhook_interaction_message( 'live' ) ),
				'id'   => 'fkwcs_live_webhook_secret',
			),
			'test_webhook_secret'                   => array(
				'name' => __( 'Test Webhook Secret', 'funnelkit-stripe-woo-payment-gateway' ),
				'type' => 'password',
				/* translators: %1$1s Webhook Status */
				'desc' => sprintf( __( 'The webhook secret is used to authenticate webhooks sent from Stripe. It ensures nobody else can send you events pretending to be Stripe. %1$1s', 'funnelkit-stripe-woo-payment-gateway' ), '</br>' . Webhook::get_webhook_interaction_message( 'test' ) ),
				'id'   => 'fkwcs_test_webhook_secret',
			),
			'debug_log'                             => array(
				'name'     => __( 'Debug Log', 'funnelkit-stripe-woo-payment-gateway' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Log debug messages', 'funnelkit-stripe-woo-payment-gateway' ),
				/* translators: %s: Log file path */
				'desc_tip' => sprintf( __( 'Log Stripe API calls, inside %s Note: this may log personal information. We recommend using this for debugging purposes only and deleting the logs when finished.', 'funnelkit-stripe-woo-payment-gateway' ), '<code>' . \WC_Log_Handler_File::get_log_file_path( 'fkwcs-stripe' ) . '</code>' ),
				'id'       => 'fkwcs_debug_log',
			),
			'fkwcs_stripe_wrap_fields_end'          => array(
				'type'       => 'fkwcs_stripe_wrap_fields_end',
				'customdata' => array(),
			),
			'section_end'                           => array(
				'type' => 'sectionend',
				'id'   => 'fkwcs_api_settings_section_end',
			),
		);
		$settings = apply_filters( 'fkwcs_settings', $settings );

		return $settings;
	}

	/**
	 * Add Stripe setting
	 *
	 * @param $settings_tabs
	 *
	 * @return mixed
	 */
	public function add_settings_tab( $settings_tabs ) {
		$settings_tabs['fkwcs_api_settings'] = __( 'Stripe', 'funnelkit-stripe-woo-payment-gateway' );

		return $settings_tabs;
	}

	/**
	 * Add sub section links
	 *
	 * @param $settings_tab
	 *
	 * @return mixed|null
	 */
	public function sub_links( $settings_tab ) {
		if ( isset( $_GET['section'] ) && 0 === strpos( wc_clean( wp_unslash( $_GET['section'] ) ), 'fkwcs_' ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$settings_tab = array();
			foreach ( $this->navigation as $key => $sub ) {
				$settings_tab[ $key ] = $sub;
			}
		}

		return apply_filters( 'fkwcs_setting_tabs', $settings_tab );
	}

	/**
	 * Display connect view
	 *
	 * @param $value
	 *
	 * @return void
	 */
	public function stripe_connect( $value ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedParameter,VariableAnalysis.Variables.VariableAnalysis.UnusedParameter
		include __DIR__ . '/parts/connect.php';
	}

	/**
	 * Display webhook section view
	 *
	 * @param $value
	 *
	 * @return void
	 */
	public function webhook_connect( $value ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedParameter,VariableAnalysis.Variables.VariableAnalysis.UnusedParameter
		if ( empty( $this->get_api_option( 'fkwcs_test_created_webhook' ) ) && empty( $this->get_api_option( 'fkwcs_live_created_webhook' ) ) ) {
			include __DIR__ . '/parts/webhook.php';
		}
	}

	/**
	 * Display webhook delete view
	 *
	 * @param $value
	 *
	 * @return void
	 */
	public function webhook_delete( $value ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedParameter,VariableAnalysis.Variables.VariableAnalysis.UnusedParameter
		if ( ! empty( $this->get_api_option( 'fkwcs_test_created_webhook' ) ) || ! empty( $this->get_api_option( 'fkwcs_live_created_webhook' ) ) ) {
			include __DIR__ . '/parts/webhook_delete.php';
		}
	}

	/**
	 * Get connect URL
	 *
	 * @return string
	 */
	public function get_connect_url() {
		$transient_key = 'fkwcs_connect_state_' . get_current_user_id();
		$state_token   = get_transient( $transient_key );
		if ( empty( $state_token ) ) {
			$state_token = wp_generate_password( 32, false );
			set_transient( $transient_key, $state_token, 30 * MINUTE_IN_SECONDS );
		}

		$custom_args = array(
			'redirect' => admin_url( 'admin.php?page=wc-settings&tab=fkwcs_api_settings&fkwcs_state=' . $state_token ),
		);

		$get_unique_id = get_option( 'fkwcs_wp_stripe', '' );
		if ( ! empty( $get_unique_id ) ) {
			$custom_args['wp_stripe'] = $get_unique_id;
		}

		return OAuth::authorizeUrl(
			apply_filters(
				'fkwcs_stripe_connect_url_data',
				array(
					'response_type'  => 'code',
					'client_id'      => 'ca_ME1hglU0nsfgQBtX4spILQpPqNH7vAtz',
					'stripe_landing' => 'login',
					'always_prompt'  => 'true',
					'scope'          => 'read_write',
					'state'          => base64_encode( //phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						wp_json_encode( $custom_args )
					),
				)
			)
		);
	}

	/**
	 * Add sections UI
	 *
	 * @return void
	 */
	public function add_breadcrumb() {
		if ( empty( $this->navigation ) ) {
			return;
		}
		?>
		<ul class="subsubsub">
			<?php
			foreach ( $this->navigation as $key => $value ) {
				$current_class = '';
				$separator     = '';
				if ( isset( $_GET['tab'] ) && 'fkwcs_api_settings' === $_GET['tab'] && 'fkwcs_api_settings' === $key ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$current_class = 'current';
					echo wp_kses_post( '<li> <a href="' . get_site_url() . '/wp-admin/admin.php?page=wc-settings&tab=fkwcs_api_settings" class="' . $current_class . '">' . $value . '</a> | </li>' );
				} else {
					if ( end( $this->navigation ) !== $value ) {
						$separator = ' | ';
					}
					echo wp_kses_post( '<li> <a href="' . get_site_url() . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=' . $key . '" class="' . $current_class . '">' . $value . '</a>' . $separator . '</li>' );
				}
			}
			?>
		</ul>
		<br class="clear"/>
		<?php
	}

	/**
	 * Stripe express checkout section settings
	 *
	 * @param $settings
	 * @param $current_section
	 *
	 * @return array|mixed|void
	 */
	public function stripe_express_checkout_settings( $settings, $current_section ) {
		if ( 'fkwcs_api_settings' === $current_section ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=fkwcs_api_settings' ) );
			exit;
		}

		if ( 'fkwcs_express_checkout' !== $current_section ) {
			return $settings;
		}

		// Only existing (pre-1_15) users have HAS_LEGACY_FLAG set. New installs never
		// had shared express settings, so redirect them away from this legacy section.
		if ( 'yes' !== get_option( \FKWCS\Gateway\Stripe\Migration::HAS_LEGACY_FLAG ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=fkwcs_stripe_apple_pay' ) );
			exit;
		}

		$values = Helper::get_gateway_settings();

		$settings = array(
			'section_title' => array(
				'name' => __( 'Express Checkout', 'funnelkit-stripe-woo-payment-gateway' ),
				'type' => 'title',
				'desc' => sprintf(
					/* translators: 1: Apple Pay URL, 2: Google Pay URL, 3: Link URL */
					__( 'Express Checkout settings have been moved to the individual payment method settings. Please configure each method separately: %1$s | %2$s | %3$s<br>', 'funnelkit-stripe-woo-payment-gateway' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=fkwcs_stripe_apple_pay' ) ) . '">Apple Pay</a>',
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=fkwcs_stripe_google_pay' ) ) . '">Google Pay</a>',
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=fkwcs_stripe_link' ) ) . '">Link</a>',
				),
				'id'   => 'fkwcs_express_checkout',
			),
			'section_end'   => array(
				'type' => 'sectionend',
				'id'   => 'fkwcs_express_checkout',
			),
		);

		return $settings;
	}

	/**
	 * Save express checkout settings
	 *
	 * @return void
	 */
	public function express_checkout_option_updates() {
		$current_section = empty( $_REQUEST['section'] ) ? '' : sanitize_title( wp_unslash( $_REQUEST['section'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $_POST['save'] ) || 'fkwcs_express_checkout' !== $current_section ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$express_checkout = array();
		$radio_checkbox   = array(
			'express_checkout_enabled'             => 'no',
			'express_checkout_link_button_enabled' => 'no',
		);
		foreach ( $_POST as $key => $value ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( 0 === strpos( $key, 'fkwcs_express_checkout' ) ) {
				$k = sanitize_text_field( str_replace( 'fkwcs_', '', $key ) );
				if ( ! empty( $radio_checkbox ) && in_array( $k, array_keys( $radio_checkbox ), true ) ) {
					$express_checkout[ $k ] = 'yes';
					unset( $radio_checkbox[ $k ] );
				} elseif ( is_array( $value ) ) {
						$express_checkout[ $k ] = array_map( 'sanitize_text_field', $value );
				} else {
					$express_checkout[ $k ] = sanitize_text_field( $value );
				}
				unset( $_POST[ $key ] ); //phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}

		if ( empty( $express_checkout ) ) {
			return;
		}

		$settings_data                              = get_option( 'woocommerce_fkwcs_stripe_settings' );
		$settings_data['express_checkout_location'] = array();
		$settings_data                              = array_merge( $settings_data, $radio_checkbox, $express_checkout );

		update_option( 'woocommerce_fkwcs_stripe_settings', $settings_data );
	}

	/**
	 * Adds custom CSS
	 * Hide navigation menu item
	 *
	 * @return void
	 */
	public function add_custom_css() {

		if ( isset( $_GET['page'] ) && 'wc-settings' === $_GET['page'] && isset( $_GET['tab'] ) && ( 'fkwcs_api_settings' === $_GET['tab'] || isset( $_GET['section'] ) && ( in_array( sanitize_text_field( $_GET['section'] ), $this->allow_scripts_methods, true ) ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended

			wp_register_style( 'fkwcs-style', FKWCS_URL . 'admin/assets/css/admin.css', array(), FKWCS_VERSION );
			wp_enqueue_style( 'fkwcs-style' );
			?>
			<style>
				a[href='<?php echo esc_url( get_site_url() ); ?>/wp-admin/admin.php?page=wc-settings&tab=fkwcs_api_settings'].nav-tab {
					display: none
				}
			</style>
			<?php
		}
	}

	/**
	 * Get active mode API key
	 *
	 * @return false|mixed|null
	 */
	protected function get_api_key() {
		$test_mode    = get_option( 'fkwcs_mode', 'test' );
		$test_pub_key = get_option( 'fkwcs_test_pub_key', '' );
		$live_pub_key = get_option( 'fkwcs_pub_key', '' );

		return ( 'test' === $test_mode ) ? $test_pub_key : $live_pub_key;
	}

	/**
	 * Get gateway all mode keys
	 *
	 * @param $key
	 *
	 * @return array|bool|mixed|string|null
	 */
	private function get_gateway_keys( $key = 'all' ) {

		$gateway_keys                        = array();
		$gateway_keys['test_mode']           = $this->get_api_option( 'fkwcs_mode' );
		$gateway_keys['test_pub_key']        = $this->get_api_option( 'fkwcs_test_pub_key' );
		$gateway_keys['test_secret_key']     = $this->get_api_option( 'fkwcs_test_secret_key' );
		$gateway_keys['live_pub_key']        = $this->get_api_option( 'fkwcs_pub_key' );
		$gateway_keys['live_secret_key']     = $this->get_api_option( 'fkwcs_secret_key' );
		$gateway_keys['live_webhook_secret'] = $this->get_api_option( 'fkwcs_live_webhook_secret' );
		$gateway_keys['test_webhook_secret'] = $this->get_api_option( 'fkwcs_test_webhook_secret' );
		$gateway_keys['debug']               = 'yes' === $this->get_api_option( 'fkwcs_debug_log' );

		if ( 'live' === $gateway_keys['test_mode'] ) {
			$gateway_keys['webhook_secret'] = $gateway_keys['live_webhook_secret'];
		}

		if ( 'test' === $gateway_keys['test_mode'] ) {
			$gateway_keys['webhook_secret'] = $gateway_keys['test_webhook_secret'];
		}

		unset( $gateway_keys['live_webhook_secret'] );
		unset( $gateway_keys['test_webhook_secret'] );

		if ( 'all' === $key ) {
			return $gateway_keys;
		}

		if ( isset( $gateway_keys[ $key ] ) ) {
			return $gateway_keys[ $key ];
		}

		return null;
	}

	/**
	 * Include JS assets on the gateway settings page
	 *
	 * @return void
	 */
	public function enqueue_js() {
		if ( isset( $_GET['page'] ) && 'wc-settings' === $_GET['page'] && isset( $_GET['tab'] ) && ( 'fkwcs_api_settings' === $_GET['tab'] || isset( $_GET['section'] ) && ( in_array( sanitize_text_field( $_GET['section'] ), $this->allow_scripts_methods, true ) ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended

			wp_register_script( 'fkwcs-stripe-external', 'https://js.stripe.com/v3/', array(), FKWCS_VERSION, true );
			wp_register_script( 'fkwcs-admin-js', plugins_url( 'assets/js/admin.js', __FILE__ ), array( 'jquery', 'fkwcs-stripe-external' ), FKWCS_VERSION, true );
			wp_enqueue_script( 'fkwcs-admin-js' );

			$settings_data       = array();
			$fkwcs_settings_data = Helper::get_gateway_settings();
			$pub_key             = $this->get_api_key();

			if ( ! empty( $fkwcs_settings_data ) && is_array( $fkwcs_settings_data ) ) {
				// Resolve theme from per-method Apple Pay setting (post-1_15 source of truth).
				$apple_data      = Helper::get_gateway_settings( 'fkwcs_stripe_apple_pay' );
				$apple_raw_theme = $apple_data['button_theme'] ?? '';
				if ( 'white' === $apple_raw_theme || 'white-outline' === $apple_raw_theme ) {
					$resolved_theme = 'light';
				} elseif ( 'black' === $apple_raw_theme ) {
					$resolved_theme = 'dark';
				} else {
					$resolved_theme = $fkwcs_settings_data['express_checkout_button_theme'] ?? 'dark';
				}
				$settings_data = array(
					'theme' => $resolved_theme,
					'title' => $fkwcs_settings_data['express_checkout_title'] ?? '',
					'text'  => $fkwcs_settings_data['express_checkout_button_text'] ?? '',
				);
			}

			wp_localize_script(
				'fkwcs-admin-js',
				'fkwcs_admin_data',
				apply_filters(
					'fkwcs_admin_localize_script_args',
					array(
						'site_url'              => get_site_url() . '/wp-admin/admin.php?page=wc-settings',
						'ajax_url'              => admin_url( 'admin-ajax.php' ),
						'icons'                 => array(
						'applepay_gray'  => FKWCS_URL . 'assets/icons/apple_pay_gray.svg',
						'applepay_light' => FKWCS_URL . 'assets/icons/apple_pay_light.svg',
						'gpay_light'     => FKWCS_URL . 'assets/icons/gpay_light.svg',
						'gpay_gray'      => FKWCS_URL . 'assets/icons/gpay_gray.svg',
						'link'           => FKWCS_URL . 'assets/icons/link.svg',
						),
						'settings'              => $settings_data,
						'messages'              => array(
								'default_text'  => __( 'Pay Now', 'funnelkit-stripe-woo-payment-gateway' ),
								'checkout_note' => __( 'NOTE: Title and Tagline appears only on Checkout page.', 'funnelkit-stripe-woo-payment-gateway' ),
								/* translators: 1: Opening anchor tag, 2: Closing anchor tag */
								'no_method'     => sprintf( __( 'No payment method detected. Either your browser is not supported or you do not have save cards. For more details read %1$1sdocument$2$2s.', 'funnelkit-stripe-woo-payment-gateway' ), '<a href="#" target="_blank">', '</a>' ),
								),
						'stripe_country'        => get_option( 'fkwcs_stripe_account_settings', array() ),
						'pub_key'               => $pub_key,
						'is_connected'          => $this->is_stripe_connected(),
						'is_manually_connected' => $this->is_manually_connected(), //phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'fkwcs_admin_settings_tab'  => isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '', //phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'fkwcs_admin_current_page'  => isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '', //phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'fkwcs_admin_nonce'         => wp_create_nonce( 'fkwcs_admin_request' ),
					'dashboard_url'             => admin_url( 'admin.php?page=wc-settings&tab=fkwcs_api_settings' ),
					'generic_error'             => __( 'Something went wrong! Please reload the page and try again.', 'funnelkit-stripe-woo-payment-gateway' ),
					'test_btn_label'            => __( 'Save changes', 'funnelkit-stripe-woo-payment-gateway' ),
					'stripe_key_notice'         => __( 'Please enter all keys to connect to stripe.', 'funnelkit-stripe-woo-payment-gateway' ),
					'stripe_key_error'          => __( 'You must enter your API keys or connect the plugin before performing a connection test. Mode:', 'funnelkit-stripe-woo-payment-gateway' ),
					'stripe_key_unavailable'    => __( 'Keys Unavailable.', 'funnelkit-stripe-woo-payment-gateway' ),
					'stripe_disconnect'         => __( 'Your Stripe account has been disconnected.', 'funnelkit-stripe-woo-payment-gateway' ),
					'stripe_connect_other_acc'  => __( 'You can connect other Stripe account now.', 'funnelkit-stripe-woo-payment-gateway' ),
					'stripe_notice_re_verify'   => __( 'Sorry, we are unable to re-verify domain at the moment. ', 'funnelkit-stripe-woo-payment-gateway' ),
					'test_mode_admin_only_html' => __( '<p class="description">This will enable Test mode only for the admin users. While other users will continue to process payments in Live mode. </p><p class="description"> <strong>Please note that this setting should always be switched back to live mode once testing is completed to avoid such issues in the future.</strong></br>', 'funnelkit-stripe-woo-payment-gateway' ),
					)
				)
			);
		}
	}

	/**
	 * Displays the stripe fee
	 *
	 * @param $order_id
	 *
	 * @return void
	 */
	public function display_order_fee( $order_id ) {
		$order    = wc_get_order( $order_id );
		$currency = Helper::get_stripe_currency( $order );

		$fee = Helper::get_meta( $order, Helper::FKWCS_STRIPE_FEE );

		/**
		 * Fallback to legacy meta keys if the new meta key is not set.
		 */
		if ( empty( $fee ) ) {
			$currency = Helper::get_meta( $order, '_stripe_currency' );
			$fee      = Helper::get_meta( $order, '_stripe_fee' );
		}
		if ( empty( $fee ) || empty( $currency ) ) {
			return;
		}
		?>
		<tr>
			<td class="label fkwcs-stripe-fee">
				<?php echo wp_kses_post( wc_help_tip( __( 'This fee, Stripe collects for the transaction.', 'funnelkit-stripe-woo-payment-gateway' ) ) ); ?>
				<?php esc_html_e( 'Stripe Fee:', 'funnelkit-stripe-woo-payment-gateway' ); ?>
			</td>
			<td width="1%"></td>
			<td class="total">
				-
				<?php
				echo wp_kses_post(
					wc_price(
						$fee,
						array(
							'currency' => $currency,
							'decimals' => 2,
						)
					)
				);
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Displays the net total of the transaction without the stripe charges
	 *
	 * @param $order_id
	 *
	 * @return void
	 */
	public function display_order_payout( $order_id ) {
		$order    = wc_get_order( $order_id );
		$currency = Helper::get_stripe_currency( $order );

		$net = Helper::get_meta( $order, Helper::FKWCS_STRIPE_NET );

		/**
		 * Fallback to legacy meta keys if the new meta key is not set.
		 */
		if ( empty( $net ) ) {
			$currency = Helper::get_meta( $order, '_stripe_currency' );

			$net = Helper::get_meta( $order, '_stripe_net' );
		}
		if ( empty( $net ) || empty( $currency ) ) {
			return;
		}
		?>
		<tr>
			<td class="label fkwcs-stripe-payout">
				<?php echo wp_kses_post( wc_help_tip( __( 'This net total that will be credited to your stripe bank account.', 'funnelkit-stripe-woo-payment-gateway' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php esc_html_e( 'Stripe Payout:', 'funnelkit-stripe-woo-payment-gateway' ); ?>
			</td>
			<td width="1%"></td>
			<td class="total">
				<?php
				echo wp_kses_post(
					wc_price(
						$net,
						array(
							'currency' => $currency,
							'decimals' => 2,
						)
					)
				);
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Add plugin action links on plugins listing page
	 *
	 * @param $links
	 *
	 * @return array|string[]
	 */
	public function add_plugin_settings_link( $links ) {
		$plugin_links = array(
			'fkwcs_settings_link'      => '<a href="admin.php?page=wc-settings&tab=fkwcs_api_settings">' . __( 'Settings', 'funnelkit-stripe-woo-payment-gateway' ) . '</a>',
			'fkwcs_documentation_link' => '<a href="https://funnelkit.com/docs/stripe-gateway-for-woocommerce/getting-started/overview/">' . __( 'Documentation', 'funnelkit-stripe-woo-payment-gateway' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Displays the express checkout preview in admin
	 *
	 * @param $value
	 *
	 * @return void
	 */
	public function express_checkout_preview( $value ) {
		do_action( 'fkwcs_before_express_checkout_preview' );

		$learn_more = sprintf( "<a href='%s'>%s</a>", 'https://funnelkit.com/docs/stripe-gateway-for-woocommerce/troubleshooting/express-payment-buttons-not-showing/', __( 'Learn more', 'funnelkit-stripe-woo-payment-gateway' ) );
		?>
		<tr valign="top" class="fkwcs-smart-container">
			<th scope="row">
				<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?> </label>
			</th>
			<td class="form-wc form-wc-<?php echo esc_attr( $value['class'] ); ?>">
				<fieldset>
					<div class="fkwcs_express_checkout_preview_wrapper">
						<div class="fkwcs_express_checkout_preview"></div>
						<div id="fkwcs-payment-request-custom-button" class="fkwcs-payment-request-custom-button-admin">
							<button lang="auto" class="fkwcs-payment-request-custom-button-render fkwcs_express_checkout_button fkwcs-express-checkout-button large" role="button" type="submit" style="height: 40px;">
								<div class="fkwcs-express-checkout-button-inner" tabindex="-1">
									<div class="fkwcs-express-checkout-button-shines">
										<div class="fkwcs-express-checkout-button-shine fkwcs-express-checkout-button-shine--scroll"></div>
										<div class="fkwcs-express-checkout-button-shine fkwcs-express-checkout-button-shine--hover"></div>
									</div>
									<div class="fkwcs-express-checkout-button-content">
										<span class="fkwcs-express-checkout-button-label"></span>
										<img src="" class="fkwcs-express-checkout-button-icon">
									</div>
									<div class="fkwcs-express-checkout-button-overlay"></div>
									<div class="fkwcs-express-checkout-button-border"></div>
								</div>
							</button>
							<button lang="auto" class="fkwcs-payment-request-custom-button-render fkwcs_express_checkout_button fkwcs-express-checkout-button large" role="button" type="submit" style="height: 40px;">
								<div class="fkwcs-express-checkout-button-inner" tabindex="-1">
									<div class="fkwcs-express-checkout-button-shines">
										<div class="fkwcs-express-checkout-button-shine fkwcs-express-checkout-button-shine--scroll"></div>
										<div class="fkwcs-express-checkout-button-shine fkwcs-express-checkout-button-shine--hover"></div>
									</div>
									<div class="fkwcs-express-checkout-button-content">
										<span class="fkwcs-express-checkout-button-label"></span>
										<img src="" class="fkwcs-express-checkout-button-icon">
									</div>
									<div class="fkwcs-express-checkout-button-overlay"></div>
									<div class="fkwcs-express-checkout-button-border"></div>
								</div>
							</button>
						</div>
					</div>
				</fieldset>
				<fieldset>
					<div class="fkwcs_test_visibility">
						<button type="button" class="fkwcs_test_visibility button-primary"><?php esc_html_e( 'Test Visibility', 'funnelkit-stripe-woo-payment-gateway' ); ?></button>
					</div>
					<div class="fkwcs_express_checkout_connection_div">
						<div class="fkwcs-btn-type-info-wrapper" id="is_apple_pay_available">
							<span class="fkwcs_btn_connection">&#10060;</span>
							<p class="fkwcs_not_supported"><?php esc_html_e( 'Apple Pay is not supported on this browser. ', 'funnelkit-stripe-woo-payment-gateway' ); ?><?php echo wp_kses_post( $learn_more ); ?></p>
							<p class="fkwcs_is_supported"><?php esc_html_e( 'Apple Pay is supported on this browser. ', 'funnelkit-stripe-woo-payment-gateway' ); ?><?php echo wp_kses_post( $learn_more ); ?></p>
						</div>
						<div class="fkwcs-btn-type-info-wrapper" id="is_google_pay_available">
							<span class="fkwcs_btn_connection">&#10060;</span>
							<p class="fkwcs_not_supported"><?php esc_html_e( 'Google Pay is not  supported on this browser. ', 'funnelkit-stripe-woo-payment-gateway' ); ?><?php echo wp_kses_post( $learn_more ); ?></p>
							<p class="fkwcs_is_supported"><?php esc_html_e( 'Google Pay is supported on this browser. ', 'funnelkit-stripe-woo-payment-gateway' ); ?><?php echo wp_kses_post( $learn_more ); ?></p>
						</div>
						<span class="spinner"></span>
					</div>
				</fieldset>
			</td>
		</tr>
		<?php
		do_action( 'fkwcs_after_express_checkout_preview' );
	}

	/**
	 * Handle redirect and save some options
	 *
	 * @return void
	 */
	public function handle_redirect() {

		if ( isset( $_GET['tab'] ) && wc_clean( wp_unslash( $_GET['tab'] ) ) === 'fkwcs_api_settings' && isset( $_GET['error'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( false === current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			if ( ! $this->verify_connect_state() ) {
				return;
			}
			$redirect_url = apply_filters( 'fkwcs_stripe_connect_redirect_url', admin_url( '/admin.php?page=wc-settings&tab=fkwcs_api_settings' ) );

			$this->settings['fkwcs_con_status'] = 'failed';
			$this->update_options( $this->settings );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		if ( isset( $_GET['tab'] ) && $_GET['tab'] === 'fkwcs_api_settings' && isset( $_GET['response'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( false === current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			if ( ! $this->verify_connect_state() ) {
				return;
			}
			$response     = $_GET; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$redirect_url = apply_filters( 'fkwcs_stripe_connect_redirect_url', admin_url( '/admin.php?page=wc-settings&tab=fkwcs_api_settings' ) );

			if ( ! empty( $response['response'] ) ) {
				$live_details = array();
				$test_details = array();
				wp_parse_str( $response['response']['live'], $live_details );
				wp_parse_str( $response['response']['test'], $test_details );
				$this->settings['fkwcs_pub_key']    = ! empty( $live_details['stripe_publishable_key'] ) ? wc_clean( $live_details['stripe_publishable_key'] ) : '';
				$this->settings['fkwcs_secret_key'] = wc_clean( $live_details['access_token'] );

				$this->settings['fkwcs_test_pub_key']    = ! empty( $test_details['stripe_publishable_key'] ) ? wc_clean( $test_details['stripe_publishable_key'] ) : '';
				$this->settings['fkwcs_test_secret_key'] = wc_clean( $test_details['access_token'] );
				$this->settings['fkwcs_account_id']      = wc_clean( $live_details['stripe_user_id'] );
				$this->settings['fkwcs_con_status']      = 'success';
				$redirect_url                            = add_query_arg( 'path', '/gateways', $redirect_url );
			} else {
				$this->settings['fkwcs_pub_key']         = '';
				$this->settings['fkwcs_secret_key']      = '';
				$this->settings['fkwcs_test_pub_key']    = '';
				$this->settings['fkwcs_test_secret_key'] = '';
				$this->settings['fkwcs_account_id']      = '';
				$this->settings['fkwcs_con_status']      = 'failed';
				$redirect_url                            = add_query_arg( 'path', '/failed', $redirect_url );
			}

			$this->settings['fkwcs_auto_connect'] = 'yes';
			$this->settings['fkwcs_debug_log']    = 'yes';

			$client = Helper::get_new_client( $this->settings['fkwcs_secret_key'] );
			$args   = $client->accounts( 'retrieve', array( $this->settings['fkwcs_account_id'] ) );

			if ( ! empty( $args['success'] ) ) {
				$account = $args['data'];
				$this->settings['fkwcs_stripe_statement_descriptor_prefix'] = $account->settings->card_payments->statement_descriptor_prefix;
				$this->settings['fkwcs_stripe_statement_descriptor_full']   = $account->settings->payments->statement_descriptor;
				$stripe_account_settings                                    = get_option( 'fkwcs_stripe_account_settings', array() );
				if ( empty( $stripe_account_settings ) ) {
					$stripe_account_settings = array(
						'country'          => $account->country,
						'default_currency' => $account->default_currency,
					);
					update_option( 'fkwcs_stripe_account_settings', $stripe_account_settings );
				}
			}
			$this->update_options( $this->settings );

			if ( ! empty( $response['wp_hash'] ) ) {
				update_option( 'fkwcs_wp_hash', sanitize_text_field( wp_unslash( $response['wp_hash'] ) ), 'no' );
			}
			do_action( 'fkwcs_after_connect_with_stripe', $this->settings['fkwcs_con_status'] );
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Verify the CSRF state token from the Stripe Connect OAuth callback.
	 *
	 * @return bool
	 */
	private function verify_connect_state() {
		$state_token = isset( $_GET['fkwcs_state'] ) ? sanitize_text_field( wp_unslash( $_GET['fkwcs_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $state_token ) ) {
			return false;
		}
		$transient_key = 'fkwcs_connect_state_' . get_current_user_id();
		$stored_token  = get_transient( $transient_key );
		if ( empty( $stored_token ) || ! hash_equals( $stored_token, $state_token ) ) {
			return false;
		}
		delete_transient( $transient_key );

		return true;
	}

	/**
	 * Check if stripe is connected
	 *
	 * @return bool
	 */
	public function is_stripe_connected() {
		if ( 'success' === $this->get_api_option( 'fkwcs_con_status' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Admin notice for missing PHP cURL extension
	 *
	 * @return void
	 */
	public function curl_missing_notice() {
		echo wp_kses_post( '<div class="notice notice-error"><p>' . __( 'FunnelKit Stripe Payment Gateway requires the PHP cURL extension to be installed and enabled. Please contact your hosting provider or server administrator to enable the cURL extension for PHP.', 'funnelkit-stripe-woo-payment-gateway' ) . '</p></div>' );
	}

	/**
	 * Filter gateway settings
	 *
	 * @param $array
	 *
	 * @return array|mixed
	 */
	public function filter_settings_fields( $array = array() ) {
		if ( 'success' === $this->get_api_option( 'fkwcs_con_status' ) ) {
			unset( $array['test_conn_button'] );
		}

		return $array;
	}

	/**
	 * Update options
	 *
	 * @param $options
	 *
	 * @return void
	 */
	public function update_options( $options ) {
		if ( ! is_array( $options ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( $options as $key => $value ) {
			update_option( $key, $value );
		}
	}

	/**
	 * Display stripe connected or disconnect UI
	 *
	 * @param $value
	 *
	 * @return void
	 */
	public function account_id( $value ) {
		if ( false === $this->is_stripe_connected() ) {
			return;
		}

		$option_value = $this->get_api_option( 'fkwcs_account_id' );

		/**
		 * Action before connected with stripe.
		 */
		do_action( 'fkwcs_before_connected_with_stripe' );

		?>
		<tr valign="top">
			<th scope="row">
				<label><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="form-wc form-wc-<?php echo esc_attr( $value['class'] ); ?>">
				<fieldset>
					<div class="account_status" data-account-connect="<?php echo 'no' === $this->get_api_option( 'fkwcs_auto_connect' ) ? 'no' : 'yes'; ?>">
						<div>
							<?php
							if ( 'no' === $this->get_api_option( 'fkwcs_auto_connect' ) ) {
								/* translators: %1$1s %2$2s %3$3s: HTML Markup */
								esc_html_e( 'Your manually managed API keys are valid.', 'funnelkit-stripe-woo-payment-gateway' );
								echo '<span style="color:green;font-weight:bold;font-size:20px;margin-left:5px;">&#10004;</span>';
								echo '<div class="notice inline notice-success">';
								echo '<p>' . esc_html__( 'It is highly recommended to Connect with Stripe for easier setup and improved security.', 'funnelkit-stripe-woo-payment-gateway' ) . '</p>';
								echo wp_kses_post( '<a class="fkwcs_connect_btn" href="' . $this->get_connect_url() . '"><span>' . __( 'Connect with Stripe', 'funnelkit-stripe-woo-payment-gateway' ) . '</span></a>' );
								echo '</div>';
							} else {
								/* translators: $1s Account name, $2s html markup, $3s account id, $4s html markup */
								echo wp_kses_post( sprintf( __( 'Account %2$1s is connected.', 'funnelkit-stripe-woo-payment-gateway' ), '<strong>', $option_value, '</strong>' ) );
								echo '<span style="color:green;font-weight:bold;font-size:20px;margin-left:5px;">&#10004;</span>';
								echo '';
							}
							?>
						</div>
						<div>
							<?php
							echo '<a href="javascript:void(0);" id="fkwcs_disconnect_acc">';
							esc_html_e( 'Disconnect &amp; connect other account?', 'funnelkit-stripe-woo-payment-gateway' );
							echo '</a>';

							if ( 'no' === $this->get_api_option( 'fkwcs_auto_connect' ) ) {
								if ( ! isset( $_GET['connect'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
									echo ' | ' . '<a href="' . admin_url() . 'admin.php?page=wc-settings&tab=fkwcs_api_settings&connect=manually" class="fkwcs_connect_mn_btn">' . __( 'Manage API keys manually', 'funnelkit-stripe-woo-payment-gateway' ) . '</a>'; //phpcs:ignore WordPress.Security.EscapeOutput
								}
							}
							?>
						</div>
					</div>
				</fieldset>
			</td>
		</tr>
		<?php

		/**
		 * Action after connected with stripe.
		 */
		do_action( 'fkwcs_after_connected_with_stripe' );
	}


	/**
	 * Check if keys are set
	 *
	 * @return bool
	 */
	public function if_keys_set() {
		$settings = $this->get_gateway_keys();

		if ( ( 'live' === $settings['test_mode'] && empty( $settings['live_pub_key'] ) && empty( $settings['live_secret_key'] ) ) || ( 'test' === $settings['test_mode'] && empty( $settings['test_pub_key'] ) && empty( $settings['test_secret_key'] ) ) || ( empty( $settings['test_mode'] ) && empty( $settings['live_secret_key'] ) && empty( $settings['test_secret_key'] ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Method to check and attempt domain verification process
	 *
	 * @return bool
	 */
	public function maybe_verify_domain( $force = false ) {
		/**
		 * Check if we have already setup for the current domain
		 */
		if ( $force === false && ! empty( $this->verified_domain ) && $this->domain === $this->verified_domain && $this->domain_is_verfied ) {
			return true;
		}

		/**
		 * Check if we have SSL installed
		 */
		if ( ! is_ssl() ) {
			Helper::log( 'Apple Pay domain verification failed due to SSL certificate is not installed.' );

			return false;
		}

		if ( $force === false && $this->already_verified_domain() ) {
			update_option( 'fkwcs_apple_pay_verified_domain', $this->domain );
			update_option( 'fkwcs_apple_pay_domain_is_verified', true );
			Helper::log( 'Apple Pay domain verification already setup correctly.' );

			return true;
		}

		$response = $this->try_to_move_file_to_apple_dir();
		if ( ! $response['success'] ) {
			Helper::log( 'Unable to copy apple pay domain verification file, reason:' . $response['message'] );

		}

		return $this->verify_domain_for_apple_pay();
	}

	/**
	 * Automatic verification for apple pay using stripe api
	 *
	 * @return bool
	 */
	public function verify_domain_for_apple_pay() {
		if ( empty( $this->get_api_option( 'fkwcs_secret_key' ) ) ) {
			return false;
		}

		$client = Helper::get_new_client( $this->options_keys['fkwcs_secret_key'] );

		$response = $client->apple_pay_domains(
			'create',
			array(
				array(
					'domain_name' => $this->domain,
				),
			)
		);

		$verification_response = $response['success'] ? $response['data'] : false;
		if ( $verification_response ) {
			update_option( 'fkwcs_apple_pay_verified_domain', $this->domain );
			update_option( 'fkwcs_apple_pay_domain_is_verified', true );

			return true;
		}

		delete_option( 'fkwcs_apple_pay_domain_is_verified' );
		delete_option( 'fkwcs_apple_pay_verified_domain' );
		Helper::log( 'Unable to verify apple domain, reason:' . $response['message'] );

		return false;
	}

	/**
	 * Checks if domain is verified for Apple Pay
	 *
	 * @return bool
	 */
	public function already_verified_domain() {
		$well_known_dir = untrailingslashit( ABSPATH ) . '/' . self::APPLEPAY_DIR;
		$full_path      = $well_known_dir . '/' . self::APPLEPAY_FILE;
		if ( ! file_exists( $full_path ) ) {
			return false;
		}

		if ( empty( $this->get_api_option( 'fkwcs_secret_key' ) ) ) {
			return false;
		}
		$client             = Helper::get_new_client( $this->options_keys['fkwcs_secret_key'] );
		$found_domain       = false;
		$registered_domains = $client->apple_pay_domains( 'all', array() );

		if ( ! is_wp_error( $registered_domains ) && $registered_domains ) {
			// loop through domains and delete if they match domain of site.
			foreach ( $registered_domains['data']->data as $domain ) {
				if ( $domain->domain_name === $this->domain ) {
					$found_domain = true;
					break;
				}
			}
		}

		return $found_domain;
	}

	/**
	 * Try copying domain verification file in the root
	 *
	 * @return array|true[]
	 */
	public function try_to_move_file_to_apple_dir() {
		$well_known_dir = untrailingslashit( ABSPATH ) . '/' . self::APPLEPAY_DIR;
		$full_path      = $well_known_dir . '/' . self::APPLEPAY_FILE;

		if ( ! file_exists( $well_known_dir ) ) {
			if ( ! @mkdir( $well_known_dir, 0755 ) ) { // @codingStandardsIgnoreLine
				return array(
					'success' => false,
					/* translators: 1 - 4 html entities */
					'message' => sprintf( __( 'Unable to create domain association folder to domain root due to file permissions. Please create %1$1s.well-known%2$2s directory under domain root and place %3$3sdomain verification file%4$4s under it and refresh.', 'funnelkit-stripe-woo-payment-gateway' ), '<code>', '</code>', '<a href="https://stripe.com/files/apple-pay/apple-developer-merchantid-domain-association" target="_blank">', '</a>' ),
				);
			}
		}

		if ( ! @copy( FKWCS_DIR . '/compatibilities/' . self::APPLEPAY_FILE, $full_path ) ) { // @codingStandardsIgnoreLine
			return array(
				'success' => false,
				'message' => __( 'Unable to copy domain association file to domain root.', 'funnelkit-stripe-woo-payment-gateway' ),
			);
		}

		return array(
			'success' => true,
		);
	}

	/**
	 * Admin notices for multiple conditions
	 *
	 * @return void
	 */
	public function init_notices() {
		if ( false === current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Show notice if PHP cURL extension is missing (use function_exists instead of extension_loaded).
		if ( ! function_exists( 'curl_version' ) ) {
			add_action( 'admin_notices', array( $this, 'curl_missing_notice' ) );
		}

		// If no SSL bail.
		if ( $this->get_api_key() !== '' && 'live' === $this->get_gateway_keys( 'test_mode' ) && ! is_ssl() ) {
			add_action( 'admin_notices', array( $this, 'ssl_not_connected' ) );
		}

		// Add notice if missing webhook secret key.
		if ( isset( $_GET['page'] ) && 'wc-settings' === $_GET['page'] && 'live' === $this->get_gateway_keys( 'test_mode' ) && $this->is_stripe_connected() && ! $this->get_gateway_keys( 'webhook_secret' ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_action( 'admin_notices', array( $this, 'webhook_missing_notice' ) );
		}

		// Payments are completing but no webhook has been received since — webhook config likely broken.
		if ( $this->should_show_webhook_health_notice() ) {
			add_action( 'admin_notices', array( $this, 'webhook_health_notice' ) );
		}

		// PHP version check.
		if ( version_compare( PHP_VERSION, '7.0', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'stripe_php_version_notice' ) );
		}

		$gateway_settings  = Helper::get_gateway_settings();
		$apple_pay_enabled = ( 'yes' === ( $gateway_settings['express_checkout_enabled'] ?? '' ) ) || ( 'yes' === ( Helper::get_gateway_settings( 'fkwcs_stripe_apple_pay' )['enabled'] ?? '' ) );
		if ( $this->is_page( 'wc-settings', 'checkout', 'fkwcs_express_checkout' ) && ! empty( $this->get_api_option( 'fkwcs_secret_key' ) ) && $apple_pay_enabled && is_ssl() && null === get_option( 'fkwcs_apple_pay_domain_is_verified', null ) && ! $this->is_notice_dismissed( 'fkwcs_apple_pay' ) ) {
			add_action( 'admin_notices', array( $this, 'apple_pay_verification_failed' ) );
		}

		if ( $this->is_express_payments_page() && ! empty( $this->get_api_option( 'fkwcs_secret_key' ) ) && ( 'yes' === $gateway_settings['express_checkout_enabled'] || 'yes' === WC()->payment_gateways()->payment_gateways()['fkwcs_stripe_apple_pay']->enabled || 'yes' === WC()->payment_gateways()->payment_gateways()['fkwcs_stripe_google_pay']->enabled ) ) {
			add_action( 'admin_notices', array( $this, 'custom_stripe_checkout_country_notice' ) );
		}
		global $fk_block_notice;
		if ( empty( $fk_block_notice ) && $this->is_stripe_connected() && class_exists( '\Automattic\WooCommerce\Blocks\BlockTypes\ClassicShortcode' ) && $this->_should_display_block_incompatible_notice() && ! $this->is_notice_dismissed( 'wc_block_incompat' ) ) {
			$fk_block_notice = true;
			add_action( 'admin_notices', array( $this, 'wc_notif_for_block_usage' ) );

		}

		if ( $this->get_api_key() !== '' && 'test' === $this->get_gateway_keys( 'test_mode' ) && ! $this->is_notice_dismissed( 'test_mode' ) ) {
			add_action( 'admin_notices', array( $this, 'test_mode_notice' ) );
		}
	}

	private function _should_display_block_incompatible_notice() {
		$wc_cart_page     = get_post( wc_get_page_id( 'cart' ) );
		$wc_checkout_page = get_post( wc_get_page_id( 'checkout' ) );

		$has_block = has_block( 'woocommerce/checkout', $wc_checkout_page ) || has_block( 'woocommerce/cart', $wc_cart_page );

		/**
		 * Let a checkout-overriding solution suppress this incompatibility notice. When another
		 * plugin (CheckoutWC, or any future checkout replacement) renders in place of the WooCommerce
		 * block cart/checkout, the block experience is never actually used, so the warning is a false
		 * positive. Compatibility handlers hook this filter and return true to hide the notice.
		 *
		 * @param bool $overridden Default false.
		 */
		return $has_block && true !== apply_filters( 'fkwcs_block_cart_checkout_overridden', false );
	}

	/**
	 * Checks if correct admin page
	 *
	 * @param $page
	 * @param $tab
	 * @param $section
	 *
	 * @return bool
	 */
	public function is_page( $page = 'wc-settings', $tab = 'fkwcs_api_settings', $section = '' ) {
		if ( ! is_admin() ) {
			return false;
		}

		if ( isset( $_GET['page'] ) && $page === $_GET['page'] && isset( $_GET['tab'] ) && $tab === $_GET['tab'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $section ) ) {
				if ( isset( $_GET['section'] ) && $section === $_GET['section'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
					return true;
				} else {
					return false;
				}
			} else {
				return true;
			}
		} else {
			return false;
		}
	}

	/**
	 * SSL not found notice text
	 *
	 * @return void
	 */
	public function ssl_not_connected() {
		echo wp_kses_post( '<div class="notice notice-error"><p>' . __( 'No SSL was detected, Stripe live mode requires SSL.', 'funnelkit-stripe-woo-payment-gateway' ) . '</p></div>' );
	}

	/**
	 * Daily passive gap check for the webhook-health notice. Called from
	 * Stripe::run_webhook_health_check() in plugin.php, the Action Scheduler callback that
	 * only fires inside Action Scheduler's own execution of the daily queued action — never
	 * on a regular front-end/admin request.
	 *
	 * Payments and webhooks normally move together (a webhook lands seconds after a charge),
	 * so when the last live webhook success is stale we run one cheap existence query: has
	 * any fkwcs_* order actually been paid since then? That's the exact failure signature of
	 * a broken webhook (CDN/firewall blocking it, or it being deleted from the Stripe
	 * dashboard) — both cases look identical from here: payments succeed, no webhook lands. A
	 * quiet store never raises the alert.
	 *
	 * @return void
	 */
	public function run_webhook_health_check() {
		if ( '' === (string) get_option( 'fkwcs_live_webhook_secret', '' ) ) {
			// Not configured at all — covered separately by webhook_missing_notice.
			delete_option( self::FKWCS_LIVE_WEBHOOK_HEALTH_ALERT );
			return;
		}

		$threshold    = (int) apply_filters( 'fkwcs_webhook_health_notice_threshold', DAY_IN_SECONDS );
		$last_success = (int) get_option( Webhook::FKWCS_LIVE_LAST_SUCCESS_AT, 0 );
		$began_at     = (int) get_option( Webhook::FKWCS_LIVE_BEGAN_AT, 0 );
		// A webhook that's never received anything yet still shouldn't be judged against orders
		// that predate it — began_at marks when we started actually expecting a webhook call.
		$reference = max( $last_success, $began_at );

		if ( $reference && ( time() - $reference ) <= $threshold ) {
			delete_option( self::FKWCS_LIVE_WEBHOOK_HEALTH_ALERT );
			return;
		}

		$order_id = $this->find_recent_successful_payment( time() - $threshold );

		if ( $order_id ) {
			update_option( self::FKWCS_LIVE_WEBHOOK_HEALTH_ALERT, $order_id, 'no' );
		} else {
			delete_option( self::FKWCS_LIVE_WEBHOOK_HEALTH_ALERT );
		}
	}

	/**
	 * Finds one order paid through any of our gateways since $since, proving live payments
	 * are flowing even though the live webhook has gone quiet. HPOS-aware, mirroring the
	 * dual-branch lookup pattern used by Webhook::get_order_id_from_intent_query().
	 *
	 * Filtered to `_fkwcs_payment_mode = 'live'` (stamped from Stripe's own `livemode` flag
	 * at intent-creation time — see Abstract_Payment_Gateway::save_intent_to_order()) so a
	 * store sitting in test mode, or an admin test order on a "Test Mode (For administrators)"
	 * store, can never be mistaken for live traffic and trip the live webhook's alert.
	 *
	 * @param int $since Unix timestamp; only orders created at or after this time count.
	 *
	 * @return int Matching order ID, or 0 when none found.
	 */
	private function find_recent_successful_payment( $since ) {
		global $wpdb;

		$paid_statuses       = array_map(
			static function ( $status ) {
				return 'wc-' . $status;
			},
			wc_get_is_paid_statuses()
		);
		$status_placeholders = implode( ',', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$payment_method_like = $wpdb->esc_like( 'fkwcs_' ) . '%';
		$since_gmt           = gmdate( 'Y-m-d H:i:s', $since );
		$args                = array_merge( array( 'shop_order' ), $paid_statuses, array( $payment_method_like, $since_gmt ) );

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "SELECT o.id FROM {$wpdb->prefix}wc_orders AS o INNER JOIN {$wpdb->prefix}wc_orders_meta AS om ON o.id = om.order_id AND om.meta_key = '_fkwcs_payment_mode' AND om.meta_value = 'live' WHERE o.type = %s AND o.status IN ($status_placeholders) AND o.payment_method LIKE %s AND o.date_created_gmt >= %s LIMIT 1";
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "SELECT p.ID FROM $wpdb->posts AS p INNER JOIN $wpdb->postmeta AS pm ON p.ID = pm.post_id AND pm.meta_key = '_payment_method' INNER JOIN $wpdb->postmeta AS mode_pm ON p.ID = mode_pm.post_id AND mode_pm.meta_key = '_fkwcs_payment_mode' AND mode_pm.meta_value = 'live' WHERE p.post_type = %s AND p.post_status IN ($status_placeholders) AND pm.meta_value LIKE %s AND p.post_date_gmt >= %s LIMIT 1";
		}

		$order_id = $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return (int) $order_id;
	}

	/**
	 * Whether the webhook-health notice should show. run_webhook_health_check(), scheduled
	 * from this class's constructor, does the actual detection work off the checkout path:
	 * it compares the last live webhook success against a threshold and, when stale, runs a
	 * single existence query for a recent successful fkwcs_* order. This method only reads
	 * the resulting flag, so it costs one get_option() on admin pages — no cron logic and no
	 * order queries here.
	 *
	 * @return bool
	 */
	private function should_show_webhook_health_notice() {
		$alert_order_id = (int) get_option( self::FKWCS_LIVE_WEBHOOK_HEALTH_ALERT, 0 );
		if ( ! $alert_order_id ) {
			return false;
		}

		// Dismissals are keyed to the alerting order: a NEW qualifying order re-arms the
		// notice even after it was dismissed for the previous one.
		return ! $this->is_notice_dismissed( 'webhook_health_' . $alert_order_id );
	}

	/**
	 * Notice: live payments are processing but webhooks have stopped arriving.
	 *
	 * @return void
	 */
	public function webhook_health_notice() {
		$alert_order_id = (int) get_option( self::FKWCS_LIVE_WEBHOOK_HEALTH_ALERT, 0 );
		if ( ! $alert_order_id ) {
			return;
		}
		$last_webhook = (int) get_option( Webhook::FKWCS_LIVE_LAST_SUCCESS_AT, 0 );
		$began_at     = (int) get_option( Webhook::FKWCS_LIVE_BEGAN_AT, 0 );

		// "Since" the last received webhook, falling back to the webhook creation time when none was ever received.
		$since_ts = $last_webhook ? $last_webhook : $began_at;
		if ( $since_ts ) {
			$message = sprintf(
				/* translators: 1: Opening strong tag, 2: Closing strong tag, 3: date/time of last received webhook */
				esc_html__( '%1$sStripe Payment Gateway for WooCommerce%2$s: Your store is not receiving payment related webhooks since %3$s. While the payments are working fine, we strongly recommend checking webhook configuration to sync payment statuses with your store more accurately.', 'funnelkit-stripe-woo-payment-gateway' ),
				'<strong>',
				'</strong>',
				wp_date( wc_date_format() . ' ' . wc_time_format(), $since_ts )
			);
		} else {
			$message = sprintf(
				/* translators: 1: Opening strong tag, 2: Closing strong tag */
				esc_html__( '%1$sStripe Payment Gateway for WooCommerce%2$s: Your store has not received any payment related webhooks, while the payments are working fine. Check your webhook configuration to sync payment statuses with your store more accurately.', 'funnelkit-stripe-woo-payment-gateway' ),
				'<strong>',
				'</strong>'
			);
		}

		$current_admin_url = rawurlencode( basename( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$dismiss_url       = admin_url( 'admin-ajax.php?action=fkwcs_dismiss_notice&notice_identifier=webhook_health_' . $alert_order_id . '&_security=' . wp_create_nonce( 'fkwcs_admin_request' ) . '&redirect=' . $current_admin_url );

		printf(
			'<div class="notice notice-warning"><p>%1$s</p><p><a href="%2$s" class="button button-primary">%3$s</a>&nbsp; <a href="%4$s">%5$s</a></p></div>',
			$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=fkwcs_api_settings' ) ),
			esc_html__( 'Check webhook settings', 'funnelkit-stripe-woo-payment-gateway' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'funnelkit-stripe-woo-payment-gateway' )
		);
	}

	/**
	 * Webhook missing notice text
	 *
	 * @return void
	 */
	public function webhook_missing_notice() {
		/* translators: %1$s Webhook secret page link, %2$s Webhook guide page link  */
		echo wp_kses_post( '<div class="notice notice-error"><p>' . sprintf( __( 'Stripe requires using the %1$swebhook%2$s. %3$sWebhook Guide%4$s ', 'funnelkit-stripe-woo-payment-gateway' ), '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=fkwcs_api_settings' ) . '">', '</a>', '<a href="https://funnelkit.com/docs/stripe-gateway-for-woocommerce/webhooks/" target="_blank">', '</a>' ) . '</p></div>' );
	}


	/**
	 * Apple Pay verification failed notice text
	 *
	 * @return void
	 */
	public function apple_pay_verification_failed() {
		echo wp_kses_post(
			'<div class="notice fkwcs_dismiss_notice_wrap_fkwcs_apple_pay notice-error"><p>' . sprintf(
				/* translators: 1: File path, 2: Support URL */
				__(
					'Unable to verify domain. The path <b>%1$s</b> does not have write permissions. <br/> <strong>Solution:</strong> To verify domain, please contact your host to provide write permission and re-verify again.  Alternatively, you can also manually verify the domain by following this guide. For further help, <a target="_blank" href="%2$s"> contact support.</a>
<br/>
<br/>
<a href="javascript:void(0)" class="button button-primary fkwcs_apple_pay_domain_verification" > I have given file writing permission, re-verify </a>  <a href="javascript:void(0)" class="button fkwcs_dismiss_notice" data-notice="fkwcs_apple_pay"> I will verify manually </a> <a href="javascript:void(0)" class="fkwcs_dismiss_notice" data-notice="fkwcs_apple_pay"> Dismiss </a> ',
					'funnelkit-stripe-woo-payment-gateway'
				),
				ABSPATH,
				esc_url( 'https://funnelkit.com/docs/stripe-gateway-for-woocommerce/troubleshooting/manual-domain-registration-for-apple-pay/' )
			) . '</p></div>'
		);
	}

	/**
	 * PHP min version notice text
	 *
	 * @return void
	 */
	function stripe_php_version_notice() {
		$message = __( 'Stripe Payment Gateway for WooCommerce requires PHP version 7.0+. Please reach out to your host to upgrade the PHP version.', 'funnelkit-stripe-woo-payment-gateway' );
		echo '<div class="notice"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * PHP min version notice text
	 *
	 * @return void
	 */
	function test_mode_notice() {
		$message           = sprintf( /* translators: 1: Opening strong tag, 2: Closing strong tag */ esc_html__( '%1$sStripe Payment Gateway for WooCommerce%2$s is in Test mode, please ensure it is switched to Live mode after testing.', 'funnelkit-stripe-woo-payment-gateway' ), '<strong>', '</strong>' );
		$current_admin_url = rawurlencode( basename( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$dismiss_url       = admin_url( 'admin-ajax.php?action=fkwcs_dismiss_notice&notice_identifier=test_mode&_security=' . wp_create_nonce( 'fkwcs_admin_request' ) . '&redirect=' . $current_admin_url );

		printf( '<div class="notice notice-error"><p>%1$s <a href="%2$s"><button class="button">%3$s</button></a>&nbsp; <a href="%4$s">%5$s</a></p></div>', $message, esc_url( admin_url( 'admin.php?page=wc-settings&tab=fkwcs_api_settings' ) ), esc_html__( 'Switch to Live mode', 'funnelkit-stripe-woo-payment-gateway' ), $dismiss_url, esc_html__( 'I\'ll do it later', 'funnelkit-stripe-woo-payment-gateway' ) ); //phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Display webhook URL UI
	 *
	 * @param $value
	 *
	 * @return void
	 */
	public function webhook_url( $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return;
		}
		$data         = $value;
		$tooltip_html = $data['desc_tip'];
		if ( $tooltip_html && 'checkbox' === $value['type'] ) {
			$tooltip_html = '<p class="description">' . $tooltip_html . '</p>';
		} elseif ( $tooltip_html ) {
			$tooltip_html = wc_help_tip( $tooltip_html );
		}
		?>
		<tr valign="top">
			<th scope="row">
				<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?><?php echo wp_kses_post( $tooltip_html ); ?></label>
			</th>
			<td class="form-wc form-wc-<?php echo esc_attr( $value['class'] ); ?>">
				<fieldset>
					<strong><?php echo esc_url( Helper::get_webhook_url() ); //phpcs:ignore WordPress.Security.EscapeOutput ?></strong>
				</fieldset>
				<p class="description">
					<?php echo wp_kses_post( $value['desc'] ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Displays stripe test connection UI
	 *
	 * @param $value
	 *
	 * @return void
	 */
	public function wc_fkwcs_connection_test( $value ) {
		$tooltip_html = '';
		?>
		<tr valign="top">
			<th scope="row">
				<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?><?php echo wp_kses_post( $tooltip_html ); ?></label>
			</th>
			<td class="form-wc form-wc-<?php echo esc_attr( $value['class'] ); ?>">
				<fieldset>
					<a id="fkwcs_test_connection" class="button wc_fkwcs_create_webhook_button" href="javascript:void(0)">
						<?php esc_html_e( 'Test Connection', 'funnelkit-stripe-woo-payment-gateway' ); ?>
					</a>
				</fieldset>
				<p class="description">
					<?php echo wp_kses_post( $value['desc'] ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Disconnect account ajax CB
	 *
	 * @return void
	 */
	public function disconnect_account() {
		check_ajax_referer( 'fkwcs_admin_request', '_security' );
		$this->check_wc_admin();

		foreach ( array_keys( $this->options_keys ) as $key ) {
			delete_option( $key );
		}
		delete_option( 'fkwcs_stripe_account_settings' );

		wp_send_json_success( array( 'message' => __( 'Stripe keys are reset successfully.', 'funnelkit-stripe-woo-payment-gateway' ) ) );
	}

	/**
	 * Connect webhook ajax CB
	 *
	 * @return void
	 * @throws \Stripe\Exception\ApiErrorException
	 */
	public function connect_webhook() {
		check_ajax_referer( 'fkwcs_admin_request', '_security' );
		$this->check_wc_admin();

		$mode = ! empty( $_POST['mode'] ) ? sanitize_text_field( wc_clean( wp_unslash( $_POST['mode'] ) ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $mode ) ) {
			wp_send_json_error( array( 'msg' => __( 'Webhook could not be created', 'funnelkit-stripe-woo-payment-gateway' ) ) );
		}

		if ( 'live' === $mode ) {
			$secret_key = $this->get_api_option( 'fkwcs_secret_key' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} else {
			$secret_key = $this->get_api_option( 'fkwcs_test_secret_key' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		$params   = array( 'secret_key' => $secret_key );
		$response = $this->create_webhook( $params );
		if ( is_array( $response ) && isset( $response['status'] ) && $response['status'] === false ) {
			wp_send_json_error( $response );
		}

		if ( ! empty( $response['livemode'] ) ) {
			update_option( 'fkwcs_live_webhook_secret', $response['secret'] );
			update_option(
				'fkwcs_live_created_webhook',
				array(
					'secret' => $response['secret'],
					'id'     => $response['id'],
				)
			);
			update_option( 'fkwcs_live_webhook_url', Helper::get_webhook_url() );
		} else {
			update_option( 'fkwcs_test_webhook_secret', $response['secret'] );
			update_option(
				'fkwcs_test_created_webhook',
				array(
					'secret' => $response['secret'],
					'id'     => $response['id'],
				)
			);
		}
		wp_send_json_error( array( 'msg' => __( 'Webhook created Successfully', 'funnelkit-stripe-woo-payment-gateway' ) ) );
	}

	/**
	 * Apple pay domain verification ajax CB
	 *
	 * @return void
	 */
	public function apple_pay_domain_verification() {
		check_ajax_referer( 'fkwcs_admin_request', '_security' );
		$this->check_wc_admin();

		$this->domain_is_verfied = null;
		$this->verified_domain   = null;

		$response = $this->maybe_verify_domain( true );
		if ( $response ) {
			wp_send_json_success( array( 'msg' => __( 'Domain verification successful', 'funnelkit-stripe-woo-payment-gateway' ) ) );
		}

		wp_send_json_error( array( 'msg' => __( 'Domain verification failed. Please check logs for more info.', 'funnelkit-stripe-woo-payment-gateway' ) ) );
	}

	/**
	 * Dismiss notice ajax CB
	 *
	 * @return void
	 */
	/**
	 * Validate a redirect value against an allowlist of known WP admin page filenames.
	 * Prevents open-redirect abuse by rejecting arbitrary paths from $_REQUEST['redirect'].
	 *
	 * @param string|null $redirect     Raw redirect value from the request.
	 * @param string      $default_url  Safe fallback URL returned when redirect is invalid.
	 * @return string
	 */
	private function get_safe_admin_redirect_url( $redirect, $default_url ) {
		if ( empty( $redirect ) ) {
			return $default_url;
		}

		$allowed_admin_pages = array(
			'index.php',
			'admin.php',
			'post.php',
			'post-new.php',
			'edit.php',
			'upload.php',
			'plugins.php',
			'users.php',
			'options-general.php',
			'admin-post.php',
		);

		$parsed        = wp_parse_url( $redirect );
		$redirect_base = isset( $parsed['path'] ) ? basename( $parsed['path'] ) : '';

		if ( ! in_array( $redirect_base, $allowed_admin_pages, true ) ) {
			return $default_url;
		}

		return admin_url( $redirect );
	}

	public function dismiss_notice() {
		check_ajax_referer( 'fkwcs_admin_request', '_security' );
		$this->check_wc_admin();

		$notice_id = isset( $_GET['notice_identifier'] ) ? wc_clean( wp_unslash( $_GET['notice_identifier'] ) ) : '';

		if ( empty( $notice_id ) ) {
			wp_send_json_error( array() );
		}

		$user = wp_get_current_user();
		$meta = get_user_meta( $user->ID, '_fkwcs_notices', true );
		if ( ! empty( $meta ) && is_array( $meta ) ) {
			$meta[] = $notice_id;
			$meta   = array_values( array_unique( $meta ) );
		} else {
			$meta = array( $notice_id );
		}

		$result = update_user_meta( $user->ID, '_fkwcs_notices', $meta ); //phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.user_meta_update_user_meta

		$redirect = isset( $_REQUEST['redirect'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect'] ) ) : null; //phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above via check_ajax_referer
		if ( ! is_null( $redirect ) ) {
			wp_safe_redirect( $this->get_safe_admin_redirect_url( $redirect, admin_url( 'admin.php?page=bwf&path=/funnels' ) ) );
			exit;
		}

		if ( $result ) {
			wp_send_json_success();
		}

		wp_send_json_error();
	}

	/**
	 * Disconnect webhook ajax CB
	 *
	 * @return void
	 */
	public function disconnect_webhook() {
		check_ajax_referer( 'fkwcs_admin_request', '_security' );
		$this->check_wc_admin();

		try {
			foreach ( array( 'live', 'test' ) as $mode ) {

				if ( $mode === 'test' ) {
					$secret_key = $this->get_api_option( 'fkwcs_test_secret_key' );//phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				} else {
					$secret_key = $this->get_api_option( 'fkwcs_secret_key' );//phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				}

				$webhook_secret = $this->get_api_option( 'fkwcs_' . $mode . '_webhook_secret' );//phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$option_name    = 'fkwcs_' . $mode . '_created_webhook';

				$webhook_data = $this->get_api_option( $option_name );

				if ( ! is_array( $webhook_data ) || ! isset( $webhook_data['id'] ) ) {
					delete_option( $option_name );
					delete_option( 'fkwcs_' . $mode . '_webhook_secret' );
				} else {
					$params   = array(
						'secret_key'     => $secret_key,
						'webhook_secret' => $webhook_secret,
						'webhook_id'     => $webhook_data['id'],
					);
					$response = $this->delete_webhook( $params );

					if ( ! empty( $response ) && true === $response['deleted'] ) {
						delete_option( $option_name );
						delete_option( 'fkwcs_' . $mode . '_webhook_secret' );
					}
				}
			}
		} catch ( \Exception $e ) {
			if ( false !== strpos( $e->getMessage(), 'No such webhook endpoint' ) ) {
				delete_option( 'fkwcs_live_created_webhook' );
				delete_option( 'fkwcs_test_created_webhook' );
				delete_option( 'fkwcs_live_webhook_secret' );
				delete_option( 'fkwcs_test_webhook_secret' );
				delete_option( 'fkwcs_live_webhook_url' );

				Helper::log( 'StripeException delete webhook data when no webhook found : ' . $e->getMessage() );
				wp_send_json_success( array( 'msg' => __( 'Webhook Deleted successfully', 'funnelkit-stripe-woo-payment-gateway' ) ) );

			} else {
				Helper::log( 'StripeException During webhook deletion: ' . $e->getMessage() );
				wp_send_json_error( array( 'msg' => __( 'Webhook could not be deleted', 'funnelkit-stripe-woo-payment-gateway' ) ) );

			}
		}

		wp_send_json_success( array( 'msg' => __( 'Webhook Deleted successfully', 'funnelkit-stripe-woo-payment-gateway' ) ) );
	}

	/**
	 * Test stripe connection ajax CB
	 *
	 * @return void
	 */
	public function connection_test() {
		check_ajax_referer( 'fkwcs_admin_request', '_security' );
		$this->check_wc_admin();

		$results = array();
		$keys    = array();

		if ( isset( $_POST['fkwcs_test_sec_key'] ) && ! empty( trim( $_POST['fkwcs_test_sec_key'] ) ) ) {//phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$keys['test'] = sanitize_text_field( trim( $_POST['fkwcs_test_sec_key'] ) );//phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} else {
			$results['test']['mode']    = __( 'Test Mode:', 'funnelkit-stripe-woo-payment-gateway' );
			$results['test']['status']  = 'invalid';
			$results['test']['message'] = __( 'Please enter secret key to test.', 'funnelkit-stripe-woo-payment-gateway' );
		}

		if ( isset( $_POST['fkwcs_secret_key'] ) && ! empty( trim( $_POST['fkwcs_secret_key'] ) ) ) {//phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$keys['live'] = sanitize_text_field( trim( $_POST['fkwcs_secret_key'] ) );//phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} else {
			$results['live']['mode']    = __( 'Live Mode:', 'funnelkit-stripe-woo-payment-gateway' );
			$results['live']['status']  = 'invalid';
			$results['live']['message'] = __( 'Please enter secret key to live.', 'funnelkit-stripe-woo-payment-gateway' );
		}

		if ( empty( $keys ) ) {
			wp_send_json_error( array( 'message' => __( 'Error: Empty String provided for keys', 'funnelkit-stripe-woo-payment-gateway' ) ) );
		}

		foreach ( $keys as $mode => $key ) {
			$stripe = new \Stripe\StripeClient( $key );

			try {
				$response = $stripe->customers->create(
					array(
						/* translators: %1$1s mode */ 'description' => sprintf( __( 'My first %1s customer (created for API docs)', 'funnelkit-stripe-woo-payment-gateway' ), $mode ),
					)
				);
				if ( ! is_wp_error( $response ) ) {
					$results[ $mode ]['status']  = 'success';
					$results[ $mode ]['message'] = __( 'Connected to Stripe successfully', 'funnelkit-stripe-woo-payment-gateway' );
				}
			} catch ( \Stripe\Exception\CardException $e ) {
				$results[ $mode ]['status']  = 'failed';
				$results[ $mode ]['message'] = $e->getError()->message;
			} catch ( \Stripe\Exception\RateLimitException $e ) {
				// Too many requests made to the API too quickly.
				$results[ $mode ]['status']  = 'failed';
				$results[ $mode ]['message'] = $e->getError()->message;
			} catch ( \Stripe\Exception\InvalidRequestException $e ) {
				// Invalid parameters were supplied to Stripe's API.
				$results[ $mode ]['status']  = 'failed';
				$results[ $mode ]['message'] = $e->getError()->message;
			} catch ( \Stripe\Exception\AuthenticationException $e ) {
				// Authentication with Stripe's API failed.
				// (maybe you changed API keys recently).
				$results[ $mode ]['status']  = 'failed';
				$results[ $mode ]['message'] = $e->getError()->message;
			} catch ( \Stripe\Exception\ApiConnectionException $e ) {
				// Network communication with Stripe failed.
				$results[ $mode ]['status']  = 'failed';
				$results[ $mode ]['message'] = $e->getError()->message;
			} catch ( \Stripe\Exception\ApiErrorException $e ) {
				$results[ $mode ]['status']  = 'failed';
				$results[ $mode ]['message'] = $e->getError()->message;
				// Display a very generic error to the user, and maybe send.
				// yourself an email.
			} catch ( \Exception $e ) {
				// Something else happened, completely unrelated to Stripe.
				$results[ $mode ]['status']  = 'failed';
				$results[ $mode ]['message'] = $e->getError()->message;
			}

			switch ( $mode ) {
				case 'test':
					$results[ $mode ]['mode'] = __( 'Test Mode:', 'funnelkit-stripe-woo-payment-gateway' );
					break;
				case 'live':
					$results[ $mode ]['mode'] = __( 'Live Mode:', 'funnelkit-stripe-woo-payment-gateway' );
					break;
				default:
					break;
			}
		}

		wp_send_json_success( array( 'data' => apply_filters( 'fkwcs_connection_test_results', $results ) ) );
	}


	/**
	 * Create webhook ajax CB
	 *
	 * @param $params
	 *
	 * @return array|\Stripe\WebhookEndpoint
	 * @throws \Stripe\Exception\ApiErrorException
	 */
	public function create_webhook( $params ) {
		$client_secret = $params['secret_key'];
		if ( empty( $client_secret ) ) {
			return array();
		}

		$response = array();
		$client   = new \Stripe\StripeClient(
			array(
				'api_key'        => $client_secret,
				'stripe_version' => '2022-08-01',

			)
		);

		/**
		 * Loop over the existing webhooks and if match the existing one then delete and then create a new
		 */
		$webhooks = $client->webhookEndpoints->all( array( 'limit' => 100 ) );
		if ( ! is_wp_error( $webhooks ) ) {
			// validate that the webhook hasn't already been created.
			foreach ( $webhooks->data as $webhook ) {
				/**
				 * @var \Stripe\WebhookEndpoint $webhook
				 */
				if ( $webhook->url === Helper::get_webhook_url() ) {
					$client->webhookEndpoints->delete( $webhook->id );
				}
			}
		}

		$enabled_events = Helper::get_enabled_webhook_events();

		try {
			$response = $client->webhookEndpoints->create(
				array(
					'url'            => Helper::get_webhook_url(),
					'enabled_events' => $enabled_events,
					'api_version'    => '2020-03-02',
				)
			);
		} catch ( \Exception $e ) {
			$response['status'] = false;
			$response['msg']    = __( 'An Error occurred while creating webhook. Error: ', 'funnelkit-stripe-woo-payment-gateway' ) . $e->getMessage();
			// Log Stripe Error Message.
			Helper::log( 'StripeException: ' . $e->getMessage() );

			return $response;
		}

		return $response;
	}

	/**
	 * Delete webhook ajax CB
	 *
	 * @param $params
	 *
	 * @return array|\Stripe\WebhookEndpoint
	 */
	public function delete_webhook( $params ) {
		$client_secret = $params['secret_key'];
		if ( empty( $client_secret ) ) {
			return array();
		}

		$client   = new \Stripe\StripeClient( $client_secret );
		$response = $client->webhookEndpoints->delete( $params['webhook_id'] );

		return $response;
	}

	/**
	 * Check if manually connected
	 *
	 * @return false|string
	 */
	public function is_manually_connected() {
		if ( isset( $_GET['connect'] ) && 'manually' === $_GET['connect'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '1';
		}

		if ( $this->get_api_option( 'fkwcs_auto_connect' ) === 'no' ) {
			return '1';
		}

		return false;
	}

	/**
	 * Checks whether the given notice is dismissed prev by the user or not
	 *
	 * @param string $notice_id Unique identifier of the notice
	 *
	 * @return bool true when dismissed false otherwise
	 */
	public function is_notice_dismissed( $notice_id ) {
		$user = wp_get_current_user();
		$meta = get_user_meta( $user->ID, '_fkwcs_notices', true );

		if ( ! is_array( $meta ) ) {
			return false;
		}
		if ( ! in_array( $notice_id, $meta, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks whether or not the current logged in user can manage WooCommerce
	 *
	 * @return true on success, wp_die() otherwise
	 */
	public function check_wc_admin() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		return true;
	}

	/**
	 *
	 * @param string             $post_type
	 * @param \WP_Post|\WC_Order $post
	 */
	public function add_meta_boxes( $post_type, $post ) {
		if ( ( Helper::is_hpos_enabled() && $post instanceof \WC_Order ) || $post_type === 'shop_order' ) {

			$order          = $post instanceof \WC_Order ? $post : wc_get_order( $post->ID );
			$payment_method = $order->get_payment_method();
			if ( $payment_method ) {
				$gateways = WC()->payment_gateways()->payment_gateways();
				if ( isset( $gateways[ $payment_method ] ) ) {
					$gateway = WC()->payment_gateways()->payment_gateways()[ $payment_method ];
					if ( $gateway instanceof Abstract_Payment_Gateway ) {

						add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_order_fee' ) );
						add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_order_payout' ), 20 );
						add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'charge_data_view' ) );
					}
				}
			}
		}
	}

	/**
	 * Ajax CB for 'wp_ajax_get_transaction_data_admin'
	 * Prepare the charge data and prepare html for the charge data popup
	 *
	 * @return void
	 */
	public function get_transaction_data() {
		check_ajax_referer( 'get_transaction_data_admin' );
		$this->check_wc_admin();

		$order_id = isset( $_GET['order_id'] ) ? sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) : 0;
		$order    = wc_get_order( absint( $order_id ) );

		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( array( 'message' => 'Unable to find order' ) );
		}

		try {
			$get_payment_mode = Helper::get_meta( $order, '_fkwcs_payment_mode' );
			$mode             = ! empty( $get_payment_mode ) ? $get_payment_mode : get_option( 'fkwcs_mode', 'test' );

			if ( 'live' === $mode ) {
				$client_secret = get_option( 'fkwcs_secret_key' );
			} else {
				$client_secret = get_option( 'fkwcs_test_secret_key' );
			}

			if ( empty( $client_secret ) ) {
				return;
			}

			$client   = new Client( $client_secret );
			$response = $client->charges( 'retrieve', array( $order->get_transaction_id() ) );

			/**
			 * If call failed
			 */
			if ( false === $response['success'] ) {
				wp_send_json_error( array( 'message' => 'Unable to fetch transaction details' ) );
			}

			$charge = $response['data'];

			ob_start();
			include __DIR__ . '/parts/html-stripe-charge-info-modal.php';
			$html = ob_get_clean();

			wp_send_json_success(
				array(
					'order_id'     => $order->get_id(),
					'order_number' => $order->get_order_number(),
					'order_total'  => $order->get_total(),
					'charge'       => $charge->jsonSerialize(),
					'html'         => $html,
				)
			);

		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX CB for wp_ajax_capture_charge
	 * Perform a capture charge API call and process WC order if successful
	 *
	 * @return void
	 */
	public function capture_charge() {
		check_ajax_referer( 'capture_charge' );
		$this->check_wc_admin();

		$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = wc_get_order( absint( $order_id ) );
		$amount   = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : 0;

		if ( ! is_numeric( $amount ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid amount', 'funnelkit-stripe-woo-payment-gateway' ) ) );

		}
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( array( 'message' => 'Unable to find order' ) );
		}

		try {
			/**
			 *
			 * @var CreditCard $gateway
			 */
			$gateway       = WC()->payment_gateways()->payment_gateways()[ $order->get_payment_method() ];
			$stripe_api    = $gateway->get_client();
			$intent_secret = Helper::get_meta( $order, '_fkwcs_intent_id' );

			$result = $stripe_api->payment_intents( 'capture', array( $intent_secret['id'], array( 'amount_to_capture' => Helper::get_stripe_amount( $amount ) ) ) );
			if ( false === $result['success'] ) {
				wp_send_json_error( array( 'message' => 'Unable to capture charge.' ) );
			}

			if ( class_exists( 'WFOCU_Core' ) ) {
				remove_action( 'woocommerce_pre_payment_complete', array( WFOCU_Core()->public, 'maybe_setup_upsell' ), 99 );

			}

			$gateway->process_final_order( end( $result['data']->charges->data ), $order_id );

			wp_send_json_success( array() );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Auto-capture an authorized fkwcs_* order when the merchant moves it into a paid status.
	 *
	 * @param int       $order_id Order ID.
	 * @param string    $from     Old status. //phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	 * @param string    $to       New status.
	 * @param \WC_Order $order    Order object.
	 *
	 * @return void
	 */
	public function maybe_capture_charge_on_status( $order_id, $from = '', $to = '', $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$new_status = ! empty( $to ) ? $to : $order->get_status();
		if ( ! in_array( $new_status, wc_get_is_paid_statuses(), true ) ) {
			return;
		}

		$payment_method = $order->get_payment_method();
		if ( 0 !== strpos( $payment_method, 'fkwcs_' ) ) {
			return;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways[ $payment_method ] ) ) {
			return;
		}
		$gateway = $gateways[ $payment_method ];

		if ( ! isset( $gateway->capture_method ) || 'manual' !== $gateway->capture_method ) {
			return;
		}

		$intent_secret = Helper::get_meta( $order, '_fkwcs_intent_id' );
		if ( empty( $intent_secret ) || empty( $intent_secret['id'] ) ) {
			return;
		}

		try {
			$stripe_api = $gateway->get_client();

			// Idempotency: bail if the eye-icon AJAX or a webhook already captured.
			$intent_check = $stripe_api->payment_intents( 'retrieve', array( $intent_secret['id'] ) );
			if ( false === $intent_check['success'] || empty( $intent_check['data'] ) || 'requires_capture' !== $intent_check['data']->status ) {
				return;
			}

			$result = $stripe_api->payment_intents( 'capture', array( $intent_secret['id'], array( 'amount_to_capture' => Helper::get_stripe_amount( $order->get_total() ) ) ) );
			if ( false === $result['success'] ) {
				$order->add_order_note( sprintf( /* translators: %s: error message */ __( 'Auto-capture on status change failed: %s', 'funnelkit-stripe-woo-payment-gateway' ), isset( $result['message'] ) ? $result['message'] : __( 'Unknown error', 'funnelkit-stripe-woo-payment-gateway' ) ) );
				return;
			}

			if ( class_exists( 'WFOCU_Core' ) ) {
				remove_action( 'woocommerce_pre_payment_complete', array( WFOCU_Core()->public, 'maybe_setup_upsell' ), 99 );
			}

			$gateway->process_final_order( end( $result['data']->charges->data ), $order_id );
		} catch ( \Exception $e ) {
			$order->add_order_note( sprintf( /* translators: %s: error message */ __( 'Auto-capture on status change failed: %s', 'funnelkit-stripe-woo-payment-gateway' ), $e->getMessage() ) );
		}
	}

	/**
	 * AJAX CB for wp_ajax_capture_charge
	 * Perform a capture charge API call and process WC order if successful
	 *
	 * @return void
	 */
	public function void_charge() {
		check_ajax_referer( 'void_charge' );
		$this->check_wc_admin();

		$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = wc_get_order( absint( $order_id ) );

		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( array( 'message' => 'Unable to find order' ) );
		}

		try {
			/**
			 *
			 * @var CreditCard $gateway
			 */
			$gateway       = WC()->payment_gateways()->payment_gateways()[ $order->get_payment_method() ];
			$intent_secret = Helper::get_meta( $order, '_fkwcs_intent_id' );

			$result = $gateway->get_client()->payment_intents( 'cancel', array( $intent_secret['id'] ) );

			if ( false === $result['success'] ) {
				wp_send_json_error( array( 'message' => 'Unable to capture charge.' ) );
			}
			$order->update_status( 'cancelled' );

			wp_send_json_success( array() );

		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Charge Data view
	 *
	 * @param \WC_Order $order
	 */
	public static function charge_data_view( $order ) {
		if ( '' !== $order->get_transaction_id() ) {
			include __DIR__ . '/parts/html-stripe-charge-info.php';
		}
	}

	/**
	 * Determine whether the current admin screen is the single order edit screen.
	 *
	 * Works under both legacy post storage and HPOS.
	 *
	 * @since 1.14.1
	 *
	 * @return bool True on the shop order / wc-orders edit screen, false otherwise.
	 */
	public function is_order_edit_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen ) {
			return false;
		}

		$order_screen_id = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		return in_array( $screen->id, array( $order_screen_id, 'shop_order', 'woocommerce_page_wc-orders' ), true );
	}

	/**
	 * Resolve the order being edited from the current admin request.
	 *
	 * Reads the legacy `post` param and the HPOS `id` param via filter_input, so
	 * no superglobal is touched directly. Only the order id is read (no state is
	 * changed), so nonce verification does not apply here.
	 *
	 * @since 1.14.1
	 *
	 * @return \WC_Order|false Order object, or false when it cannot be resolved.
	 */
	public function get_current_admin_order() {
		// FILTER_SANITIZE_NUMBER_INT is deprecated in PHP 8.1+; absint() casts the
		// raw value safely without touching a superglobal directly.
		$order_id = absint( filter_input( INPUT_GET, 'post' ) );

		if ( 0 === $order_id ) {
			$order_id = absint( filter_input( INPUT_GET, 'id' ) );
		}

		if ( 0 === $order_id ) {
			return false;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order ? $order : false;
	}

	/**
	 * Render the "Pay for Order" button on unpaid orders.
	 *
	 * Hooked on woocommerce_admin_order_data_after_order_details. Separate from
	 * charge_data_view(), which is gated on an existing transaction id.
	 *
	 * @since 1.14.1
	 *
	 * @param \WC_Order $order Order being edited.
	 *
	 * @return void
	 */
	public function render_pay_order_button( $order ) {
		if ( ! $this->can_pay_for_order( $order ) ) {
			return;
		}

		include __DIR__ . '/parts/html-order-pay.php';
	}

	/**
	 * Single source of truth for whether the "Pay for Order" feature is available
	 * for a given order in the current request.
	 *
	 * Shared by render_pay_order_button() (button markup), enqueue_order_pay_assets()
	 * (JS/CSS) and pay_order() (the charge handler) so the button, its assets and the
	 * server-side charge can never disagree — e.g. a rendered button whose scripts
	 * were never enqueued, or a charge on an order that is no longer unpaid.
	 *
	 * @since 1.14.1
	 *
	 * @param \WC_Order $order Order being edited/charged.
	 *
	 * @return bool
	 */
	public function can_pay_for_order( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		// Stripe must be usable (OAuth-connected or manual keys) for the charge to run.
		if ( ! $this->is_stripe_connected() && ! $this->is_manually_connected() ) {
			return false;
		}

		/**
		 * Filters the order statuses on which the "Pay for Order" feature is available.
		 *
		 * @since 1.14.1
		 *
		 * @param array $statuses Order status slugs (no `wc-` prefix).
		 */
		$statuses = apply_filters( 'fkwcs_admin_pay_order_statuses', array( 'pending', 'failed', 'auto-draft' ) );

		return in_array( $order->get_status(), $statuses, true );
	}

	/**
	 * Enqueue the "Pay for Order" modal assets on the Edit Order screen.
	 *
	 * Runs on admin_enqueue_scripts. Bails cheaply on every non-order screen
	 * before any database access, then loads assets only when Stripe is
	 * configured, the user can manage WooCommerce and the order is unpaid.
	 *
	 * @since 1.14.1
	 *
	 * @return void
	 */
	public function enqueue_order_pay_assets() {
		// Cheap screen gate first, before any DB lookups.
		if ( ! $this->is_order_edit_screen() ) {
			return;
		}

		$order = $this->get_current_admin_order();

		// Same availability rule as the button + the charge handler, so assets are
		// always present exactly when the button is rendered.
		if ( ! $this->can_pay_for_order( $order ) ) {
			return;
		}

		wp_register_script( 'fkwcs-stripe-external', 'https://js.stripe.com/v3/', array(), FKWCS_VERSION, true );
		wp_enqueue_script( 'fkwcs-order-pay', plugins_url( 'assets/js/order-pay.js', __FILE__ ), array( 'jquery', 'fkwcs-stripe-external', 'wc-backbone-modal' ), FKWCS_VERSION, true );
		wp_enqueue_style( 'fkwcs-order-pay', plugins_url( 'assets/css/order-pay.css', __FILE__ ), array(), FKWCS_VERSION );

		// Use the global active mode so the pub key matches the secret key used
		// server-side, not the order's prior _fkwcs_payment_mode meta.
		$active_mode = ( 'test' === get_option( 'fkwcs_mode', 'test' ) ) ? 'test' : 'live';
		$customer_id = $order->get_customer_id();
		$tokens      = array();

		if ( $customer_id > 0 ) {
			$customer_tokens = \WC_Payment_Tokens::get_customer_tokens( $customer_id, 'fkwcs_stripe' );
			foreach ( $customer_tokens as $token ) {
				if ( $active_mode !== $token->get_meta( 'mode' ) ) {
					continue;
				}

				$expiry = method_exists( $token, 'get_expiry_month' ) ? $token->get_expiry_month() . '/' . $token->get_expiry_year() : '';

				$tokens[] = array(
					'id'    => $token->get_id(),
					'last4' => method_exists( $token, 'get_last4' ) ? $token->get_last4() : '',
					'brand' => method_exists( $token, 'get_card_type' ) ? $token->get_card_type() : '',
					'exp'   => $expiry,
				);
			}
		}
		wp_localize_script(
			'fkwcs-order-pay',
			'fkwcs_admin_order_data',
			/**
			 * Filters the data localized to the "Pay for Order" modal.
			 *
			 * @since 1.14.1
			 *
			 * @param array     $args  Localized payload.
			 * @param \WC_Order $order Order being edited.
			 */
			apply_filters(
				'fkwcs_admin_order_pay_localize_args',
				array(
					'ajax_url'     => admin_url( 'admin-ajax.php' ),
					'pub_key'      => $this->get_api_key(),
					'nonce'        => wp_create_nonce( 'fkwcs_admin_pay_order' ),
					'order_id'     => $order->get_id(),
					'customer_id'  => $customer_id,
					'tokens'       => $tokens,
					'order_status' => $order->get_status(),
					'i18n'         => array(
						'error_generic' => __( 'Something went wrong. Please try again.', 'funnelkit-stripe-woo-payment-gateway' ),
						'no_method'     => __( 'Please select a payment method.', 'funnelkit-stripe-woo-payment-gateway' ),
					),
				),
				$order
			)
		);
	}

	/**
	 * AJAX CB for wp_ajax_fkwcs_admin_pay_order.
	 *
	 * Collects a card (saved token or freshly tokenized new card) and runs the
	 * order through CreditCard::process_payment(). The chosen capture method is
	 * applied for this request only via a scoped fkwcs_payment_intent_data
	 * closure, and saved tokens are resolved against the order's customer via the
	 * fkwcs_saved_token_expected_user_id filter. Relays the gateway result
	 * (including the 3D Secure branch) back to the modal.
	 *
	 * @since 1.14.1
	 *
	 * @return void
	 */
	public function pay_order() {
		check_ajax_referer( 'fkwcs_admin_pay_order' );
		$this->check_wc_admin();

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Unable to find order.', 'funnelkit-stripe-woo-payment-gateway' ) ) );
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways['fkwcs_stripe'] ) || ! $gateways['fkwcs_stripe'] instanceof CreditCard ) {
			wp_send_json_error( array( 'message' => __( 'Stripe gateway is not available.', 'funnelkit-stripe-woo-payment-gateway' ) ) );
		}

		/**
		 * Main credit-card gateway used to process this admin payment.
		 *
		 * @var CreditCard $gateway
		 */
		$gateway     = $gateways['fkwcs_stripe'];
		$customer_id = (int) $order->get_customer_id();

		// Re-validate availability at submit time. The button/asset gate ran when the
		// screen loaded, but the order may have been paid via another channel (or had
		// its status changed) while the modal was open. Charging without this re-check
		// risks double-charging the customer.
		if ( ! $this->can_pay_for_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This order can no longer be paid for — it may already be paid or its status has changed. Please reload the page and try again.', 'funnelkit-stripe-woo-payment-gateway' ) ) );
		}

		// Only automatic|manual are valid capture methods; anything else = capture now.
		$charge_type = isset( $_POST['fkwcs_charge_type'] ) ? sanitize_text_field( wp_unslash( $_POST['fkwcs_charge_type'] ) ) : 'automatic';
		if ( 'manual' !== $charge_type ) {
			$charge_type = 'automatic';
		}

		$token_id = isset( $_POST['token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['token_id'] ) ) : '';
		$source   = isset( $_POST['fkwcs_source'] ) ? sanitize_text_field( wp_unslash( $_POST['fkwcs_source'] ) ) : '';

		if ( '' === $token_id && '' === $source ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a payment method.', 'funnelkit-stripe-woo-payment-gateway' ) ) );
		}

		// Route process_payment() to the saved-token or new-card path by seeding
		// the POST keys the gateway reads downstream.
		$_POST['payment_method'] = 'fkwcs_stripe';
		if ( '' !== $token_id ) {
			$_POST['wc-fkwcs_stripe-payment-token'] = $token_id;
		} else {
			unset( $_POST['wc-fkwcs_stripe-payment-token'] );
			$_POST['fkwcs_source'] = $source;
		}

		// Resolve the saved token against the ORDER's customer, not the admin.
		$expected_user = function () use ( $customer_id ) {
			return $customer_id;
		};
		add_filter( 'fkwcs_saved_token_expected_user_id', $expected_user );

		// Apply the capture method for THIS request only — never mutate the shared
		// gateway singleton, which maybe_capture_charge_on_status reads synchronously.
		$capture_override = function ( $args ) use ( $charge_type ) {
			$args['capture_method'] = $charge_type;

			return $args;
		};
		add_filter( 'fkwcs_payment_intent_data', $capture_override, 9999 );

		// Assign the gateway to the order (unpaid/manual orders often have none) so the
		// eye-icon capture/void UI and maybe_capture_charge_on_status resolve correctly
		// after the page reloads. Clearing any stale per-order mode forces this fresh
		// charge onto the global active mode, matching the localized publishable key.
		//
		// Snapshot the pre-charge values first so they can be restored if the payment
		// fails — otherwise a failed attempt would leave the order permanently
		// re-assigned to fkwcs_stripe with its original payment mode wiped.
		$original_payment_method = $order->get_payment_method();
		$original_payment_mode   = $order->get_meta( '_fkwcs_payment_mode' );

		$order->set_payment_method( $gateway );
		$order->delete_meta_data( '_fkwcs_payment_mode' );
		$order->save();

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} catch ( \Throwable $e ) {
			Helper::log( 'Admin pay-for-order failed for order ' . $order->get_id() . ': ' . $e->getMessage(), 'error' );
			$result = array(
				'result'  => 'fail',
				'message' => $e->getMessage(),
			);
		} finally {
			remove_filter( 'fkwcs_payment_intent_data', $capture_override, 9999 );
			remove_filter( 'fkwcs_saved_token_expected_user_id', $expected_user );
		}

		if ( isset( $result['result'] ) && 'fail' === $result['result'] ) {
			// Payment failed: undo the payment-method/mode changes made above so a
			// failed attempt does not leave persistent, misleading order state.
			$order->set_payment_method( $original_payment_method );
			if ( '' !== $original_payment_mode ) {
				$order->update_meta_data( '_fkwcs_payment_mode', $original_payment_mode );
			}
			$order->save();

			wp_send_json_error( array( 'message' => isset( $result['message'] ) ? $result['message'] : __( 'Payment could not be completed.', 'funnelkit-stripe-woo-payment-gateway' ) ) );
		}

		// 3DS branch: hand the modal a verify URL so it can finalize the order
		// server-side after confirmCardPayment, exactly like checkout does.
		if ( isset( $result['fkwcs_intent_secret'] ) ) {
			$redirect_to = isset( $result['fkwcs_redirect'] ) ? $result['fkwcs_redirect'] : $gateway->get_return_url( $order );

			$result['fkwcs_verification_url'] = add_query_arg(
				array(
					'order'             => $order->get_id(),
					'order_key'         => $order->get_order_key(),
					'fkwcs_redirect_to' => rawurlencode( $redirect_to ),
					'save_card'         => $gateway->should_save_card( $order ) ? 'true' : 'false',
					'gateway'           => 'fkwcs_stripe',
				),
				\WC_AJAX::get_endpoint( 'fkwcs_stripe_verify_payment_intent' )
			);
		}

		wp_send_json_success( $result );
	}

	public function get_api_option( $key ) {
		return isset( $this->options_keys[ $key ] ) ? $this->options_keys[ $key ] : false;
	}


	public function update_api_option( $key, $value ) {
		if ( isset( $this->options_keys[ $key ] ) ) {
			update_option( $key, $value );
			$this->options_keys[ $key ] = $value;
		}
	}

	public function fkwcs_radio_html_field( $html, $key, $data, $instance ) {  //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$field_key = $instance->get_field_key( $key );
		$defaults  = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
		);

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?><?php echo $instance->get_tooltip_html( $data );//phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<input type='radio' class="<?php echo esc_attr( $data['class'] ); ?>" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" value="<?php echo esc_attr( $instance->get_option( $key ) ); ?>" placeholder="<?php echo esc_attr( $data['placeholder'] ); ?>" <?php disabled( $data['disabled'], true ); ?> <?php echo $instance->get_custom_attribute_html( $data ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
					<span class="screen-reader-text1"><span><?php echo wp_kses_post( $data['label'] ); ?></span></span>
					<?php echo $instance->get_description_html( $data ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}


	public function wc_notif_for_block_usage() {

		if ( class_exists( '\WFFN_Common' ) && 0 !== \WFFN_Common::get_store_checkout_id() ) {
			return;
		}

		$current_admin_url = basename( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$action_url        = admin_url( 'admin-ajax.php?action=fkwcs_blocks_incompatible_switch_to_classic&nonce=' . wp_create_nonce( 'fkwcs_blocks_incompatible_switch_to_classic' ) . '&redirect=' . $current_admin_url );
		$dismiss_url       = admin_url( 'admin-ajax.php?action=fkwcs_dismiss_notice&notice_identifier=wc_block_incompat&_security=' . wp_create_nonce( 'fkwcs_admin_request' ) . '&redirect=' . $current_admin_url );

		echo '<div class="notice notice-warning fkwcs_dismiss_notice_wrap_wc_block_incompat" >';

		echo wp_kses_post( $this->block_incompat_notice() );
		echo '<p><a class="button button-primary" href="' . esc_url( $action_url ) . '">' . __( ' Switch to classic cart/checkout', 'funnelkit-stripe-woo-payment-gateway' ) . '</a>     <a href="' . esc_url( $dismiss_url ) . '" class="button fkwcs_dismiss_notice" data-notice="wc_block_incompat">' . __( 'Dismiss', 'funnelkit-stripe-woo-payment-gateway' ) . '</a>  </p>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	private function block_incompat_notice() {
		return '<div>
					<h3>' . __( 'Checkout Incompatibility With Stripe!', 'funnelkit-stripe-woo-payment-gateway' ) . '</h3>
					<p>' . __( "We noticed that you're using the new Cart/Checkout experience in WooCommerce. Full compatibility is in the works with FunnelKit Stripe. We suggest switching to the Classic Checkout experience in the meantime, it's well tested and 100% compatible with our plugin and others as well.", 'funnelkit-stripe-woo-payment-gateway' ) . '</p>
				</div>';
	}

	public function blocks_incompatible_switch_to_classic_cart_checkout() {

		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\BlockTypes\ClassicShortcode' )  // Make sure WC version is atleast 8.3. This class is added at version 8.3.
		) {
			return;
		}

		check_ajax_referer( 'fkwcs_blocks_incompatible_switch_to_classic', 'nonce', true );

		$this->check_wc_admin();

		$wc_cart_page     = get_post( wc_get_page_id( 'cart' ) );
		$wc_checkout_page = get_post( wc_get_page_id( 'checkout' ) );

		if ( has_block( 'woocommerce/checkout', $wc_checkout_page ) ) {
			wp_update_post(
				array(
					'ID'           => $wc_checkout_page->ID,
					'post_content' => '<!-- wp:woocommerce/classic-shortcode {"shortcode":"checkout"} /-->',
				)
			);
		}

		if ( has_block( 'woocommerce/cart', $wc_cart_page ) ) {
			wp_update_post(
				array(
					'ID'           => $wc_cart_page->ID,
					'post_content' => '<!-- wp:woocommerce/classic-shortcode {"shortcode":"cart"} /-->',
				)
			);

		}
		$user = wp_get_current_user();
		$meta = get_user_meta( $user->ID, '_fkwcs_notices', true );

		if ( empty( $meta ) ) {
			$meta = array( 'wc_block_incompat' );
		} elseif ( is_array( $meta ) ) {
			$meta = array_push( $meta, 'wc_block_incompat' );
		} else {
			$meta = array( 'wc_block_incompat' );
		}

		update_user_meta( $user->ID, '_fkwcs_notices', $meta ); //phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.user_meta_update_user_meta

		$redirect = isset( $_REQUEST['redirect'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect'] ) ) : null; //phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above via check_ajax_referer

		wp_safe_redirect( $this->get_safe_admin_redirect_url( $redirect, admin_url( 'admin.php?page=wc-settings' ) ) );
		exit;
	}

	public function maybe_setup_account_info() {
		try {
			if ( is_user_logged_in() && current_user_can( 'administrator' ) && isset( $_GET['tab'] ) && 'fkwcs_api_settings' === $_GET['tab'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended

				if ( empty( $this->options_keys ) || ! isset( $this->options_keys['fkwcs_secret_key'] ) || empty( $this->options_keys['fkwcs_secret_key'] ) || ! isset( $this->options_keys['fkwcs_account_id'] ) || empty( $this->options_keys['fkwcs_account_id'] ) ) {
					return false;
				}

				$client = Helper::get_new_client( $this->options_keys['fkwcs_secret_key'] );
				$args   = $client->accounts( 'retrieve', array( $this->options_keys['fkwcs_account_id'] ) );

				if ( ! empty( $args['success'] ) ) {
					$account = $args['data'];
					$this->account_data['statement_descriptor_prefix'] = $account->settings->card_payments->statement_descriptor_prefix;
					$this->account_data['statement_descriptor_full']   = $account->settings->payments->statement_descriptor;

					if ( $this->options_keys['fkwcs_stripe_statement_descriptor_full'] === '' && $this->account_data['statement_descriptor_full'] !== '' ) {

						update_option( 'fkwcs_stripe_statement_descriptor_full', $this->account_data['statement_descriptor_full'] );
						$this->options_keys['fkwcs_stripe_statement_descriptor_full'] = $this->account_data['statement_descriptor_full'];
					}

					if ( $this->options_keys['fkwcs_stripe_statement_descriptor_prefix'] === '' && $this->account_data['statement_descriptor_prefix'] !== '' ) {
						update_option( 'fkwcs_stripe_statement_descriptor_prefix', $this->account_data['statement_descriptor_prefix'] );
						$this->options_keys['fkwcs_stripe_statement_descriptor_prefix'] = $this->account_data['statement_descriptor_prefix'];
					}

					$stripe_account_settings = array(
						'country'          => $account->country,
						'default_currency' => $account->default_currency,
					);
					update_option( 'fkwcs_stripe_account_settings', $stripe_account_settings );

				}
			}
		} catch ( \Exception $e ) {
			Helper::log( $e->getMessage() );
		}
	}

	public function generate_wc_fkwcs_stripe_statement_preview_html( $value ) {

		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
			</th>
			<td class="forminp">
				<div class="fkwcs-cards-wrap">

					<div class="fkwcs-card" id="fkwcs_card_custom_descriptor">
						<div class="fkwcs-card-header">
							<svg width="24" height="24" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="credit card icon" aria-hidden="true" focusable="false">
								<mask id="mask-cc" maskUnits="userSpaceOnUse" x="1" y="2" width="14" height="12" style="mask-type: alpha;">
									<path fill-rule="evenodd" clip-rule="evenodd" d="M13.3647 2.66669H2.69808C1.95808 2.66669 1.37141 3.26002 1.37141 4.00002L1.36475 12C1.36475 12.74 1.95808 13.3334 2.69808 13.3334H13.3647C14.1047 13.3334 14.6981 12.74 14.6981 12V4.00002C14.6981 3.26002 14.1047 2.66669 13.3647 2.66669ZM13.3647 12H2.69808V8.00002H13.3647V12ZM2.69808 5.33335H13.3647V4.00002H2.69808V5.33335Z" fill="white"></path>
								</mask>
								<g mask="url(#mask-cc)">
									<rect x="0.0314941" width="16" height="16" fill="#1E1E1E"></rect>
								</g>
							</svg>
							<div class="fkwcs-card-subheading"><?php esc_html_e( 'Cards & Express Checkouts', 'funnelkit-stripe-woo-payment-gateway' ); ?></div>
						</div>
						<div class="fkwcs-card-transactions">
							<div class="fkwcs-card-transactions-header">
								<span class="fkwcs-card-transaction"><?php esc_html_e( 'Transaction', 'funnelkit-stripe-woo-payment-gateway' ); ?></span>
								<span class="fkwcs-card-amount"><?php esc_html_e( 'Amount', 'funnelkit-stripe-woo-payment-gateway' ); ?></span>
							</div>
							<div class="fkwcs-card-transactions-data">
								<a href="javascript:void(0)" id="fkwcs_custom_statement_desc_val" class="fkwcs-transaction-id"><?php echo esc_html( $value['customdata']['statement_descriptor_prefix'] ) . '* '; ?></a>
								<span class="fkwcs-amount-figure">$452.25</span>
							</div>
						</div>
					</div>

					<div class="fkwcs-card" id="fkwcs_card_full_descriptor">
						<div class="fkwcs-card-header">
							<svg width="24" height="24" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="bank icon" aria-hidden="true" focusable="false">
								<mask id="mask-bank" maskUnits="userSpaceOnUse" x="1" y="1" width="14" height="14" style="mask-type: alpha;">
									<path fill-rule="evenodd" clip-rule="evenodd" d="M1.69812 4.66665L8.03145 1.33331L14.3648 4.66665V5.99998H1.69812V4.66665ZM8.03145 2.83998L11.5048 4.66665H4.55812L8.03145 2.83998ZM3.36479 7.33331H4.69812V12H3.36479V7.33331ZM8.69812 7.33331V12H7.36479V7.33331H8.69812ZM14.3648 14.6666V13.3333H1.69812V14.6666H14.3648ZM11.3648 7.33331H12.6981V12H11.3648V7.33331Z" fill="white"></path>
								</mask>
								<g mask="url(#mask-bank)">
									<rect x="0.0314941" width="16" height="16" fill="#1E1E1E"></rect>
								</g>
							</svg>
							<div class="fkwcs-card-subheading"><?php esc_html_e( 'All payment methods', 'funnelkit-stripe-woo-payment-gateway' ); ?></div>
						</div>
						<div class="fkwcs-card-transactions">
							<div class="fkwcs-card-transactions-header">
								<span class="fkwcs-card-transaction"><?php esc_html_e( 'Transaction', 'funnelkit-stripe-woo-payment-gateway' ); ?></span>
								<span class="fkwcs-card-amount"><?php esc_html_e( 'Amount', 'funnelkit-stripe-woo-payment-gateway' ); ?></span>
							</div>
							<div class="fkwcs-card-transactions-data">
								<a href="javascript:void(0)" class="fkwcs-transaction-id"><?php echo esc_html( $value['customdata']['statement_descriptor_full'] ); ?></a>
								<span class="fkwcs-amount-figure">$452.25</span>
							</div>
						</div>
					</div>

				</div>
			</td>
		</tr>

		<?php
	}

	public function generate_wc_fkwcs_stripe_wrap_fields_start_html() {

		?>
		<table class="form-table fkwcs_f_table" ><tbody>

		<?php
	}

	public function generate_wc_fkwcs_stripe_wrap_fields_end_html() {

		?>
		</tbody></table>

		<?php
	}


	public function fkwcs_admin_fields_start_html_field( $html, $key, $data, $instance ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		ob_start();
		?>
		</table>
		<table class="form-table <?php echo esc_attr( $data['class'] ); ?>">
		<?php
		return ob_get_clean();
	}

	public function fkwcs_admin_fields_end_html_field( $html, $key, $data, $instance ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		ob_start();
		?>
		</table>
		<table class="form-table">
		<?php
		return ob_get_clean();
	}

	public function fkwcs_capture_terminal_payment( $request ) {
		try {
			$intent_id = $request['payment_intent_id'];
			$order_id  = $request['order_id'];
			$order     = wc_get_order( $order_id );

			// Check that order exists before capturing payment.
			if ( ! $order ) {
				return new \WP_Error( 'wc_stripe_missing_order', __( 'Order not found', 'funnelkit-stripe-woo-payment-gateway' ), array( 'status' => 404 ) );
			}

			// Do not process refunded orders.
			if ( 0 < $order->get_total_refunded() ) {
				return new \WP_Error( 'wc_stripe_refunded_order_uncapturable', __( 'Payment cannot be captured for partially or fully refunded orders.', 'funnelkit-stripe-woo-payment-gateway' ), array( 'status' => 400 ) );
			}

			if ( 'live' === Helper::get_mode() ) {
				$client_secret = get_option( 'fkwcs_secret_key' );
			} else {
				$client_secret = get_option( 'fkwcs_test_secret_key' );
			}

			if ( empty( $client_secret ) ) {
				return;
			}

			$client   = Helper::get_new_client( $client_secret );
			$response = $client->payment_intents( 'retrieve', array( $intent_id ) );
			$intent   = $response['success'] ? $response['data'] : false;
			if ( false === $intent ) {
				return new \WP_Error( 'stripe_error', __( 'No Payment Intent found.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			// Ensure that intent can be captured.
			if ( ! in_array( $intent->status, array( 'processing', 'requires_capture' ), true ) ) {
				return new \WP_Error( 'wc_stripe_payment_uncapturable', __( 'The payment cannot be captured', 'funnelkit-stripe-woo-payment-gateway' ), array( 'status' => 409 ) );
			}

			// Update order with payment method and intent details.
			$order->set_payment_method( 'fkwcs_stripe' );
			$order->set_payment_method_title( __( 'WooCommerce Stripe In-Person Payments', 'funnelkit-stripe-woo-payment-gateway' ) );
			$gateway = WC()->payment_gateways()->payment_gateways()['fkwcs_stripe'];
			$gateway->save_intent_to_order( $order, $intent );

			$result = $client->payment_intents( 'capture', array( $intent_id ) );
			if ( false === $result['success'] ) {
				wp_send_json_error( array( 'message' => 'Unable to capture charge.' ) );

				return new \WP_Error( 'stripe_error', __( 'No Payment Intent found.', 'funnelkit-stripe-woo-payment-gateway' ) );

			}

			// Check for failure to capture payment.
			if ( empty( $result ) || empty( $result['data'] ) || 'succeeded' !== $result['data']->status ) {
				return new \WP_Error(
					'wc_stripe_capture_error',
					sprintf( // translators: %s: the error message.
						__( 'Payment capture failed to complete with the following message: %s', 'funnelkit-stripe-woo-payment-gateway' ),
						Helper::get_localized_error_message( $result ) ?? __( 'Unknown error', 'funnelkit-stripe-woo-payment-gateway' )
					),
					array( 'status' => 502 )
				);
			}

			$gateway->process_final_order( end( $result['data']->charges->data ), $order_id );

			return rest_ensure_response(
				array(
					'status' => $result['data']->status,
					'id'     => $result['data']->id,
				)
			);
		} catch ( \Exception | \Error $e ) {
			return rest_ensure_response( new \WP_Error( 'stripe_error', $e->getMessage() ) );
		}
	}

	// Add a custom admin notice if the WooCommerce store country is not supported by Stripe
	function custom_stripe_checkout_country_notice() {

		try { // List of supported Stripe countries
			$supported_countries = array(
				'AE',
				'AT',
				'AU',
				'BE',
				'BG',
				'BR',
				'CA',
				'CH',
				'CI',
				'CR',
				'CY',
				'CZ',
				'DE',
				'DK',
				'DO',
				'EE',
				'ES',
				'FI',
				'FR',
				'GB',
				'GI',
				'GR',
				'GT',
				'HK',
				'HR',
				'HU',
				'ID',
				'IE',
				'IN',
				'IT',
				'JP',
				'LI',
				'LT',
				'LU',
				'LV',
				'MT',
				'MX',
				'MY',
				'NL',
				'NO',
				'NZ',
				'PE',
				'PH',
				'PL',
				'PT',
				'RO',
				'SE',
				'SG',
				'SI',
				'SK',
				'SN',
				'TH',
				'TT',
				'US',
				'UY',
			);// Get the store country code
			$store_country       = substr( get_option( 'woocommerce_default_country' ), 0, 2 );// Check if the store's country is not supported by Stripe for Express Checkout
			if ( ! in_array( $store_country, $supported_countries, true ) ) {
				// Initialize the WC_Countries class
				$wc_countries = new \WC_Countries();

				// Get the full country name
				$country_name = isset( $wc_countries->countries[ $store_country ] ) ? $wc_countries->countries[ $store_country ] : $store_country;

				// Show an admin notice with dynamic country name and "Learn More" link
				echo '<div class="notice notice-warning">
                    <p><strong>' . esc_html__( 'FunnelKit Stripe Notice: ', 'funnelkit-stripe-woo-payment-gateway' ) . '</strong>' . sprintf(
						/* translators: 1: Country name, 2: Country name, 3: Learn More link */
					esc_html__( 'Your Stripe account address is in %1$s. Express Checkout is unfortunately not supported in %2$s. %3$s', 'funnelkit-stripe-woo-payment-gateway' ),
					esc_html( $country_name ),
					esc_html( $country_name ),
					'<a href="https://funnelkit.com/docs/stripe-gateway-for-woocommerce/faq/stripe-express-checkout-supported-countries/" target="_blank">' . esc_html__( 'Learn More', 'funnelkit-stripe-woo-payment-gateway' ) . '</a>'
				) . '</p>
                  </div>';
			}
		} catch ( \Exception | \Error $e ) {
			Helper::log( 'Error in custom_stripe_checkout_country_notice: ' . $e->getMessage() );
		}
	}

	/**
	 * Checks if the current page is an express payments page.
	 *
	 * This method iterates through a predefined list of express payment page identifiers
	 * and checks if the current page matches any of them.
	 *
	 * @return bool True if the current page is an express payments page, false otherwise.
	 */
	public function is_express_payments_page() {
		$pages = array(
			'fkwcs_express_checkout',
			'fkwcs_stripe_google_pay',
			'fkwcs_stripe_apple_pay',
		);

		foreach ( $pages as $page ) {
			if ( $this->is_page( 'wc-settings', 'checkout', $page ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adds a custom admin footer script to dynamically generate navigation links.
	 *
	 * This method creates a `<ul>` element with navigation links based on the `$navigation` property.
	 * It appends the generated navigation menu to the admin page, ensuring the current section is highlighted.
	 *
	 * @return void
	 */
	public function add_custom_admin_footer_script() {

		$navigation      = $this->sub_links( array() );  // This is directly using the class property
		$current_section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $current_section, array_keys( $navigation ), true ) ) {
			return;
		}
		$navigation_json = wp_json_encode( $navigation );
		wp_localize_script(
			'fkwcs-admin-js',
			'fkwcsAdminNav',
			array(
				'settings' => $navigation_json,
				'adminUrl' => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' ),
			)
		);
	}


	/**
	 * Adds a support link to the Stripe settings page.
	 *
	 * Checks if we're on the Stripe settings page and adds a "Need Help?" link
	 * that directs users to FunnelKit support.
	 *
	 * @param array|string $links The existing link(s) to modify or extend.
	 *
	 * @return string|array Modified links containing the support link if on Stripe settings page,
	 *                      otherwise returns original links unmodified.
	 * @since 1.0.0
	 */
	public function add_support_link( $links ) {
		if ( ! isset( $_GET['page'] ) || ! isset( $_GET['tab'] ) || 'wc-settings' !== $_GET['page'] || 'fkwcs_api_settings' !== $_GET['tab'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $links;
		}

		return sprintf( /* translators: %s: Support link HTML */ __( 'Need Help? %s', 'funnelkit-stripe-woo-payment-gateway' ), '<a target="_blank" href="' . esc_url( 'https://funnelkit.com/support/' ) . '">' . esc_html__( 'Contact Support', 'funnelkit-stripe-woo-payment-gateway' ) . '</a>' );
	}
}
