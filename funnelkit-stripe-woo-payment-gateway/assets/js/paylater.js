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

            $('body').on('updated_wc_div', () => {
                this.cartPage();
            });
            $('body').on('fkwcs_express_button_init', () => {

                this.fkcartMiniCart();
            });
            this.singleProduct();
            this.cartPage();
            this.archiveProduct();
        }

        createMessage(amount, methods, selector) {
            try {
                let supported_currency = ["USD", "GBP", "EUR", "DKK", "NOK", "SEK", "CAD", "AUD"];
                let supported_countries = ["US", "CA", "AU", "NZ", "GB", "IE", "FR", "ES", "DE", "AT", "BE", "DK", "FI", "IT", "NL", "NO", "SE"];

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

    function init_bnpl_messages() {
        const pubKey = fkwcs_paylater.pub_key;
        const mode = fkwcs_paylater.mode;
        if ('' === pubKey) {
            console.log('Live Payment Mode only work only https protocol ');
            return;
        }
        try {
            const stripe = Stripe(pubKey, {locale: fkwcs_paylater.locale});
            new PayLaterMessages(stripe);

        } catch (e) {
            console.log(e);
        }

    }

    init_bnpl_messages();
})(jQuery)