/**
 * global fkwcs_data
 * global Stripe
 */
jQuery(function ($) {
    const style = fkwcs_data.common_style;
    const available_gateways = {};
    let current_upe_gateway = 'card';
    let wcCheckoutForm = $('form.woocommerce-checkout');
    const homeURL = fkwcs_data.get_home_url;
    const stripeLocalized = fkwcs_data.stripe_localized;

    function scrollToDiv(id) {
        if (jQuery(id).length === 0) {
            return;
        }
        jQuery('html, body').animate({
            scrollTop: jQuery(id).offset().top
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
                if (self.gateway_id === $(this).val()) {
                    self.resetGateway();
                }
            });
            $(document.body).on('updated_checkout', function (e, v) {
                if (self.gateway_id === self.selectedGateway()) {
                    token_radio.trigger('change');
                    self.mountGateway();
                }
                if (undefined !== v && null !== v) {
                    self.update_fragment_data(v.fragments);
                }
            });

            if (document.readyState === 'complete' || document.readyState === 'loading') {
                $(document).ready(function () {
                    if (self.gateway_id === self.selectedGateway()) {
                        self.mountGateway();
                    }
                    self.ready();
                });
            } else {
                $(window).on('load', function () {
                    if (self.gateway_id === self.selectedGateway()) {
                        self.mountGateway();
                    }
                    self.ready();
                });
            }

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

        ready() {

        }

        fastRender() {

        }

        setupGateway() {

        }


        resetGateway() {

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

        getAddress() {
            const billingCountry = document.getElementById('billing_country');
            const billingPostcode = document.getElementById('billing_postcode');
            const billingCity = document.getElementById('billing_city');
            const billingState = document.getElementById('billing_state');
            const billingAddress1 = document.getElementById('billing_address_1');
            const billingAddress2 = document.getElementById('billing_address_2');

            return {
                country: null !== billingCountry ? billingCountry.value : '',
                city: null !== billingCity ? billingCity.value : '',
                postal_code: null !== billingPostcode ? billingPostcode.value : '',
                state: null !== billingState ? billingState.value : '',
                line1: null !== billingAddress1 ? billingAddress1.value : '',
                line2: null !== billingAddress2 ? billingAddress2.value : '',
            };
        }

        getBillingAddress(type) {
            if ($('form#order_review').length > 0) {
                return fkwcs_data.current_user_billing;
            }

            if (typeof type !== undefined && 'add_payment' === type) {
                return {};
            }
            const billingFirstName = document.getElementById('billing_first_name');
            const billingLastName = document.getElementById('billing_last_name');
            const billingEmail = document.getElementById('billing_email');
            const billingPhone = document.getElementById('billing_phone');

            const firstName = null !== billingFirstName ? billingFirstName.value : '';
            const lastName = null !== billingLastName ? billingLastName.value : '';

            return {
                name: firstName + ' ' + lastName,
                email: null !== billingEmail ? billingEmail.value : '',
                phone: null !== billingPhone ? billingPhone.value : '',
                address: this.getAddress(),
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
            let parent = $(`#${this.gateway_id}_payment_data`);
            if (parent.length === 0) {
                return {'amount': 0, 'currency': 'USD'};
            }

            let amount = parent.data('amount');
            let currency = parent.data('currency');
            return {'amount': parseFloat(amount), 'currency': currency.toUpperCase()};
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

        confirmStripePayment(clientSecret, redirectURL, intent_type, authenticationAlready = false, order_id = false) {
            if ('' === this.confirmCallBack || !this.stripe.hasOwnProperty(this.confirmCallBack)) {
                return;
            }

            if (this.gateway_id === this.selectedGateway()) {
                this.stripe[this.confirmCallBack](clientSecret, {
                    payment_method: this.paymentMethods(),
                    payment_method_options: this.paymentMethodOptions(),
                    return_url: homeURL + redirectURL,
                }).then((response) => {
                    if (response.error) {
                        this.showError(response.error);
                        return;
                    }
                    this.successResponse(response, redirectURL);
                }).catch(() => {
                    this.showError('user cancelled');
                });
            }
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

        resetGateway() {
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
            this.payment_data = {};
            this.element_data = {};
        }


        setupGateway() {

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


        resetGateway() {

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


            this.stripe.createPaymentMethod({
                type: 'card', card: this.getCardElement(), billing_details: this.getBillingAddress(type),
            }).then((response) => {

                this.handleSourceResponse(response);
            });
        }

        handleSourceResponse(response) {
            if (response.error) {
                this.showNotice(response.error);
                return;
            }
            if (response.paymentMethod) {
                this.appendMethodId(response.paymentMethod.id);
                if ($('form#order_review').length && 'yes' === fkwcs_data.is_change_payment_page) {
                    this.create_setup_intent(response.paymentMethod.id, $('form#order_review'));
                } else if ($('form#add_payment_method').length) {
                    this.create_setup_intent(response.paymentMethod.id, $('form#add_payment_method'));
                } else {
                    if ($('form#order_review').length > 0) {
                        $('form#order_review').trigger('submit');
                    } else {
                        $('form.checkout').trigger('submit');

                    }
                }
            }
        }

        create_setup_intent(payment_method, form_el) {
            let process_data = {
                'action': 'fkwcs_create_setup_intent', 'fkwcs_nonce': fkwcs_data.fkwcs_nonce, 'fkwcs_source': payment_method
            };

            $.ajax({
                type: 'POST', dataType: 'json', url: fkwcs_data.admin_ajax, data: process_data, beforeSend: () => {
                    $('body').css('cursor', 'progress');
                }, success: (response) => {
                    if (response.status === 'success') {
                        const clientSecret = response.data.client_secret;
                        let confirmSetup = this.stripe.confirmCardSetup(clientSecret, {payment_method: payment_method});
                        confirmSetup.then((resp) => {
                            if (resp.hasOwnProperty('error')) {
                                form_el.unblock();
                                this.showNotice(resp.error);

                                return;
                            }

                            form_el.trigger('submit');
                        });
                        confirmSetup.catch((error) => {
                            form_el.unblock();
                            console.log('error', error);

                        });

                    } else if (response.status === false) {
                        return false;
                    }
                    $('body').css('cursor', 'default');
                }, error() {
                    $('body').css('cursor', 'default');
                    alert('Something went wrong!');
                },
            });
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
            let source = this.hasSource();
            if ('' === source) {
                this.createSource('add_payment');
                e.preventDefault();
                return false;
            }
        }

        onEarlyRenewalSubmit(e) {
            e.preventDefault();

            $.ajax({
                url: $('#early_renewal_modal_submit').attr('href'), method: 'get', success: (html) => {
                    let response = JSON.parse(html);
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


                    // Cleanup the URL
                    this.confirmStripePayment(intentClientSecret, redirectURL, type, order_id);
                }


            }
            // Subscription early renewals modal.
            if ($('#early_renewal_modal_submit[data-payment-method]').length) {
                $('#early_renewal_modal_submit[data-payment-method=fkwcs_stripe]').on('click', this.onEarlyRenewalSubmit.bind(this));
            } else {
                $('#early_renewal_modal_submit').on('click', this.onEarlyRenewalSubmit.bind(this));
            }
            $(document.body).on('change', '.woocommerce-SavedPaymentMethods-tokenInput', function () {
                let name = $(this).attr('name');
                let el = $('.fkwcs-stripe-elements-wrapper');
                if (name == 'wc-fkwcs_stripe-payment-token') {
                    let vl = $(this).val();
                    if ('new' == vl) {
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
            this.payment_data = fkwcs_data.fkwcs_payment_data;
            this.element_data = this.payment_data.element_data;
            this.element_options = this.payment_data.element_options;
            this.createStripeElements();

        }

        createStripeElements(options = {}) {
            this.elements = this.stripe.elements(this.element_data);
            this.payment = this.elements.create('payment', this.element_options);

            this.payment.on('change', function (event) {
                current_upe_gateway = event.value.type;
            });
            this.link(this.elements);
        }

        mountElements() {
            let selector = '.fkwcs-stripe-payment-elements-field.StripeElement';
            if ($(selector).length === 0 && null !== this.payment) {
                this.payment.mount('.fkwcs-stripe-payment-elements-field');
            }
        }


        updatableElementKeys() {
            return ['locale', 'mode', 'currency', 'amount', 'setup_future_usage', 'capture_method', 'payment_method_types', 'appearance', 'on_behalf_of'];
        }

        update_fragment_data(fragments) {
            super.update_fragment_data(fragments);
            this.updateElements();
        }

        updateElements() {
            if ('payment' !== fkwcs_data.card_form_type) {
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
                if ('yes' === fkwcs_data.link_authentication_page_load) {
                    modal.launch({email: $('#billing_email').val()});
                }
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
            names = names.splice(0, 1);
            let last_name = names.join(' ');
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

        resetGateway() {
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

        resetGateway() {
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
                    this.createPaymentMethod();
                    return false;
                }
            }
        }

        createPaymentMethod() {
            /**
             * @todo
             * need return true if cart total is 0
             */

            this.stripe.createPaymentMethod({
                type: 'sepa_debit', sepa_debit: this.sepa, billing_details: this.getBillingAddress(),
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

    class FKWCS_Ideal extends Gateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.selectedIdealBank = '';
            this.error_cotainer = '.fkwcs_stripe_ideal_error';
        }

        setupGateway() {
            this.ideal = this.elements.create('idealBank', {"style": style});
            this.ideal.on('change', (event) => {
                this.selectedIdealBank = event.value;
                this.showError();
            });

        }

        resetGateway() {
            this.ideal.unmount('.fkwcs_stripe_ideal_form .fkwcs_stripe_ideal_select');
            this.mountGateway();
        }

        mountGateway() {
            let ideal_form = $('.fkwcs_stripe_ideal_form');
            if (0 === ideal_form.length) {
                return;
            }
            ideal_form.show();
            this.ideal.mount('.fkwcs_stripe_ideal_form .fkwcs_stripe_ideal_select');
            $('.fkwcs_stripe_ideal_form .fkwcs_stripe_ideal_select').css({backgroundColor: '#fff'});
        }

        processingSubmit(e) {


            if ('' === this.selectedIdealBank) {
                this.showError({message: fkwcs_data.empty_bank_message});
                this.showNotice(fkwcs_data.empty_bank_message);
                return false;
            }
            this.showError();

        }


        confirmStripePayment(clientSecret, redirectURL, intent_type, authenticationAlready = false, order_id = false) {

            if (this.gateway_id === this.selectedGateway()) {
                let ideal = this.ideal;
                this.stripe.confirmIdealPayment(clientSecret, {
                    payment_method: {
                        ideal, billing_details: this.getBillingAddress(),
                    }, return_url: homeURL + redirectURL,
                }).then((result) => {
                    if (result.error) {
                        // Show error to your customer (e.g., insufficient funds)
                        this.logError(result.error, order_id);
                        this.showNotice(getStripeLocalizedMessage(result.error.code, result.error.message));
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

            //available_gateways.payments = new FKWCS_Payments(stripe, 'fkwcs_payment');
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