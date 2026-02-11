<?php
/**
 * @var $value []
 */
$label       = __( 'Delete Webhook', 'funnelkit-stripe-woo-payment-gateway' );
$webhook_url = get_option( 'fkwcs_live_webhook_url', '' );
?>
<tr valign="top">
    <th scope="row">
        <label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
    </th>
    <td class="form-wc form-wc-<?php echo esc_attr( $value['class'] ); ?>">
        <fieldset>
            <a id="<?php echo esc_attr( $value['id'] ); ?>" class="button-primary <?php echo esc_attr( $value['class'] ); ?>" href="javascript:void(0)">
                <span><?php echo esc_html( $label ); ?></span>
            </a>
			<?php
			$webhook_id = "";
			if ( ! empty( get_option( 'fkwcs_live_created_webhook' ) ) ) {
				$webhook_id = get_option( 'fkwcs_live_created_webhook' );
			} else {
				$webhook_id = get_option( 'fkwcs_test_created_webhook' );
			}

			?>
            <br/>
            <br/>
            <div class="fkwcs_admin_settings_webhook_id"> &#9989; Webhook Created. ID : <b><?php esc_html_e( $webhook_id['id'] ); ?> </b>
            </div>

			<?php if ( ! empty( $webhook_url ) && $webhook_url !== FKWCS\Gateway\Stripe\Helper::get_webhook_url() ) { ?>
                <div class="fkwcs_inline_message_error">
                    <p><?php echo sprintf( esc_html__( 'The current webhook seems to have been configured with the URL: %1$s, however, the webhook should be configured for %2$s. Kindly delete the current webhook and a create webhook button will appear.', 'funnelkit-stripe-woo-payment-gateway' ), '<strong>' . esc_url( $webhook_url ) . '</strong>', '<strong>' . esc_url( FKWCS\Gateway\Stripe\Helper::get_webhook_url() ) . '</strong>' ); ?></p>
                </div>


			<?php } ?>
            <br/>

        </fieldset>
    </td>
</tr>
