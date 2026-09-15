<?php
/**
 * "Pay for Order" admin button and backbone modal template.
 *
 * Rendered on the Edit Order screen for unpaid orders. Expects a $order
 * (\WC_Order) variable in scope, provided by Admin::render_pay_order_button().
 * All modal behaviour lives in admin/assets/js/order-pay.js; this file only
 * outputs markup.
 *
 * @package Funnelkit_Stripe_Woo_Payment_Gateway
 * @since   1.14.1
 *
 * @var \WC_Order $order Current order being edited.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $order ) || ! $order instanceof \WC_Order ) {
	return;
}
?>
<div class="fkwcs-admin-pay-order">
	<a href="#" class="button button-primary fkwcs-open-pay-order"
		data-order="<?php echo esc_attr( $order->get_id() ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'fkwcs_admin_pay_order' ) ); ?>">
		<?php esc_html_e( 'Pay for Order', 'funnelkit-stripe-woo-payment-gateway' ); ?>
	</a>
</div>

<script type="text/template" id="tmpl-fkwcs-order-pay">
	<div class="wc-backbone-modal">
		<div class="wc-backbone-modal-content">
			<section class="wc-backbone-modal-main" role="main">
				<header class="wc-backbone-modal-header">
					<h1><?php esc_html_e( 'Pay for Order', 'funnelkit-stripe-woo-payment-gateway' ); ?></h1>
					<button class="modal-close modal-close-link dashicons dashicons-no-alt">
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'funnelkit-stripe-woo-payment-gateway' ); ?></span>
					</button>
				</header>
				<article>
					<div class="fkwcs-pay-order-section fkwcs-pay-order-charge-type">
						<p class="fkwcs-pay-order-label"><strong><?php esc_html_e( 'Charge type', 'funnelkit-stripe-woo-payment-gateway' ); ?></strong></p>
						<label class="fkwcs-pay-order-option">
							<input type="radio" name="fkwcs_charge_type" value="automatic" checked="checked" />
							<?php esc_html_e( 'Capture (charge the card now)', 'funnelkit-stripe-woo-payment-gateway' ); ?>
						</label>
						<label class="fkwcs-pay-order-option">
							<input type="radio" name="fkwcs_charge_type" value="manual" />
							<?php esc_html_e( 'Authorize (place a hold, capture later)', 'funnelkit-stripe-woo-payment-gateway' ); ?>
						</label>
					</div>

					<div class="fkwcs-pay-order-section fkwcs-pay-order-methods">
						<p class="fkwcs-pay-order-label"><strong><?php esc_html_e( 'Payment method', 'funnelkit-stripe-woo-payment-gateway' ); ?></strong></p>
						<div class="fkwcs-saved-cards"><?php // Saved cards are rendered here by order-pay.js from the localized tokens payload. ?></div>
						<label class="fkwcs-pay-order-option fkwcs-new-card-option">
							<input type="radio" name="fkwcs_pay_source" value="new" />
							<?php esc_html_e( 'Use a new card', 'funnelkit-stripe-woo-payment-gateway' ); ?>
						</label>
					</div>

					<div class="fkwcs-pay-order-section fkwcs-new-card-wrap" style="display:none;">
						<div id="fkwcs-card-element"></div>
					</div>

					<div class="fkwcs-pay-order-error woocommerce-error" style="display:none;"></div>
				</article>
				<footer>
					<div class="inner">
						<button type="button" class="button button-primary button-large fkwcs-submit-pay-order">
							<?php esc_html_e( 'Pay', 'funnelkit-stripe-woo-payment-gateway' ); ?>
						</button>
					</div>
				</footer>
			</section>
		</div>
	</div>
	<div class="wc-backbone-modal-backdrop modal-close"></div>
</script>
