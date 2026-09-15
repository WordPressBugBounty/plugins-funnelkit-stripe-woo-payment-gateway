<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Payment_Tokens;
use FKWCS\Gateway\Stripe\Traits\WC_Subscriptions_Trait;
use FKWCS\Gateway\Stripe\Traits\WC_Pre_Orders_Trait;
use WC_HTTPS;

#[\AllowDynamicProperties]
class CreditCard extends Abstract_Payment_Gateway {

	use WC_Subscriptions_Trait;
	use WC_Pre_Orders_Trait;
	use Funnelkit_Stripe_Smart_Buttons;

	/**
	 * Gateway id
	 *
	 * @var string
	 */
	public $id                       = 'fkwcs_stripe';
	public $token                    = false;
	public $payment_method_types     = 'card';
	public $credit_card_form_type    = 'card';
	protected $payment_element       = true;
	private static $instance         = null;
	public $supports_success_webhook = true;
	public $is_recursion             = false;

	public function __construct() {
		parent::__construct();
		$this->init_supports();
	}

	/**
	 * @return CreditCard gateway instance
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Setup general properties and settings
	 *
	 * @return void
	 */
	protected function init() {

		$this->method_title       = __( 'Stripe Gateway', 'funnelkit-stripe-woo-payment-gateway' );
		$this->method_description = __( ' Accepts payments via Credit or Debit Cards. The gateway supports all popular Card brands. <br/>Use Allowed Card Brands to set up brands as per your choice. ', 'funnelkit-stripe-woo-payment-gateway' );
		$this->subtitle           = __( 'Let your customers pay with major credit and debit cards without leaving your store', 'funnelkit-stripe-woo-payment-gateway' );
		$this->has_fields         = true;
		$this->init_form_fields();
		$this->init_settings();
		$this->maybe_init_subscriptions();
		$this->maybe_init_pre_orders();
		$this->title                 = $this->get_option( 'title' );
		$this->description           = $this->get_option( 'description' );
		$this->inline_cc             = $this->get_option( 'inline_cc' );
		$this->enabled               = $this->get_option( 'enabled' );
		$this->enable_saved_cards    = $this->get_option( 'enable_saved_cards' );
		$this->capture_method        = $this->get_option( 'charge_type' );
		$this->allowed_cards         = $this->get_allowed_card_brands();
		$this->credit_card_form_type = 'yes' === $this->get_option( 'payment_form' ) ? 'payment' : '';

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_stripe_js' ) );
		add_filter( 'fkwcs_localized_data', array( $this, 'localize_element_data' ), 999 );

		add_action( 'woocommerce_get_customer_payment_tokens', array( $this, 'maybe_sync_gateway_tokens' ), 8, 3 );
		add_action( 'fkwcs_webhook_event_intent_succeeded', array( $this, 'handle_webhook_intent_succeeded' ), 10, 2 );

		add_action( 'woocommerce_payment_token_deleted', array( $this, 'detach_customer_token' ), 10, 2 );

		add_filter(
			'woocommerce_gateway_title',
			function ( $title ) {
				global $theorder;

				if ( $theorder instanceof \WC_Order && $theorder->get_payment_method() === 'fkwcs_stripe' && ! empty( $theorder->get_payment_method_title() ) && ( ! did_action( 'woocommerce_admin_order_data_after_payment_info' ) && ! did_action( 'woocommerce_admin_order_data_after_order_details' ) ) ) {
					$title = $theorder->get_payment_method_title();

				}

				return $title;
			}
		);
		add_action( 'woocommerce_payment_token_set_default', array( $this, 'woocommerce_payment_token_set_default' ) );
	}

	/**
	 * Add hooks
	 *
	 * @return void
	 */
	protected function filter_hooks() {
		if ( $this->is_configured() ) {
			add_filter( 'woocommerce_payment_successful_result', array( $this, 'modify_successful_payment_result' ), 999, 2 );
			add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'send_payment_options' ), 999 );

		}
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

	/**
	 * Checks whether current page is supported for express checkout
	 *
	 * @return boolean
	 */
	public function is_page_supported() {
		return is_cart() || is_checkout() || isset( $_GET['pay_for_order'] ) || is_add_payment_method_page() || ( function_exists( 'wcs_is_view_subscription_page' ) && wcs_is_view_subscription_page() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return is_cart() || is_checkout() || isset( $_GET['pay_for_order'] ) || is_add_payment_method_page() || ( function_exists( 'wcs_is_view_subscription_page' ) && wcs_is_view_subscription_page() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Initialise gateway settings form fields
	 *
	 * @return void
	 */
	public function init_form_fields() {

		$this->form_fields = apply_filters(
			'fkwcs_card_payment_form_fields',
			array(
				'enabled'               => array(
					'label'   => ' ',
					'type'    => 'checkbox',
					'title'   => __( 'Enable Stripe Gateway', 'funnelkit-stripe-woo-payment-gateway' ),
					'default' => 'no',
				),
				'title'                 => array(
					'title'       => __( 'Title', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'text',
					'description' => __( 'Change the payment gateway title that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
					'default'     => __( 'Credit Card (Stripe)', 'funnelkit-stripe-woo-payment-gateway' ),
					'desc_tip'    => true,
				),
				'description'           => array(
					'title'       => __( 'Description', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'textarea',
					'css'         => 'width:25em',
					'description' => __( 'Change the payment gateway description that appears on the checkout.', 'funnelkit-stripe-woo-payment-gateway' ),
					'default'     => __( 'Pay with your credit card via Stripe', 'funnelkit-stripe-woo-payment-gateway' ),
					'desc_tip'    => true,
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
				'enable_saved_cards'    => array(
					'label'       => __( 'Enable Payment via Saved Cards', 'funnelkit-stripe-woo-payment-gateway' ),
					'title'       => __( 'Saved Cards', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'checkbox',
					'description' => __( 'Save card details for future orders', 'funnelkit-stripe-woo-payment-gateway' ),
					'default'     => 'yes',
					'desc_tip'    => true,
				),
				'inline_cc'             => array(
					'label'       => __( 'Enable Inline Credit Card Form', 'funnelkit-stripe-woo-payment-gateway' ),
					'title'       => __( 'Credit Card Form Style', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'        => 'checkbox',
					'description' => __( 'Use inline credit card for card payments', 'funnelkit-stripe-woo-payment-gateway' ),
					'default'     => 'no',
					'desc_tip'    => true,
					'class'       => 'fkwcs_form_type_selection fkwcs_checkbox_radio',
				),
				'standard_payment_form' => array(
					'label'       => __( 'Enable Standard Credit Card Form', 'funnelkit-stripe-woo-payment-gateway' ),
					'title'       => '&nbsp;',
					'type'        => 'checkbox',
					'description' => __( 'Use inline credit card for card payments', 'funnelkit-stripe-woo-payment-gateway' ),
					'default'     => get_option( 'woocommerce_fkwcs_stripe_settings', false ) === false ? 'no' : 'yes',
					'desc_tip'    => true,
					'class'       => 'fkwcs_form_type_selection fkwcs_checkbox_radio',
				),
				'payment_form'          => array(
					'label'       => __( 'Enable Enhanced Payment Element (Recommended)', 'funnelkit-stripe-woo-payment-gateway' ),
					'title'       => '&nbsp;',
					'type'        => 'checkbox',
					'description' => __( 'Use stripe payment elements for card payments', 'funnelkit-stripe-woo-payment-gateway' ),
					'default'     => get_option( 'woocommerce_fkwcs_stripe_settings', false ) === false ? 'yes' : 'no',
					'desc_tip'    => true,
					'class'       => 'fkwcs_form_type_selection fkwcs_checkbox_radio',
				),

				'allowed_cards'         => array(
					'title'    => __( 'Allowed Card Brands', 'funnelkit-stripe-woo-payment-gateway' ),
					'type'     => 'multiselect',
					'class'    => 'fkwcs_select_woo',
					'desc_tip' => __( 'Accepts payments using selected cads. Please select atleast one card brand.', 'funnelkit-stripe-woo-payment-gateway' ),
					'options'  => self::get_card_brands(),
					'default'  => array_keys( self::get_card_brands() ),
				),
			)
		);
	}

	/**
	 * Canonical card brand list, keyed by the slug Stripe reports. Single source of truth for the
	 * Allowed Card Brands setting and for validating payments against it.
	 *
	 * @return array
	 */
	public static function get_card_brands() {
		return array(
			'mastercard' => __( 'MasterCard', 'funnelkit-stripe-woo-payment-gateway' ),
			'visa'       => __( 'Visa', 'funnelkit-stripe-woo-payment-gateway' ),
			'amex'       => __( 'American Express', 'funnelkit-stripe-woo-payment-gateway' ),
			'discover'   => __( 'Discover', 'funnelkit-stripe-woo-payment-gateway' ),
			'jcb'        => __( 'JCB', 'funnelkit-stripe-woo-payment-gateway' ),
			'diners'     => __( 'Diners Club', 'funnelkit-stripe-woo-payment-gateway' ),
			'unionpay'   => __( 'UnionPay', 'funnelkit-stripe-woo-payment-gateway' ),
		);
	}

	/**
	 * Every brand slug this gateway knows about.
	 *
	 * @return array
	 */
	protected function get_card_brand_slugs() {
		return array_keys( self::get_card_brands() );
	}

	/**
	 * Older installs stored 'dinners' because this setting's default shipped with that typo,
	 * while Stripe reports the brand as 'diners'. Left unmapped, a Diners card would be
	 * rejected on a store that has Diners Club ticked.
	 *
	 * @param string $brand Brand slug.
	 *
	 * @return string
	 */
	protected function normalize_card_brand( $brand ) {
		$brand = strtolower( trim( (string) $brand ) );

		return 'dinners' === $brand ? 'diners' : $brand;
	}

	/**
	 * Allowed Card Brands setting, normalized to the slugs Stripe reports.
	 *
	 * Always read from the card gateway's own settings. Apple Pay and Google Pay extend this class
	 * but keep separate settings that have no allowed_cards key, so $this->get_option() would hand
	 * them an empty list and quietly accept every brand.
	 *
	 * @return array
	 */
	protected function get_allowed_card_brands() {
		$settings = get_option( 'woocommerce_fkwcs_stripe_settings', array() );
		$allowed  = isset( $settings['allowed_cards'] ) ? (array) $settings['allowed_cards'] : $this->get_card_brand_slugs();
		$allowed  = array_map( array( $this, 'normalize_card_brand' ), $allowed );

		return array_values( array_filter( $allowed ) );
	}

	/**
	 * Keep the saved setting clean: map the legacy slug, drop anything unknown, and never store
	 * an empty selection. Saving none would reject every card, so treat it as accept everything.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 *
	 * @return array
	 */
	public function validate_allowed_cards_field( $key, $value ) { //phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedParameter
		$brands = array_map( array( $this, 'normalize_card_brand' ), (array) $value );
		$brands = array_values( array_intersect( $brands, $this->get_card_brand_slugs() ) );

		return empty( $brands ) ? $this->get_card_brand_slugs() : $brands;
	}

	/**
	 * Brand of the posted payment method when the store does not accept it, otherwise ''.
	 *
	 * @return string
	 */
	protected function get_disallowed_card_brand() {
		$allowed = $this->get_allowed_card_brands();

		// Nothing restricted, so skip the work entirely and keep checkout free of an extra API call.
		if ( empty( $allowed ) || ! array_diff( $this->get_card_brand_slugs(), $allowed ) ) {
			return '';
		}

		/*
		 * A saved card posts its token instead of a new source. The token already records the brand
		 * Stripe reported when it was created, so it is checked locally with no API call. Resolved
		 * through find_saved_token() so this shares the gateway's ownership guard, and so validation
		 * always inspects the same token that process_payment() will charge.
		 */
		$token = $this->find_saved_token();

		if ( $token instanceof \WC_Payment_Token_CC ) {
			return $this->evaluate_card_brand( $token->get_card_type(), $allowed, 'token ' . $token->get_id(), 'none' );
		}

		if ( $token instanceof \WC_Payment_Token ) {
			return ''; // A saved non card token, such as ACH or SEPA, carries no brand to match.
		}

		$source = isset( $_POST['fkwcs_source'] ) ? wc_clean( wp_unslash( $_POST['fkwcs_source'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Reading the posted payment method during checkout validation

		if ( '' === $source || 0 !== strpos( $source, 'pm_' ) ) {
			return '';
		}

		/*
		 * Shares the request scoped cache with prepare_source(), which retrieves the same payment
		 * method moments later to build the charge. Without that the brand check would add a
		 * second Stripe round trip to every card checkout on a store that restricts brands.
		 */
		$payment_method = $this->retrieve_payment_method( $source );
		if ( ! $payment_method ) {
			return ''; // Do not block on an API failure, let Stripe decide.
		}

		/*
		 * Only card type payment methods carry a brand. Amazon Pay is not a card network at all, and
		 * Link on the default integration is saved as a "link" payment method that hides the funding
		 * card, so neither can be matched against this setting. Link on the "Link within card"
		 * integration does arrive as a card with a real brand and is checked like any other.
		 */
		if ( ! isset( $payment_method->type ) || 'card' !== $payment_method->type || empty( $payment_method->card->brand ) ) {
			return '';
		}

		return $this->evaluate_card_brand( $payment_method->card->brand, $allowed, $source, empty( $payment_method->card->wallet->type ) ? 'none' : $payment_method->card->wallet->type );
	}

	/**
	 * Compare one brand against the allowed list, logging the rejection.
	 *
	 * @param string $brand     Brand as reported by Stripe or stored on the token.
	 * @param array  $allowed   Allowed brand slugs.
	 * @param string $reference Source or token id, for the log line.
	 * @param string $wallet    Wallet type, for the log line.
	 *
	 * @return string Brand when rejected, '' when accepted.
	 */
	protected function evaluate_card_brand( $brand, $allowed, $reference, $wallet ) {
		$brand = $this->normalize_card_brand( $brand );

		// Stripe sends 'unknown' when it cannot classify the card, which no setting can list.
		if ( '' === $brand || 'unknown' === $brand || in_array( $brand, $allowed, true ) ) {
			return '';
		}

		/*
		 * Stripe also reports brands this gateway never offered as a checkbox: 'eftpos_au' and
		 * 'link' are both in its enum today and the list grows over time. The merchant has no way
		 * to allow those, so rejecting one is a decline they cannot undo, delivered as unusable
		 * copy ("We do not accept Eftpos_au"). Only enforce against brands the setting lists.
		 */
		if ( ! in_array( $brand, $this->get_card_brand_slugs(), true ) ) {
			Helper::log( sprintf( 'Card brand %1$s is not offered by the Allowed Card Brands setting, allowing it through. Payment method %2$s, wallet %3$s.', $brand, $reference, $wallet ) );

			return '';
		}

		/**
		 * Filters whether a rejected brand is actually enforced.
		 *
		 * Runs only when a payment is about to be rejected, so the context always describes a real
		 * decision. Return false to let the payment through. The wallet key is the reliable way to
		 * target express payments, because Apple Pay and Google Pay usually submit through the card
		 * gateway rather than their own gateway id.
		 *
		 * Example, keep enforcing typed cards but leave the wallets alone:
		 *
		 *     add_filter( 'fkwcs_enforce_allowed_card_brands', function ( $enforce, $context ) {
		 *         return in_array( $context['wallet'], array( 'apple_pay', 'google_pay' ), true ) ? false : $enforce;
		 *     }, 10, 2 );
		 *
		 * @since 1.15.0
		 *
		 * @param bool  $enforce True to reject the payment.
		 * @param array $context {
		 *     @type string $brand     Normalized brand slug, e.g. 'amex'.
		 *     @type string $wallet    Wallet type: 'apple_pay', 'google_pay', 'link' or 'none'.
		 *     @type array  $allowed   Allowed brand slugs.
		 *     @type string $gateway   Gateway id handling the payment.
		 *     @type string $reference Payment method id, or 'token <id>' for a saved card.
		 * }
		 */
		$enforce = apply_filters(
			'fkwcs_enforce_allowed_card_brands',
			true,
			array(
				'brand'     => $brand,
				'wallet'    => $wallet,
				'allowed'   => $allowed,
				'gateway'   => $this->id,
				'reference' => $reference,
			)
		);

		if ( ! $enforce ) {
			Helper::log( sprintf( 'Card brand %1$s is not allowed but was let through by fkwcs_enforce_allowed_card_brands. Wallet %2$s.', $brand, $wallet ) );

			return '';
		}

		Helper::log(
			sprintf(
				'Card brand %1$s rejected, not in Allowed Card Brands (%2$s). Payment method %3$s, wallet %4$s.',
				$brand,
				implode( ', ', $allowed ),
				$reference,
				$wallet
			)
		);

		return $brand;
	}

	/**
	 * Enforce Allowed Card Brands server side.
	 *
	 * WooCommerce calls this before the order exists, from the checkout, the pay for order page and
	 * the add payment method page. Apple Pay and Google Pay extend this gateway and their express
	 * requests run through the same checkout, so this one check covers every card path. Until now
	 * the setting was only applied by the card field's JS, which wallets never touch.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$brand = $this->get_disallowed_card_brand();

		if ( '' === $brand ) {
			return true;
		}

		$brands = self::get_card_brands();
		$label  = isset( $brands[ $brand ] ) ? $brands[ $brand ] : ucfirst( $brand );

		/* translators: %s: card brand name, for example American Express. */
		wc_add_notice( sprintf( __( 'We do not accept %s. Please use a different card.', 'funnelkit-stripe-woo-payment-gateway' ), $label ), 'error' );

		return false;
	}

	/**
	 * Process WooCommerce checkout payment
	 *
	 * @param $order_id Int Order ID
	 * @param $retry  Boolean
	 * @param $force_prevent_source_creation  Boolean
	 * @param $previous_error
	 * @param $use_order_source
	 *
	 * @return array|mixed|string[]|\WP_Error|null
	 * @throws \Exception
	 */
	public function process_payment( $order_id, $retry = true, $force_prevent_source_creation = false, $previous_error = false, $use_order_source = false ) {
		do_action( 'fkwcs_before_process_payment', $order_id );
		Helper::log( 'Entering::' . __FUNCTION__ );

		if ( $this->maybe_change_subscription_payment_method( $order_id ) ) {
			return $this->process_change_subscription_payment_method( $order_id, true );
		}

		if ( $this->maybe_process_pre_orders( $order_id ) ) {
			return $this->process_pre_order( $order_id );
		}
		$order = wc_get_order( $order_id );

		$should_save_card      = false === $force_prevent_source_creation && true === $this->should_save_card( $order );
		$attach_payment_method = false; // Controls upfront card attachment
		$setup_future_usage    = false; // Controls setup_future_usage parameter

		if ( $should_save_card ) {
			/**
			 * Do not attach the payment method before the Payment Intent exists.
			 *
			 * Attaching upfront makes SCA-regulated (EU/UK) cards reject the bare
			 * PaymentMethod.attach with a 402 authentication_required, and because no
			 * Payment Intent (and therefore no client_secret) exists yet, the 3DS
			 * challenge can never be presented. Instead we always rely on
			 * setup_future_usage = 'off_session' so a single Payment Intent runs 3DS
			 * via the existing requires_action continuation and saves the card after
			 * authentication succeeds. This matches the existing Indian/RBI flow.
			 */
			$attach_payment_method = false;
			$setup_future_usage    = true;
		}

		if ( 0 >= $order->get_total() ) {
			return $this->process_change_subscription_payment_method( $order_id );

		}

		if ( $this->is_using_saved_payment_method() ) {
			return $this->process_payment_using_saved_token( $order_id );
		}

		try {
			if ( $use_order_source ) {
				/**
				 * Process subscription renewals
				 */
				$prepared_source = $this->prepare_order_source( $order );
			} else {
				$prepared_source = $this->prepare_source( $order, $attach_payment_method );
			}

			if ( is_object( $prepared_source ) && empty( $prepared_source->source ) ) {
				if ( ! empty( $order ) ) {
					/* translators: error message */
					$this->mark_order_failed( $order, __( 'Error: Unable to get payment method from the browser, please check for browser console error. ', 'funnelkit-stripe-woo-payment-gateway' ) );
					throw new \Exception( __( 'Payment processing failed. Please retry.', 'funnelkit-stripe-woo-payment-gateway' ), 200 );
				}
			}
			$this->save_payment_method_to_order( $order, $prepared_source );

			$this->validate_minimum_order_amount( $order );

			/**
			 * Prepare Data for the API Call
			 */
			$data = array(
				'amount'               => Helper::get_stripe_amount( $order->get_total() ),
				'currency'             => get_woocommerce_currency(),
				'description'          => $this->get_order_description( $order ),
				'payment_method_types' => $this->get_payment_method_types(),
				'payment_method'       => $prepared_source->source,
				'customer'             => $prepared_source->customer,
				'capture_method'       => $this->get_effective_capture_method(),
				'confirm'              => true,
			);

			if ( Helper::should_customize_statement_descriptor() ) {
				$data['statement_descriptor_suffix'] = $this->clean_statement_descriptor( Helper::get_gateway_descriptor_suffix( $order ) );
			}
			if ( $setup_future_usage ) {
				$data['setup_future_usage'] = 'off_session';
			}

			$data['metadata'] = $this->add_metadata( $order );
			$amount_data      = $this->add_amount_details( $order, $data['payment_method_types'][0] ?? 'card' );
			if ( ! empty( $amount_data ) ) {
				$data = array_merge( $data, $amount_data );
			}
			if ( ! isset( $_POST['payment_request_type'] ) ) {//phpcs:ignore WordPress.Security.NonceVerification.Missing , FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing
				$data = $this->set_shipping_data( $data, $order );

			}
			$data = $this->maybe_mandate_data_required( $data, $order );

			$intent_data = $this->make_payment( $order, $prepared_source, $data );

			if ( ! empty( $intent_data ) ) {

				/**
				 * Order Pay page processing
				 */
				if ( did_action( 'woocommerce_before_pay_action' ) ) {

					if ( 'requires_action' === $intent_data->status || 'authentication_required' === $intent_data->status ) {
						$return_url = $this->get_return_url( $order );

						return apply_filters(
							'fkwcs_card_payment_return_intent_data',
							array(
								'result'              => 'success',
								'fkwcs_redirect'      => $return_url,
								'payment_method'      => $prepared_source->source,
								'fkwcs_intent_secret' => $intent_data->client_secret,
							)
						);

					} else {
						$return_url = $this->process_final_order( end( $intent_data->charges->data ), $order );
					}

					return apply_filters(
						'fkwcs_card_payment_return_intent_data',
						array(
							'result'   => 'success',
							'redirect' => $return_url,
						)
					);
				}

				if ( 'succeeded' === $intent_data->status || 'requires_capture' === $intent_data->status ) {

					/**
					 * Save payment method if:
					 * 1. The card was attached upfront ($attach_payment_method = true), or
					 * 2. setup_future_usage = 'off_session' was set on the intent (current default
					 *    for all save-card flows; Stripe attaches the card on intent confirmation).
					 */
					if ( $attach_payment_method || 'off_session' === $intent_data->setup_future_usage ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$this->save_payment_method( $order, $intent_data );

						$charge = $this->get_latest_charge_from_intent( $intent_data );
						if ( isset( $charge->payment_method_details->card->mandate ) ) {
							$mandate_id = $charge->payment_method_details->card->mandate;

						}

						if ( isset( $mandate_id ) && ! empty( $mandate_id ) ) {
							$order->update_meta_data( '_stripe_mandate_id', $mandate_id );
							$order->save_meta_data();
						}
					}
					$redirect_url = $this->process_final_order( end( $intent_data->charges->data ), $order_id );
					Helper::log( 'Redirect URL for ' . $order->get_id() . ' is ' . $redirect_url );

					return array(
						'result'   => 'success',
						'redirect' => $redirect_url,
					);
				} elseif ( 'processing' === $intent_data->status ) {
					/**
					 * Handle processing status for credit card - mark as failed
					 * Credit card payments should not have processing status, this indicates an issue
					 */
					Helper::log( 'Credit card payment intent returned processing status for order ' . $order->get_id() . ' - marking as failed' );
					throw new \Exception( __( 'Payment is still processing. Please try again or use an alternative payment method.', 'funnelkit-stripe-woo-payment-gateway' ), 200 );
				} elseif ( 'requires_payment_method' === $intent_data->status ) {

					if ( ! $order->has_status( 'failed' ) ) {
						// Load the right message and update the status.
						$status_message = isset( $intent_data->last_payment_error ) /* translators: 1) The error message that was received from Stripe. */ ? sprintf( __( 'Stripe SCA authentication failed. Reason: %s', 'funnelkit-stripe-woo-payment-gateway' ), $intent_data->last_payment_error->message ) : __( 'Stripe SCA authentication failed.', 'funnelkit-stripe-woo-payment-gateway' );
						throw new \Exception( $status_message, 200 );

					}
				} elseif ( 'requires_action' === $intent_data->status || 'authentication_required' === $intent_data->status ) {
					// Handle 3DS authentication flow
					$return_url = $this->get_return_url( $order );

					return apply_filters(
						'fkwcs_card_payment_return_intent_data',
						array(
							'result'              => 'success',
							'fkwcs_redirect'      => $return_url,
							'payment_method'      => $prepared_source->source,
							'fkwcs_intent_secret' => $intent_data->client_secret,
							'save_card'           => $attach_payment_method,
						)
					);
				}

				/**
				 * @see modify_successful_payment_result()
				 * This modifies the final response return in WooCommerce process checkout request
				 */
				$return_url = $this->get_return_url( $order );

				return apply_filters(
					'fkwcs_card_payment_return_intent_data',
					array(
						'result'              => 'success',
						'fkwcs_redirect'      => $return_url,
						'payment_method'      => $prepared_source->source,
						'fkwcs_intent_secret' => $intent_data->client_secret,
						'save_card'           => $setup_future_usage,
					)
				);
			} else {
				return array(
					'result'   => 'fail',
					'redirect' => '',
				);
			}
		} catch ( \Exception $e ) {
			// Check if there could be a retry without tokenization
			if ( $this->should_retry_without_tokenization( $e, $order ) ) {

				$this->is_recursion = true;

				Helper::log( 'Card does not support this type of purchase. Retrying payment without saving source.' );

				return $this->process_payment( $order_id, $retry, true, $e->getMessage(), $use_order_source );
			}

			if ( ! empty( $order ) ) {
				$this->mark_order_failed( $order, $e->getMessage() );

				if ( ! empty( $intent_data ) ) {
					$charge = $this->get_latest_charge_from_intent( $intent_data );
					do_action( 'fkwcs_process_response', $charge, $order );
				}
			}

			Helper::log( $e->getMessage() );

			throw new \Exception( $e->getMessage(), 200 ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Determines if a retry without tokenization should be attempted.
	 *
	 * This method checks if the current payment attempt is not recursive,
	 * the order does not have a subscription, and the exception message
	 * indicates that the card does not support the type of purchase.
	 *
	 * @param \Exception $e The exception thrown during the payment process.
	 * @param \WC_Order  $order The WooCommerce order object.
	 *
	 * @return bool True if a retry without tokenization should be attempted, false otherwise.
	 */
	public function should_retry_without_tokenization( $e, $order ) {
		return apply_filters( 'fkwcs_should_retry_without_tokenization', ( $this->is_recursion === false && ! $this->has_subscription( $order->get_id() ) && strpos( $e->getMessage(), 'Your card does not support this type of purchase.' ) !== false ) );
	}

	/**
	 * Process Order payment using existing customer token saved.
	 *
	 * @param $order_id Int Order ID
	 *
	 * @return array|mixed|string[]|null
	 */
	public function process_payment_using_saved_token( $order_id ) {
		$order = wc_get_order( $order_id );

		try {
			$token = $this->find_saved_token(); //phpcs:ignore WordPress.Security.NonceVerification.Missing

			/**
			 * Guard against a missing / non-owned token before dereferencing it.
			 *
			 * The find_saved_token() call returns null when the token id is invalid or
			 * does not belong to the expected user; without this check $token->get_token()
			 * would raise a fatal Error (uncaught by the Exception handler below).
			 */
			if ( ! $token instanceof \WC_Payment_Token ) {
				$this->mark_order_failed( $order, __( 'The selected saved card could not be found for this order. Please try a different payment method.', 'funnelkit-stripe-woo-payment-gateway' ) );

				return array(
					'result'   => 'fail',
					'redirect' => '',
				);
			}

			$stripe_api              = $this->get_client();
			$response                = $stripe_api->payment_methods( 'retrieve', array( $token->get_token() ) );
			$payment_method          = $response['success'] ? $response['data'] : false;
			$prepared_payment_method = Helper::prepare_payment_method( $payment_method, $token );
			$this->save_payment_method_to_order( $order, $prepared_payment_method );
			$return_url = $this->get_return_url( $order );
			/* translators: %1$1s order id, %2$2s order total amount  */
			Helper::log( sprintf( 'Begin processing payment with saved payment method for order %1$1s for the amount of %2$2s', $order_id, $order->get_total() ) );

			if ( empty( $prepared_payment_method->source ) ) {
				throw new \Exception( __( 'We are unable to process payments using the selected method. Please choose a different payment method.', 'funnelkit-stripe-woo-payment-gateway' ) );
			}

			$request = array(
				'payment_method'       => $prepared_payment_method->source,
				'payment_method_types' => $this->get_payment_method_types(),
				'amount'               => Helper::get_stripe_amount( $order->get_total() ),
				'currency'             => strtolower( $order->get_currency() ),
				'description'          => $this->get_order_description( $order ),
				'customer'             => $prepared_payment_method->customer,
				'confirm'              => true,
				'capture_method'       => $this->get_effective_capture_method(),
			);
			if ( Helper::should_customize_statement_descriptor() ) {
				$request['statement_descriptor_suffix'] = $this->clean_statement_descriptor( Helper::get_gateway_descriptor_suffix( $order ) );
			}
			$request['metadata'] = $this->add_metadata( $order );
			$amount_data         = $this->add_amount_details( $order, $request['payment_method_types'][0] ?? 'card' );
			if ( ! empty( $amount_data ) ) {
				$request = array_merge( $request, $amount_data );
			}
			$request = $this->set_shipping_data( $request, $order );

			$this->validate_minimum_order_amount( $order );
			$request = apply_filters( 'fkwcs_payment_intent_data', $request, $order );
			unset( $request['application_fee_amount'], $request['application_fee'], $request['transfer_data'], $request['on_behalf_of'] );
			$intent = $this->make_payment_by_source( $order, $prepared_payment_method, $request );

			$this->save_intent_to_order( $order, $intent );

			if ( 'requires_confirmation' === $intent->status || 'requires_action' === $intent->status || 'authentication_required' === $intent->status ) {
				return apply_filters(
					'fkwcs_card_payment_return_intent_data',
					array(
						'result'              => 'success',
						'token'               => 'yes',
						'fkwcs_redirect'      => $return_url,
						'payment_method'      => $intent->id,
						'fkwcs_intent_secret' => $intent->client_secret,
						'token_used'          => 'yes',
					)
				);
			}

			if ( $intent->amount > 0 ) {
				/** Use the last charge within the intent to proceed */
				$return_url = $this->process_final_order( end( $intent->charges->data ), $order );
			} else {
				$order->payment_complete();
			}

			/** Empty cart */
			if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
				WC()->cart->empty_cart();
			}

			/** Return thank you page redirect URL */
			return array(
				'result'   => 'success',
				'redirect' => $return_url,
			);

		} catch ( \Exception $e ) {
			Helper::log( $e->getMessage(), 'warning' );
			wc_add_notice( $e->getMessage(), 'error' );

			/* translators: error message */
			$this->mark_order_failed( $order, $e->getMessage() );
			if ( ! empty( $intent ) ) {
				$charge = $this->get_latest_charge_from_intent( $intent );
				do_action( 'fkwcs_process_response', $charge, $order );

			}

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}
	}

	/**
	 * After verify intent got called it's time to save payment method to the order
	 *
	 * @param $order
	 * @param $intent
	 *
	 * @return void
	 */
	public function save_payment_method( $order, $intent ) {
		$payment_method = $intent->payment_method;
		$response       = $this->get_client()->payment_methods( 'retrieve', array( $payment_method ) );
		$payment_method = $response['success'] ? $response['data'] : false;
		$token          = null;

		if ( 'link' !== $payment_method->type ) {
			// Do not create token for link
			$user = $order->get_id() ? $order->get_user() : wp_get_current_user();
			if ( $user instanceof \WP_User ) {
				$user_id = $user->ID;
				$token   = Helper::create_payment_token_for_user( $user_id, $payment_method, $this->id, $intent->livemode );
				Helper::log( sprintf( 'Payment method tokenized for Order id - %1$1s with token id - %2$2s', $order->get_id(), $token->get_id() ) );
				delete_transient( 'fkwcs_user_tokens_' . $user_id );
			}
		}

		$prepared_payment_method = Helper::prepare_payment_method( $payment_method, $token );

		$this->save_payment_method_to_order( $order, $prepared_payment_method );
	}

	/**
	 * Save Metadata Like Balance Charge ID & status
	 * Add respective  order notes according to stripe charge status
	 *
	 * @param $response
	 * @param $order_id Int Order ID
	 *
	 * @return string
	 */
	public function process_final_order( $response, $order_id ) {
		$order = wc_get_order( $order_id );
		if ( isset( $response->balance_transaction ) ) {
			Helper::update_balance( $order, $response->balance_transaction );
		}
		// Set wallet payment method title early so it shows correctly when order is in progress (authorized/on-hold)
		if ( ( property_exists( $response->payment_method_details, 'card' ) || isset( $response->payment_method_details->card ) ) && ( property_exists( $response->payment_method_details->card, 'wallet' ) || isset( $response->payment_method_details->card->wallet ) ) ) {
			if ( 'google_pay' === $response->payment_method_details->card->wallet->type ) {
				$gateway = WC()->payment_gateways()->payment_gateways()['fkwcs_stripe_google_pay'];
				$order->set_payment_method_title( $gateway->get_title() );
				$order->save();
			} elseif ( 'apple_pay' === $response->payment_method_details->card->wallet->type ) {
				$gateway = WC()->payment_gateways()->payment_gateways()['fkwcs_stripe_apple_pay'];
				$order->set_payment_method_title( $gateway->get_title() );
				$order->save();
			}
		}
		if ( wc_string_to_bool( $response->captured ) ) {
			$order->payment_complete( $response->id );
			$ifpe = ( 'payment' === $this->credit_card_form_type ) ? ' (PE)' : '';
			/* translators: order id */
			Helper::log( sprintf( 'Payment successful Order id - %1s', $order->get_id() ) );

			/* translators: 1: Charge ID. 2: Brand name 3: last four digit */

			if ( property_exists( $response->payment_method_details, 'link' ) || isset( $response->payment_method_details->link ) ) {
				/* translators: 1: Additional info, 2: Charge ID, 3: Payment method */
				$order->add_order_note( sprintf( __( 'Order charge successful in Stripe%1$s. Charge: %2$s. Payment method: %3$s', 'funnelkit-stripe-woo-payment-gateway' ), $ifpe, $response->id, 'link' ) );
				/* translators: 1: Additional info, 2: Charge ID, 3: Payment method */
				Helper::log( sprintf( __( 'Order charge successful in Stripe%1$s. Charge: %2$s. Payment method: %3$s', 'funnelkit-stripe-woo-payment-gateway' ), $ifpe, $response->id, 'link' ) );

			}
			if ( property_exists( $response->payment_method_details, 'card' ) || isset( $response->payment_method_details->card ) ) {
				/* translators: 1: Additional info, 2: Charge ID, 3: Card brand, 4: Last 4 digits */
				$order->add_order_note( sprintf( __( 'Order charge successful in Stripe%1$s. Charge: %2$s. Payment method: %3$s ending in %4$s', 'funnelkit-stripe-woo-payment-gateway' ), $ifpe, $response->id, ucfirst( $response->payment_method_details->card->brand ), $response->payment_method_details->card->last4 ) );
				/* translators: 1: Additional info, 2: Charge ID, 3: Card brand, 4: Last 4 digits */
				Helper::log( sprintf( __( 'Order charge successful in Stripe%1$s. Charge: %2$s. Payment method: %3$s ending in %4$s', 'funnelkit-stripe-woo-payment-gateway' ), $ifpe, $response->id, ucfirst( $response->payment_method_details->card->brand ), $response->payment_method_details->card->last4 ) );

				if ( property_exists( $response->payment_method_details->card, 'wallet' ) || isset( $response->payment_method_details->card->wallet ) ) {
					$wallet_name = ( 'google_pay' === $response->payment_method_details->card->wallet->type ) ? 'Google Pay' : ( $response->payment_method_details->card->wallet->type === 'apple_pay' ? 'Apple Pay' : $response->payment_method_details->card->wallet->type );
					/* translators: %s: Wallet name */
					$order->add_order_note( sprintf( __( 'Wallet Used %s', 'funnelkit-stripe-woo-payment-gateway' ), $wallet_name ) );
					do_action( 'fkwcs_process_final_order_wallet_payment', $response, $order );

				}
			}

			/**
			 * Remove webhook paid meta-data if order is paid from same IP
			 */
			if ( $order->get_customer_ip_address() === \WC_Geolocation::get_ip_address() ) {
				$order->delete_meta_data( '_fkwcs_webhook_paid' );
				$order->save_meta_data();
			}
		} else {
			$order->set_transaction_id( $response->id );
			$order->save();
			/* translators: transaction id */
			$order->update_status( 'on-hold', sprintf( __( 'Charge authorized (Charge ID: %s). Move this order to Processing or Completed to capture the payment, or use the eye icon next to Transaction Data to capture/void it manually.', 'funnelkit-stripe-woo-payment-gateway' ), $response->id ) );
			/* translators: transaction id */
			Helper::log( sprintf( 'Charge authorized Order id - %1s', $order->get_id() ) );
		}

		/** Empty cart */
		if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
			WC()->cart->empty_cart();
		}

		do_action( 'fkwcs_process_response', $response, $order );

		$return_url = $this->get_return_url( $order );

		return $return_url;
	}

	/**
	 * Look for saved token
	 *
	 * @return \WC_Payment_Token|null
	 */
	public function find_saved_token() {
		$payment_method = isset( $_POST['payment_method'] ) && ! is_null( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : null; //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing

		$token_request_key = 'wc-' . $payment_method . '-payment-token';

		if ( ! isset( $_POST[ $token_request_key ] ) || 'new' === wc_clean( wp_unslash( $_POST[ $token_request_key ] ) ) ) {  //phpcs:ignore WordPress.Security.NonceVerification.Missing , FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing

			return null;
		}

		$token = WC_Payment_Tokens::get( wc_clean( wp_unslash( $_POST[ $token_request_key ] ) ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing

		/**
		 * Filters the user the saved token must belong to.
		 *
		 * Defaults to the currently logged-in user, so the storefront IDOR guard is
		 * byte-identical to before. Capability-gated admin flows (e.g. the Edit Order
		 * "Pay for Order" screen) bind this to the order's customer instead, because
		 * the admin is not the token owner.
		 *
		 * @since 1.14.1
		 *
		 * @param int $expected_user_id User ID the saved token must belong to. Default current user.
		 */
		$expected_user_id = apply_filters( 'fkwcs_saved_token_expected_user_id', get_current_user_id() );

		if ( ! $token || (int) $token->get_user_id() !== (int) $expected_user_id ) {
			return null;
		}

		return $token;
	}

	public function is_using_saved_payment_method() {
		$payment_method = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : $this->id; //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing

		return ( isset( $_POST[ 'wc-' . $payment_method . '-payment-token' ] ) && 'new' !== wc_clean( wp_unslash( $_POST[ 'wc-' . $payment_method . '-payment-token' ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing
	}

	/**
	 * Print the Credit card field
	 *
	 * @return void
	 */
	public function payment_fields() {
		do_action( $this->id . '_before_payment_field_checkout' );
		include __DIR__ . '/parts/credit-card.php';
		do_action( $this->id . '_after_payment_field_checkout' );
	}

	/**
	 * Add the payment method to the customer account
	 *
	 * @return array|void
	 */
	public function add_payment_method() {
		$source_id = '';

		if ( empty( $_POST['fkwcs_source'] ) || ! is_user_logged_in() ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment method addition
			//phpcs:ignore WordPress.Security.NonceVerification.Missing
			$error_msg = __( 'There was a problem adding the payment method.', 'funnelkit-stripe-woo-payment-gateway' );
			/* translators: error msg */
			Helper::log( sprintf( 'Add payment method Error: %1$1s', $error_msg ) );

			return;
		}

		$customer_id = $this->get_customer_id();

		$source        = wc_clean( wp_unslash( $_POST['fkwcs_source'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment method addition
		$stripe_api    = $this->get_client();
		$response      = $stripe_api->payment_methods( 'retrieve', array( $source ) );
		$source_object = $response['success'] ? $response['data'] : false;

		if ( isset( $source_object ) ) {
			if ( ! empty( $source_object->error ) ) {
				$error_msg = __( 'Invalid stripe source', 'funnelkit-stripe-woo-payment-gateway' );
				wc_add_notice( $error_msg, 'error' );
				/* translators: error msg */
				Helper::log( sprintf( 'Add payment method Error: %1$1s', $error_msg ) );

				return;
			}

			$source_id = $source_object->id;
		}

		$response = $stripe_api->payment_methods( 'attach', array( $source_id, array( 'customer' => $customer_id ) ) );
		$response = $response['success'] ? $response['data'] : false;

		if ( ! $response || is_wp_error( $response ) || ! empty( $response->error ) ) {
			$error_msg = __( 'Unable to attach payment method to customer', 'funnelkit-stripe-woo-payment-gateway' );
			wc_add_notice( $error_msg, 'error' );
			/* translators: error msg */
			Helper::log( sprintf( 'Add payment method Error: %1$1s', $error_msg ) );

			return;
		}

		$user    = wp_get_current_user();
		$user_id = ( $user->ID && $user->ID > 0 ) ? $user->ID : false;
		$is_live = ( 'live' === $this->test_mode ) ? true : false;
		if ( 'link' !== $source_object->type ) {
			Helper::create_payment_token_for_user( $user_id, $source_object, $this->id, $is_live );
		}
		delete_transient( 'fkwcs_user_tokens_' . $user_id );

		do_action( 'fkwcs_add_payment_method_' . ( isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : '' ) . '_success', $source_id, $source_object ); //phpcs:ignore WordPress.Security.NonceVerification.Missing, FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment method addition

		Helper::log( 'New payment method added successfully' );

		return array(
			'result'   => 'success',
			'redirect' => wc_get_endpoint_url( 'payment-methods' ),
		);
	}

	/**
	 * Get stripe activated payment cards icon.
	 */
	public function get_icon() {
		if ( empty( $this->allowed_cards ) ) {
			return '';
		}
		$ext   = version_compare( WC()->version, '2.6', '>=' ) ? '.svg' : '.png';
		$style = version_compare( WC()->version, '2.6', '>=' ) ? 'style="margin-left: 0.3em"' : '';
		$icons = '<span class="fkwcs_stripe_icons">';

		if ( ( in_array( 'visa', $this->allowed_cards, true ) ) || ( in_array( 'Visa', $this->allowed_cards, true ) ) ) {
			$icons .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/visa' . $ext ) . '" alt="Visa" width="32" title="VISA" ' . $style . ' />';
		}
		if ( ( in_array( 'mastercard', $this->allowed_cards, true ) ) || ( in_array( 'MasterCard', $this->allowed_cards, true ) ) ) {
			$icons .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/mastercard' . $ext ) . '" alt="Mastercard" width="32" title="Master Card" ' . $style . ' />';
		}
		if ( ( in_array( 'amex', $this->allowed_cards, true ) ) || ( in_array( 'American Express', $this->allowed_cards, true ) ) ) {
			$icons .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/amex' . $ext ) . '" alt="Amex" width="32" title="American Express" ' . $style . ' />';
		}
		if ( ( in_array( 'discover', $this->allowed_cards, true ) ) || ( in_array( 'Discover', $this->allowed_cards, true ) ) ) {
			$icons .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/discover' . $ext ) . '" alt="Discover" width="32" title="Discover" ' . $style . ' />';
		}
		if ( ( in_array( 'jcb', $this->allowed_cards, true ) ) || ( in_array( 'JCB', $this->allowed_cards, true ) ) ) {
			$icons .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/jcb' . $ext ) . '" alt="JCB" width="32" title="JCB" ' . $style . ' />';
		}
		if ( ( in_array( 'diners', $this->allowed_cards, true ) ) || ( in_array( 'Diners Club', $this->allowed_cards, true ) ) ) {
			$icons .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/diners' . $ext ) . '" alt="Diners" width="32" title="Diners Club" ' . $style . ' />';
		}
		if ( ( in_array( 'unionpay', $this->allowed_cards, true ) ) || ( in_array( 'Union Pay', $this->allowed_cards, true ) ) ) {
			$icons_path = FKWCS_URL . 'assets/icons/';
			$icons     .= '<img src="' . WC_HTTPS::force_https_url( $icons_path . 'unionpay' . $ext ) . '" alt="Diners" width="32" title="Union Pay" ' . $style . ' />';
		}

		$icons .= '</span>';

		return apply_filters( 'woocommerce_gateway_icon', $icons, $this->id );
	}

	/**
	 * Get test mode description
	 *
	 * @return string
	 */
	public function get_test_mode_description() {
		/* translators: 1: Opening bold tag, 2: Closing bold tag, 3: Opening anchor tag, 4: Closing anchor tag */
		return sprintf( esc_html__( '%1$1s Test Mode Enabled:%2$2s Use demo card 4242424242424242 with any future date and CVV. Check more %3$3sdemo cards%4$4s', 'funnelkit-stripe-woo-payment-gateway' ), '<b>', '</b>', "<a href='https://stripe.com/docs/testing' target='_blank'>", '</a>' );
	}

	public function localize_element_data( $data ) {
		$data['inline_cc']            = $this->inline_cc;
		$data['card_form_type']       = $this->credit_card_form_type;
		$data['enable_saved_cards']   = $this->enable_saved_cards;
		$data['card_element_options'] = apply_filters(
			'fkwcs_card_element_options',
			array(
				'disableLink' => false,
				'showIcon'    => true,
				'iconStyle'   => 'solid',
			)
		);
		$data['allowed_cards']        = $this->allowed_cards;
		if ( 'payment' === $this->credit_card_form_type ) {
			$data['fkwcs_payment_data'] = $this->payment_element_data();
		}
		$data['country_code'] = substr( get_option( 'woocommerce_default_country' ), 0, 2 );

		return $data;
	}

	public function send_payment_options( $fragments ) {
		if ( 'payment' === $this->credit_card_form_type ) {
			$fragments['fkwcs_payment_data'] = $this->payment_element_data();
		}
		if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
			$fragments['fkwcs_cart_total'] = WC()->cart->get_total( 'edit' );
		}

		return $fragments;
	}

	public function payment_element_data() {

		$data = $this->get_payment_element_options();

		$data['appearance'] = array(
			'theme' => 'stripe',
		);

		$options = array(
			'fields' => array(
				'billingDetails' => ( true === is_wc_endpoint_url( 'order-pay' ) || true === is_wc_endpoint_url( 'add-payment-method' ) ) ? 'auto' : 'never',
			),
		);

		$is_add_payment_method = is_add_payment_method_page();
		$is_change_payment     = isset( $_GET['change_payment_method'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Processing checkout form data during payment processing

		// Show Link in the card Payment Element only when the Link gateway is enabled and the auth trigger is configured.
		$link_settings        = get_option( 'woocommerce_fkwcs_stripe_link_settings', array() );
		$link_gateway_enabled = isset( $link_settings['enabled'] ) && 'yes' === $link_settings['enabled'];
		$link_auth_trigger    = isset( $link_settings['link_authentication_trigger'] ) ? $link_settings['link_authentication_trigger'] : 'none';
		$link_in_card_element = $link_gateway_enabled && 'none' !== $link_auth_trigger && ! $is_add_payment_method && ! $is_change_payment;

		/*
		 * Default integration: "Link as a payment method" ('link' added to payment_method_types, saved as a
		 * "link" PaymentMethod). wallets.link is the display gate, so it must be 'auto' for Link to render.
		 * To switch a site to the "Link within card" integration (card-type PaymentMethod that keeps brand/
		 * last4), filter 'fkwcs_available_payment_element_types' and remove 'link' from the returned list.
		 */
		$methods           = array( 'card' );
		$link_wallet_state = 'never';

		if ( $link_in_card_element ) {
			$methods           = $this->get_payment_method_types();
			$link_wallet_state = 'auto';
		}

		$data['payment_method_types'] = apply_filters( 'fkwcs_available_payment_element_types', $methods );

		$options['wallets'] = array(
			'applePay'  => 'never',
			'googlePay' => 'never',
			'link'      => $link_wallet_state,
		);

		return apply_filters(
			'fkwcs_stripe_payment_element_data',
			array(
				'element_data'    => $data,
				'element_options' => $options,
			),
			$this
		);
	}

	/**
	 * @param \stdclass $intent
	 * @param \WC_Order $order
	 *
	 * @return void
	 */
	public function handle_webhook_intent_succeeded( $intent, $order ) {

		if ( false === wc_string_to_bool( $this->enabled ) ) {
			return;
		}

		if ( ! $order instanceof \WC_Order || $order->get_payment_method() !== $this->id || $order->is_paid() || ! is_null( $order->get_date_paid() ) || $order->has_status( 'wfocu-pri-order' ) ) {
			return;
		}

		$save_intent = $this->get_intent_from_order( $order );
		if ( empty( $save_intent ) ) {
			Helper::log( 'Could not find intent in the order handle_webhook_intent_succeeded ' . $order->get_id() );

			return;
		}

		if ( class_exists( '\WFOCU_Core' ) ) {
			Helper::log( $order->get_id() . ' :: Saving meta data during webhook to later process this order' );

			$order->update_meta_data( '_fkwcs_webhook_paid', 'yes' );
			$order->save_meta_data();
			$order->save();
		} else {

			try {
				Helper::log( $order->get_id() . ' :: Processing order during webhook' );

				$this->handle_intent_success( $intent, $order );

			} catch ( \Exception $e ) {

			}
		}
	}

	/**
	 * Save payment method to meta of the current order
	 *
	 * @param object $order current WooCommerce order.
	 * @param object $payment_method payment method associated with the current order.
	 *
	 * @return void
	 */
	public function save_payment_method_to_order( $order, $payment_method ) {
		Helper::log( 'Entering::' . __FUNCTION__ );

		if ( ! empty( $payment_method->customer ) ) {
			$order->update_meta_data( Helper::get_customer_key(), $payment_method->customer );
		}

		$order->update_meta_data( '_fkwcs_source_id', $payment_method->source );

		if ( ! empty( $payment_method->token ) ) {
			$order->update_meta_data( '_fkwcs_token_id', $payment_method->token );
			$token_obj = WC_Payment_Tokens::get( $payment_method->token );
			if ( ! is_null( $token_obj ) ) {
				$token_obj->add_meta_data( Helper::get_customer_key(), $payment_method->customer );
				$token_obj->save();
			}
		}
		if ( is_callable( array( $order, 'save' ) ) ) {
			$order->save();
		}

		$this->maybe_update_source_on_subscription_order( $order, $payment_method );
	}

	public function get_link_supported_countries() {
		return 'AE, AT, AU, BE, BG, CA, CH, CY, CZ, DE, DK, EE, ES, FI, FR, GB,GI, GR, HK, HR, HU, IE, IT, JP, LI, LT, LU, LV, MT, MX, MY, NL, NO, NZ, PL, PT, RO, SE, SG, SI, SK, US';
	}

	public function get_payment_method_types() {

		$stripe_account_settings = get_option( 'fkwcs_stripe_account_settings', array() );
		if ( empty( $stripe_account_settings ) ) {

			$client = $this->get_client();
			$args   = $client->accounts( 'retrieve', array( get_option( 'fkwcs_account_id' ) ) );
			if ( ! empty( $args['success'] ) ) {
				$account = $args['data'];

				$stripe_account_settings = array(
					'country'          => $account->country,
					'default_currency' => $account->default_currency,
				);
				update_option( 'fkwcs_stripe_account_settings', $stripe_account_settings );
			}
		}

		if ( ! empty( $stripe_account_settings ) && in_array( $stripe_account_settings['country'], array_map( 'trim', explode( ',', $this->get_link_supported_countries() ) ), true ) ) {
			return array( 'card', 'link' );
		}

		return array( $this->payment_method_types );
	}

	/**
	 * Maybe set tokens for Stripe payment gateway.
	 *
	 * @param array  $tokens The existing payment tokens.
	 * @param int    $user_id The user ID.
	 * @param string $gateway_id The gateway ID.
	 *
	 * @return array The updated payment tokens.
	 */
	public function maybe_sync_gateway_tokens( $tokens, $user_id, $gateway_id ) {
		return $this->sync_gateway_tokens( $tokens, $user_id, $gateway_id );
	}

	/**
	 * Determines if the Stripe payment gateway is available for use.
	 *
	 * This method overrides the parent implementation to add custom logic for
	 * Google Pay and Apple Pay via the Payment Request Button. If the request
	 * is for Google Pay or Apple Pay and the payment method is 'fkwcs_stripe',
	 * and the checkout process has started, the gateway is considered available.
	 * Otherwise, it falls back to the parent availability check.
	 *
	 * @return bool True if the gateway is available, false otherwise.
	 */
	public function is_available() {
		// Check if the request is for Google Pay or Apple Pay via Payment Request Button
		// Also if the gateway is credit card from the js

		if ( $this->is_payment_request_for_supported_method() ) {
			return true;
		}

		return parent::is_available();
	}

	/**
	 * Checks if the current request is for a supported payment method via Payment Request Button.
	 *
	 * 'link' is included alongside the wallets: Link express orders are submitted with
	 * payment_method=fkwcs_stripe just like Google Pay / Apple Pay ECE (Link is not a
	 * registered WC gateway), so without it here a Link express payment dies in
	 * WooCommerce's "Invalid payment method." validation whenever this gateway is disabled.
	 *
	 * @return bool
	 */
	private function is_payment_request_for_supported_method() {
		return isset( $_POST['payment_request_type'], $_POST['payment_method'] ) && in_array( $_POST['payment_request_type'], array( 'google_pay', 'apple_pay', 'link' ), true ) && 'fkwcs_stripe' === $_POST['payment_method'] && did_action( 'woocommerce_before_checkout_process' );         // phpcs:ignore WordPress.Security.NonceVerification.Missing , FKWCS.CodeAnalysis.FKWCSSpecific.MissingCapabilityCheck, FKWCS.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- Processing checkout form data during payment processing
	}

	/**
	 * Resolve the capture method (Charge Type) to use for the current payment.
	 *
	 * Express wallet payments (Apple Pay / Google Pay) are processed through the main
	 * card gateway, but each wallet gateway exposes its own "Charge Type" setting that
	 * the merchant configures independently. Honour that wallet-specific setting so the
	 * authorize/capture behaviour matches what is configured for the wallet instead of
	 * silently falling back to the card gateway's Charge Type.
	 *
	 * @return string 'automatic' or 'manual'.
	 */
	private function get_effective_capture_method() {
		$request_type = wc_clean( (string) filter_input( INPUT_POST, 'payment_request_type' ) );

		$wallet_gateway_ids = array(
			'apple_pay'  => 'fkwcs_stripe_apple_pay',
			'google_pay' => 'fkwcs_stripe_google_pay',
		);

		if ( isset( $wallet_gateway_ids[ $request_type ] ) ) {
			$gateways   = WC()->payment_gateways()->payment_gateways();
			$gateway_id = $wallet_gateway_ids[ $request_type ];
			if ( isset( $gateways[ $gateway_id ] ) && ! empty( $gateways[ $gateway_id ]->capture_method ) ) {
				return $gateways[ $gateway_id ]->capture_method;
			}
		}

		return $this->capture_method;
	}

	/**
	 * Sync gateway tokens from the API, generic method that handled cache detection too
	 * This method will check for existing tokens and then will sync them with the stripe API
	 * Any methods which are not found in the stripe API will be removed from the tokens
	 *
	 * @param $tokens
	 * @param $user_id
	 * @param $gateway_id
	 * @param $skip_cache
	 *
	 * @return mixed
	 */
	public function sync_gateway_tokens( $tokens, $user_id, $gateway_id, $skip_cache = false ) {
		if ( ! $this->is_available() ) {
			Helper::log( 'Gateway is not available.' );

			return $tokens;
		}

		if ( ! empty( $gateway_id ) && ! in_array( $gateway_id, array( 'fkwcs_stripe' ), true ) ) {
			Helper::log( 'Gateway ID not supported: ' . $gateway_id );

			return $tokens;
		}

		$stored_tokens = array();
		if ( ! empty( $tokens ) ) {
			foreach ( $tokens as $token ) {
				if ( method_exists( $token, 'get_token' ) && ! empty( $token->get_token() ) ) {
					$stored_tokens[ $token->get_token() ] = $token;
				}
			}
		}

		try {
			$payment_methods = $this->get_payment_methods_customer( $user_id, $skip_cache );

			remove_action( 'woocommerce_get_customer_payment_tokens', array( $this, 'maybe_sync_gateway_tokens' ), 8 );

			foreach ( $payment_methods as $payment_method ) {
				if ( ! isset( $payment_method->type ) ) {
					continue;
				}

				$token                      = Helper::create_payment_token_for_user( $user_id, $payment_method, $this->id, $payment_method->livemode );
				$tokens[ $token->get_id() ] = $token;

				if ( isset( $stored_tokens[ $token->get_token() ] ) ) {
					unset( $stored_tokens[ $token->get_token() ] );
				}
			}

			if ( ! empty( $tokens ) && ! empty( $gateway_id ) ) {
				foreach ( $tokens as $key => $token ) {
					if ( $this->id !== $token->get_gateway_id() || $this->test_mode !== $token->get_meta( 'mode' ) ) {
						unset( $tokens[ $key ] );
					}
				}

				remove_action( 'woocommerce_payment_token_deleted', array( $this, 'detach_customer_token' ), 10, 2 );

				foreach ( $stored_tokens as $token ) {
					unset( $tokens[ $token->get_id() ] );
					$token->delete();
					Helper::log( 'Deleted stored token ID: ' . $token->get_id() );
				}
				add_action( 'woocommerce_payment_token_deleted', array( $this, 'detach_customer_token' ), 10, 2 );
			}
			add_action( 'woocommerce_get_customer_payment_tokens', array( $this, 'maybe_sync_gateway_tokens' ), 8, 3 );

		} catch ( \Exception | \Error $e ) {
			Helper::log( 'Error: ' . $e->getMessage() );
		} finally {
			add_action( 'woocommerce_payment_token_deleted', array( $this, 'detach_customer_token' ), 10, 2 );
			add_action( 'woocommerce_get_customer_payment_tokens', array( $this, 'maybe_sync_gateway_tokens' ), 8, 3 );
		}

		return $tokens;
	}

	/**
	 * Fetch all user tokens from users account directly from stripe
	 *
	 * @param int $user_id
	 *
	 * @return array|mixed
	 */
	public function get_payment_methods_customer( $user_id, $skip_cache = false ) {
		if ( ! $user_id ) {
			return array();
		}

		if ( $skip_cache ) {
			$payment_methods = false;
		} else {
			$payment_methods = get_transient( 'fkwcs_user_tokens_' . $user_id );

		}
		if ( false === $payment_methods ) {

			$client = $this->get_client();

			$customer = $this->filter_customer_id( get_user_option( '_fkwcs_customer_id', $user_id ) );
			if ( empty( $customer ) ) {
				$compatibility_keys = Helper::get_compatibility_keys( '_fkwcs_customer_id' );
				if ( ! empty( $compatibility_keys ) ) {
					foreach ( $compatibility_keys as $key ) {
						$customer = $this->filter_customer_id( get_user_option( $key, $user_id ) );
						if ( ! empty( $customer ) ) {
							break;
						}
					}
				}

				if ( empty( $customer ) ) {
					return array();
				}
			}
			$response = $client->customers(
				'allPaymentMethods',
				array(
					$customer,
					array(
						'limit' => 100,
						'type'  => 'card',
					),
				)
			);
			if ( ! empty( $response['error'] ) ) {
				return array();
			}
			if ( ! empty( $response['data'] ) ) {
				$payment_methods = $response['data'];
			}
			set_transient( 'fkwcs_user_tokens_' . $user_id, $payment_methods, DAY_IN_SECONDS );
		}

		return empty( $payment_methods ) ? array() : $payment_methods;
	}

	/**
	 * Deletes a token from Stripe.
	 *
	 * @param int               $token_id The WooCommerce token ID.
	 * @param \WC_Payment_Token $token The WC_Payment_Token object.
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 */
	public function detach_customer_token( $token_id, $token ) {
		try {
			if ( 'test' === $this->test_mode && is_admin() && 'production' !== wp_get_environment_type() ) {
				return $token_id;
			}
			$client = $this->get_client();

			$customer = $this->filter_customer_id( get_user_option( '_fkwcs_customer_id', $token->get_user_id() ) );
			if ( empty( $customer ) ) {
				$compatibility_keys = Helper::get_compatibility_keys( '_fkwcs_customer_id' );
				if ( ! empty( $compatibility_keys ) ) {
					foreach ( $compatibility_keys as $key ) {
						$customer = $this->filter_customer_id( get_user_option( $key, $token->get_user_id() ) );
						if ( ! empty( $customer ) ) {
							break;
						}
					}
				}

				if ( empty( $customer ) ) {
					return array();
				}
			}

			if ( empty( $customer ) ) {
				return array();
			}
			$client->payment_methods( 'detach', array( $token->get_token() ) );
			delete_transient( 'fkwcs_user_tokens_' . $token->get_user_id() );
		} catch ( \Exception | \Error $e ) {
			Helper::log( 'Error: ' . $e->getMessage() );
		}
	}

	/**
	 * Set as default in Stripe.
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 */
	public function woocommerce_payment_token_set_default( $token_id ) {

		try {
			$token    = Helper::get_cached_token( $token_id );
			$client   = $this->get_client();
			$customer = $this->filter_customer_id( get_user_option( '_fkwcs_customer_id', get_current_user_id() ) );
			if ( empty( $customer ) ) {
				return array();
			}
			$client->customers( 'update', array( $customer, array( 'invoice_settings' => array( 'default_payment_method' => sanitize_text_field( $token->get_token() ) ) ) ) );
		} catch ( \Exception | \Error $e ) {
			Helper::log( 'Error: ' . $e->getMessage() );
		}
	}

	/**
	 * Override to check if charge was captured before completing payment
	 * CreditCard gateway needs to verify captured status for authorization-only charges
	 *
	 * @param object    $intent The payment intent object
	 * @param \WC_Order $order The order object
	 *
	 * @return bool True if payment should be completed, false otherwise
	 */
	protected function should_complete_payment_on_thankyou( $intent, $order ) {

		// Skip capture check for pre-orders
		if ( $this->has_pre_order( $order->get_id() ) ) {
			Helper::log( 'CreditCard Gateway: Skipping capture check for pre-order ' . $order->get_id() );
			return true;
		}

		// For regular CreditCard orders, check if the charge was actually captured
		$charge = $this->get_latest_charge_from_intent( $intent );

		if ( $charge && wc_string_to_bool( $charge->captured ) ) {
			// Charge was captured, safe to complete payment
			Helper::log( 'CreditCard Gateway: Charge captured, payment completion allowed for order ' . $order->get_id() );

			return true;
		} else {
			// Charge was not captured (authorization only), should be on-hold
			Helper::log( 'CreditCard Gateway: Charge not captured (authorization only), payment completion blocked for order ' . $order->get_id() );

			// Set order to on-hold if not already
			if ( ! $order->has_status( 'on-hold' ) ) {
				$order->set_transaction_id( $intent->id );
				/* translators: %s: Charge ID */
				$order->update_status( 'on-hold', sprintf( __( 'Charge authorized (Charge ID: %s). Move this order to Processing or Completed to capture the payment, or use the eye icon next to Transaction Data to capture/void it manually.', 'funnelkit-stripe-woo-payment-gateway' ), $intent->id ) );
				Helper::log( 'CreditCard Gateway: Order ' . $order->get_id() . ' set to on-hold for authorization-only charge' );
			}

			return false;
		}
	}

	/**
	 * Verify intent secret and redirect to the thankyou page
	 *
	 * @return void
	 */
	public function verify_intent() {
		global $woocommerce;
		wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );

		try {
			$order_id = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order    = wc_get_order( $order_id );

			if ( ! isset( $_GET['order_key'] ) || ! $order instanceof \WC_Order || ! $order->key_is_valid( wc_clean( wp_unslash( $_GET['order_key'] ) ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				throw new \Exception( __( 'Invalid Order Key.', 'funnelkit-stripe-woo-payment-gateway' ) );

			}
		} catch ( \Exception $e ) {
			/* translators: Error message text */
			$message = sprintf( __( 'Payment verification error: %s', 'funnelkit-stripe-woo-payment-gateway' ), $e->getMessage() );
			wc_add_notice( esc_html( $message ), 'error' );
			$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
			$this->handle_error( $e, $redirect_url );
		}

		try {
			$redirect_url = isset( $_GET['fkwcs_redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['fkwcs_redirect_to'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( $order->is_paid() || ! is_null( $order->get_date_paid() ) || ! $order->has_status(
				apply_filters(
					'fkwcs_stripe_allowed_payment_processing_statuses',
					array(
						'pending',
						'failed',
					),
					$order
				)
			) ) {
				$redirect_url = $this->get_return_url( $order );
				remove_all_actions( 'wp_redirect' );
				wp_safe_redirect( $redirect_url );
				exit;

			}

			$intent = $this->get_intent_from_order( $order );
			if ( false === $intent ) {
				throw new \Exception( 'Intent Not Found' );
			}
			if ( isset( $_GET['save_card'] ) || 'off_session' === $intent->setup_future_usage ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->save_payment_method( $order, $intent );

				if ( 'setup_intent' === $intent->object ) {
					$mandate_id = isset( $intent->mandate ) ? $intent->mandate : false;
				} else {
					$charge = $this->get_latest_charge_from_intent( $intent );
					if ( isset( $charge->payment_method_details->card->mandate ) ) {
						$mandate_id = $charge->payment_method_details->card->mandate;

					}
				}

				if ( isset( $mandate_id ) && ! empty( $mandate_id ) ) {
					$order->update_meta_data( '_stripe_mandate_id', $mandate_id );
					$order->save_meta_data();
				}
			}
			if ( 'setup_intent' === $intent->object && 'succeeded' === $intent->status ) {
				// Check if this is a pre-order and mark accordingly
				if ( $this->has_pre_order( $order ) ) {
					$this->mark_order_as_pre_ordered( $order );
				} else {
					$order->payment_complete();
				}
				$redirect_url = $this->get_return_url( $order );

				/**
				 * Remove the webhook paid meta data from the order
				 * This is to avoid any extra processing of this order
				 */
				$order->delete_meta_data( '_fkwcs_webhook_paid' );
				$order->save_meta_data();

				// Remove cart.
				if ( ! is_null( WC()->cart ) && WC()->cart instanceof \WC_Cart ) {
					WC()->cart->empty_cart();
				}
			} elseif ( 'succeeded' === $intent->status || 'requires_capture' === $intent->status ) {
				$redirect_url = $this->process_final_order( end( $intent->charges->data ), $order_id );
			} elseif ( 'processing' === $intent->status ) {
				/**
				 * Handle processing status for credit card - mark as failed
				 * Credit card payments should not have processing status, this indicates an issue
				 */
				$redirect_url = wc_get_checkout_url();
				wc_add_notice( __( 'Payment is still processing. Please try again or use an alternative payment method.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );
				if ( isset( $_GET['wfacp_id'] ) && isset( $_GET['wfacp_is_checkout_override'] ) && 'no' === $_GET['wfacp_is_checkout_override'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$redirect_url = get_the_permalink( wc_clean( wp_unslash( $_GET['wfacp_id'] ) ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
				$this->mark_order_failed( $order, __( 'Payment failed: Unexpected "processing" status from Stripe for credit card payment.', 'funnelkit-stripe-woo-payment-gateway' ) );
				Helper::log( 'Credit card payment intent returned processing status for order ' . $order->get_id() . ' - marking as failed' );
			} elseif ( 'requires_payment_method' === $intent->status ) {

				$redirect_url = wc_get_checkout_url();
				wc_add_notice( __( 'Unable to process this payment, please try again or use alternative method.', 'funnelkit-stripe-woo-payment-gateway' ), 'error' );
				if ( isset( $_GET['wfacp_id'] ) && isset( $_GET['wfacp_is_checkout_override'] ) && 'no' === $_GET['wfacp_is_checkout_override'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$redirect_url = get_the_permalink( wc_clean( wp_unslash( $_GET['wfacp_id'] ) ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
				/**
				 * Handle intent with no payment method here, we mark the order as failed and show users a notice
				 */
				if ( $order->has_status( 'failed' ) ) {
					wp_safe_redirect( $redirect_url );
					exit;

				}

				// Load the right message and update the status.
				$status_message = isset( $intent->last_payment_error ) /* translators: 1) The error message that was received from Stripe. */ ? sprintf( __( 'Stripe SCA authentication failed. Reason: %s', 'funnelkit-stripe-woo-payment-gateway' ), $intent->last_payment_error->message ) : __( 'Stripe SCA authentication failed.', 'funnelkit-stripe-woo-payment-gateway' );
				$this->mark_order_failed( $order, $status_message );

			}

			Helper::log( 'Redirecting to :' . $redirect_url );
		} catch ( \Exception $e ) {
			$redirect_url = $woocommerce->cart->is_empty() ? get_permalink( wc_get_page_id( 'shop' ) ) : wc_get_checkout_url();
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
		}
		if ( ! isset( $_GET['is_ajax'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			remove_all_actions( 'wp_redirect' );
			wp_safe_redirect( $redirect_url );
			exit;

		}
		exit;
	}
}
