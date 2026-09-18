/**
 * global fkwcs_data
 * global Stripe
 */
jQuery(function ($) {
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
	const style = fkwcs_data.common_style;
    window.fkwcsIsDomLoaded = false;
    const available_gateways = {};
    let _current_upe_gateway = 'card';
    let wcCheckoutForm = $('form.woocommerce-checkout');
    const homeURL = fkwcs_data.get_home_url;
    const stripeLocalized = fkwcs_data.stripe_localized;

    window.fkwcsStripeGatewaysInitDone = window.fkwcsStripeGatewaysInitDone || false;


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

    let fkwcsStripeSdkInjectPromise = null;
    const fkwcsSdkRecovery = {
        attempt: 0,
        maxAttempts: 0,
        inFlight: false,
        loggedFinalFailure: false,
        initialDelayMs: 30,
        backoffStepMs: 350,
        maxDelayMs: 2500,
    };

    (function fkwcsApplyStripeSdkRecoveryFromLocalized() {
        const r = fkwcs_data.stripe_sdk_init_recovery;
        if (!r || typeof r !== 'object') {
            return;
        }
        const max = parseInt(r.max_attempts, 10);
        if (!isNaN(max)) {
            fkwcsSdkRecovery.maxAttempts = Math.max(0, Math.min(20, max));
        }
        const id = parseInt(r.initial_delay_ms, 10);
        if (!isNaN(id)) {
            fkwcsSdkRecovery.initialDelayMs = Math.max(0, Math.min(60000, id));
        }
        const bs = parseInt(r.backoff_step_ms, 10);
        if (!isNaN(bs)) {
            fkwcsSdkRecovery.backoffStepMs = Math.max(0, Math.min(60000, bs));
        }
        const md = parseInt(r.max_delay_ms, 10);
        if (!isNaN(md)) {
            fkwcsSdkRecovery.maxDelayMs = Math.max(0, Math.min(60000, md));
        }
    })();

    function fkwcsGetStripeJsUrl() {
        const u = (fkwcs_data.stripe_js_url || 'https://js.stripe.com/v3/').trim();
        return u || 'https://js.stripe.com/v3/';
    }

    function fkwcsIsStripeUnavailableError(e) {
        if (typeof Stripe === 'undefined') {
            return true;
        }
        if (!e) {
            return false;
        }
        if (e.name === 'ReferenceError' && /Stripe/i.test(String(e.message))) {
            return true;
        }
        return false;
    }

    function fkwcsWaitForStripeGlobal(timeoutMs, intervalMs) {
        return new Promise(function (resolve, reject) {
            const start = Date.now();
            (function tick() {
                if (typeof Stripe === 'function') {
                    resolve();
                    return;
                }
                if (Date.now() - start >= timeoutMs) {
                    reject(new Error('fkwcs_stripe_global_timeout'));
                    return;
                }
                setTimeout(tick, intervalMs);
            })();
        });
    }

    function fkwcsStripeScriptAlreadyInDocument() {
        const scripts = document.getElementsByTagName('script');
        for (let i = 0; i < scripts.length; i++) {
            const src = scripts[i].src || '';
            if (src.indexOf('js.stripe.com') !== -1) {
                return true;
            }
        }
        return false;
    }

    function fkwcsInjectStripeScript(forceRefresh) {
        if (typeof Stripe === 'function') {
            return Promise.resolve();
        }
        if (!forceRefresh && fkwcsStripeSdkInjectPromise) {
            return fkwcsStripeSdkInjectPromise;
        }
        if (forceRefresh) {
            fkwcsStripeSdkInjectPromise = null;
        }
        let url = fkwcsGetStripeJsUrl();
        if (forceRefresh) {
            url += (url.indexOf('?') >= 0 ? '&' : '?') + '_fkwcs_retry=' + String(Date.now());
        }
        fkwcsStripeSdkInjectPromise = new Promise(function (resolve, reject) {
            const s = document.createElement('script');
            s.src = url;
            s.async = true;
            s.onload = function () {
                if (typeof Stripe === 'function') {
                    resolve();
                } else {
                    fkwcsStripeSdkInjectPromise = null;
                    reject(new Error('fkwcs_stripe_missing_after_load'));
                }
            };
            s.onerror = function () {
                fkwcsStripeSdkInjectPromise = null;
                reject(new Error('fkwcs_stripe_script_network_error'));
            };
            document.head.appendChild(s);
        });
        return fkwcsStripeSdkInjectPromise;
    }

    function fkwcsEnsureStripeJs(forceRefresh) {
        if (typeof Stripe === 'function') {
            return Promise.resolve();
        }
        if (fkwcsStripeScriptAlreadyInDocument() && !forceRefresh) {
            return fkwcsWaitForStripeGlobal(12000, 75).catch(function () {
                return fkwcsInjectStripeScript(true);
            });
        }
        return fkwcsInjectStripeScript(forceRefresh);
    }

    /**
     * Silent recovery: load Stripe.js and re-run init_gateways() until success or max attempts.
     * No user-visible errors; final failure is console-only.
     */
    function fkwcsQueueStripeSdkRecovery() {
        if (fkwcsSdkRecovery.maxAttempts < 1) {
            return;
        }
        if (window.fkwcsStripeGatewaysInitDone || fkwcsSdkRecovery.inFlight) {
            return;
        }
        if (fkwcsSdkRecovery.attempt > fkwcsSdkRecovery.maxAttempts) {
            return;
        }
        fkwcsSdkRecovery.inFlight = true;
        fkwcsSdkRecovery.attempt += 1;
        if (fkwcsSdkRecovery.attempt > fkwcsSdkRecovery.maxAttempts) {
            fkwcsSdkRecovery.inFlight = false;
            return;
        }
        const delay = fkwcsSdkRecovery.attempt <= 1 ? fkwcsSdkRecovery.initialDelayMs : Math.min(fkwcsSdkRecovery.maxDelayMs, fkwcsSdkRecovery.backoffStepMs * fkwcsSdkRecovery.attempt);
        setTimeout(function () {
            /**
             * Always force refresh in recovery: if a Stripe <script> exists but Stripe is still
             * undefined (blocked, slow, or failed), fkwcsEnsureStripeJs(false) waits up to 12s
             * polling — that caused multi-second first-attempt delays. Forcing inject skips that wait.
             */
            fkwcsEnsureStripeJs(true).then(function () {
                fkwcsSdkRecovery.inFlight = false;
                init_gateways();
                if (!window.fkwcsStripeGatewaysInitDone) {
                    fkwcsQueueStripeSdkRecovery();
                }
            }).catch(function () {
                fkwcsSdkRecovery.inFlight = false;
                fkwcsStripeSdkInjectPromise = null;
                fkwcsQueueStripeSdkRecovery();
            });
        }, delay);
    }

    function fkwcsRemountActiveStripeGateway() {
        try {
            const sel = $('input[name="payment_method"]:checked').val();
            if (!sel) {
                return;
            }
            for (const key in available_gateways) {
                if (!Object.prototype.hasOwnProperty.call(available_gateways, key)) {
                    continue;
                }
                const gw = available_gateways[key];
                if (gw && gw.gateway_id === sel && typeof gw.mountGateway === 'function') {
                    gw.mountGateway();
                }
            }
        } catch (err) { /* noop */ }
    }

    function scrollToDiv(id, offset) {
        if (typeof offset === 'undefined') {
            offset = 0;
        }
        if ($(id).length === 0) {
            return;
        }
        $('html, body').animate({
            scrollTop: $(id).offset().top - offset
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

        /**
         * Resolve the Express Checkout Element `click` event for the inline wallet
         * gateways (Apple Pay / Google Pay) rendered on the checkout page.
         *
         * Honors the wallet's "Disable Shipping Info in Payment Wallet" setting
         * (fkwcs_data.apple_pay_disable_shipping / google_pay_disable_shipping): when the
         * setting is on, or the cart needs no shipping, Stripe is told not to collect a
         * shipping address in the wallet sheet. Mirrors express-checkout.js::expressClick.
         * `event.resolve()` must be called synchronously, so no awaits here.
         *
         * @param {object} event              Stripe Express Checkout Element `click` event.
         * @param {string} disableShippingKey  fkwcs_data flag key for this wallet.
         * @return {void}
         */
        resolveExpressCheckoutClick(event, disableShippingKey) {
            let shippingAddressRequired = 'yes' === fkwcs_data.shipping_required;
            if ('yes' === fkwcs_data[disableShippingKey]) {
                shippingAddressRequired = false;
            }
            const options = {shippingAddressRequired: shippingAddressRequired};
            if (shippingAddressRequired) {
                // Placeholder rate keeps the wallet sheet valid; the order's real shipping
                // method comes from the WooCommerce checkout form when it is submitted.
                options.shippingRates = [{id: 'pending', displayName: 'Pending', amount: 0}];
            }
            event.resolve(options);
        }

        prepareStripe() {
            this.elements = this.stripe.elements({"appearance": this.getAppearance()});
            this.setupGateway();
            this.wc_events();
        }

        getAppearance() {
            return {};
        }

        /**
         * Redirect after a confirmed payment/setup intent.
         *
         * Detaches any lingering `beforeunload` handlers (theme, abandoned-cart
         * plugins, WooCommerce checkout guards) before navigating, so the
         * intentional post-confirmation redirect never triggers the browser's
         * "Changes you made may not be saved" prompt. The prompt only surfaces
         * once the page has user activation - e.g. after a manual 3DS challenge
         * click - which is why frictionless flows never show it.
         *
         * @param {string} url Destination URL (order received / confirmation page).
         * @return {void}
         */
        safeRedirect(url) {
            $(window).off('beforeunload');
            window.onbeforeunload = null;
            window.location = url;
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
            const placeOrderBtn = $('#place_order');
            if (placeOrderBtn.length) {
                placeOrderBtn.show();
                placeOrderBtn.removeClass('fkwcs_hidden');
            }
            this.hideGatewayWallets();
        }

        hidePlaceOrder() {
            const placeOrderBtn = $('#place_order');
            if (placeOrderBtn.length) {
                placeOrderBtn.hide();
                placeOrderBtn.addClass('fkwcs_hidden');
            }
        }

        hideGatewayWallets() {
            const gatewayWallets = $(this.gateway_wallet_wrapper_class);
            if (gatewayWallets.length) {
                gatewayWallets.hide();
            }
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

        processingSubmit() {

        }

        processOrderReview() {

        }

        add_payment_method() {

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

            // Handle dynamic element data for gateways that become available after country change
            if (fragments.hasOwnProperty('fkwcs_element_data') && fragments.fkwcs_element_data) {
                this.handle_dynamic_element_data(fragments.fkwcs_element_data);
            }
        }

        /**
         * Handle dynamic element data from fragments
         * This updates fkwcs_data with new element data for gateways that become available
         *
         * @param element_data Object containing element data for each gateway
         */
        handle_dynamic_element_data(element_data) {
            try {
                // Validate element_data is an object
                if (!element_data || typeof element_data !== 'object') {
                    console.log('Invalid element_data received:', element_data);
                    return;
                }

                // Update fkwcs_data with element data for each gateway
                for (let gateway_id in element_data) {
                    if (element_data.hasOwnProperty(gateway_id)) {
                        try {
                            // Map gateway IDs to their data keys
                            let data_key = this.get_element_data_key(gateway_id);
                            if (data_key && element_data[gateway_id]) {
                                fkwcs_data[data_key] = element_data[gateway_id];

                                // Trigger reinit for this specific gateway instance
                                if (this.gateway_id === gateway_id) {
                                    this.reinitialize_gateway();
                                }
                            }
                        } catch (e) {
                            console.log('Error processing gateway ' + gateway_id + ':', e);
                        }
                    }
                }
            } catch (e) {
                console.log('Error in handle_dynamic_element_data:', e);
            }
        }

        /**
         * Get the fkwcs_data key for a gateway ID
         *
         * @param gateway_id Gateway ID
         * @return string Data key for fkwcs_data
         */
        get_element_data_key(gateway_id) {
            try {
                if (!gateway_id || typeof gateway_id !== 'string') {
                    return null;
                }

                // Map gateway IDs to their fkwcs_data keys
                const keyMap = {
                    'fkwcs_stripe_ideal': 'fkwcs_payment_data_ideal',
                    'fkwcs_stripe_multibanco': 'fkwcs_payment_data_multibanco',
                    'fkwcs_stripe_pix': 'fkwcs_payment_data_pix',
                    'fkwcs_stripe_cashapp': 'fkwcs_payment_data_cashapp',
                    'fkwcs_stripe_eps': 'fkwcs_payment_data_eps',
                    'fkwcs_stripe_twint': 'fkwcs_payment_data_twint',
                };
                return keyMap[gateway_id] || null;
            } catch (e) {
                return null;
            }
        }

        /**
         * Reinitialize gateway with new element data
         * Override in child class if needed
         */
        reinitialize_gateway() {
            // Override in child classes that need reinitialization
        }

        appendMethodId(payment_method) {
            let source_el = $('.fkwcs_source');
            if (source_el.length > 0) {
                source_el.remove();
            }
            wcCheckoutForm.append(`<input type='hidden' name='fkwcs_source' class='fkwcs_source' value='${payment_method}'>`);
        }

        getMethodId() {
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

            if (typeof type !== 'undefined' && 'add_payment' === type) {
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

            // First, try to get phone from WFACP hidden field (checkout page builder)
            let phone = '';
            const wfacpPhoneField = document.getElementById('wfacp_input_phone_field');
            if (wfacpPhoneField && wfacpPhoneField.value) {
                try {
                    const phoneData = JSON.parse(wfacpPhoneField.value);
                    if (phoneData && phoneData.billing && phoneData.billing.number) {
                        const countryCode = phoneData.billing.code || '';
                        const phoneNumber = phoneData.billing.number;
                        if (phoneNumber) {
                            phone = countryCode ? '+' + countryCode + phoneNumber : phoneNumber;
                        }
                    }
                } catch (_e) { // no-op
                }
            }

            if (!phone && null !== billingPhone && billingPhone.value) {
                phone = billingPhone.value;
            }

            let getBilling = {
                name: firstName + ' ' + lastName,
                email: null !== billingEmail ? billingEmail.value : '',
                phone: phone,
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

        confirmStripePayment() {
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
            let order_key = this.getOrderKeyFromUrl();
            $.ajax({
                type: 'POST', url: fkwcs_data.admin_ajax, data: {
                    "action": 'fkwcs_js_errors', "_security": fkwcs_data.js_nonce, "order_id": order_id, "order_key": order_key, "error": error
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

        getOrderKeyFromUrl() {
            let urlParams = new URLSearchParams(window.location.search);
            return urlParams.get('key') || '';
        }

        getAmountCurrency() {
            const source = (this.fragments && this.fragments.fkwcs_paylater_data) || (fkwcs_data && fkwcs_data.fkwcs_paylater_data);
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
                        this.showNotice(response.error);
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
                this.logError(error);
                this.showNotice(getStripeLocalizedMessage(error.code, error.message));
            } else if (paymentIntent.status === 'succeeded') {
                // Inform the customer that the payment was successful
                this.safeRedirect(redirectURL);
            } else if (paymentIntent.status === 'requires_action') {
                // Inform the customer that the payment did not go through
            }
        }

        isSupportedCountries() {
            return false;
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
            let order_key = self.getOrderKeyFromUrl();
            let orderData = {
                action: 'fkwcs_create_payment_intent',
                order_id: order_id,
                order_key: order_key,
                gateway_id: this.gateway_id,
                security: fkwcs_data.nonce,
                type: type
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
                                self.showError(result.error);
                                self.showNotice(result.error.message);
                                self.logError(result.error, order_id);
                                wcCheckoutForm.unblock();
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
            if (!this.stripe || typeof this.stripe.elements !== 'function') {
                if (!window.fkwcsStripeGatewaysInitDone && fkwcsSdkRecovery.maxAttempts >= 1) {
                    fkwcsQueueStripeSdkRecovery();
                }
                return;
            }
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

            if ($('.fkwcs-credit-card-error.fkwcs-error-text').length > 0 && $('.fkwcs-credit-card-error.fkwcs-error-text').text() !== '') {
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

            // On the WooCommerce Subscriptions change-payment-method form the subscription
            // id is rendered as a hidden input; pass it so the server can build the
            // SetupIntent with India card.mandate_options and persist the mandate against
            // the subscription. Empty on normal add-PM / checkout flows.
            const change_subscription_id = (form_el && form_el.find('input[name="woocommerce_change_payment"]').val()) || $('input[name="woocommerce_change_payment"]').val() || '';

            const process_data = {
                action: 'fkwcs_create_setup_intent',
                fkwcs_nonce,
                fkwcs_source: payment_method,
                gateway_id: this.selectedGateway(),
                fkwcs_change_subscription_id: change_subscription_id
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
                    this.safeRedirect(redirectURL);
                }
            }).catch(function () {

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
                        /**
                         * Early renewal confirms an EXISTING renewal PaymentIntent that already has the
                         * subscription's saved card attached - there is no mounted Payment Element here.
                         * Force the saved-source confirmation path (5th arg = 'yes') so we use
                         * stripe.confirmCardPayment(clientSecret) directly. The Payment Element path
                         * requires elements.submit() before stripe.confirmPayment(), which never runs in
                         * this flow and throws "elements.submit() must be called before stripe.confirmPayment()".
                         */
                        this.confirmStripePayment(response.intent_secret, response.redirect_url, undefined, false, 'yes');
                    } else {
                        this.safeRedirect(response.redirect_url);
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
            this.element_options.fields.billingDetails.name = $("#billing_first_name").length ? "never" : "auto";
            this.element_options.fields.billingDetails.email = $("#billing_email").length ? "never" : "auto";
            this.element_options.fields.billingDetails.phone = $("#billing_phone").length ? "never" : "auto";


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
                        name: $("#billing_first_name").val() + " " + $("#billing_last_name").val(),
                        email: $("#billing_email").val(),
                        phone: $("#billing_phone").val()
                    }
                };
            }

            this.createStripeElements();

        }

        createStripeElements(_reset = false) {


            this.elements = this.stripe.elements(this.element_data);
            this.payment = this.elements.create('payment', this.element_options);
            this.payment.on('change', function (event) {
                _current_upe_gateway = event.value.type;
            });
            this.payment.on('ready', () => {
                this.amount_to_small = false;
            });
            this.payment.on('loaderror', (event) => {
                if ('amount_too_small' === event.error.code) {
                    this.amount_to_small = true;
                }
                console.log('Stripe PaymentElement is unable to load ', event.error);
            });
        }


        mountElements() {

            /**
             * Mounts Stripe payment elements to the DOM
             *
             * Attempts to mount the Stripe payment elements if they don't already exist.
             * First checks if payment elements are already mounted by looking for the iframe.
             * If not found, creates new elements and mounts them to the container.
             * Handles errors gracefully with console logging.
             *
             * @since 2.0.0
             * @returns {void}
             */
            if (!this.stripe || typeof this.stripe.elements !== 'function') {
                if (!window.fkwcsStripeGatewaysInitDone && fkwcsSdkRecovery.maxAttempts >= 1) {
                    fkwcsQueueStripeSdkRecovery();
                }
                return;
            }
            try {
                let selector = '.fkwcs-stripe-payment-elements-field.StripeElement .__PrivateStripeElement iframe';
                if ($(selector).length === 0) {
                    this.createStripeElements();
                    this.payment.mount('.fkwcs-stripe-payment-elements-field');

                    /**
                     * Fires immediately after the card Payment Element is mounted to the DOM.
                     *
                     * Upgrade-safe extension point for integrators who need the live Payment
                     * Element instance (e.g. to attach a Stripe `change` listener). Because
                     * setGateway() -> mountGateway() routes through mountElements(), this event
                     * re-fires on every remount, so listeners can be re-attached to the new
                     * element without monkey-patching or a window global.
                     *
                     * @since 1.14.1
                     * @param {Object} paymentElement The live Stripe Payment Element instance.
                     * @param {FKWCS_Stripe} gateway   The card gateway instance.
                     */
                    $(document).trigger('fkwcs_payment_element_mounted', [this.payment, this]);
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


        createUPESource() {
            wcCheckoutForm.block({
                message: null, overlayCSS: {
                    background: '#fff', opacity: 0.6
                }
            });
            let payment_submit = this.elements.submit();
            payment_submit.then(() => {

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
                    this.safeRedirect(redirectURL);
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
            if (this.p24) {
                this.p24.unmount();
            }
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

        processingSubmit() {
            // check for P24.
            if ('' === this.selectedP24Bank) {
                this.showError({message: fkwcs_data.empty_bank_message});
                this.showNotice(fkwcs_data.empty_bank_message);
                return false;
            }
            this.showError('');
        }

        confirmStripePayment(clientSecret, redirectURL) {

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
            if (this.sepa) {
                this.sepa.unmount();
            }
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

        processingSubmit() {
            if ('' === this.paymentMethod && !this.isSepaSaveCardChosen()) {
                if (false === this.sepaIBAN) {
                    this.showError(this.emptySepaIBANMessage);
                    return false;
                }


                this.createPaymentMethod();
                return false;
            }
        }

        processOrderReview() {
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
                            this.safeRedirect(redirectURL);
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
                            this.safeRedirect(redirectURL);
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
                            this.safeRedirect(redirectURL);
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
                            this.safeRedirect(redirectURL);
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
            this.gateway_initialized = false;
        }

        setupGateway() {
            //check if defined fkwcs_data.fkwcs_payment_data_ideal
            if (typeof fkwcs_data.fkwcs_payment_data_ideal === 'undefined') {
                return;
            }
            let amount_data = this.getAmountCurrency();
            if (amount_data.amount <= 0) {
                return;
            }

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
            this.element_options.fields.billingDetails.name = $("#billing_first_name").length ? "never" : "auto";
            this.element_options.fields.billingDetails.email = $("#billing_email").length ? "never" : "auto";
            this.element_options.fields.billingDetails.phone = $("#billing_phone").length ? "never" : "auto";


            this.element_options.defaultValues = {
                billingDetails: {
                    name: $("#billing_first_name").length ? $("#billing_first_name").val() + " " + $("#billing_last_name").val() : '',
                    email: $("#billing_email").val(),
                    phone: $("#billing_phone").val()
                }
            };


            this.ideal = this.elements.create('payment', this.element_options);
            this.gateway_initialized = true;

        }

        /**
         * Reinitialize gateway when element data becomes available dynamically
         */
        reinitialize_gateway() {
            try {
                // Only reinitialize if not already initialized
                if (!this.gateway_initialized && typeof fkwcs_data.fkwcs_payment_data_ideal !== 'undefined') {
                    console.log('Reinitializing iDEAL gateway with dynamic element data');
                    this.setupGateway();
                }
            } catch (e) {
                console.log('Error reinitializing iDEAL gateway:', e);
            }
        }

        setGateway() {
            try {
                if (this.ideal) {
                    this.ideal.unmount();
                }
                this.mountGateway();
            } catch (e) {
                console.log('Error in iDEAL setGateway:', e);
            }
        }

        mountGateway() {
            try {
                if (typeof fkwcs_data.fkwcs_payment_data_ideal === 'undefined') {
                    return;
                }
                let form = $(`.${this.gateway_id}_form`);
                if (0 === form.length) {
                    return;
                }
                let selector = `.${this.gateway_id}_form .${this.gateway_id}_select`;

                // Only mount if element exists and selector is available
                if (this.ideal && $(selector).length > 0) {
                    this.ideal.mount(selector);
                    $(selector).css({backgroundColor: '#fff'});
                }
            } catch (e) {
                console.log('Error in iDEAL mountGateway:', e);
            }
        }

        processingSubmit() {
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

        confirmStripePayment(clientSecret, redirectURL) {

            if (this.gateway_id === this.selectedGateway()) {
                this.elements.submit().then(() => {
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

        confirmStripePayment(clientSecret, redirectURL) {
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
                    this.logError(error);
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
    class FKWCS_BLIK extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_blik_error';
            this.mountable = false; // BLIK doesn't use Payment Element, uses custom input field
        }

        setupGateway() {
            // BLIK doesn't need Payment Element setup
            this.setup_ready = true;
        }

        mountGateway() {
            // BLIK doesn't mount Payment Element, form field is rendered by PHP
            // Just show the form
            let blik_form = $(`.${this.gateway_id}_form`);
            if (0 === blik_form.length) {
                return;
            }
            blik_form.show();
        }

        ready() {
            this.validateBlikCode();
        }

        /**
         * Get BLIK code from input field
         * @returns {string} BLIK code value
         */
        getBlikCode() {
            return $('#fkwcs-blik-code').val() || '';
        }

        validateBlikCode() {
            let self = this;
            $(document.body).on('checkout_place_order_' + this.gateway_id, function() {
                let blikCode = self.getBlikCode();
                if (!blikCode || blikCode.length !== 6 || !/^\d{6}$/.test(blikCode)) {
                    self.showError('Please enter a valid 6-digit BLIK code.');
                    return false;
                }
                self.showError('');
                return true;
            });
        }

        processingSubmit(e) {
            // Get BLIK code once at the top
            let blikCode = this.getBlikCode();

            // Validate BLIK code before submission
            if (!blikCode || blikCode.length !== 6 || !/^\d{6}$/.test(blikCode)) {
                this.showError('Please enter a valid 6-digit BLIK code.');
                if (e) e.preventDefault();
                return false;
            }
            this.showError('');

            return true;
        }

        // Override to handle order pay page - create payment method first, then create intent
        processOrderReview(e) {
            if (this.gateway_id === this.selectedGateway()) {
                // Get BLIK code once at the top
                let blikCode = this.getBlikCode();

                // Validate BLIK code
                if (!blikCode || blikCode.length !== 6 || !/^\d{6}$/.test(blikCode)) {
                    this.showError('Please enter a valid 6-digit BLIK code.');
                    e.preventDefault();
                    return false;
                }

                this.createIntent('order_review');
                e.preventDefault();
                return false;
            }
            return true;
        }


        // Override createIntent for BLIK - we don't have elements, so just submit form after intent is created
        createIntent(type) {
            if (!this.processingSubmit()) {
                return;
            }

            let self = this;
            let order_id = self.getOrderIdFromUrl();
            let order_key = self.getOrderKeyFromUrl();

            // Get BLIK code from input field
            let blikCode = this.getBlikCode();

            if (!blikCode) {
                wcCheckoutForm.unblock();
                self.showError('Please enter a valid 6-digit BLIK code.');
                return;
            }

            // Block form during payment intent creation
            wcCheckoutForm.block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });

            let orderData = {
                action: 'fkwcs_create_payment_intent',
                order_id: order_id,
                order_key: order_key,
                gateway_id: this.gateway_id,
                security: fkwcs_data.nonce,
                type: type
            };

            $.ajax({
                url: fkwcs_data.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: orderData
            }).done(function (response) {
                if (response && response.result === 'success' && response.redirect) {
                    // If redirect contains hash, set it and trigger handler
                    if (response.redirect.indexOf('#') === 0) {
                        window.location.hash = response.redirect;
                        // Small delay to ensure hash is set, then trigger handler
                        setTimeout(() => {
                            self.handleOrderPayPageAndChangePaymentPage();
                        }, 100);
                    } else {
                        // Direct redirect to thank you page
                        self.safeRedirect(response.redirect);
                    }
                    return;
                }

                // Handle standard fkwcs_create_payment_intent response format
                if (response && response.success && response.data) {
                    let payment_id = response.data.payment_id;
                    let clientSecret = response.data.client_secret;
                    let redirectURL = response.data.redirect_url;

                    if (payment_id && clientSecret) {
                        // Get BLIK code
                        let blikCode = self.getBlikCode();

                        // Confirm payment with BLIK code (as per Stripe docs)
                        self.stripe.confirmBlikPayment(clientSecret, {
                            payment_method: {
                                blik: {},
                                billing_details: self.getBillingAddress()
                            },
                            payment_method_options: {
                                blik: {
                                    code: blikCode
                                }
                            },
                            return_url: homeURL + redirectURL
                        }).then((result) => {
                            if (result.error) {
                                self.logError(result.error);
                                self.showError(result.error.message);
                                wcCheckoutForm.unblock();
                                return;
                            }
                            if (result.paymentIntent && (result.paymentIntent.status === 'succeeded' || result.paymentIntent.status === 'processing')) {
                                // Payment succeeded, redirect to thank you page
                                self.safeRedirect(homeURL + redirectURL);
                            }
                        }).catch((error) => {
                            self.logError(error);
                            self.showError(error.message || 'Failed to confirm payment');
                            wcCheckoutForm.unblock();
                        });
                    } else {
                        wcCheckoutForm.unblock();
                        self.showError('Payment intent creation failed. Missing required data.');
                    }
                } else {
                    let errorMessage = (response && response.data && response.data.message) || response.message || 'Error creating payment intent.';
                    wcCheckoutForm.unblock();
                    self.showError(errorMessage);
                }
            }).fail(function (_jqXHR, _textStatus, _errorThrown) {
                wcCheckoutForm.unblock();
                self.showError('An error occurred while creating payment intent. Please try again.');
            });
        }

        hasSource() {
            return '';
        }

        confirmStripePayment(clientSecret, redirectURL, intent_type, _authenticationAlready = false, order_id = false) {
            if (this.gateway_id !== this.selectedGateway()) {
                return;
            }

            // Get BLIK code once at the top
            let blikCode = this.getBlikCode();

            this.stripe.confirmBlikPayment(clientSecret, {
                payment_method: {
                    blik: {},
                    billing_details: this.getBillingAddress()
                },
                payment_method_options: {
                    blik: {
                        code: blikCode
                    }
                },
                return_url: homeURL + redirectURL
            }).then((result) => {
                if (result.error) {
                    this.logError(result.error, order_id);
                    this.showNotice(result.error.message);
                    return;
                }
                if (result.paymentIntent && (result.paymentIntent.status === 'succeeded' || result.paymentIntent.status === 'processing')) {
                    // Payment succeeded, redirect to thank you page
                    this.safeRedirect(homeURL + redirectURL);
                }
            });
        }
    }
    class FKWCS_MBWAY extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_mbway_error';
            this.confirmCallBack = 'confirmMbWayPayment';
        }

        setupGateway() {
            let amount_data = this.getAmountCurrency();
            if (amount_data.amount <= 0) {
                return;
            }
            this.elements = this.stripe.elements({
                mode: 'payment',
                currency: amount_data.currency.toLowerCase(),
                amount: amount_data.amount,
                payment_method_types: ['mb_way']
            });

            this.mbway = this.elements.create('payment', {
                fields: {
                    billingDetails: {
                        phone: $("#billing_phone").length ? "never" : "auto"
                    }
                }
            });

            this.mbway.on('change', (event) => {
                this.empty = event.empty;
                this.showError();
            });

        }
        setGateway() {
            this.mbway.unmount();
            this.mountGateway();
        }

        mountGateway() {
            let mbway_form = $(`.${this.gateway_id}_form`);
            if (0 === mbway_form.length) {
                return;
            }
            mbway_form.show();
            let selector = `.${this.gateway_id}_form`;
            this.mbway.mount(selector);
            $(selector).css({backgroundColor: '#fff'});
        }

        processingSubmit(_e) {
            this.showError('');
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

        confirmStripePayment(clientSecret, redirectURL, intent_type, _authenticationAlready = false, order_id = false) {
            if (this.gateway_id === this.selectedGateway()) {
                this.elements.submit().then(() => {
                    let billingDetails = this.getBillingAddress();

                    if (!billingDetails.phone) {
                        billingDetails.phone = '';
                    }

                    this.stripe.confirmPayment({
                        elements: this.elements,
                        clientSecret: clientSecret,
                        confirmParams: {
                            return_url: `${homeURL}${redirectURL}`,
                            payment_method_data: {
                                billing_details: billingDetails
                            }
                        }
                    }).then((result) => {
                        if (result.error) {
                            this.logError(result.error, order_id);
                            this.showError(result.error);
                            this.showNotice(result.error);
                        }
                    }).catch((error) => {
                        this.logError(error, order_id);
                        this.showError(error);
                    });
                }).catch((error) => {
                    this.showError(error);
                    this.logError(error, order_id);
                });
            }
        }

        paymentMethodTypes() {
            return ['mb_way'];
        }

    }
    class FKWCS_ApplePay extends Gateway {
        constructor(stripe, gateway_id) {

            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_apple_pay_error';
            this.mountable = true;
            this.gateway_class = 'li.payment_method_fkwcs_stripe_apple_pay';
            this.apple_place_btn_wrapper = '.fkwcs_stripe_apple_pay_button';
            this.apple_pay_btn = '';
            this.payment_request_options = null;
            this.shipping_options = [];
            this.request_data = {};
            this.express_btn_click = false;
            let ct = this;
            const addDisplayNoneClass = function() {
                const $applePayElement = $('li.payment_method_fkwcs_stripe_apple_pay');
                if ($applePayElement.length > 0 && !$applePayElement.hasClass('fkwcs_display_none')) {
                    $applePayElement.addClass('fkwcs_display_none');
                }
            };

            // True when show_as_regular is set but checkout express button is not — the
            // gateway shows as a regular payment method driven by expressButtonReady().
            const isRegularOnly = $.inArray('show_as_regular', fkwcs_data.apple_pay_positions || []) !== -1
                && $.inArray('checkout', fkwcs_data.apple_pay_positions || []) === -1;

            $(document.body).on('updated_checkout', function() {
                if (isRegularOnly) {
                    // Once Apple Pay availability is confirmed, don't rehide on every AJAX
                    // refresh — that would cause flicker and prevent the gateway staying visible.
                    if (!ct.apple_pay_ready) {
                        addDisplayNoneClass();
                    } else {
                        // Fragment refresh re-rendered the <li> in its default state; restore
                        // it synchronously (before paint) so the row does not flicker.
                        ct.showRegularGateway();
                    }
                    ct.mountExpress();
                } else {
                    if (true === ct.apple_pay_ready) {
                        /*
                         * Availability cannot change mid-session. The checkout AJAX replaced the
                         * payment fragment and re-rendered this <li>; hiding it here and waiting
                         * for the async smart-buttons re-check (fkwcs_smart_buttons_showed) made
                         * the row disappear and reappear on every update_order_review. Restore it
                         * immediately instead.
                         */
                        ct.showRegularGateway();
                        ct.mountExpress();
                    } else {
                        addDisplayNoneClass();
                    }
                }
            });

            $(document.body).on('fkwcs_smart_buttons_showed', function (key, res, two) {
                // In show_as_regular-only mode express-checkout.js may still fire this event
                // (for other smart buttons like Google Pay or Link). Apple Pay visibility here
                // is driven solely by expressButtonReady() — ignore smart button results.
                if (isRegularOnly) {
                    return;
                }
                const $applePayElement = $('li.payment_method_fkwcs_stripe_apple_pay');
                if ($applePayElement.length === 0) {
                    return;
                }
                if (two && two.applePay) {
                    // Remember for the session — updated_checkout uses this to restore the
                    // row synchronously after fragment refreshes instead of re-hiding it.
                    ct.apple_pay_ready = true;
                    $applePayElement.show();
                    $applePayElement.removeClass('fkwcs_display_none');
                    ct.mountGateway();
                } else {
                    $applePayElement.hide();
                    $applePayElement.addClass('fkwcs_display_none');
                    if ($applePayElement.find('input[type="radio"]').is(':checked')) {
                        $applePayElement.find('input[type="radio"]').prop('checked', false);
                        $('li.wc_payment_method:visible').not($applePayElement).first().find('input[type="radio"]').prop('checked', true).trigger('click');
                    }
                }
            });
            this.apple_pay_ready = false;
            /** Inline gateway only: Stripe Express element invalid after checkout AJAX if we only elements.update() */
            this._applePayReadyBound = false;
            /** Holds the last real cart total from fkwcs_get_cart_details. Stays null until that AJAX responds — no placeholder fallback, ever, because a fallback can be charged. */
            this._applePayLastUpdate = null;
            /** Flipped true the first time fkwcs_get_cart_details supplies a real amount/currency. The Apple Pay button is kept hidden until then. */
            this._realAmountReady = false;
            this.block_data = {
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6,
                },
            };

            // Order Pay page fires no cart/fragment refresh, so arm the ECE once from the order
            // total localized by PHP (applepay.php localize_element_data order-pay branch).
            // update_fragment_data() only mounts when Apple Pay is already the selected gateway,
            // which it isn't on initial order-pay load — so mount explicitly afterwards. That
            // makes the ECE 'ready' event fire and reveal the regular row (expressButtonReady ->
            // showRegularGateway), mirroring Google Pay's unconditional mountExpressRegular().
            if ('yes' === fkwcs_data.is_pay_for_order_page && fkwcs_data.fkwcs_apple_pay_order_pay_data) {
                const selfApple = this;
                $(function () {
                    setTimeout(function () {
                        selfApple.update_fragment_data({fkwcs_cart_details: fkwcs_data.fkwcs_apple_pay_order_pay_data});
                        selfApple.mountExpress();
                    }, 300);
                });
            } else if ('yes' === fkwcs_data.is_checkout) {
                // Checkout page: the element is armed only by the fkwcs_cart_details fragment,
                // which rides on updated_checkout. Where that event never reaches us — another
                // plugin breaking the update_order_review response, a template driving its own
                // order review — expressCheckoutElement is never created, so mountExpress()
                // bails out and the customer gets a selectable Apple Pay row with no button.
                // Place Order is hidden for Apple Pay, so that leaves no way to pay at all.
                //
                // Fall back to asking the server for the cart total directly, using the same
                // endpoint the express buttons use (registered whenever any express method is
                // enabled, so it is always available when this row can appear). Deliberately
                // late: on a healthy checkout updated_checkout has already armed the element by
                // now and this costs nothing.
                const selfApple = this;
                $(function () {
                    setTimeout(function () {
                        selfApple.armFromCartDetailsRequest();
                    }, 3000);
                });
            }
        }

        /**
         * Arm + mount the Express Checkout Element from a fresh fkwcs_get_cart_details request.
         *
         * Only used as a fallback when no checkout fragment refresh has armed the element (see
         * the constructor). Re-checks expressCheckoutElement inside the response handler because
         * updated_checkout may land while the request is in flight — it carries the same totals
         * from the same code path, so whichever arrives first wins and the other backs off.
         *
         * update_fragment_data() only mounts while Apple Pay is the selected gateway, which it
         * is not on first load, so mount explicitly — as the order-pay path does. PrepareButton()
         * keeps the button hidden until Apple Pay is selected, so mounting early is safe.
         */
        armFromCartDetailsRequest() {
            if (this.expressCheckoutElement) {
                return;
            }
            const endpoint = fkwcs_data.wc_endpoints && fkwcs_data.wc_endpoints.fkwcs_get_cart_details;
            if (!endpoint) {
                return;
            }
            $.post(endpoint, {fkwcs_nonce: fkwcs_data.fkwcs_nonce}, (response) => {
                if (!response || true !== response.success || !response.data || this.expressCheckoutElement) {
                    return;
                }
                this.update_fragment_data({fkwcs_cart_details: response.data});
                this.mountExpress();
            });
        }


        get_wrapper_selector() {
            return '.fkwcs_stripe_apple_pay_button';
        }

        showRegularGateway() {
            const $el = $('li.payment_method_fkwcs_stripe_apple_pay');
            if ($el.length > 0) {
                $el.show().removeClass('fkwcs_display_none');
            }
        }

        /** Full rebuild when elements.update throws or mount reports destroyed. Caller MUST pass a real amount + currency — refusing here is intentional: a missing value means we don't know what to charge, and we will not charge a guess. */
        recreateApplePayExpressElements(update_data) {
            if (!update_data || update_data.amount === undefined || update_data.amount === null || !update_data.currency) {
                return;
            }
            const amt = update_data.amount;
            const cur = String(update_data.currency).toLowerCase();
            this._applePayLastUpdate = { amount: amt, currency: cur };
            this._applePayReadyBound = false;
            try {
                if (this.expressCheckoutElement && typeof this.expressCheckoutElement.destroy === 'function') {
                    this.expressCheckoutElement.destroy();
                }
            } catch (e) {
                /* already destroyed */
            }
            const elementOptions = {
                mode: 'payment',
                amount: amt,
                currency: cur,
                appearance: {
                    theme: 'stripe',
                    variables: {},
                },
                paymentMethodCreation: 'manual',
                payment_method_types: ['card'],
            };
            const expressOptions = {
                buttonHeight: 42,
                buttonType: { applePay: fkwcs_data.apple_pay_button_type || 'plain' },
                buttonTheme: {
                    applePay: fkwcs_data.apple_pay_button_theme || 'black',
                },
                paymentMethods: {
                    googlePay: 'never',
                    applePay: 'always',
                    link: 'never',
                    paypal: 'never',
                    klarna: 'never',
                },
            };
            this.elements = this.stripe.elements(elementOptions);
            this.expressCheckoutElement = this.elements.create('expressCheckout', expressOptions);
            this.expressCheckoutElement.on('confirm', this.onPaymentMethod.bind(this));
            this.expressCheckoutElement.on('cancel', this.cancelPayment.bind(this));
            // Honor the "Disable Shipping Info in Payment Wallet" setting for Apple Pay.
            this.expressCheckoutElement.on('click', (event) => this.resolveExpressCheckoutClick(event, 'apple_pay_disable_shipping'));
            // When shipping is collected, keep the sheet responsive; the WooCommerce form
            // supplies the actual shipping method on submit, so a placeholder rate suffices.
            this.expressCheckoutElement.on('shippingaddresschange', (event) => event.resolve({shippingRates: [{id: 'pending', displayName: 'Pending', amount: 0}]}));
            this.expressCheckoutElement.on('shippingratechange', (event) => event.resolve({}));
        }

        setupGateway() {
            /* Intentional no-op. The Stripe Express element is NOT created at init — it is created on the first fkwcs_get_cart_details response (via recreateApplePayExpressElements) so it never exists with a placeholder amount. Override is preserved to suppress the parent's setup. */
        }

        mountExpress() {
            const self = this;
            setTimeout(() => {
                const sel = self.get_wrapper_selector();
                if (undefined === self.expressCheckoutElement || !$(sel).length) {
                    return;
                }
                if (!self._applePayReadyBound) {
                    self.expressCheckoutElement.on('ready', self.expressButtonReady.bind(self));
                    self._applePayReadyBound = true;
                }
                try {
                    self.expressCheckoutElement.mount(sel);
                    self.showButton();
                } catch (err) {
                    if (err && err.message && err.message.indexOf('destroyed') !== -1) {
                        self.recreateApplePayExpressElements(self._applePayLastUpdate);
                        self._applePayReadyBound = false;
                        if (!self._applePayReadyBound) {
                            self.expressCheckoutElement.on('ready', self.expressButtonReady.bind(self));
                            self._applePayReadyBound = true;
                        }
                        self.expressCheckoutElement.mount(sel);
                        self.showButton();
                    }
                }
            }, 500);
        }

        expressButtonReady({availablePaymentMethods}) {
            if (!availablePaymentMethods) {
                // Apple Pay unavailable (e.g. Chrome/Android): hide ONLY Apple Pay's own wrappers. Do NOT call hideGatewayWallets() here — it hides the shared
                $(this.apple_place_btn_wrapper).hide();
                $('.fkwcs_apple_pay_gateway_wrap').hide();
            } else {
                this.apple_pay_ready = true;
                this.showRegularGateway()
            }

        }

        onPaymentMethod() {

            let payment_submit = this.elements.submit();
            payment_submit.then(() => {
                this.stripe.createPaymentMethod({
                    elements: this.elements
                }).then(({paymentMethod}) => {
                    this.express_btn_click = true;
                    this.appendMethodId(paymentMethod.id);
                    // need to save source in hidden
                }).catch((error) => {
                    this.handleExpressConfirmError(error);
                });
            });
            payment_submit.catch((error) => {
                this.handleExpressConfirmError(error);
            });


        }

        /**
         * Surface an Apple Pay express-confirm failure to the customer and the
         * server logs instead of swallowing it in a console.log-only catch.
         * showError()/showNotice() also unblock the checkout form so the customer
         * can retry rather than facing a dead Apple Pay button.
         *
         * @param {Object} error Stripe error object from the confirm path.
         */
        handleExpressConfirmError(error) {
            if (!error) {
                return;
            }
            this.logError(error);
            this.showError(error);
            this.showNotice(getStripeLocalizedMessage(error.code, error.message));
        }

        appendMethodId(source) {
            super.appendMethodId(source);
            if (this.express_btn_click) {
                const termsCheckbox = $('#terms');
                if (termsCheckbox.length) {
                    termsCheckbox.prop('checked', true);
                }
            }

            if ($('form#order_review').length > 0) {
                $('form#order_review').trigger('submit');
            } else {
                $('form.checkout').trigger('submit');

            }
        }

        confirmStripePayment(clientSecret, redirectURL, intent_type, order_id = false, retry = false) {

            let confirm_data = {
                'elements': this.elements,
                'clientSecret': clientSecret,
                confirmParams: {
                    return_url: fkwcs_data.get_home_url + redirectURL,
                },
                'redirect': 'if_required'
            };
            let cardPayment = null;
            if ('si' === intent_type) {
                cardPayment = this.stripe.confirmSetup(confirm_data);
            } else {
                cardPayment = this.stripe.confirmPayment(confirm_data);
            }
            let FormEl = $('form.woocommerce-checkout');
            cardPayment.then((result) => {
                if (result.error) {
                    /**
                     * Insert logs to the server and show error messages
                     */
                    this.logError(result.error);
                    $('.woocommerce-error').remove();
                    FormEl.unblock();
                    $('.woocommerce-notices-wrapper:first-child').html('<div class="woocommerce-error fkwcs-errors">' + result.error.message + '</div>').show();
                    this.logError(result.error, order_id);
                } else {

                    let intent = result['si' === intent_type ? 'setupIntent' : 'paymentIntent'];
                    if (false === retry && (intent.status === 'requires_action' || intent.status === 'requires_source_action')) {
                        this.confirmStripePayment(clientSecret, redirectURL, intent_type, order_id, true);
                    } else {
                        FormEl.addClass('processing');
                        FormEl.block(this.block_data);
                        this.safeRedirect(redirectURL);
                    }
                }
            });
        }

        cancelPayment() {
            $(document.body).trigger('fkwcs_express_cancel_payment', this);
        }

        update_fragment_data(fragments) {
            super.update_fragment_data(fragments);
            if (!fragments || !fragments.fkwcs_cart_details || !fragments.fkwcs_cart_details.order_data) {
                return;
            }
            const order_data = fragments.fkwcs_cart_details.order_data;
            const rawAmount = order_data.total && order_data.total.amount;
            const currency = order_data.currency;
            /* Refuse to proceed without a real amount AND currency. No fallback — a missing value means the cart total is unknown, and we will not arm Apple Pay with a guess. */
            if (rawAmount === undefined || rawAmount === null || !currency) {
                return;
            }
            // Free-trial / $0-now carts: Stripe's payment-mode Express Checkout Element throws
            // "amount must be greater than 0", which killed the element mid-arming and hid the
            // radio row (same defect as Google Pay's regular mode). Use the shared 100-subunit
            // placeholder; the real amount is determined server-side at order creation.
            const amount = Number(rawAmount) > 0 ? Number(rawAmount) : 100;
            const update_data = { amount: amount, currency: currency };
            this._applePayLastUpdate = {
                amount: amount,
                currency: String(currency).toLowerCase(),
            };
            if (!this.expressCheckoutElement) {
                /* First real cart total — create the Stripe Express element with the verified amount (we deliberately did not create it at init). */
                this.recreateApplePayExpressElements(update_data);
            } else {
                try {
                    if (this.elements && typeof this.elements.update === 'function') {
                        this.elements.update(update_data);
                    }
                } catch (e) {
                    this.recreateApplePayExpressElements(update_data);
                }
            }
            /* Stripe element now reflects the verified cart total — button may safely be exposed. */
            this._realAmountReady = true;
            /* Inline gateway only, and only while Apple Pay is selected — avoids re-mount on every checkout AJAX */
            if (this.gateway_id !== this.selectedGateway()) {
                return;
            }
            this.mountExpress();
        }

        setGateway() {

            this.hidePlaceOrder();
            this.mountExpress();
            this.showButton();
        }

        unsetGateway() {
            if (this.gateway_id !== this.selectedGateway()) {
                $(this.apple_place_btn_wrapper).hide();
            }

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
            if (this.gateway_id !== this.selectedGateway()) {
                return;
            }
            /* Do not reveal the button until the verified cart total has been applied to the Stripe element. Showing it earlier risks charging a stale or guessed amount. */
            if (!this._realAmountReady) {
                return;
            }
            wrapper.show();
        }


        hidePlaceOrder() {
            if (this.gateway_id !== this.selectedGateway()) {
                return;
            }
            this.hideGatewayWallets();
            const appleButtonContainer = $('.fkwcs-apple-button-container');
            if (appleButtonContainer.length) {
                appleButtonContainer.show();
            }
            const placeOrderBtn = $('#place_order');
            if (placeOrderBtn.length) {
                placeOrderBtn.hide();
                placeOrderBtn.addClass('fkwcs_hidden');
            }
        }

        showPlaceOrder() {
            this.hideGatewayWallets();
        }

    }

    /**
     * Amazon Pay shown as a regular payment-method option (mirrors FKWCS_ApplePay).
     *
     * Mounts the Amazon Express Checkout Element into the render_wrapper (.fkwcs_stripe_amazon_pay_button,
     * printed after the Place Order button) and hides the standard Place Order button while Amazon Pay is
     * the selected gateway. Amazon's element is NOT in paymentMethodCreation:'manual' mode, so instead of
     * createPaymentMethod we use createConfirmationToken (same flow as processAmazonExpress) and submit the
     * confirmation token through the normal checkout form, which AmazonPay::process_payment() reads.
     */
    class FKWCS_AmazonPay extends Gateway {
        constructor(stripe, gateway_id) {

            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_amazon_pay_error';
            this.mountable = true;
            this.gateway_class = 'li.payment_method_fkwcs_stripe_amazon_pay';
            this.amazon_place_btn_wrapper = '.fkwcs_stripe_amazon_pay_button';
            this.amazon_pay_btn = '';
            this.express_btn_click = false;
            let ct = this;
            // Function to add fkwcs_display_none class when element exists
            const addDisplayNoneClass = function() {
                const $amazonPayElement = $('li.payment_method_fkwcs_stripe_amazon_pay');
                if ($amazonPayElement.length > 0 && !$amazonPayElement.hasClass('fkwcs_display_none')) {
                    $amazonPayElement.addClass('fkwcs_display_none');
                }
            };

            $(document.body).on('updated_checkout', function() {
                addDisplayNoneClass();
            });

            // Watchdog: FunnelKit Aero re-renders the place-order area unpredictably (and in multiple
            // responsive copies), destroying the mounted Stripe button. Whenever Amazon Pay is the
            // selected gateway but the visible wrapper has lost its iframe, rebuild + remount it.
            setInterval(function () {
                if (ct.gateway_id !== ct.selectedGateway()) {
                    return;
                }
                const $visible = $(ct.amazon_place_btn_wrapper).filter(':visible');
                if (!$visible.length) {
                    return;
                }
                const hasIframe = $visible.toArray().some(function (w) { return w.querySelector('iframe'); });
                if (!hasIframe && !ct._amazonMounting) {
                    ct._amazonMounting = true;
                    ct.mountExpress();
                    setTimeout(function () { ct._amazonMounting = false; }, 1200);
                }
            }, 1500);

            $(document.body).on('fkwcs_smart_buttons_showed', function (key, res, two) {
                const $amazonPayElement = $('li.payment_method_fkwcs_stripe_amazon_pay');
                if ($amazonPayElement.length === 0) {
                    return;
                }
                if (two && two.amazonPay) {
                    $amazonPayElement.show();
                    $amazonPayElement.removeClass('fkwcs_display_none');
                    ct.mountGateway();
                } else {
                    $amazonPayElement.hide();
                    $amazonPayElement.addClass('fkwcs_display_none');
                    if ($amazonPayElement.find('input[type="radio"]').is(':checked')) {
                        $amazonPayElement.find('input[type="radio"]').prop('checked', false);
                        $('li.wc_payment_method:visible').not($amazonPayElement).first().find('input[type="radio"]').prop('checked', true).trigger('click');
                    }
                }
            });
            this.amazon_pay_ready = false;
            /** Inline gateway only: Stripe Express element invalid after checkout AJAX if we only elements.update() */
            this._amazonPayReadyBound = false;
            // setupGateway() already ran from the base Gateway constructor and seeded
            // _amazonPayLastUpdate via amazonSeedTotal() — never clobber it back to the placeholder.
            if (!this._amazonPayLastUpdate) {
                this._amazonPayLastUpdate = this.amazonSeedTotal();
            }
            this.block_data = {
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6,
                },
            };

            // Order Pay page fires no cart/fragment refresh, so update_fragment_data() never runs and
            // the element would stay on the {amount: 500} placeholder. Seed the real order total
            // localized by PHP (amazonpay.php localize_element_data order-pay branch) so every later
            // mount — including the watchdog remount — rebuilds against it.
            //
            // Also drive mountGateway() once on init: mountExpress() bails while Amazon Pay is not the
            // selected gateway, and when it IS preselected (first radio on the order-pay form) no
            // change event ever fires setGateway(), which would leave Place Order visible with no
            // wallet button behind it. mountGateway() is itself guarded, so it no-ops otherwise.
            if ('yes' === fkwcs_data.is_pay_for_order_page && fkwcs_data.fkwcs_amazon_pay_order_pay_data) {
                const order_pay_data = fkwcs_data.fkwcs_amazon_pay_order_pay_data.order_data;
                this._amazonPayLastUpdate = {
                    amount: order_pay_data.total.amount,
                    currency: String(order_pay_data.currency).toLowerCase(),
                };
                $(function () {
                    setTimeout(function () {
                        ct.mountGateway();
                    }, 300);
                });
            }
        }


        get_wrapper_selector() {
            return '.fkwcs_stripe_amazon_pay_button';
        }

        showRegularGateway() {
            if ($('li.payment_method_fkwcs_stripe_amazon_pay').length > 0) {
                $('li.payment_method_fkwcs_stripe_amazon_pay').show();
            }
        }

        /**
         * Best total known before any fragment refresh: the order-pay order total, else the cart
         * total localized on page load (plugin.php always supplies cart_data), else a 500
         * placeholder as last resort. An element left on the placeholder makes the Amazon sheet
         * show $5, and the buyer then authorizes an amount that differs from the confirmed
         * PaymentIntent — declined on the Amazon side.
         *
         * Reads only globals: it is called from setupGateway(), which the base Gateway
         * constructor runs BEFORE this subclass's constructor body — instance fields set in the
         * constructor do not exist yet at that point.
         */
        amazonSeedTotal() {
            const d = window.fkwcs_data || {};
            const fallbackCurrency = String(d.currency || 'usd').toLowerCase();
            if ('yes' === d.is_pay_for_order_page && d.fkwcs_amazon_pay_order_pay_data && d.fkwcs_amazon_pay_order_pay_data.order_data) {
                const od = d.fkwcs_amazon_pay_order_pay_data.order_data;
                return { amount: Number(od.total.amount), currency: String(od.currency || fallbackCurrency).toLowerCase() };
            }
            if (d.cart_data && d.cart_data.order_data && d.cart_data.order_data.total && d.cart_data.order_data.total.amount !== undefined && d.cart_data.order_data.total.amount !== null) {
                const amt = Number(d.cart_data.order_data.total.amount);
                // amt === 0 with payment still needed (free trial / $0-now) is a REAL total, not a
                // missing one: keep it so amazonElementOptions() builds in setup mode and the
                // confirmation token matches the server's SetupIntent (usage: off_session).
                if (amt > 0 || d.cart_data.is_fkwcs_need_payment === true) {
                    return { amount: amt, currency: String(d.cart_data.order_data.currency || fallbackCurrency).toLowerCase() };
                }
            }
            return { amount: 500, currency: fallbackCurrency };
        }

        /** Amazon's Express Checkout Element is not manual, so it must NOT set paymentMethodCreation. */
        amazonElementOptions(amount, currency) {
            // Match the working express button: the Express Checkout Element uses automatic payment
            // methods — do NOT set payment_method_types (that suppresses the button render here).
            const cur = currency || (fkwcs_data.currency || 'usd').toLowerCase();
            // Free trial / $0-now: nothing to charge but a mandate must be collected. Build in
            // setup mode (no amount) so createConfirmationToken produces a setup-type token the
            // server confirms as a SetupIntent — a payment-mode token carries
            // setup_future_usage=null and Stripe rejects the mismatch ("The provided
            // setup_future_usage (off_session) does not match..."). Mirrors the express button's
            // isAmazonSetup path in express-checkout.js.
            if (!(Number(amount) > 0)) {
                return {
                    mode: 'setup',
                    currency: cur,
                    appearance: {
                        theme: 'stripe',
                        variables: {},
                    },
                };
            }
            return {
                mode: 'payment',
                amount: Number(amount),
                currency: cur,
                appearance: {
                    theme: 'stripe',
                    variables: {},
                },
            };
        }

        amazonExpressOptions() {
            return {
                buttonHeight: 42,
                paymentMethods: {
                    googlePay: 'never',
                    applePay: 'never',
                    link: 'never',
                    paypal: 'never',
                    klarna: 'never',
                    // Amazon Pay only accepts 'auto'/'never' in the Express Checkout Element
                    // (unlike Apple/Google which accept 'always'); 'always' throws IntegrationError.
                    amazonPay: 'auto',
                },
            };
        }

        /** Full rebuild when elements.update throws or mount reports destroyed */
        recreateAmazonExpressElements(update_data) {
            const amt = update_data && update_data.amount !== undefined && update_data.amount !== null ? update_data.amount : this._amazonPayLastUpdate.amount;
            const cur = update_data && update_data.currency ? String(update_data.currency).toLowerCase() : this._amazonPayLastUpdate.currency;
            this._amazonPayLastUpdate = { amount: amt, currency: cur };
            this._amazonPayReadyBound = false;
            try {
                if (this.expressCheckoutElement && typeof this.expressCheckoutElement.destroy === 'function') {
                    this.expressCheckoutElement.destroy();
                }
            } catch (e) {
                /* already destroyed */
            }
            this.elements = this.stripe.elements(this.amazonElementOptions(amt, cur));
            this.expressCheckoutElement = this.elements.create('expressCheckout', this.amazonExpressOptions());
            // What the live element was actually BUILT with — mountExpress() rebuilds only when this
            // drifts from _amazonPayLastUpdate, so a plain re-mount never destroys a good button.
            this._amazonPayBuiltWith = { amount: amt, currency: cur };
            this.expressCheckoutElement.on('confirm', this.onPaymentMethod.bind(this));
            this.expressCheckoutElement.on('cancel', this.cancelPayment.bind(this));
        }

        setupGateway() {
            try {
                // Build against the best total known right now. Runs from the base Gateway
                // constructor (before this subclass's constructor body), so seed lazily here.
                // mountExpress() still rebuilds whenever _amazonPayLastUpdate drifts from what
                // was built.
                const seed = this._amazonPayLastUpdate || (this._amazonPayLastUpdate = this.amazonSeedTotal());
                this.elements = this.stripe.elements(this.amazonElementOptions(seed.amount, seed.currency));
                this.expressCheckoutElement = this.elements.create('expressCheckout', this.amazonExpressOptions());
                this._amazonPayBuiltWith = { amount: seed.amount, currency: seed.currency };
                this.mountExpress();
                this.expressCheckoutElement.on('confirm', this.onPaymentMethod.bind(this));
                this.expressCheckoutElement.on('cancel', this.cancelPayment.bind(this));

            } catch (e) {
                console.log('Amazon pay Error', e)
            }

        }

        mountExpress() {
            const self = this;
            setTimeout(() => {

                const $all = $(self.get_wrapper_selector());
                if (!$all.length) {
                    return;
                }
                // Mount the EXISTING element. Rebuilding on every call tears down a live button, so
                // selecting the gateway made it flicker (rendered -> destroyed -> re-rendered from
                // scratch). Aero re-renders the order-review on updated_checkout and destroys the
                // element with it; Stripe then throws 'destroyed' on mount, and only that path rebuilds
                // (mirrors Google Pay's mountExpressRegular). Mount into the VISIBLE wrapper — Aero keeps
                // hidden responsive copies too.
                const $visible = $all.filter(':visible');
                const target = $visible.length ? $visible.get(0) : $all.get(0);
                if (undefined === self.expressCheckoutElement) {
                    return;
                }
                const bindReady = function () {
                    if (self._amazonPayReadyBound) {
                        return;
                    }
                    self.expressCheckoutElement.on('ready', self.expressButtonReady.bind(self));
                    self._amazonPayReadyBound = true;
                };
                // setupGateway() builds against a 500 placeholder, so the element must still be rebuilt
                // once the real total lands (order-pay seed / cart fragment) — but ONLY then.
                const built = self._amazonPayBuiltWith;
                const want = self._amazonPayLastUpdate;
                if (want && (!built || built.amount !== want.amount || built.currency !== want.currency)) {
                    self.recreateAmazonExpressElements(want);
                }
                try {
                    bindReady();
                    self.expressCheckoutElement.mount(target);
                    self.showButton();
                } catch (err) {
                    if (err && err.message && err.message.indexOf('destroyed') !== -1) {
                        try {
                            self.recreateAmazonExpressElements(self._amazonPayLastUpdate);
                            bindReady();
                            self.expressCheckoutElement.mount(target);
                            self.showButton();
                        } catch (e) {
                            console.log('Amazon Pay mount error', e && e.message);
                        }
                        return;
                    }
                    // 'already mounted' / other mount errors: element stays mounted, just reveal it.
                    self.showButton();
                }
            }, 500);
        }

        expressButtonReady({availablePaymentMethods}) {
            if (!availablePaymentMethods) {
                this.hideGatewayWallets();
            } else {
                this.amazon_pay_ready = true;
                this.showRegularGateway()
            }

        }

        /**
         * Amazon confirm: create a confirmation token (element is non-manual) and submit it through the
         * normal checkout form so AmazonPay::process_payment() can create + confirm the PaymentIntent and
         * return the Amazon redirect URL.
         */
        onPaymentMethod() {
            let payment_submit = this.elements.submit();
            payment_submit.then(() => {
                this.stripe.createConfirmationToken({
                    elements: this.elements
                }).then(({confirmationToken, error}) => {
                    if (error) {
                        console.log('error', error);
                        return;
                    }
                    this.express_btn_click = true;
                    this.appendMethodId(confirmationToken.id);
                }).catch((error) => {
                    console.log('error', error);
                });
            });
            payment_submit.catch((error) => {
                console.log(error)
            });


        }

        appendMethodId(confirmation_token) {
            let token_el = $('.fkwcs_confirmation_token');
            if (token_el.length > 0) {
                token_el.remove();
            }
            wcCheckoutForm.append(`<input type='hidden' name='fkwcs_confirmation_token' class='fkwcs_confirmation_token' value='${confirmation_token}'>`);
            if (this.express_btn_click) {
                const termsCheckbox = $('#terms');
                if (termsCheckbox.length) {
                    termsCheckbox.prop('checked', true);
                }
            }

            if ($('form#order_review').length > 0) {
                $('form#order_review').trigger('submit');
            } else {
                $('form.checkout').trigger('submit');

            }
        }

        confirmStripePayment(clientSecret, redirectURL, intent_type, order_id = false, retry = false) {

            let confirm_data = {
                'elements': this.elements,
                'clientSecret': clientSecret,
                confirmParams: {
                    return_url: fkwcs_data.get_home_url + redirectURL,
                },
                'redirect': 'if_required'
            };
            let cardPayment = null;
            if ('si' === intent_type) {
                cardPayment = this.stripe.confirmSetup(confirm_data);
            } else {
                cardPayment = this.stripe.confirmPayment(confirm_data);
            }
            let FormEl = $('form.woocommerce-checkout');
            cardPayment.then((result) => {
                if (result.error) {
                    this.logError(result.error);
                    $('.woocommerce-error').remove();
                    FormEl.unblock();
                    $('.woocommerce-notices-wrapper:first-child').html('<div class="woocommerce-error fkwcs-errors">' + result.error.message + '</div>').show();
                    this.logError(result.error, order_id);
                } else {

                    let intent = result['si' === intent_type ? 'setupIntent' : 'paymentIntent'];
                    if (false === retry && (intent.status === 'requires_action' || intent.status === 'requires_source_action')) {
                        this.confirmStripePayment(clientSecret, redirectURL, intent_type, order_id, true);
                    } else {
                        FormEl.addClass('processing');
                        FormEl.block(this.block_data);
                        this.safeRedirect(redirectURL);
                    }
                }
            });
        }

        cancelPayment() {
            $(document.body).trigger('fkwcs_express_cancel_payment', this);
        }

        update_fragment_data(fragments) {
            super.update_fragment_data(fragments);
            if (!fragments || !fragments.fkwcs_cart_details || !fragments.fkwcs_cart_details.order_data) {
                return;
            }
            const update_data = {
                amount: fragments.fkwcs_cart_details.order_data.total.amount,
                currency: fragments.fkwcs_cart_details.order_data.currency,
            };
            // ALWAYS record the latest total, even while another gateway is selected. Totals that
            // change while Amazon Pay is not the active radio (coupon, shipping, address) used to be
            // dropped here entirely, so selecting Amazon Pay later mounted the element on a stale or
            // placeholder amount — the sheet showed the wrong price, and the buyer authorized an
            // amount that differed from the confirmed PaymentIntent (declined on the Amazon side).
            // mountExpress() rebuilds from this whenever the built element drifts.
            this._amazonPayLastUpdate = {
                amount: update_data.amount,
                currency: String(update_data.currency).toLowerCase(),
            };
            /* Live element update/remount only while Amazon Pay is selected — avoids re-init on every checkout AJAX */
            if (this.gateway_id !== this.selectedGateway()) {
                return;
            }
            try {
                if (this.elements && typeof this.elements.update === 'function') {
                    const wantPayment  = Number(update_data.amount) > 0;
                    const builtPayment = this._amazonPayBuiltWith ? Number(this._amazonPayBuiltWith.amount) > 0 : null;
                    if (null !== builtPayment && wantPayment !== builtPayment) {
                        // Crossing the payment <-> setup boundary (total became 0, or a 0 total
                        // gained a charge): elements.update() cannot change the mode, and a
                        // wrong-mode element produces a confirmation token the server-side
                        // intent rejects. Rebuild in the right mode instead.
                        this.recreateAmazonExpressElements(update_data);
                    } else {
                        if (wantPayment) {
                            this.elements.update(update_data);
                        } else {
                            // Setup-mode element takes no amount — refresh the currency only.
                            this.elements.update({currency: this._amazonPayLastUpdate.currency});
                        }
                        // elements.update() re-prices the LIVE element, so it is no longer stale — keep
                        // _amazonPayBuiltWith in step or mountExpress() would rebuild (and flicker) after
                        // every cart update.
                        this._amazonPayBuiltWith = {
                            amount: this._amazonPayLastUpdate.amount,
                            currency: this._amazonPayLastUpdate.currency,
                        };
                    }
                }
            } catch (e) {
                this.recreateAmazonExpressElements(update_data);
            }
            this.mountExpress();
        }

        setGateway() {

            this.hidePlaceOrder();
            this.mountExpress();
            this.showButton();
        }

        unsetGateway() {
            if (this.gateway_id !== this.selectedGateway()) {
                $(this.amazon_place_btn_wrapper).hide();
            }

        }

        mountGateway() {
            this.hidePlaceOrder();
            // updated_checkout destroyed the iframe; rebuild + remount it while Amazon Pay is selected.
            if (this.gateway_id === this.selectedGateway()) {
                this.mountExpress();
            }
            this.showButton();
        }


        showButton() {
            this.PrepareButton();
        }


        PrepareButton() {
            let wrapper = $(this.amazon_place_btn_wrapper);
            wrapper.hide();
            this.amazon_pay_btn = wrapper;
            this.amazon_pay_btn.addClass('fkwcs-amazon-button-container');
            if (this.gateway_id !== this.selectedGateway()) {
                return;
            }
            wrapper.show();
        }


        hidePlaceOrder() {
            if (this.gateway_id !== this.selectedGateway()) {
                return;
            }
            this.hideGatewayWallets();
            const amazonButtonContainer = $('.fkwcs-amazon-button-container');
            if (amazonButtonContainer.length) {
                amazonButtonContainer.show();
            }
            const placeOrderBtn = $('#place_order');
            if (placeOrderBtn.length) {
                placeOrderBtn.hide();
                placeOrderBtn.addClass('fkwcs_hidden');
            }
        }

        showPlaceOrder() {
            this.hideGatewayWallets();
        }

    }
    class FKWCS_GOOGLEPAY extends Gateway {

        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);

            this.error_container = '.fkwcs_stripe_google_pay_error';
            this.mountable = true;
            this.gateway_class = 'li.payment_method_fkwcs_stripe_google_pay';
            this.express_btn_click = false;
            this.gpay_express_ready = false;
            this._gpayExpressReadyBound = false;
            this._gpayLastUpdate = null;
            // The ECE mounts into this wrapper, which is rendered right after the Place Order
            // button (googlepay.php render_wrapper) and replaces it when Google Pay is selected
            // — mirrors Apple Pay's .fkwcs_stripe_apple_pay_button.
            this.express_btn_wrapper = '.fkwcs_stripe_google_pay_button';

            // True when show_as_regular is set but the checkout express button is not — the
            // gateway shows purely as a regular payment method (mirror Apple Pay's isRegularOnly).
            const positions = fkwcs_data.google_pay_positions || [];
            this.isRegularOnly = $.inArray( 'show_as_regular', positions ) !== -1 && $.inArray( 'checkout', positions ) === -1;

            let self = this;
            $(document.body).on('updated_checkout', function () {
                self.mountExpressRegular();
            });

            // Order Pay page fires no cart/fragment refresh, so arm + mount the ECE once from
            // the order total localized by PHP (googlepay.php localize_element_data order-pay branch).
            if ('yes' === fkwcs_data.is_pay_for_order_page && fkwcs_data.fkwcs_google_pay_data) {
                $(function () {
                    setTimeout(function () {
                        self.update_fragment_data({fkwcs_google_pay_data: fkwcs_data.fkwcs_google_pay_data});
                    }, 300);
                });
            } else if ('yes' === fkwcs_data.google_pay_as_regular && fkwcs_data.gpay_cart_data) {
                // Regular-gateway row: arm + mount from the cart data localized at page load
                // instead of waiting for the first updated_checkout fragment refresh.
                //
                // The <li> is hidden by CSS until the ECE reports Google Pay is available
                // (expressRegularReady -> showRegularGateway), so on any checkout where
                // updated_checkout never reaches us — a checkout AJAX response another plugin
                // has polluted, a template that drives its own order review — the radio stays
                // invisible forever even though Google Pay works on the device. gpay_cart_data
                // carries the same order_data shape (currency + total_amount_subunit) that the
                // fkwcs_google_pay_data fragment does, so this arms the element with a real,
                // server-computed total — never a guess.
                //
                // updated_checkout still owns amount refreshes; this only covers the first arm,
                // and bails out if that event already got there first.
                $(function () {
                    setTimeout(function () {
                        if (self.expressCheckoutElement) {
                            return;
                        }
                        self.update_fragment_data({fkwcs_google_pay_data: fkwcs_data.gpay_cart_data});
                    }, 300);
                });
            }
        }


        setGateway() {
            // hidePlaceOrder() hides #place_order and reveals .fkwcs-gpay-button-container
            // (the place-order wrapper) where the Express Checkout Element mounts.
            this.hidePlaceOrder();
            this.mountExpressRegular();
        }


        mountGateway() {
            this.hidePlaceOrder();
            this.mountExpressRegular();
        }

        update_fragment_data(fragments) {
            super.update_fragment_data(fragments);
            this.updateExpressRegularElement(fragments);
        }

        // ---------------------------------------------------------------------
        // Express Checkout Element regular-gateway rendering (express_checkout mode).
        // Mirrors FKWCS_ApplePay: build an expressCheckout element (googlePay only),
        // mount it into the gateway's radio box, show/hide the radio on availability,
        // and submit the checkout form on confirm.
        // ---------------------------------------------------------------------

        get_wrapper_selector() {
            return this.express_btn_wrapper;
        }

        showRegularGateway() {
            const $el = $(this.gateway_class);
            if ($el.length > 0) {
                $el.show().removeClass('fkwcs_display_none');
            }
        }

        /** Full (re)build of the express element with a verified amount + currency. */
        recreateGpayExpressElements(update_data) {
            if (!update_data || update_data.amount === undefined || update_data.amount === null || !update_data.currency) {
                return;
            }
            const amt = update_data.amount;
            const cur = String(update_data.currency).toLowerCase();
            this._gpayLastUpdate = {amount: amt, currency: cur};
            this._gpayExpressReadyBound = false;
            try {
                if (this.expressCheckoutElement && typeof this.expressCheckoutElement.destroy === 'function') {
                    this.expressCheckoutElement.destroy();
                }
            } catch (e) {
                /* already destroyed */
            }
            const elementOptions = {
                mode: 'payment',
                amount: amt,
                currency: cur,
                appearance: {theme: 'stripe', variables: {}},
                paymentMethodCreation: 'manual',
                payment_method_types: ['card'],
            };
            const expressOptions = {
                buttonHeight: 42,
                buttonType: {googlePay: fkwcs_data.google_pay_btn_theme || 'plain'},
                buttonTheme: {googlePay: fkwcs_data.google_pay_btn_color || 'black'},
                paymentMethods: {
                    googlePay: 'always',
                    applePay: 'never',
                    link: 'never',
                    paypal: 'never',
                    klarna: 'never',
                },
            };
            this.elements = this.stripe.elements(elementOptions);
            this.expressCheckoutElement = this.elements.create('expressCheckout', expressOptions);
            this.expressCheckoutElement.on('confirm', this.onExpressRegularConfirm.bind(this));
            this.expressCheckoutElement.on('cancel', this.cancelPayment.bind(this));
            // Honor the "Disable Shipping Info in Payment Wallet" setting for Google Pay.
            this.expressCheckoutElement.on('click', (event) => this.resolveExpressCheckoutClick(event, 'google_pay_disable_shipping'));
            // When shipping is collected, keep the sheet responsive; the WooCommerce form
            // supplies the actual shipping method on submit, so a placeholder rate suffices.
            this.expressCheckoutElement.on('shippingaddresschange', (event) => event.resolve({shippingRates: [{id: 'pending', displayName: 'Pending', amount: 0}]}));
            this.expressCheckoutElement.on('shippingratechange', (event) => event.resolve({}));
        }

        updateExpressRegularElement(fragments) {
            const data = fragments && fragments.fkwcs_google_pay_data;
            const order_data = data && data.order_data;
            // Use the subunit (cents) total — the Express Checkout Element requires an integer
            // in the currency subunit. order_data.total.amount is decimal dollars (native API).
            const rawAmount = order_data && order_data.total_amount_subunit;
            const currency = order_data && order_data.currency;
            /* No fallback — without a verified amount + currency we will not arm Google Pay with a guess. */
            if (rawAmount === undefined || rawAmount === null || !currency) {
                return;
            }
            // Free-trial / $0-now carts: Stripe's payment-mode Express Checkout Element throws
            // "amount must be greater than 0", which killed the element mid-arming and left the
            // (CSS-hidden) radio row invisible — Google Pay then showed only as an express button.
            // Use the same 100-subunit placeholder as the express-button path (express-checkout.js)
            // and Amazon Pay's element options; the real amount is determined server-side at order
            // creation (a SetupIntent when there is nothing to charge now).
            const amount = Number(rawAmount) > 0 ? Number(rawAmount) : 100;
            this._gpayLastUpdate = {amount: amount, currency: String(currency).toLowerCase()};
            if (!this.expressCheckoutElement) {
                this.recreateGpayExpressElements({amount: amount, currency: currency});
            } else {
                try {
                    if (this.elements && typeof this.elements.update === 'function') {
                        this.elements.update({amount: amount, currency: String(currency).toLowerCase()});
                    }
                } catch (e) {
                    this.recreateGpayExpressElements({amount: amount, currency: currency});
                }
            }
            this._gpayRealAmountReady = true;
            // Mount unconditionally (not only when Google Pay is the selected gateway): the
            // regular-radio button must mount so the ECE can report availability and reveal
            // the radio in the payment list. Mirrors Apple Pay's unconditional updated_checkout mount.
            this.mountExpressRegular();
        }

        mountExpressRegular() {
            const self = this;
            setTimeout(() => {
                const sel = self.get_wrapper_selector();
                if (undefined === self.expressCheckoutElement || !$(sel).length) {
                    return;
                }
                if (!self._gpayExpressReadyBound) {
                    self.expressCheckoutElement.on('ready', self.expressRegularReady.bind(self));
                    self._gpayExpressReadyBound = true;
                }
                try {
                    self.expressCheckoutElement.mount(sel);
                } catch (err) {
                    if (err && err.message && err.message.indexOf('destroyed') !== -1) {
                        self.recreateGpayExpressElements(self._gpayLastUpdate);
                        self._gpayExpressReadyBound = false;
                        self.expressCheckoutElement.on('ready', self.expressRegularReady.bind(self));
                        self._gpayExpressReadyBound = true;
                        self.expressCheckoutElement.mount(sel);
                    }
                    // "already mounted" / other mount errors: element stays mounted; ignore.
                }
            }, 500);
        }

        expressRegularReady({availablePaymentMethods}) {

            if (!availablePaymentMethods || !availablePaymentMethods.googlePay) {
                $(this.gateway_class).hide().addClass('fkwcs_display_none');
                if ($(this.gateway_class).find('input[type="radio"]').is(':checked')) {
                    $(this.gateway_class).find('input[type="radio"]').prop('checked', false);
                    $('li.wc_payment_method:visible').not(this.gateway_class).first().find('input[type="radio"]').prop('checked', true).trigger('click');
                }
            } else {
                this.gpay_express_ready = true;
                this.showRegularGateway();
            }
        }

        onExpressRegularConfirm() {
            let payment_submit = this.elements.submit();
            payment_submit.then(() => {
                this.stripe.createPaymentMethod({elements: this.elements}).then(({paymentMethod}) => {
                    this.express_btn_click = true;
                    // Append the hidden fkwcs_source input (base behaviour) then submit the
                    // checkout form so payment goes through the standard gateway flow.
                    this.appendMethodId(paymentMethod.id);
                    const termsCheckbox = $('#terms');
                    if (termsCheckbox.length) {
                        termsCheckbox.prop('checked', true);
                    }
                    if ($('form#order_review').length > 0) {
                        $('form#order_review').trigger('submit');
                    } else {
                        $('form.checkout').trigger('submit');
                    }
                }).catch((error) => {
                    this.handleExpressConfirmError(error);
                });
            });
            payment_submit.catch((error) => {
                this.handleExpressConfirmError(error);
            });
        }

        /**
         * Surface a Google Pay express-confirm failure to the customer and the
         * server logs instead of swallowing it in a console.log-only catch.
         * showError()/showNotice() also unblock the checkout form so the customer
         * can retry rather than facing a dead Google Pay button.
         *
         * @param {Object} error Stripe error object from the confirm path.
         */
        handleExpressConfirmError(error) {
            if (!error) {
                return;
            }
            this.logError(error);
            this.showError(error);
            this.showNotice(getStripeLocalizedMessage(error.code, error.message));
        }

        cancelPayment() {
            $(document.body).trigger('fkwcs_express_cancel_payment', this);
        }

        hidePlaceOrder() {
            const gpayButtonContainer = $('.fkwcs-gpay-button-container');
            if (gpayButtonContainer.length) {
                gpayButtonContainer.show();
            }
            const placeOrderBtn = $('#place_order');
            if (placeOrderBtn.length) {
                placeOrderBtn.hide();
                placeOrderBtn.addClass('fkwcs_hidden');
            }
        }

        showPlaceOrder() {
            this.hideGatewayWallets();
        }

        appendMethodId(source) {
            super.appendMethodId(source);
            if (this.express_btn_click) {
                const termsCheckbox = $('#terms');
                if (termsCheckbox.length) {
                    termsCheckbox.prop('checked', true);
                }
            }
            const paymentMethodGpay = $('#payment_method_fkwcs_stripe_google_pay');
            if (paymentMethodGpay.length) {
                paymentMethodGpay.trigger('click');
            }

            if ($('form#order_review').length > 0) {
                $('form#order_review').trigger('submit');
            } else {
                $('form.checkout').trigger('submit');

            }
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
                                this.safeRedirect(redirectURL);
                            }
                        });
                        return;
                    }
                    this.safeRedirect(redirectURL);
                }
            }).catch(function () {

                // Report back to the server.
                $.get(redirectURL + '&is_ajax');
            });
        }

    }

    class FKWCS_AliPay extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_alipay_error';
            this.confirmCallBack = 'confirmAlipayPayment';
        }

    }

    class FKWCS_CASHAPP extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_cashapp_error';
            this.payment_method = '';
            this.setup_intent_processing = false; // Flag to prevent duplicate AJAX calls
            this.setup_intent_created = false;   // Flag to track if setup intent was already created
            this.element_mounted = false; // Track mount state
        }

        isZeroDollarPayment () {
            let amount_data = this.getAmountCurrency();
            return amount_data.amount === 0;
        }

        isOrderPayPage() {
            return ('yes' === fkwcs_data.is_pay_for_order_page) ||
                window.location.pathname.includes('/order-pay/') ||
                window.location.search.includes('pay_for_order=true');
        }

        isChangePaymentPage() {
            return 'yes' === fkwcs_data.is_change_payment_page;
        }

        isCashAppSaveCardChosen() {
            return ($('#payment_method_fkwcs_stripe_cashapp').is(':checked') &&
                $('input[name="wc-fkwcs_stripe_cashapp-payment-token"]').is(':checked') &&
                'new' !== $('input[name="wc-fkwcs_stripe_cashapp-payment-token"]:checked').val());
        }

        isAddPaymentMethodPage() {
            return $('body').hasClass('woocommerce-add-payment-method');
        }

        setupGateway() {
            if (typeof fkwcs_data.fkwcs_payment_data_cashapp === 'undefined') {
                return;
            }
            let amount_data = this.getAmountCurrency();
            let isZeroDollar = this.isZeroDollarPayment();

            // Only use setup mode for add payment method page or change payment method page
            if (this.isAddPaymentMethodPage() || this.isChangePaymentPage() ) {
                this.elements = this.stripe.elements({
                    mode: 'setup',
                    currency: amount_data.currency.toLowerCase(),
                    payment_method_types: ['cashapp']
                });
            } else if (isZeroDollar) {
                this.elements = this.stripe.elements({
                    mode: 'setup',
                    currency: amount_data.currency.toLowerCase(),
                    payment_method_types: ['cashapp'],
                    paymentMethodCreation: 'manual'
                });
            }else if (this.isOrderPayPage()) {
                this.elements = this.stripe.elements({
                    mode: 'payment',
                    currency: amount_data.currency.toLowerCase(),
                    amount: amount_data.amount,
                    payment_method_types: ['cashapp'],
                    setup_future_usage: 'off_session'
                });
            } else {
                // Use payment mode for all other cases (checkout, order pay)
                this.elements = this.stripe.elements({
                    mode: 'payment',
                    currency: amount_data.currency.toLowerCase(),
                    amount: amount_data.amount,
                    payment_method_types: ['cashapp'],
                    paymentMethodCreation: 'manual'
                });
            }

            this.element_options = {
                fields: {
                    billingDetails: 'never'
                }
            };

            if (fkwcs_data.fkwcs_payment_data_cashapp) {
                let paymentData = fkwcs_data.fkwcs_payment_data_cashapp;
                if (paymentData.element_options) {
                    this.element_options = {
                        ...this.element_options,
                        ...paymentData.element_options
                    };
                }
            }

            this.cashapp = this.elements.create('payment', this.element_options);
            this.cashapp.on('change', (event) => {
                this.empty = event.empty;
                this.showError();
            });
            this.setupSavedPaymentMethodListeners();
        }

        setupSavedPaymentMethodListeners() {
            $(document).on('change', 'input[name="wc-fkwcs_stripe_cashapp-payment-token"]', () => {
                // Reset state when user switches between saved/new payment methods
                this.resetSetupIntentState();
                this.handlePaymentMethodSelection();
            });

            $(document).on('change', 'input[name="payment_method"]', () => {
                if (this.selectedGateway() === this.gateway_id) {
                    // Reset state when user switches to this gateway
                    this.resetSetupIntentState();
                    this.handlePaymentMethodSelection();
                }
            });
        }

        // Reset state flags (useful when switching between payment methods)
        resetSetupIntentState() {
            this.setup_intent_processing = false;
            this.setup_intent_created = false;
            this.payment_method = '';
        }

        setGateway() {
            // Safely unmount before remounting
            if (this.element_mounted && this.cashapp) {
                try {
                    this.cashapp.unmount();
                    this.element_mounted = false;
                } catch (e) {
                    console.log('Element unmount failed or already unmounted:', e.message);
                }
            }
            this.mountGateway();
        }

        handlePaymentMethodSelection() {
            setTimeout(() => {
                if (this.isCashAppSaveCardChosen()) {
                    $('.fkwcs_stripe_cashapp_select').hide();
                    // Unmount element when using saved payment method
                    if (this.element_mounted && this.cashapp) {
                        try {
                            this.cashapp.unmount();
                            this.element_mounted = false;
                        } catch (e) {
                            console.log('Element unmount failed:', e.message);
                        }
                    }
                } else {
                    $('.fkwcs_stripe_cashapp_select').show();
                    let selector = `.${this.gateway_id}_form .${this.gateway_id}_select`;

                    // Only mount if not already mounted
                    if (!this.element_mounted && $(selector).length > 0) {
                        try {
                            this.cashapp.mount(selector);
                            this.element_mounted = true;
                            $(selector).css({backgroundColor: '#fff'});
                        } catch (e) {
                            console.log('Element mount failed:', e.message);
                            this.element_mounted = false;
                        }
                    }
                }
            }, 100);
        }

        mountGateway() {
            let cashapp_form = $(`.${this.gateway_id}_form`);
            if (0 === cashapp_form.length) {
                return;
            }

            cashapp_form.show();

            if (!this.isCashAppSaveCardChosen()) {
                let selector = `.${this.gateway_id}_form .${this.gateway_id}_select`;
                const selectorElement = $(selector);

                const actuallyMounted = selectorElement.length > 0 && selectorElement.children().length > 0;

                // Only mount if selector exists and element is not already mounted
                if (selectorElement.length > 0 && !actuallyMounted) {
                    try {
                        this.cashapp.mount(selector);
                        this.element_mounted = true;
                        $(selector).css({backgroundColor: '#fff'});
                    } catch (e) {
                        console.log('Element mount failed:', e.message);
                        this.element_mounted = false;
                    }
                }
            }
        }

        add_payment_method(e) {
            if (this.gateway_id === this.selectedGateway()) {
                let source_el = $('.fkwcs_source');
                if (source_el.length > 0) {
                    return;
                }

                // Prevent duplicate calls
                if (!this.setup_intent_processing && !this.setup_intent_created) {
                    this.create_setup_intent('add_payment');
                    e.preventDefault();
                    return false;
                } else if (this.setup_intent_created && this.payment_method) {
                    // Already have payment method, submit form
                    this.appendMethodId(this.payment_method);
                    $('#add_payment_method').trigger('submit');
                    e.preventDefault();
                    return false;
                } else {
                    // Still processing
                    e.preventDefault();
                    return false;
                }
            }
        }

        create_setup_intent(submit_type = 'add_payment') {
            // Prevent duplicate AJAX calls
            if (this.setup_intent_processing) {
                return;
            }

            // For change payment method with existing token, don't create setup intent
            if (this.isChangePaymentPage() && this.isCashAppSaveCardChosen()) {
                this.submitChangePaymentWithToken();
                return;
            }

            // For zero dollar payments with saved payment method, skip setup intent creation
            if (this.isZeroDollarPayment() && this.isCashAppSaveCardChosen()) {
                if (this.isChangePaymentPage()) {
                    this.submitChangePaymentWithToken();
                } else {
                    // Just submit the form with the existing token
                    if (this.isAddPaymentMethodPage()) {
                        $('#add_payment_method').trigger('submit');
                    } else {
                        wcCheckoutForm.trigger('submit');
                    }
                }
                return;
            }

            // Check if we already have a payment method for this session
            if (this.setup_intent_created && this.payment_method) {
                this.handleExistingPaymentMethod(submit_type);
                return;
            }

            // Check if element is mounted before proceeding (only for new payment methods)
            if (!this.element_mounted && !this.isCashAppSaveCardChosen()) {
                this.showNotice('Payment element not ready. Please try again.');
                return;
            }

            // Set processing flag to prevent duplicates
            this.setup_intent_processing = true;

            const {fkwcs_nonce, admin_ajax} = fkwcs_data;
            const process_data = {
                action: 'fkwcs_create_setup_intent',
                gateway_id: this.gateway_id,
                fkwcs_nonce
            };

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
                    // Reset processing flag
                    _this.setup_intent_processing = false;

                    if (response.status !== 'success') {
                        $('body').css('cursor', 'default');
                        _this.showNotice('Error creating setup intent');
                        return false;
                    }

                    const {client_secret: clientSecret} = response.data;

                    _this.elements.submit().then(() => {
                        return _this.stripe.confirmSetup({
                            elements: _this.elements,
                            clientSecret,
                            confirmParams: {
                                return_url: homeURL,
                                payment_method_data: {
                                    billing_details: _this.getBillingAddress()
                                }
                            },
                            redirect: 'if_required',
                        });
                    }).then((result) => {
                        $('body').css('cursor', 'default');

                        if (result.error) {
                            console.log('Setup confirmation error:', result.error);
                            _this.showNotice(getStripeLocalizedMessage(result.error.code, result.error.message));
                            return;
                        }

                        if (result.setupIntent && result.setupIntent.payment_method) {
                            _this.payment_method = result.setupIntent.payment_method;
                            _this.setup_intent_created = true;

                            // Handle the submission based on context
                            _this.handleSuccessfulSetupIntent(submit_type);
                        } else {
                            _this.showNotice('Setup intent completed but no payment method found');
                        }
                    }).catch((error) => {
                        console.log("Error in setup process:", error);
                        $('body').css('cursor', 'default');
                        _this.setup_intent_processing = false;
                        _this.showNotice('An error occurred during setup');
                    });
                },
                error(xhr, status, error) {
                    console.log('AJAX error:', error);
                    $('body').css('cursor', 'default');
                    _this.setup_intent_processing = false;
                    _this.showNotice('Communication error occurred');
                },
                complete() {
                    $('body').css('cursor', 'default');
                }
            });
        }

        // Handle successful setup intent based on context
        handleSuccessfulSetupIntent(submit_type) {
            if (this.isChangePaymentPage()) {
                // For change payment method, add payment method to form and submit
                this.handleChangePaymentMethodWithNewSource();
            } else if (this.isAddPaymentMethodPage() || submit_type === 'add_payment') {
                // For add payment method page
                $('#add_payment_method').find('.fkwcs_payment_method').remove();
                this.appendMethodId(this.payment_method);
                $('#add_payment_method').trigger('submit');
            } else {
                // For checkout page
                wcCheckoutForm.find('.fkwcs_payment_method').remove();
                this.appendMethodId(this.payment_method);
                wcCheckoutForm.trigger('submit');
            }
        }

        // Handle existing payment method (when setup intent already created)
        handleExistingPaymentMethod(submit_type) {
            if (this.isChangePaymentPage()) {
                this.handleChangePaymentMethodWithNewSource();
            } else if (this.isAddPaymentMethodPage() || submit_type === 'add_payment') {
                this.appendMethodId(this.payment_method);
                $('#add_payment_method').trigger('submit');
            } else {
                this.appendMethodId(this.payment_method);
                wcCheckoutForm.find('.fkwcs_payment_method').remove();
                wcCheckoutForm.trigger('submit');
            }
        }

        // Handle change payment method with new payment source
        handleChangePaymentMethodWithNewSource() {

            // Prevent recursion - check if we're already processing
            if (this.isProcessingPaymentChange) {
                return;
            }

            // Set flag to prevent recursion
            this.isProcessingPaymentChange = true;

            try {
                // Find the form to submit
                const formSelectors = [
                    '#order_review'
                ];

                let form = null;
                for (let selector of formSelectors) {
                    form = $(selector).first();
                    if (form.length) {
                        break;
                    }
                }

                if (form && form.length) {
                    // Remove any existing source fields
                    form.find('input[name="fkwcs_source"]').remove();

                    // Add the new payment method
                    const hiddenInput = `<input type="hidden" name="fkwcs_source" value="${this.payment_method}">`;
                    form.append(hiddenInput);

                    // Ensure payment method is selected
                    const paymentMethodRadio = form.find('input[name="payment_method"][value="' + this.gateway_id + '"]');
                    if (paymentMethodRadio.length) {
                        paymentMethodRadio.prop('checked', true);
                    }

                    // Ensure "new" token is selected
                    const newTokenRadio = form.find('input[name="wc-fkwcs_stripe_cashapp-payment-token"][value="new"]');
                    if (newTokenRadio.length) {
                        newTokenRadio.prop('checked', true);
                    }

                    // Use setTimeout to break the call stack and prevent immediate recursion
                    setTimeout(() => {
                        // Submit the form
                        form[0].submit(); // Use native DOM submit instead of jQuery trigger

                        // Reset the flag after a delay
                        setTimeout(() => {
                            this.isProcessingPaymentChange = false;
                        }, 1000);
                    }, 10);

                } else {
                    console.log('Could not find change payment method form');
                    this.isProcessingPaymentChange = false;
                }
            } catch (error) {
                console.log('Error in handleChangePaymentMethodWithNewSource:', error);
                this.isProcessingPaymentChange = false;
                this.showNotice('An error occurred while changing payment method - please try again');
            }
        }

        // Method to handle change payment with existing token (this works correctly)
        submitChangePaymentWithToken() {
            const selectedToken = $('input[name="wc-fkwcs_stripe_cashapp-payment-token"]:checked').val();
            if (selectedToken && selectedToken !== 'new') {
                this.submitChangePaymentForm();
            }
        }

        // Standard method to submit change payment form (used for existing tokens)
        submitChangePaymentForm() {
            const formSelectors = [
                '#change_payment_method_form',
                '.woocommerce-MyAccount-content form',
                'form[action*="change_payment_method"]',
                'form[action*="subscription"]',
                'form.woocommerce-form'
            ];

            let form = null;
            for (let selector of formSelectors) {
                form = $(selector).first();
                if (form.length) {
                    break;
                }
            }

            if (form && form.length) {
                form.trigger('submit');
            } else {
                console.log('Could not find change payment method form for existing token');
                // Fallback
                const fallbackForm = $('form').first();
                if (fallbackForm.length) {
                    console.log('Using fallback form submission');
                    fallbackForm.trigger('submit');
                } else {
                    this.showNotice('Unable to find payment method form - please refresh and try again');
                }
            }
        }

        processingSubmit() {
            if (this.isCashAppSaveCardChosen()) {
                this.showError('');
                return true;
            }

            if (this.isOrderPayPage()) {
                this.showError('');
                return true;
            } else {
                // For zero-dollar payments with saved payment method, don't create new payment method
                if (this.isZeroDollarPayment() && this.isCashAppSaveCardChosen()) {
                    this.showError('');
                    return true;
                }

                if ('' === this.payment_method) {
                    this.createPaymentMethod();
                    return false;
                }
                this.showError('');
                return true;
            }
        }

        createPaymentMethod(type = 'submit') {
            if (this.isCashAppSaveCardChosen()) {
                return;
            }

            // Check if element is mounted before proceeding
            if (!this.element_mounted) {
                this.showNotice('Payment element not ready. Please try again.');
                return;
            }

            if (type === 'add_payment_method' || this.isAddPaymentMethodPage()) {
                this.elements.submit().then(() => {
                    this.stripe.createPaymentMethod({
                        elements: this.elements,
                        params: {
                            billing_details: this.getBillingAddress(type)
                        }
                    }).then((result) => {
                        if (result.error) {
                            this.logError(result.error);
                            this.showNotice(getStripeLocalizedMessage(result.error.code, result.error.message));
                            return;
                        }

                        if (result.paymentMethod) {
                            this.payment_method = result.paymentMethod.id;
                            this.appendMethodId(this.payment_method);
                            $('#add_payment_method').trigger('submit');
                        }
                    });
                });
                return;
            }

            this.elements.submit().then(() => {
                this.stripe.createPaymentMethod({
                    elements: this.elements,
                    params: {
                        billing_details: this.getBillingAddress(type)
                    }
                }).then((result) => {
                    if (result.error) {
                        this.logError(result.error);
                        this.showNotice(getStripeLocalizedMessage(result.error.code, result.error.message));
                        return;
                    }

                    if (result.paymentMethod) {
                        wcCheckoutForm.find('.fkwcs_payment_method').remove();
                        this.payment_method = result.paymentMethod.id;
                        this.appendMethodId(this.payment_method);
                        wcCheckoutForm.trigger('submit');
                    }
                });
            });
        }

        hasSource() {
            let source_el = $('.fkwcs_source');
            if (source_el.length > 0) {
                return source_el.val();
            }
            return '';
        }

        processOrderReview(e) {
            if (this.gateway_id === this.selectedGateway()) {

                if (this.isChangePaymentPage()) {
                    if (this.isCashAppSaveCardChosen()) {
                        return true;
                    } else {
                        // Prevent duplicate calls for change payment method
                        let source = this.hasSource();
                        if ('' === source) {
                            if (!this.setup_intent_processing && !this.setup_intent_created) {
                                this.create_setup_intent('change_payment');
                                e.preventDefault();
                                return false;
                            } else if (this.setup_intent_created && this.payment_method) {
                                // Setup intent already created, handle submission
                                this.handleChangePaymentMethodWithNewSource();
                                e.preventDefault();
                                return false;
                            } else {
                                // Still processing, prevent form submission
                                e.preventDefault();
                                return false;
                            }
                        }
                    }
                }

                // If using saved payment method (including zero dollar), don't need to create new source
                if (this.isCashAppSaveCardChosen()) {
                    return true;
                }

                // For zero dollar payments with new payment method, create setup intent instead of payment intent
                if (this.isZeroDollarPayment()) {
                    let source = this.hasSource();
                    if ('' === source) {
                        this.create_setup_intent('order_review');
                        e.preventDefault();
                        return false;
                    }
                    return true;
                }

                let source = this.hasSource();
                if ('' === source) {
                    if (this.isOrderPayPage()) {
                        this.createIntent('order_review');
                        e.preventDefault();
                        return false;
                    } else {
                        this.createIntent('order_review');
                        e.preventDefault();
                        return false;
                    }
                }
            }
        }

        confirmStripePayment(clientSecret, redirectURL, intent_type, authenticationAlready = false, order_id = false) {
            if (this.gateway_id === this.selectedGateway()) {

                // For saved payment methods or order pay page with authentication already done
                if (this.isOrderPayPage() && !authenticationAlready) {
                    this.stripe.confirmPayment({
                        clientSecret: clientSecret,
                        confirmParams: {
                            return_url: `${homeURL}${redirectURL}`
                        }
                    }).then((result) => {
                        if (result.error) {
                            this.logError(result.error, order_id);
                            this.showError(result.error);
                            this.unblockElement();
                        } else if (result.paymentIntent &&
                            ['succeeded', 'processing'].includes(result.paymentIntent.status)) {
                            $('.woocommerce-error').remove();
                            this.safeRedirect(redirectURL);
                        } else if (result.paymentIntent && result.paymentIntent.next_action) {
                            this.safeRedirect(result.paymentIntent.next_action.redirect_to_url.url);
                        }
                    }).catch((error) => {
                        this.logError(error, order_id);
                        this.showError(error);
                        this.unblockElement();
                    });
                } else {
                    // Check if element is mounted before proceeding with new payment methods
                    if (!this.element_mounted && !this.isCashAppSaveCardChosen()) {
                        this.showNotice('Payment element not ready. Please try again.');
                        this.unblockElement();
                        return;
                    }

                    // For new payment methods that need confirmation or change payment method
                    this.elements.submit().then(() => {
                        const confirmPaymentData = {
                            elements: this.elements,
                            clientSecret: clientSecret,
                            confirmParams: {
                                return_url: `${homeURL}${redirectURL}`,
                                payment_method_data: {
                                    billing_details: this.getBillingAddress()
                                }
                            }
                        };

                        if ('si' === intent_type) {
                            this.stripe.confirmSetup(confirmPaymentData).then((result) => {
                                this.handleConfirmationResult(result, redirectURL, order_id, 'setupIntent');
                            }).catch((error) => {
                                this.handleConfirmationError(error, order_id);
                            });
                        } else {
                            this.stripe.confirmPayment(confirmPaymentData).then((result) => {
                                this.handleConfirmationResult(result, redirectURL, order_id, 'paymentIntent');
                            }).catch((error) => {
                                this.handleConfirmationError(error, order_id);
                            });
                        }
                    }).catch((error) => {
                        this.handleConfirmationError(error, order_id);
                    });
                }
            }
        }

        handleConfirmationResult(result, redirectURL, order_id, intentType) {
            if (result.error) {
                this.logError(result.error, order_id);
                this.showError(result.error);
                this.unblockElement();
                return;
            }

            const intent = result[intentType];
            const successStatuses = ['succeeded', 'processing'];

            if (successStatuses.includes(intent.status)) {
                $('.woocommerce-error').remove();
                this.safeRedirect(redirectURL);
            } else if (intent.next_action && intent.next_action.redirect_to_url) {
                this.safeRedirect(intent.next_action.redirect_to_url.url);
            } else {
                console.log('Unexpected intent status:', intent.status);
                this.logError({message: `Unexpected intent status: ${intent.status}`}, order_id);
                this.unblockElement();
            }
        }

        handleConfirmationError(error, order_id) {
            console.log('Cash App Pay confirmation error:', error);
            this.logError(error, order_id);
            this.showError(error);
            this.unblockElement();
        }
    }
    class FKWCS_Multibanco extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_multibanco_error';
            this.gateway_initialized = false;
        }

        setupGateway() {
            if (typeof fkwcs_data.fkwcs_payment_data_multibanco === 'undefined') {
                return;
            }
            let amount_data = this.getAmountCurrency();
            if (amount_data.amount <= 0) {
                return;
            }
            this.elements = this.stripe.elements({
                mode: 'payment',
                currency: amount_data.currency.toLowerCase(),
                amount: amount_data.amount,
                payment_method_types: ['multibanco']
            });

            this.element_options = {
                fields: {
                    billingDetails: {
                        address: 'never',
                        name: $("#billing_first_name").length ? "never" : "auto",
                        email: $("#billing_email").length ? "never" : "auto",
                        phone: $("#billing_phone").length ? "never" : "auto"
                    }
                },
                defaultValues: {
                    billingDetails: {
                        name: $("#billing_first_name").length ? $("#billing_first_name").val() + " " + $("#billing_last_name").val() : '',
                        email: $("#billing_email").val(),
                        phone: $("#billing_phone").val()
                    }
                }
            };

            if (fkwcs_data.fkwcs_payment_data_multibanco) {
                let paymentData = fkwcs_data.fkwcs_payment_data_multibanco;
                if (paymentData.element_options) {
                    this.element_options = {
                        ...this.element_options,
                        ...paymentData.element_options
                    };
                }
            }
            this.multibanco = this.elements.create('payment', this.element_options);
            this.gateway_initialized = true;
        }

        /**
         * Reinitialize gateway when element data becomes available dynamically
         */
        reinitialize_gateway() {
            try {
                if (!this.gateway_initialized && typeof fkwcs_data.fkwcs_payment_data_multibanco !== 'undefined') {
                    console.log('Reinitializing Multibanco gateway with dynamic element data');
                    this.setupGateway();

                    if (this.gateway_id === this.selectedGateway()) {
                        this.mountGateway();
                    }
                }
            } catch (e) {
                console.log('Error reinitializing Multibanco gateway:', e);
            }
        }

        setGateway() {
            try {
                if (this.multibanco) {
                    this.multibanco.unmount();
                }
                this.mountGateway();
            } catch (e) {
                console.log('Error in Multibanco setGateway:', e);
            }
        }

        mountGateway() {
            try {
                let multibanco_form = $(`.${this.gateway_id}_form`);
                if (0 === multibanco_form.length) {
                    return;
                }
                multibanco_form.show();
                let selector = `.${this.gateway_id}_form .${this.gateway_id}_select`;

                // Only mount if element exists and selector is available
                if (this.multibanco && $(selector).length > 0) {
                    this.multibanco.mount(selector);
                    $(selector).css({backgroundColor: '#fff'});
                }
            } catch (e) {
                console.log('Error in Multibanco mountGateway:', e);
            }
        }

        processingSubmit() {
            this.showError('');
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


        confirmStripePayment(clientSecret, redirectURL) {
            if (this.gateway_id === this.selectedGateway()) {
                this.elements.submit().then(() => {
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
                });
            }
        }
    }

    class FKWCS_PIX extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_pix_error';
            this.gateway_initialized = false;
        }

        setupGateway() {
            if (typeof fkwcs_data.fkwcs_payment_data_pix === 'undefined') {
                return;
            }
            let amount_data = this.getAmountCurrency();
            if (amount_data.amount <= 0) {
                return;
            }
            this.elements = this.stripe.elements({
                mode: 'payment',
                currency: amount_data.currency.toLowerCase(),
                amount: amount_data.amount,
                payment_method_types: ['pix']
            });

            this.element_options = {
                fields: {
                    billingDetails: 'never'
                }
            };

            if (fkwcs_data.fkwcs_payment_data_pix) {
                let paymentData = fkwcs_data.fkwcs_payment_data_pix;
                if (paymentData.element_options) {
                    this.element_options = {
                        ...this.element_options,
                        ...paymentData.element_options
                    };
                }
            }
            this.pix = this.elements.create('payment', this.element_options);
            this.gateway_initialized = true;
        }

        /**
         * Reinitialize gateway when element data becomes available dynamically
         */
        reinitialize_gateway() {
            try {
                if (!this.gateway_initialized && typeof fkwcs_data.fkwcs_payment_data_pix !== 'undefined') {
                    console.log('Reinitializing PIX gateway with dynamic element data');
                    this.setupGateway();

                    if (this.gateway_id === this.selectedGateway()) {
                        this.mountGateway();
                    }
                }
            } catch (e) {
                console.log('Error reinitializing PIX gateway:', e);
            }
        }

        setGateway() {
            try {
                if (this.pix) {
                    this.pix.unmount();
                }
                this.mountGateway();
            } catch (e) {
                console.log('Error in PIX setGateway:', e);
            }
        }

        mountGateway() {
            try {
                let pix_form = $(`.${this.gateway_id}_form`);
                if (0 === pix_form.length) {
                    return;
                }
                pix_form.show();
                let selector = `.${this.gateway_id}_form .${this.gateway_id}_select`;

                // Only mount if element exists and selector is available
                if (this.pix && $(selector).length > 0) {
                    this.pix.mount(selector);
                    $(selector).css({backgroundColor: '#fff'});
                }
            } catch (e) {
                console.log('Error in PIX mountGateway:', e);
            }
        }

        processingSubmit() {
            this.showError('');
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

        confirmStripePayment(clientSecret, redirectURL) {
            if (this.gateway_id === this.selectedGateway()) {
                this.elements.submit().then(() => {
                    this.stripe.confirmPayment({
                        elements: this.elements,
                        clientSecret: clientSecret,
                        confirmParams: {
                            return_url: `${homeURL}${redirectURL}`,
                            payment_method_data: {
                                billing_details: this.getBillingAddress()
                            }
                        }
                    }).then((result) => {
                        if (result.error) {
                            this.logError(result.error);
                            this.showError(result.error);
                            this.unblockElement();
                        } else {
                            this.safeRedirect(result.payment_intent.next_action.redirect_to_url.url);
                        }
                    }).catch((error) => {
                        this.logError(error);
                        this.showError(error);
                        this.unblockElement();
                    });
                });
            }
        }
    }
    class FKWCS_ach extends Gateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_ach_error';
        }

        getSavedPaymentMethod() {
            const checkedToken = document.querySelector("input[name='wc-fkwcs_stripe_ach-payment-token']:checked");
            return (checkedToken && checkedToken.value) || false;
        }

        isZeroDollarPayment() {
            let amount_data = this.getAmountCurrency();
            return amount_data.amount === 0;
        }

        setupGateway() {
            if (typeof fkwcs_data.fkwcs_ach_payment_data === 'undefined') {
                return;
            }
            let paymentData = fkwcs_data.fkwcs_ach_payment_data;
            this.element_data = paymentData.element_data;
            let isZeroDollar = this.isZeroDollarPayment();

            if (isZeroDollar) {
                this.elements = this.stripe.elements({
                    mode: 'setup',
                    currency: this.element_data.currency,
                    payment_method_types: ['us_bank_account'],
                    paymentMethodCreation: 'manual'
                });
            } else {
                this.elements = this.stripe.elements(this.element_data);
            }
            this.ach = this.elements.create('payment', {
                fields: {
                    billingDetails: {
                        name: 'never',
                        email: 'never'
                    }
                }
            });
            this.ach.on('change', (event) => {
                this.empty = event.empty;
                this.showError();
            });

        }

        setGateway() {
            if (this.ach) {
                this.ach.unmount();
            }
            this.mountGateway();
        }

        mountGateway() {
            let ach_form = $(`.${this.gateway_id}_form`);
            if (0 === ach_form.length) {
                return;
            }
            ach_form.show();
            let selector = `.${this.gateway_id}_form`;
            this.ach.mount(selector);
            $(selector).css({backgroundColor: '#fff'});
        }

        processingSubmit() {
            if (!this.getSavedPaymentMethod() || this.getSavedPaymentMethod() === 'new') {
                if (true === this.empty) {
                    this.showError({message: fkwcs_data.empty_bank_message});
                    this.showNotice(fkwcs_data.empty_bank_message);
                    return false;
                }
            }
            this.showError('');
            return true;
        }

        add_payment_method(e) {
            if (this.gateway_id === this.selectedGateway()) {

                let source_el = $('.fkwcs_source');
                if (source_el.length > 0) {
                    return;
                }
                this.create_setup_intent('add_payment');
                e.preventDefault();
                return false;

            }
        }

        create_setup_intent() {
            const {fkwcs_nonce, admin_ajax} = fkwcs_data;
            const process_data = {
                action: 'fkwcs_create_setup_intent',
                gateway_id: this.gateway_id,
                fkwcs_nonce
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
                    _this.elements.submit().then(() => {
                        _this.stripe.confirmSetup({
                            elements: _this.elements,
                            clientSecret,
                            confirmParams: {
                                return_url: homeURL,
                                payment_method_data: {
                                    billing_details: _this.getBillingAddress()
                                }
                            },
                            redirect: 'if_required',
                        }).then((result) => {
                            if (result.error) {
                                _this.showNotice(getStripeLocalizedMessage(result.error.code, result.error.message));
                                return;
                            }

                            if (result.setupIntent && result.setupIntent.payment_method) {
                                wcCheckoutForm.find('.fkwcs_payment_method').remove();
                                _this.payment_method = result.setupIntent.payment_method;
                                _this.appendMethodId(_this.payment_method);
                                wcCheckoutForm.trigger('submit');
                            } else {
                                _this.showNotice(fkwcs_data.empty_bank_message || 'Unable to add the bank account. Please try again.');
                            }
                        });
                    }).catch((error) => {
                        console.error("Error submitting elements:", error);
                    });
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


        hasSource() {
            let saved_source = $('input[name="wc-' + this.gateway_id + '-payment-token"]:checked');
            if (saved_source.length > 0 && 'new' !== saved_source.val()) {
                return saved_source.val();
            }

            let source_el = $('.fkwcs_source');
            if (source_el.length > 0) {
                return source_el.val();
            }

            return '';
        }

        processOrderReview(e) {
            if (this.gateway_id === this.selectedGateway()) {
                let source = this.hasSource();
                if ('' === source) {
                    if (!this.getSavedPaymentMethod() || this.getSavedPaymentMethod() === 'new') {
                        if ('yes' === fkwcs_data.is_change_payment_page) {
                            this.create_setup_intent('add_payment');
                            e.preventDefault();
                            return false;
                        } else {
                            this.createIntent('order_review');
                            e.preventDefault();
                            return false;
                        }
                    }
                }
            }
        }

        createIntent(type) {
            if (!this.processingSubmit()) {
                setTimeout(() => {
                    const overlay = document.querySelector('.blockUI.blockOverlay');
                    if (overlay) {
                        overlay.remove();
                    }
                }, 100);
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
            let order_key = self.getOrderKeyFromUrl();
            let orderData = {
                action: 'fkwcs_create_payment_intent',
                order_id: order_id,
                order_key: order_key,
                gateway_id: this.gateway_id,
                security: fkwcs_data.nonce,
                type: type
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
                        }).then(() => {
                            wcCheckoutForm.trigger('submit');
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


        confirmStripePayment(clientSecret, redirectURL, intent_type) {

            if (this.gateway_id === this.selectedGateway()) {
                this.elements.submit().then(() => {
                    const confirmPaymentData = {
                        clientSecret: clientSecret,
                        confirmParams: {
                            return_url: `${homeURL}${redirectURL}`,
                            payment_method_data: {
                                billing_details: this.getBillingAddress()
                            }
                        }
                    };
                    if (!this.getSavedPaymentMethod() || this.getSavedPaymentMethod() === 'new') {
                        confirmPaymentData.elements = this.elements;
                    }
                    if ('si' === intent_type) {
                        this.stripe.confirmSetup(confirmPaymentData).then((result) => {
                            if (result.error) {
                                console.error("setup confirmation error:", result.error);
                            } else {
                                console.log("setup confirmation success:", result);
                            }
                        });
                    } else {
                        this.stripe.confirmPayment(confirmPaymentData).then((result) => {
                            if (result.error) {
                                console.error("Payment confirmation error:", result.error);
                            } else {
                                console.log("Payment confirmation success:", result);
                            }
                        });
                    }
                }).catch((error) => {
                    console.error("Error submitting elements:", error);
                });
            }
        }

    }

    class FKWCS_EPS extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_eps_error';
            this.gateway_initialized = false;
        }

        setupGateway() {
            if (typeof fkwcs_data.fkwcs_payment_data_eps === 'undefined') {
                return;
            }
            let amount_data = this.getAmountCurrency();
            this.elements = this.stripe.elements({
                mode: 'payment',
                currency: amount_data.currency.toLowerCase(),
                amount: amount_data.amount,
                payment_method_types: ['eps']
            });

            this.element_options = {
                fields: {
                    billingDetails: {
                        name: $("#billing_first_name").length ? "never" : "auto",
                    }
                },
                defaultValues: {
                    billingDetails: {
                        name: $("#billing_first_name").length ? $("#billing_first_name").val() + " " + $("#billing_last_name").val() : '',
                    }
                }
            };

            if (fkwcs_data.fkwcs_payment_data_eps) {
                let paymentData = fkwcs_data.fkwcs_payment_data_eps;
                if (paymentData.element_options) {
                    this.element_options = {
                        ...this.element_options,
                        ...paymentData.element_options
                    };
                }
            }
            this.eps = this.elements.create('payment', this.element_options);
            this.gateway_initialized = true;
        }

        /**
         * Reinitialize gateway when element data becomes available dynamically
         */
        reinitialize_gateway() {
            try {
                if (!this.gateway_initialized && typeof fkwcs_data.fkwcs_payment_data_eps !== 'undefined') {
                    console.log('Reinitializing EPS gateway with dynamic element data');
                    this.setupGateway();

                    if (this.gateway_id === this.selectedGateway()) {
                        this.mountGateway();
                    }
                }
            } catch (e) {
                console.log('Error reinitializing EPS gateway:', e);
            }
        }

        setGateway() {
            try {
                if (this.eps) {
                    this.eps.unmount();
                }
                this.mountGateway();
            } catch (e) {
                console.log('Error in EPS setGateway:', e);
            }
        }

        mountGateway() {
            try {
                let eps_form = $(`.${this.gateway_id}_form`);
                if (0 === eps_form.length) {
                    return;
                }
                eps_form.show();
                let selector = `.${this.gateway_id}_form .${this.gateway_id}_select`;

                // Only mount if element exists and selector is available
                if (this.eps && $(selector).length > 0) {
                    this.eps.mount(selector);
                    $(selector).css({backgroundColor: '#fff'});
                }
            } catch (e) {
                console.log('Error in EPS mountGateway:', e);
            }
        }

        processingSubmit() {
            this.showError('');
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


        confirmStripePayment(clientSecret, redirectURL) {
            if (this.gateway_id === this.selectedGateway()) {
                this.elements.submit().then(() => {
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
                });
            }
        }
    }

    class FKWCS_TWINT extends LocalGateway {
        constructor(stripe, gateway_id) {
            super(stripe, gateway_id);
            this.error_container = '.fkwcs_stripe_twint_error';
            this.gateway_initialized = false;
        }

        setupGateway() {
            if (typeof fkwcs_data.fkwcs_payment_data_twint === 'undefined') {
                return;
            }
            let amount_data = this.getAmountCurrency();
            let paymentData = fkwcs_data.fkwcs_payment_data_twint || {};
            let fromPhp = paymentData.element_data || {};
            this.elements = this.stripe.elements(Object.assign({}, fromPhp, {
                mode: 'payment',
                currency: amount_data.currency.toLowerCase(),
                amount: amount_data.amount,
                payment_method_types: ['twint']
            }));

            this.element_options = {
                fields: {
                    billingDetails: {
                        name: $("#billing_first_name").length ? "never" : "auto",
                    }
                },
                defaultValues: {
                    billingDetails: {
                        name: $("#billing_first_name").length ? $("#billing_first_name").val() + " " + $("#billing_last_name").val() : '',
                    }
                }
            };

            if (paymentData.element_options) {
                this.element_options = {
                    ...this.element_options,
                    ...paymentData.element_options
                };
            }
            this.twint = this.elements.create('payment', this.element_options);
            this.element = this.twint;
            this.gateway_initialized = true;
        }

        reinitialize_gateway() {
            try {
                if (!this.gateway_initialized && typeof fkwcs_data.fkwcs_payment_data_twint !== 'undefined') {
                    this.setupGateway();
                    if (this.gateway_id === this.selectedGateway()) {
                        this.mountGateway();
                    }
                }
            } catch (e) {
                console.log('Error reinitializing TWINT gateway:', e);
            }
        }

        setGateway() {
            if (this.twint) {
                this.twint.unmount();
            }
            this.mountGateway();
        }

        mountGateway() {
            let twint_form = $(`.${this.gateway_id}_form`);
            if (0 === twint_form.length || !this.twint) {
                return;
            }
            twint_form.show();
            let selector = `.${this.gateway_id}_form .${this.gateway_id}_select`;
            if (!$(selector).length) {
                return;
            }
            if (0 === $(selector).children().length) {
                this.twint.mount(selector);
            }
            $(selector).css({backgroundColor: '#fff'});
        }

        processingSubmit(_e) {
            this.showError('');
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

        confirmStripePayment(clientSecret, redirectURL, intent_type, _authenticationAlready = false, _order_id = false) {
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

    function fkwcsRegisterStripeHashChangeListener() {
        if (window.fkwcsStripeHashListenerRegistered) {
            return;
        }
        window.fkwcsStripeHashListenerRegistered = true;
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

    function init_gateways() {

        if (window.fkwcsStripeGatewaysInitDone) {
            return;
        }

        const pubKey = fkwcs_data.pub_key;
        const mode = fkwcs_data.mode;
        if ('' === pubKey || ('live' === mode && !fkwcs_data.is_ssl)) {
            console.log('Live Payment Mode only work only https protocol ');
            return;
        }
        try {
            const stripe = window.fkwcsGetStripe(pubKey, {locale: fkwcs_data.locale});
			if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe === 'yes') {
                available_gateways.card = new FKWCS_Stripe(stripe, 'fkwcs_stripe');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_p24 === 'yes') {
                available_gateways.p24 = new FKWCS_P24(stripe, 'fkwcs_stripe_p24');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_ach === 'yes') {
                available_gateways.us_bank_account = new FKWCS_ach(stripe, 'fkwcs_stripe_ach');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_sepa === 'yes') {
                available_gateways.sepa_debit = new FKWCS_Sepa(stripe, 'fkwcs_stripe_sepa');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_ideal === 'yes') {
                available_gateways.ideal = new FKWCS_Ideal(stripe, 'fkwcs_stripe_ideal');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_pix === 'yes') {
                available_gateways.pix = new FKWCS_PIX(stripe, 'fkwcs_stripe_pix');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_bancontact === 'yes') {
                available_gateways.bancontact = new FKWCS_BanContact(stripe, 'fkwcs_stripe_bancontact');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_multibanco === 'yes') {
                available_gateways.multibanco = new FKWCS_Multibanco(stripe, 'fkwcs_stripe_multibanco');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_eps === 'yes') {
                available_gateways.eps = new FKWCS_EPS(stripe, 'fkwcs_stripe_eps');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_affirm === 'yes') {
                available_gateways.affirm = new FKWCS_AFFIRM(stripe, 'fkwcs_stripe_affirm');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_klarna === 'yes') {
                available_gateways.klarna = new FKWCS_KLARNA(stripe, 'fkwcs_stripe_klarna');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_afterpay === 'yes') {
                available_gateways.afterpay = new FKWCS_AFTERPAY(stripe, 'fkwcs_stripe_afterpay');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_mobilepay === 'yes') {
                available_gateways.mobilepay = new FKWCS_MOBILEPAY(stripe, 'fkwcs_stripe_mobilepay');
            }
            if (fkwcs_data.enable_gateways?.fkwcs_stripe_mbway === 'yes') {
                available_gateways.mbway = new FKWCS_MBWAY(stripe, 'fkwcs_stripe_mbway');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_cashapp === 'yes') {
                available_gateways.cashapp = new FKWCS_CASHAPP(stripe, 'fkwcs_stripe_cashapp');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_google_pay === 'yes') {
                available_gateways.google_pay = new FKWCS_GOOGLEPAY(stripe, 'fkwcs_stripe_google_pay');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_apple_pay === 'yes' && typeof fkwcs_data.apple_pay_positions !== 'undefined' && $.inArray('show_as_regular', fkwcs_data.apple_pay_positions) !== -1) {
                available_gateways.apple_pay = new FKWCS_ApplePay(stripe, 'fkwcs_stripe_apple_pay');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_amazon_pay === 'yes' && typeof fkwcs_data.amazon_pay_positions !== 'undefined' && $.inArray('show_as_regular', fkwcs_data.amazon_pay_positions) !== -1) {
                available_gateways.amazon_pay = new FKWCS_AmazonPay(stripe, 'fkwcs_stripe_amazon_pay');
            }
            if (fkwcs_data.enable_gateways && fkwcs_data.enable_gateways.fkwcs_stripe_alipay === 'yes') {
                available_gateways.alipay = new FKWCS_AliPay(stripe, 'fkwcs_stripe_alipay');
            }
            if (fkwcs_data.enable_gateways?.fkwcs_stripe_twint === 'yes') {
                available_gateways.twint = new FKWCS_TWINT(stripe, 'fkwcs_stripe_twint');
            }
            if (fkwcs_data.enable_gateways?.fkwcs_stripe_blik === 'yes') {
                available_gateways.blik = new FKWCS_BLIK(stripe, 'fkwcs_stripe_blik');
            }
            $(document).trigger('fkwcs_gateway_loaded', {
                "Gateway": Gateway,
                "LocalGateway": LocalGateway,
                "FKWCS_Stripe": FKWCS_Stripe,
                "FKWCS_P24": FKWCS_P24,
                "FKWCS_ach": FKWCS_ach,
                "FKWCS_Sepa": FKWCS_Sepa,
                "FKWCS_Ideal": FKWCS_Ideal,
                "FKWCS_BanContact": FKWCS_BanContact,
                "FKWCS_Multibanco": FKWCS_Multibanco,
                "FKWCS_EPS": FKWCS_EPS,
                "FKWCS_AFFIRM": FKWCS_AFFIRM,
                "FKWCS_KLARNA": FKWCS_KLARNA,
                "FKWCS_AFTERPAY": FKWCS_AFTERPAY,
                "FKWCS_MOBILEPAY": FKWCS_MOBILEPAY,
                "FKWCS_MBWAY": FKWCS_MBWAY,
                "FKWCS_PIX": FKWCS_PIX,
                "FKWCS_CASHAPP": FKWCS_CASHAPP,
                "FKWCS_TWINT": FKWCS_TWINT,
                "FKWCS_BLIK": FKWCS_BLIK,
                'stripe_object': stripe
            });
            window.fkwcsStripeGatewaysInitDone = true;
            fkwcsSdkRecovery.attempt = 0;
            fkwcsSdkRecovery.loggedFinalFailure = false;
            fkwcsRemountActiveStripeGateway();
        } catch (e) {
            if (fkwcsIsStripeUnavailableError(e)) {
                if (fkwcsSdkRecovery.maxAttempts >= 1) {
                    fkwcsQueueStripeSdkRecovery();
                } else {
                    console.log(e);
                }
            } else {
                console.log(e);
            }
        }

    }

    fkwcsRegisterStripeHashChangeListener();

    /**
     * Proactively ensure Stripe.js is available before initializing gateways.
     *
     * Normal loads hit the synchronous fast path (the Stripe global is already present
     * from the header <script>), so there is no added latency in the common case.
     *
     * When a JS optimizer (e.g. WP Rocket "Delay JS execution" / Autoptimize) defers the
     * header SDK <script>, the global is missing at this point. Instead of letting
     * init_gateways() throw and relying on the reactive retry (which is gated behind
     * stripe_sdk_init_recovery.max_attempts and can poll for several seconds), we inject
     * the SDK on its load event. A runtime-injected <script> is not subject to the
     * optimizer's HTML-level delay/defer, so it loads immediately and we initialize once
     * it is ready. Recovery remains a final backstop if even the injection fails.
     */
    if (typeof Stripe === 'function') {
        init_gateways();
    } else {
        fkwcsInjectStripeScript(false).then(function () {
            init_gateways();
        }).catch(function (err) {
            /**
             * Injection itself failed (network error, or Stripe still undefined after
             * load). Hand off to recovery when it is enabled; otherwise log so a blocked
             * or network-failed SDK load is still diagnosable, matching the previous
             * synchronous path that console.log'd when recovery was disabled.
             */
            if (fkwcsSdkRecovery.maxAttempts >= 1) {
                fkwcsQueueStripeSdkRecovery();
            } else {
                console.log(err);
            }
        });
    }
});
