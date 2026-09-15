<?php

namespace FKWCS\Gateway\Stripe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time settings migration 1_15.
 *
 * Copies Express Checkout shared settings into per-method option rows for
 * Apple Pay, Google Pay, and Link. Runs once at plugins_loaded, gated by a
 * version flag. Old keys are never deleted so a plugin rollback is safe.
 */
class Migration {

	const VERSION_FLAG      = 'fkwcs_settings_migration_1_15_done';
	const HAS_LEGACY_FLAG   = 'fkwcs_migration_1_15_has_legacy';
	const GPAY_EXPRESS_FLAG = 'fkwcs_gpay_express_unify_done';



	/**
	 * Return a nonce-protected reset URL for use in admin notices.
	 */
	public static function get_reset_url(): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'                  => 'wc-settings',
					'tab'                   => 'fkwcs_api_settings',
					'section'               => 'fkwcs_express_checkout',
					'fkwcs_reset_migration' => '1',
				),
				admin_url( 'admin.php' )
			),
			'fkwcs_reset_migration'
		);
	}

	/**
	 * Entry point. Safe to call on every request — exits immediately if already done.
	 */
	public static function run() {
		// Always-checked one-time forward migration: collapse Google Pay onto Express Checkout
		// (the legacy Native Google Pay API mode is removed). Runs independently of the 1_15 gate
		// below so sites already migrated to per-method settings — which would otherwise early-return
		// here — are still covered.
		self::unify_google_pay_to_express();

		if ( 'yes' === get_option( self::VERSION_FLAG ) ) {
			return;
		}

		try {
			$shared = get_option( 'woocommerce_fkwcs_stripe_settings', array() );

			// First-time install: no shared settings exist yet. Stamp and exit.
			// HAS_LEGACY_FLAG is intentionally NOT set so the admin UI can distinguish
			// fresh installs from sites that were migrated from the old shared settings.
			if ( empty( $shared ) ) {
				update_option( self::VERSION_FLAG, 'yes', true );
				return;
			}

			self::migrate_apple_pay( $shared );
			self::migrate_google_pay( $shared );
			self::migrate_link( $shared );

			update_option( self::VERSION_FLAG, 'yes', true );
			// Mark that real migration happened so the admin notice is shown only to
			// existing users whose settings were actually moved, not to new installs.
			update_option( self::HAS_LEGACY_FLAG, 'yes', true );
			Helper::log( '[Migration 1_15] Express payment settings migrated successfully.', 'info' );
		} catch ( \Throwable $e ) {
			Helper::log( '[Migration 1_15] Migration failed: ' . $e->getMessage(), 'error' );
		}
	}

	/**
	 * One-time forward migration: flip any saved Google Pay `integration_mode` of
	 * `native_google_pay` to `express_checkout`.
	 *
	 * The Native Google Pay API mode has been removed; every Google Pay integration is now
	 * Stripe Express Checkout (ECE) powered. Gated by its own flag so it runs exactly once per
	 * site — covering fresh installs, sites already migrated to per-method settings, and sites
	 * that manually selected the native mode in the UI.
	 *
	 * @return void
	 */
	public static function unify_google_pay_to_express() {
		if ( 'yes' === get_option( self::GPAY_EXPRESS_FLAG ) ) {
			return;
		}

		try {
			$settings = get_option( 'woocommerce_fkwcs_stripe_google_pay_settings', array() );

			if ( is_array( $settings ) && isset( $settings['integration_mode'] ) && 'express_checkout' !== $settings['integration_mode'] ) {
				$settings['integration_mode'] = 'express_checkout';
				update_option( 'woocommerce_fkwcs_stripe_google_pay_settings', $settings );
				Helper::log( '[Migration gpay-express] Flipped Google Pay integration_mode to express_checkout.', 'info' );
			}

			update_option( self::GPAY_EXPRESS_FLAG, 'yes', true );
		} catch ( \Throwable $e ) {
			Helper::log( '[Migration gpay-express] Failed: ' . $e->getMessage(), 'error' );
		}
	}

	// -------------------------------------------------------------------------
	// Per-method migrations
	// -------------------------------------------------------------------------

	private static function migrate_apple_pay( array $shared ) {
		$existing = get_option( 'woocommerce_fkwcs_stripe_apple_pay_settings', array() );

		$express_was_on  = isset( $shared['express_checkout_enabled'] ) && 'yes' === $shared['express_checkout_enabled'];
		$applepay_was_on = isset( $existing['enabled'] ) && 'yes' === $existing['enabled'];

		// Enable if either the shared Express Checkout was on (Apple Pay rendered via SmartButtons)
		// or the standalone Apple Pay gateway was already enabled.
		$enabled = ( $express_was_on || $applepay_was_on ) ? 'yes' : 'no';

		// show_as_regular only if the standalone gateway was previously enabled — do not add it
		// to users who only had Express Checkout active.
		$locations = self::get_shared_locations( $shared );
		if ( $applepay_was_on && ! in_array( 'show_as_regular', $locations, true ) ) {
			$locations[] = 'show_as_regular';
		}

		$migrated = array_merge(
			// Field defaults — lowest priority.
			array(
				'charge_type'           => 'automatic',
				'title'                 => 'Apple Pay',
				'description'           => 'Pay with Apple Pay',
				'disable_shipping_info' => 'no',
			),
			// Previously saved Apple Pay values override defaults.
			$existing,
			// Migrated shared fields — highest priority, always written.
			array(
				'enabled'               => $enabled,
				'display_locations'     => $locations,
				'product_page_position' => self::map_product_position( $shared['express_checkout_product_page_position'] ?? '' ),
				'button_type'           => 'plain',
				'button_theme'          => 'black',
				'separator_text'        => self::get_separator( $shared ),
			)
		);

		update_option( 'woocommerce_fkwcs_stripe_apple_pay_settings', $migrated );
	}

	private static function migrate_google_pay( array $shared ) {
		$existing = get_option( 'woocommerce_fkwcs_stripe_google_pay_settings', array() );

		$express_was_on = isset( $shared['express_checkout_enabled'] ) && 'yes' === $shared['express_checkout_enabled'];
		$gpay_was_on    = isset( $existing['enabled'] ) && 'yes' === $existing['enabled'];

		// Enable if either the shared Express Checkout was on or the standalone gateway was enabled.
		$enabled = ( $express_was_on || $gpay_was_on ) ? 'yes' : 'no';

		// Preserve the standalone Google Pay gateway's own saved display locations. A site that ran
		// the now-removed Native Google Pay mode kept its checkout selection here; using only the
		// shared express_checkout_location could drop `checkout` and hide the express button
		// post-migration (RC-6). Union the two so the merchant's prior Google Pay locations —
		// including checkout — survive. Idempotent + rollback-safe: nothing is deleted and
		// re-running yields the same set.
		$locations          = self::get_shared_locations( $shared );
		$existing_locations = ( isset( $existing['display_locations'] ) && is_array( $existing['display_locations'] ) ) ? $existing['display_locations'] : array();
		if ( ! empty( $existing_locations ) ) {
			$locations = array_values( array_unique( array_merge( $locations, $existing_locations ) ) );
		}

		// show_as_regular only if the standalone Google Pay gateway was previously enabled.
		if ( $gpay_was_on && ! in_array( 'show_as_regular', $locations, true ) ) {
			$locations[] = 'show_as_regular';
		}

		// Google Pay is always Express Checkout (ECE) powered now — the legacy Native Google Pay
		// API mode has been removed, so integration_mode is forced to express_checkout regardless
		// of how the standalone gateway was previously configured.
		$migrated = array_merge(
			array(
				'integration_mode'      => 'express_checkout',
				'merchant_id'           => '',
				'merchant_name'         => get_bloginfo( 'name' ),
				'charge_type'           => 'automatic',
				'title'                 => 'Google Pay',
				'description'           => 'Pay with your Google Pay',
				'disable_shipping_info' => 'no',
				'icon_type'             => 'round-border',
			),
			$existing,
			array(
				'enabled'               => $enabled,
				'integration_mode'      => 'express_checkout',
				'display_locations'     => $locations,
				'product_page_position' => self::map_product_position( $shared['express_checkout_product_page_position'] ?? '' ),
				'button_type'           => 'plain',
				'button_color'          => 'black',
				'separator_text'        => self::get_separator( $shared ),
			)
		);

		update_option( 'woocommerce_fkwcs_stripe_google_pay_settings', $migrated );
	}

	private static function migrate_link( array $shared ) {
		$existing = get_option( 'woocommerce_fkwcs_stripe_link_settings', array() );

		// Resolve tri-radio pattern → single select value.
		// 'on_email' is no longer supported (Stripe retired linkAutofillModal); map it to 'none'.
		$existing_trigger = $existing['link_authentication_trigger'] ?? '';
		if ( 'on_card' === $existing_trigger ) {
			$auth_trigger = 'on_card';
		} elseif ( 'yes' === ( $shared['link_in_card_field'] ?? '' ) ) {
			$auth_trigger = 'on_card';
		} else {
			$auth_trigger = 'none';
		}

		// Link is express-only — strip show_as_regular if it somehow got in.
		$locations = array_values( array_diff( self::get_shared_locations( $shared ), array( 'show_as_regular' ) ) );

		$migrated = array_merge(
			array(
				'enabled'                     => 'no',
				'display_locations'           => $locations,
				'link_authentication_trigger' => $auth_trigger,
				'product_page_position'       => 'above-add-to-cart',
				'separator_text'              => 'Or',
			),
			$existing,
			array(
				'enabled'                     => self::get_value( $shared, 'express_checkout_link_button_enabled', 'no' ),
				'display_locations'           => $locations,
				'link_authentication_trigger' => $auth_trigger,
				'product_page_position'       => self::map_product_position( $shared['express_checkout_product_page_position'] ?? '' ),
				'separator_text'              => self::get_separator( $shared ),
			)
		);

		update_option( 'woocommerce_fkwcs_stripe_link_settings', $migrated );
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	private static function get_shared_locations( array $shared ): array {
		$locs = $shared['express_checkout_location'] ?? array();
		return is_array( $locs ) && ! empty( $locs ) ? $locs : array( 'product', 'cart', 'checkout' );
	}

	private static function get_value( array $shared, string $key, string $default ): string {
		return ( isset( $shared[ $key ] ) && '' !== $shared[ $key ] ) ? (string) $shared[ $key ] : $default;
	}

	private static function get_separator( array $shared ): string {
		foreach ( array( 'express_checkout_separator_checkout', 'express_checkout_separator_product', 'express_checkout_separator_cart' ) as $key ) {
			if ( ! empty( $shared[ $key ] ) ) {
				return (string) $shared[ $key ];
			}
		}
		return 'Or';
	}

	private static function map_product_position( string $pos ): string {
		if ( 'below' === $pos ) {
			return 'below-add-to-cart';
		}
		if ( 'inline' === $pos ) {
			return 'inline';
		}
		// Preserve an explicit legacy 'above' choice — do not collapse it into below.
		if ( 'above' === $pos ) {
			return 'above-add-to-cart';
		}
		// Empty/unset legacy value now defaults to below-add-to-cart for PayPal parity.
		return 'below-add-to-cart';
	}
}
