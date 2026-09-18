(function ($) {

    class PayLaterMessages {
        constructor(stripe) {


            this.init_stripe(stripe);
            this.events();

        }

        init_stripe(stripe) {
            let body = $('body');
            let font_family = body.css('font-family');
            let color = body.css('color');
            let font_weight = body.css('font-weight');
            let loop_product_title = $('.woocommerce-loop-product__title');
            let single_product_title = $('.product_title');
            if (loop_product_title.length > 0) {
                font_family = loop_product_title.css('font-family');
                color = loop_product_title.css('color');
                font_weight = loop_product_title.css('font-weight');
            } else if (single_product_title.length > 0) {
                font_family = single_product_title.css('font-family');
                color = single_product_title.css('color');
                font_weight = single_product_title.css('font-weight');
            }
            let font_size = '14px';
            let appearance = {
                variables: {
                    colorText: color,
                    colorTextSecondary: 'rgb(28, 198, 255)', // "Learn more" text color
                    fontSizeBase: font_size,
                    fontSizeSm: font_size,
                    fontSizeXs: font_size,
                    fontSize2Xs: font_size,
                    fontWeightMedium: font_weight,
                    fontFamily: font_family,
                }
            };

            if (fkwcs_paylater.appearance && typeof fkwcs_paylater.appearance === 'object') {
                for (let i in fkwcs_paylater.appearance) {
                    if (fkwcs_paylater.appearance.hasOwnProperty(i)) {
                        appearance[i] = fkwcs_paylater.appearance[i];
                    }
                }
            } else {
                console.log('Invalid appearance object:', fkwcs_paylater.appearance);
            }


            this.elements = stripe.elements({appearance});
        }

        events() {

            try {

                let self = this;
                if (document.readyState === 'complete' || document.readyState === 'loading') {
                    $(document).ready(function () {
                        self.attachEvents();
                    });
                } else {
                    $(window).on('load', function () {
                        self.attachEvents();
                    });
                }


            } catch (e) {

            }
        }

        attachEvents() {
            /**
             * Align with the jQuery build WooCommerce is using.
             *
             * This file captures the jQuery that exists when it executes. If a theme or plugin loads a
             * second jQuery build later (a raw code.jquery.com tag is the usual case), WooCommerce's
             * footer scripts capture that later build instead. jQuery keeps event handlers per build,
             * so updated_checkout, checkout_place_order_* and our own submit triggers would never cross
             * between the two. By DOM ready every synchronous script has run, so the global is the build
             * WooCommerce has. Adopt it, but only when blockUI is attached to that build: WooCommerce's
             * frontend scripts cannot run without it, so its presence proves WooCommerce lives there. If
             * a rogue build loads after WooCommerce's scripts instead, blockUI is missing on it and we
             * stay put. On a normal page the condition is false and nothing changes.
             */
            if (window.jQuery && window.jQuery !== $ && window.jQuery.fn && typeof window.jQuery.fn.on === 'function' && typeof window.jQuery.fn.block === 'function') {
                $ = window.jQuery;
            }

            $('body').on('updated_wc_div', () => {
                this.cartPage();
            });
            $('body').on('fkwcs_express_button_init', () => {

                this.fkcartMiniCart();
            });
            // Dedicated hook so the FunnelKit slide cart can refresh ONLY the drawer BNPL messaging
            $('body').on('fkcart_paylater_refresh', () => {
                this.fkcartMiniCart();
            });
            this.singleProduct();
            this.cartPage();
            this.archiveProduct();
        }

        createMessage(amount, methods, selector) {
            try {
                if (!Array.isArray(methods) || methods.length === 0) {
                    $(selector).hide();
                    return;
                }
                let supported_currency = ["USD", "GBP", "EUR", "DKK", "NOK", "SEK", "CAD", "AUD"];
                let supported_countries = ["US", "CA", "AU", "NZ", "GB", "IE", "FR", "ES", "DE", "AT", "BE", "DK", "FI", "IT", "NL", "NO", "SE", "GR"];

                let currency = fkwcs_paylater.currency.toUpperCase();
                if (supported_currency.indexOf(currency) < 0 || supported_countries.indexOf(fkwcs_paylater.country_code) < 0) {
                    return;
                }
                let element = this.elements.create('paymentMethodMessaging', {
                    amount: amount,
                    currency: currency,
                    paymentMethodTypes: methods,
                    countryCode: fkwcs_paylater.country_code,
                });

                //klarna modal z-index was too less that fkcart modal so we are setting it to 2147483646 to make it compatible
                let fkcartModal = $('#fkcart-modal');
                if (fkcartModal.length > 0) {
                    fkcartModal.css('z-index', '2147483646');
                }

                element.mount(selector);
                $(selector).show();
            } catch (Exception) {
                console.log('Exception', Exception);
                $(selector).hide();
            }

        }

        singleProduct() {

            if (!('yes' === fkwcs_paylater.is_product_page || '1' === fkwcs_paylater.is_product_page)) {
                return;
            }
            let single_div = $('.fkwcs_paylater_messaging.fkwcs_single_product');
            if (single_div.length === 0) {
                return;
            }
            let paylater_data = JSON.parse(single_div.attr('paylater-data'));
            let variation_form = $('.variations_form.cart');
            let is_variable_type = variation_form.length > 0;
            if (is_variable_type) {
                variation_form.on('found_variation', (e, variation) => {
                    this.createMessage(variation.fkwcs_stripe_amount, fkwcs_paylater.paylater_messaging.single, '.fkwcs_paylater_messaging.fkwcs_single_product');
                    single_div.show();
                });
                variation_form.on('reset_data', () => {
                    single_div.hide();
                });
            } else {
                this.createMessage(paylater_data.amount, fkwcs_paylater.paylater_messaging.single, '.fkwcs_paylater_messaging.fkwcs_single_product');
            }


        }


        cartPage() {
            if (!('yes' === fkwcs_paylater.is_cart || '1' === fkwcs_paylater.is_cart)) {
                return;
            }
            let single_div = $('.fkwcs_paylater_messaging.fkwcs_cart_page');
            if (single_div.length === 0) {
                return;
            }
            let paylater_data = JSON.parse(single_div.attr('paylater-data'));
            this.createMessage(paylater_data.amount, fkwcs_paylater.paylater_messaging.cart, '.fkwcs_paylater_messaging.fkwcs_cart_page');
        }

        fkcartMiniCart() {
            let single_div = $('.fkwcs_paylater_messaging.fkwcs_fkcart_drawer');
            if (single_div.length === 0) {
                return;
            }
            let paylater_data = JSON.parse(single_div.attr('paylater-data'));
            this.createMessage(paylater_data.amount, fkwcs_paylater.paylater_messaging.cart, '.fkwcs_paylater_messaging.fkwcs_fkcart_drawer');
        }

        archiveProduct() {
            let archive_pro = $('.fkwcs_shop_page');
            if (archive_pro.length === 0) {
                return;
            }
            let self = this;
            archive_pro.each(function () {
                let product_id = $(this).attr('paylater-product-id');
                let paylater_data = JSON.parse($(this).attr('paylater-data'));
                self.createMessage(paylater_data.amount, fkwcs_paylater.paylater_messaging.shop, `.fkwcs_shop_pro_${product_id}`);
            });
        }

    }


    /**
     * One Stripe instance per publishable key, shared by every FunnelKit script on the page.
     *
     * stripe-elements.js, express-checkout.js and paylater.js each used to call Stripe()
     * separately, so a checkout page could carry three instances. Each opens its own
     * connection and iframes, refetches shared.js, and keeps its own Link/wallet state.
     * Keyed by publishable key, since paylater does not pass it through the
     * fkwcs_api_client_public_key filter and could in theory differ. Defined defensively so
     * whichever script loads first creates it.
     */
    window.fkwcsGetStripe = window.fkwcsGetStripe || function (pubKey, options) {
        window.fkwcsStripeInstances = window.fkwcsStripeInstances || {};

        if (!window.fkwcsStripeInstances[pubKey]) {
            window.fkwcsStripeInstances[pubKey] = Stripe(pubKey, options);
        }

        return window.fkwcsStripeInstances[pubKey];
    };

    function init_bnpl_messages() {
        const pubKey = fkwcs_paylater.pub_key;
        const mode = fkwcs_paylater.mode;
        if ('' === pubKey) {
            console.log('Live Payment Mode only work only https protocol ');
            return;
        }
        try {
            const stripe = window.fkwcsGetStripe(pubKey, {locale: fkwcs_paylater.locale});
            new PayLaterMessages(stripe);

        } catch (e) {
            console.log(e);
        }

    }

    init_bnpl_messages();
})(jQuery)