<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CheckoutWC (checkoutwc-lite / checkout-for-woocommerce) compatibility.
 *
 * CheckoutWC replaces the WooCommerce block cart/checkout with its own template while its "enable"
 * setting is on, so the block cart/checkout is never actually rendered. In that case FunnelKit
 * Stripe's "block checkout incompatible" admin notice is a false positive, so we suppress it by
 * hooking the fkwcs_block_cart_checkout_overridden filter.
 */
if ( ! class_exists( 'FKWCS_CheckoutWC_Compatibility' ) ) {

	class FKWCS_CheckoutWC_Compatibility {

		public function __construct() {
			add_filter( 'fkwcs_block_cart_checkout_overridden', array( $this, 'maybe_overridden' ) );
		}

		/**
		 * Report the block cart/checkout as overridden when CheckoutWC is active and enabled.
		 *
		 * @param bool $overridden Whether a previous handler already flagged the block as overridden.
		 *
		 * @return bool
		 */
		public function maybe_overridden( $overridden ) {
			if ( true === $overridden ) {
				return $overridden;
			}

			// Mirrors CheckoutWC's own gate for taking over the checkout template.
			if ( class_exists( '\Objectiv\Plugins\Checkout\Managers\SettingsManager' )
				&& 'yes' === \Objectiv\Plugins\Checkout\Managers\SettingsManager::instance()->get_setting( 'enable' ) ) {
				return true;
			}

			return $overridden;
		}
	}

	new FKWCS_CheckoutWC_Compatibility();
}
