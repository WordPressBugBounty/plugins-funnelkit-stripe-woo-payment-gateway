/**
 * global fkwcs_data
 * global Stripe
 */
jQuery(function ($) {
    const style = fkwcs_data.common_style;
    window.fkwcsIsDomLoaded = false;
    const available_gateways = {};
    let current_upe_gateway = 'card';
    let wcCheckoutForm = $('form.woocommerce-checkout');
    const homeURL = fkwcs_data.get_home_url;
    const stripeLocalized = fkwcs_data.stripe_localized;

    function scrollToDiv(id, offset) {
        if (typeof offset === 'undefined') {
            offset = 0;
        }
        if (jQuery(id).length === 0) {
            return;
        }
        jQuery('html, body').animate({
            scrollTop: jQuery(id).offset().top - offset
        }, 500);
    }

    function getStripeLocalizedMessage(type, message) {
        return (null !== stripeLocalized[type] && undefined !== stripeLocalized[type]) ? stripeLocalized[type] : message;
    }


    class Gateway {
        constructor(stripe, gateway_id) {
            this.gateway_id = gateway_id;
            this.error_container = '.fkwcs-credit-card-error';
            this.gateway_container = '';
            this.stripe = stripe;
            this.mode = 'test';
            this.fragments = {};
            this.setup_ready = false;
            this.mountable = false;
            this.element_type = '';
            this.gateway_wallet_wrapper_class = '.fkwcs_wallet_gateways';
            this.prepareStripe();
        }

        prepareStripe() {
            this.elements = this.stripe.elements({"appearance": this.getAppearance()});
            this.setupGateway();
            this.wc_events();
        }

        getAppearance() {
            return {};
        }

        wc_events() {
            let self = this;
            let token_radio = $(`input[name='wc-${self.gateway_id}-payment-token']:checked`);

            let add_payment_method = $('form#add_payment_method');
            $('form.checkout').on('checkout_place_order_' + this.gateway_id, this.processingSubmit.bind(this));

            if ($('form#order_review').length > 0) {
                $('form#order_review').on('submit', this.processOrderReview.bind(this));
                wcCheckoutForm = $('form#order_review');
            }
            if (add_payment_method.length > 0) {
                add_payment_method.on('submit', this.add_payment_method.bind(this));
                wcCheckoutForm = add_payment_method;
            }

            $('#createaccount').on('change', function () {
                if ($(this).is(':checked')) {
                    $('#fkwcs-save-cc-fieldset').show();
                } else {
                    $('#fkwcs-save-cc-fieldset').hide();
                }
            });
            $(document.body).on('change', 'input[name="payment_method"]', function () {
                self.showError();
                self.unsetGateway($(this).val());

                if (self.gateway_id === $(this).val()) {
                    let valueofgateway = $(this).val();
                    self.showPlaceOrder();
                    setTimeout(function () {
                        self.setGateway(valueofgateway);

                    }, 100);
                }
            });
            $(document.body).on('updated_checkout', function (e, v) {
                if (undefined !== v && null !== v) {
                    self.update_fragment_data(v.fragments);
                }
                if (self.gateway_id === self.selectedGateway()) {
                    token_radio.trigger('change');
                    self.mountGateway();
                }

            });


            $(document).ready(function () {
                if (self.gateway_id === self.selectedGateway()) {
                    self.mountGateway();
                }
                self.ready();
                self.handleOrderPayPageAndChangePaymentPage();
                window.fkwcsIsDomLoaded = true;

            });

            $(window).on('load', function () {
                if (window.fkwcsIsDomLoaded) {
                    return;
                }
                if (self.gateway_id === self.selectedGateway()) {
                    self.mountGateway();
                }
                self.ready();
                self.handleOrderPayPageAndChangePaymentPage();
            });


            let fkwcs_gateway = $('#payment_method_' + this.gateway_id);
            if (fkwcs_gateway.length > 0 && fkwcs_gateway.is(":checked")) {
                self.fastRender();
            }

            $(window).on('fkwcs_on_hash_change', this.onHashChange.bind(this));
            $(document.body).on('change', `input[name='wc-${this.gateway_id}-payment-token']`, function () {
                if ('new' !== $(this).val()) {
                    self.hideGatewayContainer();
                } else {
                    self.showGatewayContainer();
                }

            });
            token_radio.trigger('change');

            /**
             * We must clear any saved source in input hidden on error, so we could create new source on re attempt
             */
            $(document).on('checkout_error', function () {
                let source_el = $('.fkwcs_source');
                if (source_el.length > 0) {
                    source_el.remove();
                }
            });


            $(document.body).trigger('wc-credit-card-form-init');


        }

        handleOrderPayPageAndChangePaymentPage() {
            /**
             * If this is the change payment or a pay page we need to trigger the tokenization form
             */
            if ('yes' === fkwcs_data.is_change_payment_page || 'yes' === fkwcs_data.is_pay_for_order_page) {

                /**
                 * IN case of SCA payments we need to trigger confirmStripePayment as hash change will not fire auto
                 * @type {RegExpMatchArray}
                 */
                let partials = window.location.hash.match(/^#?fkwcs-confirm-(pi|si)-([^:]+):(.+):(.+):(.+):(.+)$/);
                if (null == partials) {
                    partials = window.location.hash.match(/^#?fkwcs-confirm-(pi|si)-([^:]+):(.+)$/);
                }
                if (partials) {
                    const type = partials[1];
                    const intentClientSecret = partials[2];
                    const redirectURL = decodeURIComponent(partials[3]);
                    const order_id = decodeURIComponent(partials[4]);


                    const payment_method = decodeURIComponent(partials[5]);
                    // Cleanup the URL
                    if (this.gateway_id === payment_method) {
                        $('input[name="payment_method"][value="' + payment_method + '"]').prop('checked', true).trigger('click');
                        this.confirmStripePayment(intentClientSecret, redirectURL, type, order_id);
                    }
                }


            }
        }

        ready() {

        }

        fastRender() {

        }

        setupGateway() {

        }

        showPlaceOrder() {
            $('#place_order')?.show();
            $('#place_order')?.removeClass('fkwcs_hidden');
            this.hideGatewayWallets();
        }

        hidePlaceOrder() {
            $('#place_order')?.hide();
            $('#place_order')?.addClass('fkwcs_hidden');
        }

        hideGatewayWallets() {
            $(this.gateway_wallet_wrapper_class)?.hide();
        }

        /**
         * This function run when person select a gateway from gateway list
         */
        setGateway() {

        }

        /**
         * This function run when person changed gateway from previous selected gateway
         */
        unsetGateway(gateway_id) {

            if (gateway_id.indexOf('fkwcs_') < 0) {
                this.showPlaceOrder();// place order for other gateway
            }

        }

        mountGateway() {

        }

        createSource() {

        }

        processingSubmit(e) {

        }

        processOrderReview(e) {

        }

        add_payment_method(e) {

        }

        hideGatewayContainer() {
            $(this.gateway_container).length > 0 ? $(this.gateway_container).hide() : ''; // jshint ignore:line
        }

        showGatewayContainer() {
            $(this.gateway_container).length > 0 ? $(this.gateway_container).show() : ''; // jshint ignore:line
        }

        get_fragment_data() {
            return this.fragments;
        }

        update_fragment_data(fragments) {
            this.fragments = fragments;
        }

        appendMethodId(payment_method) {
            let source_el = $('.fkwcs_source');
            if (source_el.length > 0) {
                source_el.remove();
            }
            wcCheckoutForm.append(`<input type='hidden' name='fkwcs_source' class='fkwcs_source' value='${payment_method}'>`);
        }

        getMethodId(payment_method) {
            return $('.fkwcs_source').val();
        }


        getAddress(type = 'billing') {
            const billingCountry = document.getElementById(type + '_country');
            const billingPostcode = document.getElementById(type + '_postcode');
            const billingCity = document.getElementById(type + '_city');
            const billingState = document.getElementById(type + '_state');
            const billingAddress1 = document.getElementById(type + '_address_1');
            const billingAddress2 = document.getElementById(type + '_address_2');

            let address = {
                country: null !== billingCountry && '' !== billingCountry ? billingCountry.value : fkwcs_data.country_code,
                city: null !== billingCity && '' !== billingCity ? billingCity.value : undefined,
                postal_code: null !== billingPostcode && '' !== billingPostcode ? billingPostcode.value : undefined,
                state: null !== billingState && '' !== billingState ? billingState.value : undefined,
                line1: null !== billingAddress1 && '' !== billingAddress1 ? billingAddress1.value : undefined,
                line2: null !== billingAddress2 && '' !== billingAddress2 ? billingAddress2.value : undefined,
            };

            // Iterate over the address object and delete any properties that are null, undefined, or an empty string
            for (let prop in address) {
                if (address[prop] === null || address[prop] === undefined || address[prop] === '') {
                    address[prop] = null;
                }
            }
            if (typeof this.prevent_empty_line_address !== 'undefined' && this.prevent_empty_line_address === true && address.line1 === null) {
                return [];
            }
            return address;
        }


        getBillingAddress(type) {
            if ($('form#order_review').length > 0) {
                return fkwcs_data.current_user_billing_for_order;
            }

            if (typeof type !== undefined && 'add_payment' === type) {
                return {
                    'name': fkwcs_data.current_user_billing.name ? fkwcs_data.current_user_billing.name : undefined,
                    'email': fkwcs_data.current_user_billing.email ? fkwcs_data.current_user_billing.email : undefined,
                    address: {
                        country: null !== fkwcs_data.current_user_billing.address.country && '' !== fkwcs_data.current_user_billing.address.country ? fkwcs_data.current_user_billing.address.country : undefined,
                        city: null !== fkwcs_data.current_user_billing.address.city && '' !== fkwcs_data.current_user_billing.address.city ? fkwcs_data.current_user_billing.address.city : undefined,
                        postal_code: null !== fkwcs_data.current_user_billing.address.postal_code && '' !== fkwcs_data.current_user_billing.address.postal_code ? fkwcs_data.current_user_billing.address.postal_code : undefined,
                        state: null !== fkwcs_data.current_user_billing.address.state && '' !== fkwcs_data.current_user_billing.address.state ? fkwcs_data.current_user_billing.address.state : undefined,
                        line1: null !== fkwcs_data.current_user_billing.address.line1 && '' !== fkwcs_data.current_user_billing.address.line1 ? fkwcs_data.current_user_billing.address.line1 : undefined,
                        line2: null !== fkwcs_data.current_user_billing.address.line2 && '' !== fkwcs_data.current_user_billing.address.line2 ? fkwcs_data.current_user_billing.address.line2 : undefined,
                    }

                };
            }
            const billingFirstName = document.getElementById('billing_first_name');
            const billingLastName = document.getElementById('billing_last_name');
            const billingEmail = document.getElementById('billing_email');
            const billingPhone = document.getElementById('billing_phone');

            const firstName = null !== billingFirstName ? billingFirstName.value : undefined;
            const lastName = null !== billingLastName ? billingLastName.value : undefined;
            let getBilling = {
                name: firstName + ' ' + lastName,
                email: null !== billingEmail ? billingEmail.value : '',
                phone: null !== billingPhone ? billingPhone.value : '',
                address: this.getAddress()
            };

            return getBilling;
        }

        getShippingAddress() {
            let ship_to_different = $('#ship-to-different-address-checkbox');
            let address = this.getAddress();
            let billingFirstName = document.getElementById('billing_first_name');
            let billingLastName = document.getElementById('billing_last_name');
            if (ship_to_different.length > 0 && ship_to_different.is(":checked")) {
                address = this.getAddress('shipping');
                const shippingFirstName = document.getElementById('shipping_first_name');
                const shippingLastName = document.getElementById('shipping_last_name');
                if (null !== shippingFirstName && null !== shippingLastName) {
                    billingFirstName = shippingFirstName;
                    billingLastName = shippingLastName;
                }

            }

            const firstName = null !== billingFirstName ? billingFirstName.value : '';
            const lastName = null !== billingLastName ? billingLastName.value : '';

            return {
                name: firstName + ' ' + lastName,
                address: address,
            };
        }

        selectedGateway() {
            let el = $('input[name="payment_method"]:checked');
            if (el.length > 0) {
                return el.val();
            }
            return '';
        }

        confirmStripePayment(clientSecret, redirectURL, intent_type, authenticationAlready = false, order_id = false, is_save_payment_source_used = 'no') {
            console.log('Please override in child class');
        }


        onHashChange(e, partials) {


            const type = partials[1];
            const intentClientSecret = partials[2];
            const redirectURL = decodeURIComponent(partials[3]);
            const order_id = decodeURIComponent(partials[4]);
            const payment_method = decodeURIComponent(partials[5]);
            const is_save_payment_source_used = decodeURIComponent(partials[6]);

            // Cleanup the URL
            if (this.gateway_id === payment_method) {
                this.confirmStripePayment(intentClientSecret, redirectURL, type, order_id, is_save_payment_source_used);
            }
        }

        showError(error) {

            wcCheckoutForm.removeClass('processing');

            this.unblockElement();
            if (error) {
                $(this.error_container).html(error.message);
            } else {
                $(this.error_container).html('');
            }
        }

        showNotice(message) {
            if (typeof message === 'object') {
                if (message.type === "validation_error") {
                    wcCheckoutForm.removeClass('processing');
                    scrollToDiv('.fkwcs-stripe-elements-wrapper', 100);
                    this.unblockElement();
                    return;
                }
                message = message.message;
            }
            wcCheckoutForm.removeClass('processing');

            $('.woocommerce-error').remove();
            $('.woocommerce-notices-wrapper').eq(0).html('<div class="woocommerce-error fkwcs-errors">' + message + '</div>').show();
            this.unblockElement();
            scrollToDiv('.woocommerce-notices-wrapper');

        }

        unblockElement() {
            $('form.woocommerce-checkout').unblock();
            $('form#order_review').unblock();
            $('form#add_payment_method').unblock();
        }

        logError(error, order_id = '') {
            let body = $('body');
            $.ajax({
                type: 'POST', url: fkwcs_data.admin_ajax, data: {
                    "action": 'fkwcs_js_errors', "_security": fkwcs_data.js_nonce, "order_id": order_id, "error": error
                }, beforeSend: () => {
                    body.css('cursor', 'progress');
                }, success(response) {
                    if (response.success === false) {
                        return response.message;
                    }
                    body.css('cursor', 'default');
                }, error() {
                    body.css('cursor', 'default');
                },
            });
        }

        async createPaymentIntent() {

            let formdata = new FormData();
            formdata.append("action", "fkwcs_create_payment_intent");
            formdata.append("fkwcs_nonce", fkwcs_data.fkwcs_nonce);
            let response = await fetch(fkwcs_data.admin_ajax, {
                method: "POST", cache: "no-cache", body: formdata,
            });
            return response.json();
        }

        getAmountCurrency() {
            const source = this.fragments?.fkwcs_paylater_data || fkwcs_data?.fkwcs_paylater_data;
            return source ? {'amount': parseFloat(source.amount), 'currency': source.currency.toUpperCase()}
                : {'amount': 0, 'currency': 'USD'};
        }

        isAvailable() {
            let div = $(`#payment_method_${this.gateway_id}`);
            return div.length > 0;
        }


    }

    class LocalGateway extends Gateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.mountable = true;
            this.error_container = `.fkwcs_stripe_${gateway_id}_error`;
            this.confirmCallBack = '';
            this.current_amount = 0;
            this.message_element = false;
            this.element = null;
        }

        wc_events() {
            super.wc_events();
        }

        getAppearance() {
            let body = $('li.wc_payment_method label');
            let font_family = body.css('font-family');
            let color = body.css('color');
            let font_weight = body.css('font-weight');
            let line_height = body.css('line-height');
            let font_size = '14px';
            return {
                variables: {
                    colorText: color,
                    colorTextSecondary: 'rgb(28, 198, 255)', // "Learn more" text color
                    fontSizeBase: font_size,
                    fontSizeSm: font_size,
                    fontSizeXs: font_size,
                    fontSize2Xs: font_size,
                    fontLineHeight: line_height,
                    spacingUnit: '10px',
                    fontWeightMedium: font_weight,
                    fontFamily: font_family,
                }
            };

        }


        update_fragment_data(fragments) {

            super.update_fragment_data(fragments);
            this.updateElements(fragments);
        }

        updateElements(fragments) {
            try {
                if (!fragments.hasOwnProperty('fkwcs_paylater_data')) {
                    return;
                }
                let amount = fragments.fkwcs_paylater_data.amount;
                let currency = fragments.fkwcs_paylater_data.currency;
                if (amount !== this.current_amount && null !== this.element) {
                    this.element.update({'currency': currency.toUpperCase(), 'amount': amount});
                    this.current_amount = amount;
                }
                if (true === this.message_element) {
                    this.unmount();
                    this.createMessage(amount, currency);
                    this.mountGateway(false);
                }
            } catch (e) {
                console.log(e);// Log Error
            }
        }

        ready() {
            try {
                this.mountGateway();
            } catch (e) {
                console.log('exception', e);
            }

        }

        createMessage(amount, currency) {
            if (!this.isSupportedCountries()) {
                return;
            }

            this.element = this.elements.create('paymentMethodMessaging', {
                amount: amount, // $99.00 USD
                currency: currency.toUpperCase(),
                paymentMethodTypes: this.paymentMethodTypes(),
                countryCode: $('#billing_country').val(),
            });
        }

        confirmStripePayment(clientSecret, redirectURL, intent_type, order_id = false) {
            if ('' === this.confirmCallBack || !this.stripe.hasOwnProperty(this.confirmCallBack)) {
                return;
            }
            if (this.gateway_id === this.selectedGateway()) {
                this.stripe[this.confirmCallBack](clientSecret, this.stripePaymentMethodOptions(redirectURL)).then((response) => {

                    if (response.error) {
                        this.logError(response.error, order_id);
                        this.showError(response.error);
                        return;
                    }
                    this.successResponse(response, redirectURL);
                }).catch(() => {
                    this.showError('user cancelled');
                });
            }
        }

        /**
         * variable needed for verify payment using client secrets
         * @returns {{return_url: *, payment_method_options: {}, payment_method: {billing_details: (*|{}|{address: *, phone: *|string, name: string, email: *|string})}}}
         */
        stripePaymentMethodOptions(redirectURL) {
            return {
                payment_method: this.paymentMethods(),
                payment_method_options: this.paymentMethodOptions(),
                //shipping: this.getShippingAddress(),
                return_url: homeURL + redirectURL,
            };
        }

        paymentMethodTypes() {
            return [];
        }

        paymentMethodOptions() {
            return {};
        }

        paymentMethods() {
            return {
                billing_details: this.getBillingAddress()
            };
        }


        unmount() {
            let selector = $(`.${this.gateway_id}_select`);
            if (null !== this.element && '' !== selector.html()) {
                this.element.unmount();
            }
        }

        setGateway() {
            if (false === this.mountable) {
                return;
            }

            //this.unmount();
            this.mountGateway();
        }

        mountGateway(update_price = true) {
            if (false === this.mountable || null == this.element) {
                return;
            }
            let form = $(`.${this.gateway_id}_form`);
            if (0 === form.length) {
                return;
            }
            form.show();
            let selector = `.${this.gateway_id}_form .${this.gateway_id}_select`;

            if ($(selector).children().length === 0) {
                this.element.mount(selector);
            }
            $(selector).css({backgroundColor: '#fff'});
            if (true === update_price) {
                let amount_data = this.getAmountCurrency();
                this.element.update({'currency': amount_data.currency, 'amount': amount_data.amount});
            }


        }

        successResponse(response, redirectURL) {
            const {error, paymentIntent} = response;
            if (error) {
                this.showError(error);
                this.logError(error, order_id);
                this.showNotice(getStripeLocalizedMessage(error.code, error.message));
            } else if (paymentIntent.status === 'succeeded') {
                // Inform the customer that the payment was successful
                window.location = redirectURL;
            } else if (paymentIntent.status === 'requires_action') {
                // Inform the customer that the payment did not go through
            }
        }

        isSupportedCountries() {
            return false;
        }
    }


    class FKWCS_Stripe extends Gateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs-credit-card-error';
            this.mountable = true;

            this.amount_to_small = false;
        }


        setupGateway() {
            this.payment_data = {};
            this.element_data = {};
            this.gateway_container = '.fkwcs-stripe-elements-form';
            if ('payment' === fkwcs_data.card_form_type) {

                this.setupUPEGateway();
                return;
            }

            if (this.isInlineGateway()) {
                this.inLineFields();
            } else {
                this.separateFields();
            }

        }


        isInlineGateway() {
            return ('yes' === fkwcs_data.inline_cc);
        }

        inLineFields() {


            this.card = this.elements.create('card', $.extend({'style': fkwcs_data.inline_style, 'hidePostalCode': true, 'iconStyle': 'solid'}, fkwcs_data.card_element_options));
            /**
             * display error messages
             */
            this.card.on('change', ({brand, error}) => {
                this.showError();
                if (error) {
                    this.showError(error);
                    return;
                }

                if (brand) {
                    if (this.isAllowedBrand(brand)) {
                        this.showError();
                        return;
                    }
                    if ('unknown' === brand) {
                        this.showError();
                    } else {
                        this.showError({'message': fkwcs_data.default_cards[brand] + ' ' + fkwcs_data.not_allowed_string});
                    }
                }
            });
        }


        separateFields() {

            let _style = JSON.stringify(style);
            let styleForSeperateFields = JSON.parse(_style);
            delete styleForSeperateFields.base.padding;
            if (undefined !== styleForSeperateFields.base.iconColor) {
                delete styleForSeperateFields.base.iconColor;
            }
            this.cardNumber = this.elements.create('cardNumber', $.extend({'style': styleForSeperateFields}, fkwcs_data.card_element_options));
            this.cardExpiry = this.elements.create('cardExpiry', {'style': styleForSeperateFields});
            this.cardCvc = this.elements.create('cardCvc', {'style': styleForSeperateFields});
            /**
             * display error messages
             */
            this.cardNumber.on('change', ({brand, error}) => {
                let card_number_div = $('#fkwcs-stripe-elements-wrapper .fkwcs-credit-card-number');
                let card_icon_holder = $('.fkwcs-stripe-elements-field');

                this.showError();
                if (error) {
                    card_number_div.addClass('haserror');
                    this.showError(error);
                    return;
                }
                card_number_div.removeClass('haserror');
                let imageUrl = fkwcs_data.assets_url + '/icons/card.svg';
                if ('unknown' === brand) {
                    card_icon_holder.removeClass('fkwcs_brand');

                    return;
                }

                if (brand) {

                    if (!this.isAllowedBrand(brand)) {
                        if ('unknown' === brand) {
                            card_icon_holder.removeClass('fkwcs_brand');
                        } else {
                            $('.fkwcs-credit-card-error').html(fkwcs_data.default_cards[brand] + ' ' + fkwcs_data.not_allowed_string);
                        }
                        return;
                    }
                    if (card_number_div.length > 0) {
                        imageUrl = fkwcs_data.assets_url + '/icons/' + brand + '.svg';

                        card_icon_holder.addClass('fkwcs_brand');

                    }
                }
            });
            this.cardExpiry.on('change', ({error}) => {

                if (error) {
                    $('.fkwcs-credit-expiry').addClass('haserror');
                    $('.fkwcs-credit-expiry-error').html(error.message);
                } else {
                    $('.fkwcs-credit-expiry-error').html('').removeClass('haserror');
                }
            });
            this.cardCvc.on('change', ({error}) => {
                if (error) {
                    $('.fkwcs-credit-cvc-error').html(error.message);
                    $('.fkwcs-credit-cvc').addClass('haserror');
                } else {
                    $('.fkwcs-credit-cvc-error').html('').removeClass('haserror');
                }
            });
        }


        setGateway() {

            if ('payment' === fkwcs_data.card_form_type) {
                if (null !== this.payment) {
                    this.payment.unmount();
                }
            } else if (this.isInlineGateway()) {
                if (null !== this.card) {
                    this.card.unmount();
                }
            } else {
                if (null !== this.cardNumber) {
                    this.cardNumber.unmount();
                    this.cardExpiry.unmount();
                    this.cardCvc.unmount();
                }
            }
            this.mountGateway();
        }

        mountGateway() {

            if ('payment' === fkwcs_data.card_form_type) {
                this.mountElements();
                return;

            }

            this.mountCard();
        }

        mountCard() {
            $('.fkwcs-stripe-elements-wrapper').show();
            if (this.isInlineGateway()) {
                if (!$('.fkwcs-stripe-elements-wrapper .fkwcs-credit-card-field').html() && null !== this.card) {
                    this.card.mount('.fkwcs-stripe-elements-wrapper .fkwcs-credit-card-field');
                }
                return;
            }

            if (!this.isInlineGateway() && null !== this.cardNumber) {
                this.cardNumber.mount('.fkwcs-stripe-elements-wrapper .fkwcs-credit-card-number');
                this.cardExpiry.mount('.fkwcs-stripe-elements-wrapper .fkwcs-credit-expiry');
                this.cardCvc.mount('.fkwcs-stripe-elements-wrapper .fkwcs-credit-cvc');
            }
        }

        getCardElement() {
            let card_element = null;
            if (this.isInlineGateway()) {
                card_element = this.card;
            } else {
                card_element = this.cardNumber;
            }
            return card_element;
        }

        createSource(type) {
            wcCheckoutForm.block({
                message: null, overlayCSS: {
                    background: '#fff', opacity: 0.6
                }
            });


            /**
             * Check if UPE is turned on, override from here
             */
            if ('payment' === fkwcs_data.card_form_type) {
                this.createUPESource(type);
                return;
            }

            if($('.fkwcs-credit-card-error.fkwcs-error-text').length > 0 && $('.fkwcs-credit-card-error.fkwcs-error-text').text() !== '') {
                scrollToDiv($('.fkwcs-credit-card-error.fkwcs-error-text'), 100);
                wcCheckoutForm.unblock();
                return;
            }
            this.stripe.createPaymentMethod({
                type: 'card', card: this.getCardElement(), billing_details: this.getBillingAddress(type),
            }).then((response) => {

                this.handleSourceResponse(response);
            });
        }

        handleSourceResponse(response) {
            if (response.error) {
                this.showNotice(response.error);
                this.logError(response.error);
                return;
            }
            if (response.paymentMethod) {
                this.appendMethodId(response.paymentMethod.id);
                if ($('form#order_review').length && 'yes' === fkwcs_data.is_change_payment_page) {
                    this.create_setup_intent(response.paymentMethod.id, $('form#order_review'), response.paymentMethod.type);
                } else if ($('form#add_payment_method').length) {
                    this.create_setup_intent(response.paymentMethod.id, $('form#add_payment_method'), response.paymentMethod.type);
                } else {
                    if ($('form#order_review').length > 0) {
                        $('form#order_review').trigger('submit');
                    } else {
                        $('form.checkout').trigger('submit');

                    }
                }
            }
        }

        create_setup_intent(payment_method, form_el, type) {
            const {fkwcs_nonce, admin_ajax} = fkwcs_data;
            const process_data = {
                action: 'fkwcs_create_setup_intent',
                fkwcs_nonce,
                fkwcs_source: payment_method,
                gateway_id: this.selectedGateway()
            };

            // Bind the function to preserve `this` context when used within the callback
            const _this = this;

            $.ajax({
                type: 'POST',
                dataType: 'json',
                url: admin_ajax,
                data: process_data,
                beforeSend: () => {
                    $('body').css('cursor', 'progress');
                },
                success(response) {
                    if (response.status !== 'success') {
                        $('body').css('cursor', 'default');
                        return false;
                    }

                    const {client_secret: clientSecret} = response.data;
                    const confirm_data = {
                        elements: _this.elements,
                        clientSecret,
                        confirmParams: {
                            return_url: homeURL,
                        },
                        redirect: 'if_required',
                    };

                    // Call the appropriate confirmation based on type
                    const confirmSetup = (type === 'link') ? _this.stripe.confirmSetup(confirm_data) : _this.stripe.confirmCardSetup(clientSecret, {payment_method});

                    // Handle the confirmation using async function
                    _this.handleConfirmation(confirmSetup, form_el);
                },
                error() {
                    $('body').css('cursor', 'default');
                    alert('Something went wrong!');
                },
                complete() {
                    $('body').css('cursor', 'default');
                }
            });
        }

// New helper method for handling confirmation and form submission
        async handleConfirmation(confirmSetup, form_el) {
            try {
                const resp = await confirmSetup;

                if (resp.error) {
                    form_el.unblock();
                    this.showNotice(resp.error);
                    return;
                }

                form_el.trigger('submit');
            } catch (error) {
                form_el.unblock();
                console.error('Error in handleConfirmation:', error);
            }
        }


        confirmStripePayment(clientSecret, redirectURL, intent_type, order_id = false, is_save_payment_source_used = 'no') {

            if ('payment' === fkwcs_data.card_form_type && 'no' === is_save_payment_source_used) {
                this.confirmStripePaymentEl(clientSecret, redirectURL, intent_type, order_id);
                return;
            }

            let cardPayment = null;
            if ('si' === intent_type) {
                cardPayment = this.stripe.handleCardSetup(clientSecret, {});
            } else {
                cardPayment = this.stripe.confirmCardPayment(clientSecret, {});
            }

            cardPayment.then((result) => {
                if (result.error) {
                    this.showNotice(result.error);
                    let source_el = $('.fkwcs_source');
                    if (source_el.length > 0) {
                        source_el.remove();
                    }

                    if (result.error.hasOwnProperty('type') && result.error.type === 'api_connection_error') {
                        return;
                    }
                    this.logError(result.error, order_id);


                } else {

                    let intent = result[('si' === intent_type) ? 'setupIntent' : 'paymentIntent'];
                    if ('requires_capture' !== intent.status && 'succeeded' !== intent.status) {
                        return;
                    }
                    window.location = redirectURL;
                }
            }).catch(function (error) {

                // Report back to the server.
                $.get(redirectURL + '&is_ajax');
            });
        }


        hasSource() {
            let saved_source = $('input[name="wc-fkwcs_stripe-payment-token"]:checked');
            if (saved_source.length > 0 && 'new' !== saved_source.val()) {
                return saved_source.val();
            }

            let source_el = $('.fkwcs_source');
            if (source_el.length > 0) {
                return source_el.val();
            }

            return '';
        }

        processingSubmit(e) {

            let source = this.hasSource();
            if ('' === source) {
                this.createSource('submit');
                e.preventDefault();
                return false;
            }

        }

        processOrderReview(e) {

            if (this.gateway_id === this.selectedGateway()) {


                let source = this.hasSource();

                if ('' === source) {
                    this.createSource('order_review');
                    e.preventDefault();
                    return false;
                }
            }
        }

        add_payment_method(e) {
            if (this.gateway_id === this.selectedGateway()) {


                let source = this.hasSource();
                if ('' === source) {
                    this.createSource('add_payment');
                    e.preventDefault();
                    return false;
                }
            }

        }

        onEarlyRenewalSubmit(e) {
            e.preventDefault();

            $.ajax({
                url: $('#early_renewal_modal_submit').attr('href'), method: 'get', complete: (html) => {
                    let response = JSON.parse(html.responseText);
                    if (response.fkwcs_stripe_sca_required) {
                        this.confirmStripePayment(response.intent_secret, response.redirect_url);
                    } else {
                        window.location = response.redirect_url;
                    }
                },
            });

            return false;
        }

        isAllowedBrand(brand) {
            if (0 === fkwcs_data.allowed_cards.length) {
                return false;
            }
            return (-1 === $.inArray(brand, fkwcs_data.allowed_cards)) ? false : true;
        }

        wc_events() {
            super.wc_events();

            // Subscription early renewals modal.
            if ($('#early_renewal_modal_submit[data-payment-method]').length) {
                $('#early_renewal_modal_submit[data-payment-method=fkwcs_stripe]').on('click', this.onEarlyRenewalSubmit.bind(this));
            } else {
                $('#early_renewal_modal_submit').on('click', this.onEarlyRenewalSubmit.bind(this));
            }
            $(document.body).on('change', '.woocommerce-SavedPaymentMethods-tokenInput', function () {
                let name = $(this).attr('name');
                let el = $('.fkwcs-stripe-elements-wrapper');
                if (name === 'wc-fkwcs_stripe-payment-token') {
                    let vl = $(this).val();
                    if ('new' === vl) {
                        el.show();
                    } else {
                        el.hide();
                    }

                } else {
                    el.show();
                }
            });
        }

        setupUPEGateway() {
            this.setup_ready = true;
            let paymentData = fkwcs_data.fkwcs_payment_data;
            this.element_data = paymentData.element_data;
            this.element_options = paymentData.element_options;
            this.element_options.fields.billingDetails = paymentData.element_options.fields.billingDetails;


            if (typeof this.element_options.fields.billingDetails !== 'object') {
                this.element_options.fields.billingDetails = {};
                this.element_options.fields.billingDetails.address = 'never';
            }
            this.element_options.fields.billingDetails.name = jQuery("#billing_first_name").length ? "never" : "auto";
            this.element_options.fields.billingDetails.email = jQuery("#billing_email").length ? "never" : "auto";
            this.element_options.fields.billingDetails.phone = jQuery("#billing_phone").length ? "never" : "auto";


            if (fkwcs_data.is_add_payment_page === 'yes') {


                this.element_options.defaultValues = {
                    billingDetails: {
                        'name': fkwcs_data.current_user_billing.name ? fkwcs_data.current_user_billing.name : undefined,
                        'email': fkwcs_data.current_user_billing.email ? fkwcs_data.current_user_billing.email : undefined
                    }
                };
            } else {
                this.element_options.defaultValues = {
                    billingDetails: {
                        name: jQuery("#billing_first_name").val() + " " + jQuery("#billing_last_name").val(),
                        email: jQuery("#billing_email").val(),
                        phone: jQuery("#billing_phone").val()
                    }
                };
            }

            this.createStripeElements();

        }

        createStripeElements(options = {}, reset = false) {


            this.elements = this.stripe.elements(this.element_data);
            this.payment = this.elements.create('payment', this.element_options);
            this.payment.on('change', function (event) {
                current_upe_gateway = event.value.type;
            });
            this.payment.on('ready', (event) => {
                this.amount_to_small = false;
            });
            this.payment.on('loaderror', (event) => {
                if ('amount_too_small' === event.error.code) {
                    this.amount_to_small = true;
                }
            });
            if (false === reset) {
                this.link(this.elements);
            }
        }


        mountElements() {

            try {
                // Check if the selector matches any element
                let selector = '.fkwcs-stripe-payment-elements-field.StripeElement';


                // If the 'payment' object is available and the element is not mounted, mount it
                if ($(selector).length === 0 && null !== this.payment) {
                    this.payment.mount('.fkwcs-stripe-payment-elements-field');
                } else {
                    if (null === this.payment) {
                        console.log("Payment object is not initialized.");

                    }
                }
            } catch (e) {
                // Log the error with the error message
                console.log("Error in mountElements():", e);

            }

        }


        updatableElementKeys() {
            return ['mode', 'currency', 'amount', 'setup_future_usage', 'capture_method', 'payment_method_types', 'appearance', 'on_behalf_of'];
        }

        update_fragment_data(fragments) {
            super.update_fragment_data(fragments);
            this.updateElements();
        }

        updateElements() {
            if ('payment' !== fkwcs_data.card_form_type || Object.keys(this.element_data).length === 0) {
                return;
            }
            let fragments = this.get_fragment_data();
            if (!fragments.hasOwnProperty('fkwcs_payment_data')) {
                return false;
            }

            this.payment_data = fragments.fkwcs_payment_data;
            let element_data = this.payment_data.element_data;
            if (JSON.stringify(element_data) === JSON.stringify(this.element_data)) {
                return;
            }
            this.element_data = element_data;
            if (true === this.amount_to_small) {
                this.createStripeElements({}, true);
                this.mountElements();
                return;
            }
            let keys = this.updatableElementKeys();
            for (let key in element_data) {
                if (keys.indexOf(key) < 0) {
                    continue;
                }
                let update_data = {};
                update_data[key] = element_data[key];
                this.elements.update(update_data);
            }
        }


        createUPESource(type) {
            wcCheckoutForm.block({
                message: null, overlayCSS: {
                    background: '#fff', opacity: 0.6
                }
            });
            let payment_submit = this.elements.submit();
            payment_submit.then((response) => {

                this.stripe.createPaymentMethod({
                    elements: this.elements, params: {
                        billing_details: this.getBillingAddress()
                    }
                }).then((result) => {
                    if (result.error) {
                        if (result.error.type !== "validation_error") {
                            this.showError(result.error);

                        } else {
                            /**
                             * We do not need to print any validation related errors here since they are auto showed up
                             */
                            this.showError(false);
                        }
                        scrollToDiv('li.payment_method_fkwcs_stripe');
                        return;
                    }

                    this.handleSourceResponse(result);

                }).catch((error) => {
                    console.log('error', error);
                });
            });

        }

        confirmStripePaymentEl(clientSecret, redirectURL, intent_type, order_id = false) {
            let confirm_data = {
                'elements': this.elements,
                'clientSecret': clientSecret,
                confirmParams: {
                    return_url: homeURL + redirectURL,
                },
                'redirect': 'if_required'
            };

            if ('yes' === fkwcs_data.is_change_payment_page || 'yes' === fkwcs_data.is_pay_for_order_page) {
                delete confirm_data.elements;
            }

            let cardPayment = null;
            if ('si' === intent_type) {
                cardPayment = this.stripe.confirmSetup(confirm_data);
            } else {
                cardPayment = this.stripe.confirmPayment(confirm_data);
            }
            cardPayment.then((result) => {
                if (result.error) {
                    this.showNotice(result.error);
                    let source_el = $('.fkwcs_source');
                    if (source_el.length > 0) {
                        source_el.remove();
                    }
                    if (result.error.hasOwnProperty('type') && result.error.type === 'api_connection_error') {
                        return;
                    }
                    this.logError(result.error, order_id);
                    this.showError(result.error);
                } else {

                    let intent = result[('si' === intent_type) ? 'setupIntent' : 'paymentIntent'];
                    if ('requires_capture' !== intent.status && 'succeeded' !== intent.status && 'processing' !== intent.status) {
                        return;
                    }
                    window.location = redirectURL;
                }
            }).then((error) => {
                if (!error) {
                    return;
                }
                this.showError(error);
                this.logError(error, order_id);
                this.showNotice(getStripeLocalizedMessage(error.code, error.message));
            });
        }

        link(element) {
            try {
                let self = this;
                if (!('yes' === fkwcs_data.link_authentication && 'payment' === fkwcs_data.card_form_type)) {
                    return;
                }
                let modal = this.stripe.linkAutofillModal(element);
                $(document.body).on('keyup', '#billing_email', function () {
                    modal.launch({email: $(this).val()});
                });

                modal.on('autofill', function (e) {
                    let billing_address = e.value?.billingAddress;
                    let shipping_address = e.value?.shippingAddress;

                    if (null === billing_address && null !== shipping_address) {
                        billing_address = shipping_address;
                    }
                    if (null === shipping_address && null !== billing_address) {
                        shipping_address = billing_address;
                    }

                    if (typeof billing_address == "object" && billing_address != null) {
                        self.prefillFields(billing_address, 'billing');
                    }
                    if (typeof shipping_address == "object" && billing_address != null) {
                        self.prefillFields(shipping_address, 'shipping');
                    }
                    $('[name="terms"]').prop('checked', true);
                    $('#wc-fkwcs_stripe-payment-token-new')?.prop('checked', true).trigger('change');
                    let gatewayElem = $('#payment_method_' + self.gateway_id);
                    gatewayElem.trigger('click');
                    gatewayElem.trigger('change');

                    wcCheckoutForm.block({
                        message: null, overlayCSS: {
                            background: '#fff', opacity: 0.6
                        }
                    });
                    setTimeout(function () {
                        wcCheckoutForm.block({
                            message: null, overlayCSS: {
                                background: '#fff', opacity: 0.6
                            }
                        });
                        $('#place_order').trigger('click');

                    }, 1000);
                });
            } catch (error) {
                console.log(error);
            }
        }

        prefillFields(data, type = 'billing') {

            let name = data.name;
            let names = name.split(' ');
            let firsname = names[0];
            let last_name = names.slice(1).join(' ');
            let last_name_e = $(`#${type}_last_name`);
            let first_name_e = $(`#${type}_first_name`);
            if (0 === last_name_e.length) {
                first_name_e.val(name);
            } else {
                first_name_e.val(firsname);
                last_name_e.val(last_name);
            }
            let address = data.address;
            $(`#${type}_city`)?.val(address.city);
            $(`#${type}_country`)?.val(address.country).trigger('change');
            $(`#${type}_postcode`)?.val(address.postal_code);

            let address_1 = $(`#${type}_address_1`);
            let address_2 = $(`#${type}_address_2`);


            if (address_2.length > 0) {
                address_1.val(address.line1);
                address_2.val(address.line2);
            } else {
                address_1.val(address.line1 + ' ' + address.line2);
            }
            setTimeout((address, type) => {
                let state = address.state;
                let states_field = $(`#${type}_state`);
                if (states_field.length > 0) {
                    let have_options = states_field.find('option');
                    if (have_options.length > 0) {
                        have_options.each(function () {
                            let text = $(this).text();
                            let val = $(this).val();
                            state = state.toLowerCase();
                            text = text.toLowerCase();
                            let val_l = val.toLowerCase();
                            if (state == text || state == val_l) {
                                states_field.val(val);
                            }
                        });
                    } else {
                        states_field.val(state);
                    }
                    states_field.trigger('change');
                }
            }, 2000, address, type);
        }
    }


    class FKWCS_P24 extends Gateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.selectedP24Bank = '';
            this.error_container = '.fkwcs_stripe_p24_error';
        }

        setupGateway() {
            let self = this;
            this.p24 = this.elements.create('p24Bank', {"style": style});
            this.p24.on('change', function (event) {
                self.selectedP24Bank = event.value;
                self.showError();
            });
        }

        setGateway() {
            this.p24.unmount();
            this.mountGateway();
        }

        mountGateway() {
            let p24_form = $(`.${this.gateway_id}_form`);
            if (0 === p24_form.length) {
                return;
            }
            p24_form.show();
            let selector = `.${this.gateway_id}_form .${this.gateway_id}_select`;
            this.p24.mount(selector);
            $(selector).css({backgroundColor: '#fff'});

        }

        processingSubmit(e) {
            // check for P24.
            if ('' === this.selectedP24Bank) {
                this.showError({message: fkwcs_data.empty_bank_message});
                this.showNotice(fkwcs_data.empty_bank_message);
                return false;
            }
            this.showError('');
        }

        confirmStripePayment(clientSecret, redirectURL, intent_type, authenticationAlready = false, order_id = false) {

            if (this.gateway_id === this.selectedGateway()) {
                this.stripe.confirmP24Payment(clientSecret, {
                    payment_method: {
                        billing_details: this.getBillingAddress(),
                    }, return_url: homeURL + redirectURL,
                });
            }

        }


    }

    class FKWCS_Sepa extends Gateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_sepa_error';

            this.sepaIBAN = false;
            this.paymentMethod = '';
            this.emptySepaIBANMessage = fkwcs_data.empty_sepa_iban_message;
        }

        setupGateway() {
            let self = this;
            this.gateway_container = '.fkwcs_stripe_sepa_payment_form';

            let sepaOptions = Object.keys(fkwcs_data.sepa_options).length ? fkwcs_data.sepa_options : {};
            this.sepa = this.elements.create('iban', sepaOptions);
            this.sepa.on('change', ({error}) => {
                if (this.isSepaSaveCardChosen()) {
                    return true;
                }
                if (error) {
                    self.sepaIBAN = false;
                    self.emptySepaIBANMessage = error.message;

                    self.showError(error);
                    self.logError(error);
                    return;
                }
                this.sepaIBAN = true;
                self.showError('');

            });
            this.setup_ready = true;
        }

        setGateway() {
            this.sepa.unmount();
            this.mountGateway();
        }

        mountGateway() {
            if (false === this.setup_ready) {
                return;

            }
            if (0 === $('.payment_method_fkwcs_stripe_sepa').length) {
                return false;
            }

            this.sepa.mount('.fkwcs_stripe_sepa_iban_element_field');
            $('.fkwcs_stripe_sepa_payment_form .fkwcs_stripe_sepa_iban_element_field').css({backgroundColor: '#fff', borderRadius: '3px'});
        }

        isSepaSaveCardChosen() {
            return ($('#payment_method_fkwcs_stripe_sepa').is(':checked') && $('input[name="wc-fkwcs_stripe_sepa-payment-token"]').is(':checked') && 'new' !== $('input[name="wc-fkwcs_stripe_sepa-payment-token"]:checked').val());
        }

        processingSubmit(e) {
            if ('' === this.paymentMethod && !this.isSepaSaveCardChosen()) {
                if (false === this.sepaIBAN) {
                    this.showError(this.emptySepaIBANMessage);
                    return false;
                }


                this.createPaymentMethod();
                return false;
            }
        }

        processOrderReview(e) {
            if (this.gateway_id === this.selectedGateway()) {
                if ('' === this.paymentMethod && !this.isSepaSaveCardChosen()) {
                    this.createPaymentMethod('order_review');
                    return false;
                }
            }
        }


        add_payment_method(e) {
            if (this.gateway_id === this.selectedGateway()) {

                let source_el = $('.fkwcs_source');
                if (source_el.length > 0) {
                    return;
                }
                this.createPaymentMethod('add_payment');
                e.preventDefault();
                return false;

            }
        }


        createPaymentMethod(type = 'submit') {
            /**
             * @todo
             * need return true if cart total is 0
             */

            this.stripe.createPaymentMethod({
                type: 'sepa_debit', sepa_debit: this.sepa, billing_details: this.getBillingAddress(type),
            }).then((result) => {

                if (result.error) {
                    this.logError(result.error);

                    this.showNotice(getStripeLocalizedMessage(result.error.code, result.error.message));
                    return;
                }

                // Handle result.error or result.paymentMethod
                if (result.paymentMethod) {
                    wcCheckoutForm.find('.fkwcs_payment_method').remove();
                    this.paymentMethod = result.paymentMethod.id;
                    this.appendMethodId(this.paymentMethod);
                    wcCheckoutForm.trigger('submit');
                }
            });

        }


        confirmStripePayment(clientSecret, redirectURL, intent_type, authenticationAlready = false, order_id = false) {
            if (this.gateway_id !== this.selectedGateway()) {
                return;
            }


            if ('si' === intent_type) {


                if (this.isSepaSaveCardChosen() || authenticationAlready) {
                    this.stripe.confirmSepaDebitSetup(clientSecret, {}).then((result) => {
                        if (result.error) {
                            this.logError(result.error, order_id);

                            this.showNotice(getStripeLocalizedMessage(result.error.code, result.error.message));
                            return;
                        }
                        // The payment has been processed!
                        if (result.setupIntent.status === 'succeeded' || result.setupIntent.status === 'processing') {
                            $('.woocommerce-error').remove();
                            window.location = redirectURL;
                        }

                    });

                } else {
                    this.stripe.confirmSepaDebitSetup(clientSecret, {
                        payment_method: {
                            sepa_debit: this.sepa, billing_details: this.getBillingAddress()
                        },
                    }).then((result) => {
                        if (result.error) {
                            this.logError(result.error);
                            this.showNotice(getStripeLocalizedMessage(result.error.code, result.error.message));
                            return;
                        }


                        // The payment has been processed!
                        if (result.setupIntent.status === 'succeeded' || result.setupIntent.status === 'processing') {
                            $('.woocommerce-error').remove();
                            window.location = redirectURL;
                        }
                    });
                }
            } else {


                if (this.isSepaSaveCardChosen() || authenticationAlready) {
                    this.stripe.confirmSepaDebitPayment(clientSecret, {}).then((result) => {
                        if (result.error) {
                            this.logError(result.error, order_id);

                            this.showNotice(getStripeLocalizedMessage(result.error.code, result.error.message));
                            return;
                        }

                        // The payment has been processed!
                        if (result.paymentIntent.status === 'succeeded' || result.paymentIntent.status === 'processing') {
                            $('.woocommerce-error').remove();
                            window.location = redirectURL;
                        }

                    });

                } else {
                    this.stripe.confirmSepaDebitPayment(clientSecret, {
                        payment_method: {
                            sepa_debit: this.sepa, billing_details: this.getBillingAddress()
                        },
                    }).then((result) => {
                        if (result.error) {
                            this.logError(result.error);
                            this.showNotice(getStripeLocalizedMessage(result.error.code, result.error.message));
                            return;
                        }


                        // The payment has been processed!
                        if (result.paymentIntent.status === 'succeeded' || result.paymentIntent.status === 'processing') {
                            $('.woocommerce-error').remove();
                            window.location = redirectURL;
                        }
                    });
                }
            }


        }
    }

    class FKWCS_Ideal extends LocalGateway {


        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_ideal_error';
        }

        setupGateway() {
            //check if defined fkwcs_data.fkwcs_payment_data_ideal
            if (typeof fkwcs_data.fkwcs_payment_data_ideal === 'undefined') {
                return;
            }
            let amount_data = this.getAmountCurrency();
            this.elements = this.stripe.elements({
                mode: 'payment',
                currency: amount_data.currency.toLowerCase(),
                amount: amount_data.amount,
                payment_method_types: ['ideal']
            });


            let paymentData = fkwcs_data.fkwcs_payment_data_ideal;
            this.element_data = paymentData.element_data;
            this.element_options = paymentData.element_options;
            this.element_options.fields.billingDetails = paymentData.element_options.fields.billingDetails;


            if (typeof this.element_options.fields.billingDetails !== 'object') {
                this.element_options.fields.billingDetails = {};
                this.element_options.fields.billingDetails.address = 'never';
            }
            this.element_options.fields.billingDetails.name = jQuery("#billing_first_name").length ? "never" : "auto";
            this.element_options.fields.billingDetails.email = jQuery("#billing_email").length ? "never" : "auto";
            this.element_options.fields.billingDetails.phone = jQuery("#billing_phone").length ? "never" : "auto";


            this.element_options.defaultValues = {
                billingDetails: {
                    name: jQuery("#billing_first_name").length ? jQuery("#billing_first_name").val() + " " + jQuery("#billing_last_name").val() : '',
                    email: jQuery("#billing_email").val(),
                    phone: jQuery("#billing_phone").val()
                }
            };


            this.ideal = this.elements.create('payment', this.element_options);

        }

        setGateway() {
            this.ideal.unmount();
            this.mountGateway();
        }

        mountGateway() {
            if (typeof fkwcs_data.fkwcs_payment_data_ideal === 'undefined') {
                return;
            }
            let form = $(`.${this.gateway_id}_form`);
            if (0 === form.length) {
                return;
            }
            let selector = `.${this.gateway_id}_form .${this.gateway_id}_select`;
            this.ideal.mount(selector);
            $(selector).css({backgroundColor: '#fff'});
        }

        processingSubmit(e) {
            return true;
        }

        hasSource() {
            return '';
        }

        processOrderReview(e) {
            if (this.gateway_id === this.selectedGateway()) {
                let source = this.hasSource();
                if ('' === source) {
                    this.createIntent('order_review');
                    e.preventDefault();
                    return false;
                }
            }
        }

        createIntent(type) {
            if (!this.processingSubmit()) {
                return;
            }
            wcCheckoutForm.block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });
            let self = this;
            let order_id = self.getOrderIdFromUrl();
            let orderData = {
                action: 'fkwcs_create_payment_intent',
                order_id: order_id,
                gateway_id: this.gateway_id,
                security: fkwcs_data.nonce
            };
            $.ajax({
                url: fkwcs_data.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: orderData
            }).done(function (response) {
                if (response.success && response.data.client_secret && response.data.payment_id) {
                    let payment_id = response.data.payment_id;
                    let clientSecret = response.data.client_secret;
                    let redirectURL = response.data.redirect_url;
                    wcCheckoutForm.append(`<input type='hidden' name='payment_intent' class='payment_intent' value='${payment_id}'>`);
                    wcCheckoutForm.append(`<input type='hidden' name='payment_intent_client_secret' class='payment_intent_client_secret' value='${clientSecret}'>`);
                    wcCheckoutForm.append(`<input type='hidden' name='fkwcs_source' class='fkwcs_source' value='${payment_id}'>`);
                    self.elements.submit().then(() => {
                        self.stripe.confirmPayment({
                            elements: self.elements,
                            clientSecret: clientSecret,
                            confirmParams: {
                                return_url: `${homeURL}${redirectURL}`,
                                payment_method_data: {
                                    billing_details: self.getBillingAddress()
                                }
                            }
                        }).then((result) => {
                            // Check if there is an error in the result
                            if (result.error) {
                                // Validation or payment error occurred — handle it here
                                console.error("Payment confirmation error:", result.error.message);
                                // Show error to user, prevent form submission
                            } else {
                                // Payment successful or processing, proceed with form submit
                                wcCheckoutForm.trigger('submit');
                            }
                        }).catch((error) => {
                            console.error("Error confirming payment:", error);
                        });
                    }).catch((error) => {
                        console.error("Error submitting elements:", error);
                    });

                } else {
                    console.error("Server Error:", response.message || "Error creating payment intent.");
                }
            }).fail(function (jqXHR, textStatus, errorThrown) {
                console.error("AJAX Error:", textStatus, errorThrown);
                console.error("Response Text:", jqXHR.responseText);
            }).always(function () {
                wcCheckoutForm.unblock();
            });
        }

        getOrderIdFromUrl() {
            let urlParams = new URLSearchParams(window.location.search);
            let orderIdFromQuery = urlParams.get("order_id");

            if (!orderIdFromQuery) {
                let pathSegments = window.location.pathname.split('/');
                let orderIndex = pathSegments.indexOf('order-pay');
                if (orderIndex !== -1 && pathSegments.length > orderIndex + 1) {
                    return pathSegments[orderIndex + 1];
                }
            }
            return orderIdFromQuery || null;
        }

        confirmStripePayment(clientSecret, redirectURL, intent_type, authenticationAlready = false, order_id = false) {

            if (this.gateway_id === this.selectedGateway()) {
                this.elements.submit();
                this.stripe.confirmPayment({
                    elements: this.elements,
                    clientSecret: clientSecret,
                    confirmParams: {
                        return_url: `${homeURL}${redirectURL}`,
                        payment_method_data: {
                            billing_details: this.getBillingAddress()
                        }
                    }
                });
            }

        }
    }


    class FKWCS_BanContact extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = `.fkwcs_stripe_bancontact_error`;
            this.confirmCallBack = `confirmBancontactPayment`;
        }

        confirmStripePayment(clientSecret, redirectURL, intent_type, authenticationAlready = false, order_id = false) {
            if (this.gateway_id === this.selectedGateway()) {
                this.stripe.confirmBancontactPayment(clientSecret, {
                    payment_method: {
                        billing_details: this.getBillingAddress(),
                    }, return_url: homeURL + redirectURL,
                }).then(({error}) => {
                    if (!error) {
                        return;
                    }
                    this.showError(error);
                    this.logError(error, order_id);
                    this.showNotice(getStripeLocalizedMessage(error.code, error.message));
                });
            }

        }


    }

    class FKWCS_AFFIRM extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_affirm_error';
            this.confirmCallBack = 'confirmAffirmPayment';
            this.mountable = true;
            this.message_element = true;
            this.prevent_empty_line_address = true;

        }

        setupGateway() {
            this.setup_ready = true;
            let data = this.getAmountCurrency();
            this.createMessage(data.amount, data.currency);
        }


        paymentMethodTypes() {
            return ['affirm'];
        }

        isSupportedCountries() {
            let billing_country = $('#billing_country').val();
            return ['US', 'CA'].indexOf(billing_country) > -1;
        }


    }

    class FKWCS_KLARNA extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_klarna_error';
            this.confirmCallBack = 'confirmKlarnaPayment';
            this.mountable = true;
            this.message_element = true;
        }

        setupGateway() {
            this.setup_ready = true;
            let data = this.getAmountCurrency();
            this.createMessage(data.amount, data.currency);
        }

        paymentMethodTypes() {
            return ['klarna'];
        }

        isSupportedCountries() {
            let billing_country = $('#billing_country').val();
            return ['AU', 'CA', 'US', 'DK', 'NO', 'SE', 'GB', 'PL', 'CH', 'NZ', 'AT', 'BE', 'DE', 'ES', 'FI', 'FR', 'GR', 'IE', 'IT', 'NL', 'PT'].indexOf(billing_country) > -1;
        }


    }

    class FKWCS_AFTERPAY extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_afterpay_error';
            this.confirmCallBack = 'confirmAfterpayClearpayPayment';
            this.mountable = true;
            this.message_element = true;
            this.prevent_empty_line_address = true;
        }


        setupGateway() {
            this.setup_ready = true;
            let data = this.getAmountCurrency();
            this.createMessage(data.amount, data.currency);
        }

        paymentMethodTypes() {
            return ['afterpay_clearpay'];
        }

        isSupportedCountries() {
            let billing_country = $('#billing_country').val();
            return ['US'].indexOf(billing_country) > -1;
        }

        stripePaymentMethodOptions(redirectURL) {
            return {
                payment_method: this.paymentMethods(),
                payment_method_options: this.paymentMethodOptions(),
                return_url: homeURL + redirectURL,
            };
        }
    }

    class FKWCS_MOBILEPAY extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_mobilepay_error';
            this.confirmCallBack = 'confirmMobilepayPayment';
            this.mountable = true;
        }


        setupGateway() {
            this.setup_ready = true;
            let data = this.getAmountCurrency();
            this.createMessage(data.amount, data.currency);
        }

        paymentMethodTypes() {
            return ['mobilepay'];
        }

        stripePaymentMethodOptions(redirectURL) {
            return {
                payment_method: this.paymentMethods(),
                payment_method_options: this.paymentMethodOptions(),
                return_url: homeURL + redirectURL,
            };
        }
    }


    class FKWCS_ApplePay extends Gateway {
        constructor(stripe, gateway_id) {

            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_apple_pay_error';
            this.mountable = true;
            this.gateway_class = 'li.payment_method_fkwcs_stripe_apple_pay';
            this.apple_place_btn_wrapper = '.fkwcs_apple_pay_gateway_wrap';
            this.apple_pay_btn = '';
            this.payment_request_options = null;
            this.shipping_options = [];
            this.request_data = {};
            this.express_btn_click = false;
            let ct = this;
            $(document.body).on('fkwcs_smart_buttons_showed', function (key, res, two) {
                if (two.applePay) {
                    if ($('li.payment_method_fkwcs_stripe_apple_pay').length > 0) {
                        $('li.payment_method_fkwcs_stripe_apple_pay').show();
                        ct.mountGateway();
                    }
                } else {

                }
            });
        }


        setGateway() {
            this.hidePlaceOrder();
        }


        mountGateway() {
            this.hidePlaceOrder();
            this.showButton();
        }


        showButton() {
            this.PrepareButton();
        }


        PrepareButton() {
            let wrapper = $(this.apple_place_btn_wrapper);
            wrapper.hide();
            this.apple_pay_btn = wrapper;
            this.apple_pay_btn.addClass('fkwcs-apple-button-container');
            $('#place_order').after(this.apple_pay_btn);
            if (this.gateway_id !== this.selectedGateway()) {
                return;
            }
            this.hideGatewayWallets();
            wrapper.show();
        }


        hidePlaceOrder() {
            if (this.gateway_id !== this.selectedGateway()) {
                return;
            }
            this.hideGatewayWallets();
            $('.fkwcs-apple-button-container')?.show();
            $('#place_order')?.hide();
            $('#place_order')?.addClass('fkwcs_hidden');
        }

        showPlaceOrder() {
            this.hideGatewayWallets();
        }

    }

    class FKWCS_GOOGLEPAY extends Gateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            if (!fkwcs_data.hasOwnProperty('google_pay')) {
                return;
            }


            this.error_container = '.fkwcs_stripe_google_pay_error';
            this.mountable = true;
            this.gateway_class = 'li.payment_method_fkwcs_stripe_google_pay';
            this.gpay_button_html = '';
            this.google_pay_client = null;
            this.payment_request_options = null;
            this.shipping_options = [];
            this.request_data = {};
            this.google_is_ready = null;
            this.express_gpay_available = false;
            this.express_btn_click = false;
            this.createPaymentClient();
            $(document.body).on('fkwcs_smart_buttons_showed', this.checkexpressSmartButtonAvailable.bind(this));
            $(document.body).on('fkwcs_smart_buttons_not_available', this.hideGatewayForExpressButtons.bind(this));

        }

        isModeLiveMerchantIDNotAvailable() {
            return 'live' == fkwcs_data.mode && '' == fkwcs_data.google_pay.merchant_id;
        }

        checkexpressSmartButtonAvailable(e, v, result) {
            this.express_gpay_available = result.googlePay;
            if (false === this.express_gpay_available && this.isModeLiveMerchantIDNotAvailable()) {
                $(this.gateway_class).hide();
            }
        }

        hideGatewayForExpressButtons() {
            if (this.isModeLiveMerchantIDNotAvailable()) {
                $(this.gateway_class).hide();
                $('input[name="payment_method"]')?.eq(0).trigger('click');
            }
        }

        createPaymentClient() {
            if ('live' == fkwcs_data.mode && '' == fkwcs_data.google_pay.merchant_id) {
                return;
            }
            try {
                this.google_pay_client = new google.payments.api.PaymentsClient(this.getMerchantData());
                let request_data = this.googlePayVersion();
                request_data.allowedPaymentMethods = [this.getBaseCardBrand()];
                this.google_pay_client.isReadyToPay(request_data).then(() => {
                    this.google_is_ready = true;
                    $(document.body).trigger('fkwcs_google_ready_pay', [this.google_pay_client]);
                    this.createCheckoutExpressBtn();
                    $(this.gateway_class).show();
                }).catch((err) => {
                    console.log('error', err);
                });


            } catch (e) {
                console.log(e);
            }
        }


        googlePayVersion() {
            return {
                "apiVersion": 2,
                "apiVersionMinor": 0
            };
        }


        getBaseCardBrand() {
            return {
                type: 'CARD',
                parameters: {
                    allowedAuthMethods: ["PAN_ONLY"],
                    allowedCardNetworks: ["AMEX", "DISCOVER", "INTERAC", "JCB", "MASTERCARD", "VISA"],
                    assuranceDetailsRequired: true
                },
                tokenizationSpecification: {
                    type: "PAYMENT_GATEWAY",
                    parameters: {
                        gateway: 'stripe',
                        "stripe:version": "2018-10-31",
                        "stripe:publishableKey": fkwcs_data.pub_key
                    }
                }
            };
        }


        getMerchantData() {
            let data = {
                environment: ('test' === fkwcs_data.mode ? 'TEST' : 'PRODUCTION'),
                merchantId: fkwcs_data.google_pay.merchant_id,
                merchantName: fkwcs_data.google_pay.merchant_name,
                paymentDataCallbacks: {
                    onPaymentAuthorized: function onPaymentAuthorized() {
                        return new Promise(function (resolve) {
                            resolve({
                                transactionState: "SUCCESS"
                            });
                        }.bind(this));
                    },
                }
            };
            if ('test' === fkwcs_data.mode) {
                delete data.merchantId;
            }
            if (this.shippingAddressRequired()) {
                data.paymentDataCallbacks.onPaymentDataChanged = this.paymentDataChanged.bind(this);
            }
            return data;

        }


        setGateway() {
            this.hidePlaceOrder();
            this.showGpayButton();
        }


        mountGateway() {
            this.hidePlaceOrder();
            this.showGpayButton();
        }

        update_fragment_data(fragments) {
            super.update_fragment_data(fragments);
            this.update_transaction_data(fragments?.fkwcs_google_pay_data);
            if (this.google_is_ready) {
                $(this.gateway_class)?.show();
            }
        }

        billingAddressRequired() {
            if ($('form.checkout').length > 0) {
                if (this.field_required('billing_phone') || this.field_required('billing_email')) {
                    return true;
                }
                return false;
            }
            return true;
        }

        showGpayButton() {
            if (true === this.google_is_ready) {
                this.createGpayButton();
                $(this.gateway_class).show();
            } else if (this.isModeLiveMerchantIDNotAvailable()) {
                this.appendGpayBrowserBased();
            } else {
                this.showPlaceOrder();
            }
        }

        paymentDataChanged(data) {
            return new Promise((resolve) => {
                let response = this.update_payment_data(data);

                // Validate shipping data via an AJAX call
                response.then((response) => {
                    if (response.result === 'fail') {
                        // Reject with an error message to show in Google Pay popup
                        resolve({
                            error: {
                                reason: 'SHIPPING_ADDRESS_UNSUPPORTED',
                                message: fkwcs_data.shipping_error,
                                intent: 'SHIPPING_ADDRESS'
                            }
                        });
                    } else {
                        // Resolve with successful shipping update data
                        resolve(response.paymentRequestUpdate);
                        $('body').trigger('update_checkout', {update_shipping_method: false});
                    }
                }).catch((data) => {
                    // Handle any unexpected errors gracefully
                    resolve({
                        error: {
                            reason: 'SHIPPING_ADDRESS_UNSUPPORTED',
                            message: fkwcs_data.shipping_error,
                            intent: 'SHIPPING_ADDRESS'
                        }
                    });
                });
            });
        }

        map_google_pay_address(shippingAddress) {
            return {'country': shippingAddress.countryCode, 'postcode': shippingAddress.postalCode, 'city': shippingAddress.locality, 'state': shippingAddress.administrativeArea};
        }


        update_payment_data(data, extraData) {
            return new Promise((resolve, reject) => {
                let shipping_method = data.shippingOptionData.id === 'default' ? null : data.shippingOptionData.id;

                $.ajax({
                    url: fkwcs_data.wc_endpoints.fkwcs_gpay_update_shipping_address,
                    dataType: 'json',
                    method: 'POST',
                    data: $.extend({'fkwcs_nonce': fkwcs_data.fkwcs_nonce}, {
                        shipping_address: this.map_google_pay_address(data.shippingAddress),
                        shipping_method: [shipping_method]
                    }, extraData),
                    success: (response) => {
                        // Check for a failed response
                        if (response.result === 'fail') {
                            this.showError({
                                message: 'Shipping address is invalid or no shipping methods are available. Please update your address and try again.'
                            });
                            reject(response);
                        } else {
                            resolve(response);
                        }
                    },
                    error: (jqXHR, textStatus, errorThrown) => {
                        console.error('Error in shipping address update:', textStatus, errorThrown);
                        this.showError({
                            message: 'There was an error processing the shipping address. Please try again later.'
                        });
                        reject(errorThrown);
                    }
                });
            });
        }


        /**
         * Create GooGle Pay Button with custom wrapper
         * @param callback
         */
        createGooglePayButton(callback, indentifier = '') {

            callback.buttonSizeMode = 'fill';
            indentifier += " fkwcs_google_button_theme_" + fkwcs_data.google_pay_btn_theme;

            if ('yes' === fkwcs_data.is_change_payment_page || 'yes' === fkwcs_data.is_pay_for_order_page) {
                this.update_transaction_data(fkwcs_data.gpay_cart_data);
            }
            return $(`<div class='fkwcs_google_pay_wrapper fkwcs_smart_product_button ${indentifier}'></div>`).html(this.google_pay_client.createButton(callback));
        }


        createGpayButton() {
            if (this.gpay_button_html) {
                this.gpay_button_html.remove();
            }
            if ('yes' === fkwcs_data.google_pay_as_regular) {
                this.gpay_button_html = this.createGooglePayButton(this.getGatewayOptions(), 'fkwcs-gpay-button-container fkwcs_wallet_gateways');
                $('#place_order').after(this.gpay_button_html);
            }
        }

        appendGpayBrowserBased() {

            if (false === this.express_gpay_available) {
                $(this.gateway_class).hide();
            }
            if ($('.woocommerce-checkout-payment').find('.fkwcs_wallet_gateways_gpay').length > 0) {
                $('.fkwcs_wallet_gateways_gpay').show();
                return;
            }
            setTimeout(() => {
                $('.fkwcs_wallet_gateways_gpay').remove();
                let place_order = $('#place_order');
                let stripeGpay = $('#fkwcs_custom_express_button .fkwcs_express_google_pay');
                if (stripeGpay.length > 0) {
                    place_order.after($('#fkwcs_custom_express_button').clone().addClass('fkwcs_wallet_gateways fkwcs_wallet_gateways_gpay'));
                    $('.fkwcs_wallet_gateways_gpay').show();
                    $(this.gateway_class).show();
                }
            }, 300);
        }

        createCheckoutExpressBtn() {
            if ('yes' !== fkwcs_data.google_pay_as_express || 'yes' !== fkwcs_data.is_checkout) {
                return;
            }
            let checkout_express = $('#wfacp_smart_button_fkwcs_google_pay');
            if (checkout_express.length > 0) {
                checkout_express.html(this.createGooglePayButton(this.getExpressOptions(), 'fkwcs-express-wfacp'));
                this.hidePaymentRequestBtn();
                checkout_express.show();
            } else {

                let native_checkout = jQuery('.fkwcs_custom_express_button').eq(0);
                if (native_checkout.length > 0) {
                    native_checkout.after(this.createGooglePayButton(this.getExpressOptions(), 'fkwcs-express-native'));
                    this.hidePaymentRequestBtn();
                }
            }
        }

        hidePaymentRequestBtn() {
            let link_button = $('.fkwcs_express_payment_request_api');// Check for Link
            let apple_pay_button = $('.fkwcs_express_apple_pay');// Check for Link
            let google_pay_button = $('.fkwcs_express_google_pay');// Check for Link
            if (apple_pay_button.length > 0) {
                return;
            }

            if (link_button.length > 0 || google_pay_button.length > 0) {
                google_pay_button?.hide();
                link_button?.hide();
            }

        }


        /**
         * Create button for Express Button on Checkout page.
         * @returns {{onClick: any, buttonType: *, buttonColor: *}}
         */

        getExpressOptions() {
            return {
                buttonColor: fkwcs_data.google_pay_btn_color,
                buttonType: fkwcs_data.google_pay_btn_theme,
                onClick: this.startExpressGpayPayment.bind(this),
            };
        }

        /**
         * Create Button in Regular Payment Gateway
         * @returns {{onClick: any, buttonType: *, buttonColor: *}}
         */

        getGatewayOptions() {
            return {
                buttonColor: fkwcs_data.google_pay_btn_color,
                buttonType: fkwcs_data.google_pay_btn_theme,
                onClick: this.startGpayPayment.bind(this),
            };
        }

        startExpressGpayPayment() {
            this.express_btn_click = true;
            this.startGpayPayment();
        }


        startGpayPayment() {
            this.google_pay_client.loadPaymentData(this.buildGpayPaymentData()).then(this.processGpayData.bind(this)).catch((error) => {
                if (error.statusCode === "CANCELED") {
                    return;
                }
                if (error.statusMessage && error.statusMessage.indexOf("paymentDataRequest.callbackIntent") > -1) {
                    this.showError({"message": "DEVELOPER_ERROR_WHITELIST"});
                } else {
                    this.showError({"message": error.statusMessage});
                }
            });
        }


        processGpayData(paymentData) {
            let data = JSON.parse(paymentData.paymentMethodData.tokenizationData.token);
            this.updateAddress(paymentData);
            // convert token to payment method
            this.stripe.createPaymentMethod({
                type: 'card',
                card: {token: data.id},
                billing_details: this.getBillingAddress()
            }).then((result) => {
                if (result.error) {
                    return this.showError(result.error);
                }
                this.appendMethodId(result.paymentMethod.id);
            });
        }

        shippingAddressRequired() {

            if ('yes' === fkwcs_data.is_change_payment_page || 'yes' === fkwcs_data.is_pay_for_order_page) {
                if ('yes' !== fkwcs_data.gpay_cart_data.shipping_required) {
                    return false;
                }
            }
            if ('yes' !== fkwcs_data.shipping_required) {
                return false;
            }
            if (!this.isCheckoutPage()) {
                return true;
            }
            let field = ['address_1', 'city', 'postcode', 'state', 'country'];

            if ($('form.checkout').length > 0) {
                let type = 'billing';
                if ($('#ship-to-different-address-checkbox').is(":checked")) {
                    type = 'shipping';
                }
                for (let i = 0; i < field.length; i++) {
                    let key = field[i];
                    let required = this.field_required(`${type}_${key}`);
                    if (required) {
                        return true;
                    }
                }
            }


            return false;
        }

        isCheckoutPage() {
            return $('form.checkout').length > 1;
        }

        buildGpayPaymentData() {
            let request = $.extend({}, this.googlePayVersion(), {
                emailRequired: this.field_required('billing_email') || !this.isCheckoutPage(),
                environment: ('test' === fkwcs_data.mode ? 'TEST' : 'PRODUCTION'),
                merchantInfo: {
                    merchantName: fkwcs_data.google_pay.merchant_name,
                    merchantId: fkwcs_data.google_pay.merchant_id,
                },
                allowedPaymentMethods: [this.getBaseCardBrand()],
            });


            request.shippingAddressRequired = this.shippingAddressRequired();

            request.callbackIntents = ["PAYMENT_AUTHORIZATION"];
            request.allowedPaymentMethods[0].parameters.billingAddressRequired = this.billingAddressRequired();
            if (request.allowedPaymentMethods[0].parameters.billingAddressRequired) {
                request.allowedPaymentMethods[0].parameters.billingAddressParameters = {
                    format: "FULL",
                    phoneNumberRequired: this.field_required('billing_phone') || !this.isCheckoutPage()
                };
            }
            request = $.extend(request, this.request_data);
            if (request.shippingAddressRequired) {
                request.shippingAddressParameters = {};
                request.shippingOptionRequired = true;
                request.shippingOptionParameters = {
                    shippingOptions: this.shipping_options
                };
                request.callbackIntents = ["SHIPPING_ADDRESS", "SHIPPING_OPTION", "PAYMENT_AUTHORIZATION"];

            }
            return request;
        }


        update_transaction_data(fkwcs_google_pay_data) {
            let disaplay_items = fkwcs_google_pay_data.order_data.displayItems;
            this.request_data = {
                transactionInfo: {
                    countryCode: fkwcs_google_pay_data.order_data.country_code.toUpperCase(),
                    currencyCode: fkwcs_google_pay_data.order_data.currency.toUpperCase(),
                    totalPriceStatus: "ESTIMATED",
                    totalPrice: fkwcs_google_pay_data.order_data.total.amount.toString(),
                    displayItems: disaplay_items,
                    totalPriceLabel: fkwcs_google_pay_data.order_data.total.label
                }
            };
            if (fkwcs_google_pay_data?.shipping_options) {
                this.shipping_options = fkwcs_google_pay_data?.shipping_options;
            }
        }

        field_required(key) {
            if ($('form.checkout').length > 0) {
                return $(`#${key}_field`)?.length > 0 && $(`#${key}_field`).hasClass('validate-required') && '' == $(`#${key}`).val();
            }
            return false;
        }


        hidePlaceOrder() {
            $('.fkwcs-gpay-button-container')?.show();
            $('#place_order')?.hide();
            $('#place_order')?.addClass('fkwcs_hidden');
        }

        showPlaceOrder() {
            this.hideGatewayWallets();
        }

        appendMethodId(source) {
            super.appendMethodId(source);
            if (this.express_btn_click) {
                $('#terms')?.prop('checked', true);
            }
            $('#payment_method_fkwcs_stripe_google_pay')?.trigger('click');

            if ($('form#order_review').length > 0) {
                $('form#order_review').trigger('submit');
            } else {
                $('form.checkout').trigger('submit');

            }
        }

        /**
         * Update checkout field with address details and also return in json data.
         * @param type
         * @param addressData
         * @returns {{}}
         */
        mapAddress(type, addressData) {

            let json = {};
            if (addressData.hasOwnProperty('address1')) {
                $(`#${type}_address_1`)?.val(addressData?.address1);
                json[`line_1`] = addressData?.address1;
            }
            if (addressData.hasOwnProperty('address2')) {
                $(`#${type}_address_2`)?.val(addressData?.address2 + addressData?.address3);
                json[`line_2`] = addressData?.address2 + addressData?.address3;

            }
            if (addressData.hasOwnProperty('locality')) {
                $(`#${type}_city`)?.val(addressData?.locality);
                json[`city`] = addressData?.locality;
            }
            if (addressData.hasOwnProperty('postalCode')) {
                $(`#${type}_postcode`)?.val(addressData?.postalCode);
                json[`postal_code`] = addressData?.postalCode;
            }
            if (addressData.hasOwnProperty('administrativeArea')) {
                $(`#${type}_state`)?.val(addressData?.administrativeArea);
                json[`state`] = addressData?.administrativeArea;
            }
            if (addressData.hasOwnProperty('countryCode')) {
                $(`#${type}_country`)?.val(addressData?.countryCode);
                json[`country`] = addressData?.countryCode;
            }
            if (addressData.hasOwnProperty('name')) {
                json[`name`] = addressData.name;
                if ($(`#${type}_last_name`).length > 0) {
                    let names = addressData.name.split(' ');
                    let first_name = names[0];
                    let last_name = names.slice(1).join(' ');
                    $(`#${type}_first_name`).val(first_name);
                    $(`#${type}_last_name`).val(last_name);
                } else {
                    $(`#${type}_first_name`).val(addressData.name);
                }
            }
            return json;

        }

        confirmStripePayment(clientSecret, redirectURL, intent_type, order_id = false) {

            let cardPayment = null;


            if (intent_type == 'si') {
                cardPayment = this.stripe.handleCardSetup(clientSecret, {payment_method: this.getMethodId()}, {handleActions: false});
            } else {
                cardPayment = this.stripe.confirmCardPayment(clientSecret, {payment_method: this.getMethodId()}, {handleActions: false});
            }


            cardPayment.then((result) => {
                if (result.error) {
                    this.showNotice(result.error);
                    let source_el = $('.fkwcs_source');
                    if (source_el.length > 0) {
                        source_el.remove();
                    }
                    if (result.error.hasOwnProperty('type') && result.error.type === 'api_connection_error') {
                        return;
                    }
                    this.logError(result.error, order_id);
                } else {
                    let intent = result[('si' === intent_type) ? 'setupIntent' : 'paymentIntent'];
                    if (intent.status === "requires_action" || intent.status === "requires_source_action") {
                        let cardPaymentRetry = null;
                        // Let Stripe.js handle the rest of the payment flow.
                        if (intent_type == 'si') {
                            cardPaymentRetry = this.stripe.handleCardSetup(clientSecret);
                        } else {
                            cardPaymentRetry = this.stripe.confirmCardPayment(clientSecret);

                        }
                        cardPaymentRetry.then((result) => {
                            if (result.error) {
                                this.showNotice(result.error);
                                let source_el = $('.fkwcs_source');
                                if (source_el.length > 0) {
                                    source_el.remove();
                                }
                                if (result.error.hasOwnProperty('type') && result.error.type === 'api_connection_error') {
                                    return;
                                }
                                this.logError(result.error, order_id);
                            } else {
                                window.location = redirectURL;
                            }
                        });
                        return;
                    }
                    window.location = redirectURL;
                }
            }).catch(function (error) {

                // Report back to the server.
                $.get(redirectURL + '&is_ajax');
            });
        }

        /**
         * Update User details in checkout field also Return in json
         * @param paymentData
         * @returns {{}}
         */
        updateAddress(paymentData) {
            let user_details = {};

            let shipping_address = paymentData.hasOwnProperty('shippingAddress') ? paymentData.shippingAddress : null;
            if (null !== shipping_address) {
                user_details.shipping = this.mapAddress('shipping', shipping_address);
                user_details.shipping.shipping_method = paymentData?.shippingOptionData;
                $('#ship_to_different_address').prop('checked', true);
            }
            let billing_address = (paymentData.hasOwnProperty('paymentMethodData') && paymentData.paymentMethodData.hasOwnProperty('info') && paymentData.paymentMethodData.info.hasOwnProperty('billingAddress')) ? paymentData.paymentMethodData.info.billingAddress : null;
            if (null !== billing_address) {
                user_details.billing = this.mapAddress('billing', billing_address);
            }
            if (null == billing_address && null !== shipping_address) {
                user_details.billing = this.mapAddress('billing', shipping_address);
            }
            if (null !== billing_address && billing_address.hasOwnProperty('phoneNumber')) {
                user_details.phone = billing_address?.phoneNumber;
            }

            if (paymentData?.email) {
                $('#billing_email')?.val(paymentData?.email);
                user_details.email = paymentData?.email;
            }
            if (paymentData?.phone) {
                user_details.phone = paymentData?.phone;
                $('#billing_phone')?.val(paymentData?.phone);
                $('#shipping_phone')?.val(paymentData?.phone);
            }

            return user_details;
        }

    }

    class FKWCS_AliPay extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_alipay_error';
            this.confirmCallBack = 'confirmAlipayPayment';
        }


    }


    function init_gateways() {

        const pubKey = fkwcs_data.pub_key;
        const mode = fkwcs_data.mode;
        if ('' === pubKey || ('live' === mode && !fkwcs_data.is_ssl)) {
            console.log('Live Payment Mode only work only https protocol ');
            return;
        }
        try {
            let betas = ['link_beta_2'];
            if ('yes' === fkwcs_data.link_authentication && 'payment' === fkwcs_data.card_form_type) {
                betas = ['link_autofill_modal_beta_1'];
            }

            const stripe = Stripe(pubKey, {locale: fkwcs_data.locale, 'betas': betas});
            available_gateways.card = new FKWCS_Stripe(stripe, 'fkwcs_stripe');
            available_gateways.p24 = new FKWCS_P24(stripe, 'fkwcs_stripe_p24');
            available_gateways.sepa_debit = new FKWCS_Sepa(stripe, 'fkwcs_stripe_sepa');
            available_gateways.ideal = new FKWCS_Ideal(stripe, 'fkwcs_stripe_ideal');
            available_gateways.bancontact = new FKWCS_BanContact(stripe, 'fkwcs_stripe_bancontact');
            available_gateways.affirm = new FKWCS_AFFIRM(stripe, 'fkwcs_stripe_affirm');
            available_gateways.klarna = new FKWCS_KLARNA(stripe, 'fkwcs_stripe_klarna');
            available_gateways.afterpay = new FKWCS_AFTERPAY(stripe, 'fkwcs_stripe_afterpay');
            available_gateways.mobilepay = new FKWCS_MOBILEPAY(stripe, 'fkwcs_stripe_mobilepay');

            available_gateways.google_pay = new FKWCS_GOOGLEPAY(stripe, 'fkwcs_stripe_google_pay');
            available_gateways.apple_pay = new FKWCS_ApplePay(stripe, 'fkwcs_stripe_apple_pay');
            available_gateways.alipay = new FKWCS_AliPay(stripe, 'fkwcs_stripe_alipay');
            $(document).trigger('fkwcs_gateway_loaded', {
                "Gateway": Gateway,
                "LocalGateway": LocalGateway,
                "FKWCS_Stripe": FKWCS_Stripe,
                "FKWCS_P24": FKWCS_P24,
                "FKWCS_Sepa": FKWCS_Sepa,
                "FKWCS_Ideal": FKWCS_Ideal,
                "FKWCS_BanContact": FKWCS_BanContact,
                "FKWCS_AFFIRM": FKWCS_AFFIRM,
                "FKWCS_KLARNA": FKWCS_KLARNA,
                "FKWCS_AFTERPAY": FKWCS_AFTERPAY,
                "FKWCS_MOBILEPAY": FKWCS_MOBILEPAY,
                'stripe_object': stripe
            });
        } catch (e) {
            console.log(e);
        }

        window.addEventListener('hashchange', function () {

            let partials = window.location.hash.match(/^#?fkwcs-confirm-(pi|si)-([^:]+):(.+):(.+):(.+):(.+)$/);
            if (null == partials) {
                partials = window.location.hash.match(/^#?fkwcs-confirm-(pi|si)-([^:]+):(.+)$/);
            }
            if (!partials || 4 > partials.length) {
                return;
            }

            history.pushState({}, '', window.location.pathname);
            $(window).trigger('fkwcs_on_hash_change', [partials]);
        });


    }

    init_gateways();
});

