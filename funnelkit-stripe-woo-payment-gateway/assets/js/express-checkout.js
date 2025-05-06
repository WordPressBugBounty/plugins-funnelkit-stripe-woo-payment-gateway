(function ($) {

        class FKWCS_Smart_Buttons {
            constructor() {
                this.css_selector = {};
                this.button_id = 'fkwcs_stripe_smart_button';
                this.payment_request = null;
                this.express_request_type = null;
                this.express_button_wrapper = null;
                this.is_product_page = false;
                this.style_value = fkwcs_data.style;
                this.request_data = {};
                this.cart_request_data = {};
                this.add_to_cart_end_point = 'fkwcs_add_to_cart';
                this.is_google_ready_to_pay = false;//This variable is used to determine if native Google Pay integration is available.;
                /**
                 * Setup data for the product page
                 */
                this.dataCommon = {
                    currency: fkwcs_data.currency,
                    country: fkwcs_data.country_code,
                    requestPayerName: true,
                    requestPayerEmail: true,
                    requestPayerPhone: true
                };
                /**
                 * bail out if stripe public key not configured
                 */
                if ('' === fkwcs_data.pub_key) {
                    return;
                }
                try {
                    this.stripe = Stripe(fkwcs_data.pub_key, {locale: fkwcs_data.locale});
                    this.init();
                } catch (e) {
                    if ('yes' === fkwcs_data.debug_log) {
                        console.log('Stripe Error', e);
                    }
                }
                this.wcEvents();

            }

            init() {

                if ('yes' === fkwcs_data.is_product) {
                    this.request_data = Object.assign(this.dataCommon, {
                        total: fkwcs_data.single_product.total, requestShipping: ('yes' === fkwcs_data.single_product.requestShipping), displayItems: fkwcs_data.single_product.displayItems,
                    });
                } else if ('yes' === fkwcs_data.is_cart) {
                    this.request_data = Object.assign(this.dataCommon, {
                        total: fkwcs_data.cart_data.order_data.total, requestShipping: ('yes' === fkwcs_data.shipping_required), displayItems: fkwcs_data.cart_data.displayItems,

                    });

                }
                this.setupPaymentRequest();

            }


            ajaxEndpoint(action) {
                let url = '';
                if (fkwcs_data.hasOwnProperty('wc_endpoints') && fkwcs_data.wc_endpoints.hasOwnProperty(action)) {
                    url = fkwcs_data.wc_endpoints[action];
                }

                return url;
            }

            setRequestData(data) {
                if (!data.hasOwnProperty('order_data')) {
                    return;
                }

                this.request_data = Object.assign(this.dataCommon, {
                    total: data.order_data.total,
                    currency: data.order_data.currency,
                    country: data.order_data.country_code,
                    requestShipping: ('yes' === data.shipping_required),
                    displayItems: data.order_data.displayItems,
                });

            }

            getRequestData() {
                return this.request_data;
            }

            setupPaymentRequest() {
                let reqData = this.getRequestData();
                if (Object.keys(reqData).length === 0) {
                    return;
                }

                try {
                    let payment_request = this.stripe.paymentRequest(reqData);
                    this.productEvents(payment_request);
                    /**
                     * Bind core events
                     */
                    payment_request.canMakePayment().then(this.makePayment.bind(this)).catch(this.makePaymentCatch.bind(this));
                    payment_request.on('paymentmethod', this.onPaymentMethod.bind(this));
                    payment_request.on('shippingaddresschange', this.shippingAddressChange.bind(this));
                    payment_request.on('shippingoptionchange', this.shippingOptionChange.bind(this));
                    payment_request.on('cancel', this.cancelPayment.bind(this));

                } catch (exc) {
                    console.log(exc);
                }


            }

            productEvents(payment_request) {
                let self = this;

                $('.fkwcs_smart_cart_button').off('click').on('click', function (e) {
                    payment_request.show();
                    e.preventDefault();
                });
                $('body').off('click', '.fkwcs_smart_checkout_button').on('click', '.fkwcs_smart_checkout_button', function (e) {
                    payment_request.show();
                    e.preventDefault();

                });


                let single_add_to_cart_button = $('button.fkwcs_smart_product_button');

                single_add_to_cart_button.off('click').on('click', function (e) {

                    let addToCartBtn = $('form.cart button.single_add_to_cart_button');
                    /**
                     * prevent process if button disabled on single product page
                     */
                    if ($(this).hasClass('fkwcs_disabled_btn')) {
                        if (addToCartBtn.is('.wc-variation-is-unavailable')) {
                            window.alert(wc_add_to_cart_variation_params.i18n_unavailable_text);
                        } else if (addToCartBtn.is('.wc-variation-selection-needed')) {
                            window.alert(wc_add_to_cart_variation_params.i18n_make_a_selection_text);
                        }
                        return;
                    }


                    payment_request.show();
                    e.preventDefault();
                    return $.when(self.addToCartProduct());

                });
                $(document.body).off('show_variation').on('show_variation', function (event, variation, purchasable) {

                    if (purchasable) {
                        single_add_to_cart_button.removeClass('fkwcs_disabled_btn');

                    } else {
                        single_add_to_cart_button.addClass('fkwcs_disabled_btn');

                    }

                });

                $(document.body).off('hide_variation').on('hide_variation', function () {
                    /**
                     * Disable button after variation selection
                     */
                    single_add_to_cart_button.addClass('fkwcs_disabled_btn');
                });

                $(document.body).off('woocommerce_variation_has_changed').on('woocommerce_variation_has_changed', function () {
                    self.updateSelectedProductsData(payment_request);
                });


                $('form.cart .quantity').off('input').on('input', '.qty', function () {
                    self.updateSelectedProductsData(payment_request);
                });
            }

            updateSelectedProductsData(payment_request) {
                $.when(this.prepareSelectedProductData()).then(function (response) {

                    /**
                     * Trigger error here
                     */
                    if (response.error) {
                        self.showErrorMessage(response.error);
                    } else {

                        /**
                         * update the payment request
                         */
                        $.when(payment_request.update({
                            total: response.total, displayItems: response.displayItems,
                        })).then(function () {
                        });
                    }
                });
            }

            /**
             * Create payment request express buttons
             * @param payment_request
             */
            createButton(payment_request) {


            }

            makePayment(result) {
                let smart_buttons = $('.fkwcs_smart_buttons');


                if (!result) {
                    if ('yes' === fkwcs_data.debug_log) {
                        /**
                         * console log the reason of why the payment buttons are not showing up
                         */
                        console.log(fkwcs_data.debug_msg);
                        smart_buttons.hide();
                    }
                    $(document.body).trigger('fkwcs_smart_buttons_not_available');
                    return;
                }


                /**
                 * declare wrapper elements
                 * @type {*|jQuery|HTMLElement}
                 */

                let express_checkout_button_icon = $('.fkwcs_express_checkout_button_icon');
                let smart_button_wrapper = $('.fkwcs_stripe_smart_button_wrapper');
                let smart_request_separator = $('#fkwcs-payment-request-separator');

                let iconUrl = '';
                if (true === this.is_google_ready_to_pay && 'yes' === fkwcs_data.google_pay_as_express && (false === result.applePay || null === result.applePay)) {
                    result.googlePay = false;
                    result.link = false;
                    return;
                }
                if (result.applePay) {
                    this.express_request_type = 'apple_pay';
                    this.express_button_wrapper = 'fkwcs_ec_applepay_button';
                    iconUrl = 'dark' === fkwcs_data.style.theme ? fkwcs_data.icons.applepay_light : fkwcs_data.icons.applepay_gray;

                } else if (result.googlePay) {
                    this.express_request_type = 'google_pay';
                    this.express_button_wrapper = 'fkwcs_ec_googlepay_button';
                    iconUrl = 'dark' === fkwcs_data.style.theme ? fkwcs_data.icons.gpay_light : fkwcs_data.icons.gpay_gray;

                } else {

                    if (this.linkEnabled()) {

                        this.express_request_type = 'payment_request_api';
                        this.express_button_wrapper = 'fkwcs_ec_link_button';
                        iconUrl = fkwcs_data.icons.link;
                    } else {
                        smart_buttons.hide();
                        return;
                    }
                }
                if (!smart_button_wrapper.hasClass('fkwcs_hide_button')) {
                    smart_buttons.show();
                }

                this.makeButtonVisibleInline();

                /* Button Styling */
                this.buttonOnProductPage();
                this.buttonOnCartPage();
                this.buttonOnCheckoutPage();
                express_checkout_button_icon.hide();
                smart_buttons.addClass('fkwcs_express_' + this.express_request_type);
                smart_buttons.removeClass('fkwcs_ec_payment_button' + '-' + fkwcs_data.style.theme);
                smart_buttons.addClass(this.express_button_wrapper + '-' + fkwcs_data.style.theme);

                if ('' !== iconUrl) {
                    express_checkout_button_icon.attr('src', iconUrl);
                    express_checkout_button_icon.show();
                }

                if (smart_request_separator.hasClass('cart')) {
                    smart_request_separator.show();
                }

                if (smart_request_separator.hasClass('checkout')) {
                    smart_request_separator.show();
                }

                if (smart_request_separator.hasClass('fkwcs-product')) {
                    if (!smart_button_wrapper.hasClass('inline')) {
                        smart_request_separator.show();
                    }
                }

                if (smart_button_wrapper.length) {
                    smart_button_wrapper.css("display", "block");
                    $(document.body).trigger('fkwcs_smart_buttons_showed', ['stripe', result]);
                }

                if ('inline' === fkwcs_data.style.button_position && smart_button_wrapper.hasClass('fkwcs-product')) {
                    document.getElementById('fkwcs_stripe_smart_button_wrapper').style.display = 'inline-block';
                }

            }

            /**
             * CB for the payment method selection during express checkout button
             * @param event
             */
            onPaymentMethod(event) {
                let payment_data = this.paymentMethodData(event);


                $.ajax({
                    type: 'POST',
                    data: payment_data,
                    dataType: 'text', // Set to 'text' to handle any extra text around JSON
                    url: this.ajaxEndpoint('wc_stripe_create_order'),
                    success: (responseText) => {
                        const parseJSONFromResponse = (response) => {
                            // Regular expression to find JSON-like content
                            const jsonMatch = response.match(/\{(?:[^{}]|(\{[^{}]*\}))*\}/);

                            if (jsonMatch) {
                                try {
                                    // Attempt to parse the matched JSON
                                    return JSON.parse(jsonMatch[0]);
                                } catch (e) {
                                    console.error("Failed to parse JSON:", e);
                                    return null;
                                }
                            } else {
                                console.warn("No JSON object found in response.");
                                return null;
                            }
                        };

                        // Parse the JSON from response text
                        const response = parseJSONFromResponse(responseText);

                        // Proceed only if valid JSON was parsed
                        if (response && response.result === 'success') {
                            if (false === this.confirmPaymentIntent(event, response.redirect)) {
                                window.location = response.redirect;
                            }
                        } else {
                            this.abortPayment(event, response ? response.messages : "Error processing payment");
                        }
                    }
                });

            }

            addToCartProduct() {
                let productId = $('.single_add_to_cart_button').val();
                let single_var = $('.single_variation_wrap');


                /**
                 * Find product ID if its a variable product
                 */
                if (single_var.length) {
                    productId = single_var.find('input[name="product_id"]').val();
                }

                let qtyProduct = $('.quantity .qty').val();
                const productData = {
                    fkwcs_nonce: fkwcs_data.fkwcs_nonce,
                    action: 'add_to_cart',
                    product_id: productId,
                    qty: qtyProduct,
                    attributes: $('.variations_form').length ? this.getVariationAttributes().attributes : [],
                };

                /**
                 * Iterate over the add to cart forms to handle addons data too during request
                 * @type {*|jQuery}
                 */
                const formCartData = $('form.cart').serializeArray();
                $.each(formCartData, function (i, field) {
                    if (/^addon-/.test(field.name)) {
                        if (/\[\]$/.test(field.name)) {
                            const fieldName = field.name.substring(0, field.name.length - 2);
                            if (productData[fieldName]) {
                                productData[fieldName].push(field.value);
                            } else {
                                productData[fieldName] = [field.value];
                            }
                        } else {
                            productData[field.name] = field.value;
                        }
                    }
                });
                return $.ajax({
                    type: 'POST', data: productData, url: this.ajaxEndpoint(this.add_to_cart_end_point),
                    'success': (response) => {
                        $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $('.single_add_to_cart_button')]);
                    }
                });
            }


            prepareSelectedProductData() {

                let is_variable_product = $('.single_variation_wrap');
                let product_id = $('.single_add_to_cart_button').val();
                if (is_variable_product.length > 0) {
                    product_id = $('.single_variation_wrap').find('input[name="product_id"]').val();
                }

                let product_addons = $('#product-addons-total');
                let addon_price_value = 0;
                if (product_addons.length > 0) {
                    let addons_price_data = product_addons.data('price_data') || [];
                    addon_price_value = addons_price_data.reduce(function (sum, single) {
                        return sum + single.cost;
                    }, 0);
                }

                const data = {
                    fkwcs_nonce: fkwcs_data.fkwcs_nonce,
                    product_id: product_id,
                    qty: $('.quantity .qty').val(),
                    addon_value: addon_price_value,
                    attributes: $('.variations_form').length ? this.getVariationAttributes().attributes : [],

                };

                return $.ajax({
                    type: 'POST', data: data, url: this.ajaxEndpoint('fkwcs_selected_product_data'),
                });
            }


            logError(error, order_id = '', failed = false) {
                $.ajax({
                    type: 'POST', url: fkwcs_data.admin_ajax, data: {
                        "action": 'fkwcs_js_errors', "_security": fkwcs_data.js_nonce, "failed": failed, "error": error
                    }
                });
            }

            getVariationAttributes() {


                let variation_forms = $('.variations_form');
                let select_list = variation_forms.find('.variations select');
                let attributes = {};
                let count = 0, chosen = 0;
                select_list.each(function () {
                    let name = $(this).data('attribute_name') || $(this).attr('name');
                    attributes[name] = $(this).val() || '';
                    count++;
                });
                return {
                    count, chosenCount: chosen, attributes,
                };
            }

            /**
             * Prepare Payment method data to pass onto confirm button
             * @param event
             * @returns {*|{billing_last_name: (*|string), billing_phone: (*|string|string), payment_request_type: null, billing_country: (*|string), billing_city: (*|string), fkwcs_nonce: *, billing_company: string, billing_state: (*|string), terms: number, billing_address_1: (*|string), shipping_method: *[], order_comments: string, billing_email: (*|string), billing_address_2: (*|string), billing_postcode: (*|string), fkwcs_source, billing_first_name: (*|string), payment_method: string}}
             * @constructor
             */
            paymentMethodData(event) {

                /**
                 * Gather Data from the chosen method
                 */
                const paymentMethod = event.paymentMethod;
                const billingDetails = paymentMethod.billing_details;
                const email = billingDetails.email;
                const phone = billingDetails.phone;
                const billing = billingDetails.address;
                const name = billingDetails.name;
                const shipping = event.shippingAddress;
                /**
                 * Prepare Data
                 * @type {{billing_last_name: (*|string), billing_phone: (*|string|string), payment_request_type: null, billing_country: (*|string), billing_city: (*|string), fkwcs_nonce: *, billing_company: string, billing_state: (*|string), terms: number, billing_address_1: (*|string), shipping_method: *[], order_comments: string, billing_email: (*|string), billing_address_2: (*|string), billing_postcode: (*|string), fkwcs_source, billing_first_name: (*|string), payment_method: string}}
                 */
                let data = {
                    fkwcs_nonce: fkwcs_data.fkwcs_nonce,
                    billing_first_name: null !== name ? name.split(' ').slice(0, 1).join(' ') : 'test',
                    billing_last_name: null !== name ? name.split(' ').slice(1).join(' ') : 'test',
                    billing_company: '',
                    billing_email: null !== email ? email : event.payerEmail,
                    billing_phone: null !== phone ? phone : event.payerPhone && event.payerPhone.replace('/[() -]/g', ''),
                    order_comments: '',
                    payment_method: 'fkwcs_stripe',
                    terms: 1,
                    fkwcs_source: paymentMethod.id,
                    payment_request_type: this.express_request_type,
                };


                if ($('input[name="billing_email"]').length > 0 && $('input[name="billing_email"]').val() !== '') {
                    data.billing_email = $('input[name="billing_email"]').val();
                }

                /**
                 * Handling a case where the payment method is Apple Pay and the payment method Apple Pay is showing on the checkout page
                 * In this case we need to set the payment method to Apple Pay & do not process using CC method
                 */
                if (this.express_request_type === 'apple_pay' && $('li.payment_method_fkwcs_stripe_apple_pay').length > 0) {
                    data.payment_method = 'fkwcs_stripe_apple_pay';
                }

                /**
                 * Prepare billing address
                 * @type {*}
                 */
                data = this.prepareBillingAddress(data, billing);

                /**
                 * Prepare Shipping address
                 * @type {*}
                 */
                data = this.prepareShippingAddress(data, shipping);

                /**
                 * If its a checkout page from where the request is getting formed, then loop over form data to combine data
                 */
                if (fkwcs_data.is_checkout === 'yes') {


                    /**
                     * Here the shippingoption that we get in return from payment request button is the prior one, so we need to set it checked
                     */
                    if (null !== event.shippingOption) {
                        $('input[name="shipping_method[0]"][value="' + event.shippingOption.id + '"]').prop('checked', true);

                    }

                    let formData = $("form[name=checkout]").serializeArray();
                    $.each(formData, function (i, field) {
                        if (false === Object.prototype.hasOwnProperty.call(data, field.name) || '' === data[field.name]) {
                            data[field.name] = field.value;
                        }
                    });
                    data.page_from = 'checkout';
                } else if (fkwcs_data.is_product === 'yes') {
                    data.page_from = 'product';
                } else {
                    data.page_from = 'cart';
                }

                /**
                 * We need to unset the payment token so that payment could be treated as new payment method
                 */
                if (true === Object.prototype.hasOwnProperty.call(data, 'wc-fkwcs_stripe-payment-token')) {
                    delete data['wc-fkwcs_stripe-payment-token'];
                }

                data = JSON.parse(JSON.stringify(data));
                return data;
            }

            /**
             * Prepare Billing Address data using data return by stripe buttons
             * @param address_data
             * @param billing
             * @returns {*}
             */
            prepareBillingAddress(address_data, billing) {
                if (null === billing) {
                    return address_data;
                }


                address_data.billing_address_1 = null !== billing ? billing.line1 : '';
                address_data.billing_address_2 = null !== billing ? billing.line2 : '';
                address_data.billing_city = null !== billing ? billing.city : '';
                address_data.billing_state = null !== billing ? billing.state : '';
                address_data.billing_postcode = null !== billing ? billing.postal_code : '';
                address_data.billing_country = null !== billing ? billing.country : '';

                return address_data;
            }

            /**
             * Prepare Shipping Address data using data return by stripe buttons
             * @param address_data
             * @param shipping_data
             * @returns {*}
             */
            prepareShippingAddress(address_data, shipping_data) {
                if (shipping_data) {
                    address_data.shipping_first_name = shipping_data.recipient.split(' ').slice(0, 1).join(' ');
                    address_data.shipping_last_name = shipping_data.recipient.split(' ').slice(1).join(' ');
                    address_data.shipping_company = shipping_data.organization;
                    address_data.shipping_country = shipping_data.country;
                    address_data.shipping_address_1 = typeof shipping_data.addressLine[0] === 'undefined' ? '' : shipping_data.addressLine[0];
                    address_data.shipping_address_2 = typeof shipping_data.addressLine[1] === 'undefined' ? '' : shipping_data.addressLine[1];
                    address_data.shipping_city = shipping_data.city;
                    address_data.shipping_state = shipping_data.region;
                    if (address_data.hasOwnProperty('billing_phone')) {
                        address_data.shipping_phone = address_data.billing_phone;
                    }
                    address_data.shipping_postcode = shipping_data.postalCode;
                    address_data.ship_to_different_address = 1;
                }
                return address_data;
            }

            /**
             * Cb to handle response from the AJAX request on payment method
             * @param event
             * @param hash
             */
            confirmPaymentIntent(event, hash) {
                let hashpartials = hash.match(/^#?fkwcs-confirm-(pi|si)-([^:]+):(.+):(.+):(.+):(.+)$/);
                if (!hashpartials || 5 > hashpartials.length) {
                    window.location.redirect = hash;
                    return false;
                }
                let type = hashpartials[1];
                let intentClientSec = hashpartials[2];
                let redirectURI = decodeURIComponent(hashpartials[3]);
                this.confirmPayment(event, intentClientSec, redirectURI, type);
            }

            /**
             * Attempt to confirm the payment intent using Stripe methods
             * @param event
             * @param clientSecret
             * @param redirectURL
             * @param intent_type
             */
            confirmPayment(event, clientSecret, redirectURL, intent_type) {


                let cardPayment = null;
                if (intent_type === 'si') {
                    cardPayment = this.stripe.handleCardSetup(clientSecret, {payment_method: event.paymentMethod.id}, {handleActions: false});
                } else {
                    cardPayment = this.stripe.confirmCardPayment(clientSecret, {payment_method: event.paymentMethod.id}, {handleActions: false});
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
                        event.complete('fail');
                    } else {
                        event.complete('success');
                        let intent = result[('si' === intent_type) ? 'setupIntent' : 'paymentIntent'];
                        if (intent.status === "requires_action" || intent.status === "requires_source_action") {

                            let instance = this;
                            let cardPaymentRetry = null;
                            // Let Stripe.js handle the rest of the payment flow.
                            if (intent_type === 'si') {
                                cardPaymentRetry = this.stripe.handleCardSetup(clientSecret);

                            } else {
                                cardPaymentRetry = this.stripe.confirmCardPayment(clientSecret);

                            }
                            cardPaymentRetry.then((result) => {
                                if (result.error) {
                                    instance.logError(result.error);
                                    $('.woocommerce-error').remove();
                                    FormEl.unblock();
                                    $('.woocommerce-notices-wrapper:first-child').html('<div class="woocommerce-error fkwcs-errors">' + result.error.message + '</div>').show();
                                } else {
                                    FormEl.addClass('processing');
                                    FormEl.block({
                                        message: null, overlayCSS: {
                                            background: '#fff', opacity: 0.6
                                        }
                                    });
                                    window.location = redirectURL;
                                }

                            });

                        } else {
                            FormEl.addClass('processing');
                            FormEl.block({
                                message: null, overlayCSS: {
                                    background: '#fff', opacity: 0.6
                                }
                            });
                            window.location = redirectURL;
                        }


                    }


                });
            }

            abortPayment(event, message) {
                event.complete('success');// close gpay window and display Error Notices on checkout page.
                this.showErrorMessage(message);
            }

            /**
             * Shipping address selection change, responsible for new shipping methods
             * @param event
             * @returns {*}
             */
            shippingAddressChange(event) {
                let address = event.shippingAddress;
                let data = {
                    fkwcs_nonce: fkwcs_data.fkwcs_nonce,
                    country: address.country,
                    state: address.region,
                    postcode: address.postalCode,
                    city: address.city,
                    address: typeof address.addressLine[0] === 'undefined' ? '' : address.addressLine[0],
                    address_2: typeof address.addressLine[1] === 'undefined' ? '' : address.addressLine[1],
                    payment_request_type: this.express_request_type,
                    is_product_page: this.is_product_page,
                };

                return $.ajax({
                    type: 'POST',
                    data: data,
                    url: this.ajaxEndpoint('fkwcs_update_shipping_address'),
                    success: (response) => {
                        if ('success' === response.result) {
                            /**
                             * return back to String FW to show current items along with the status
                             */
                            event.updateWith({
                                status: response.result, total: response.total, shippingOptions: response.shipping_methods, displayItems: response.displayItems
                            });
                            return;
                        }
                        if ('fail' === response.result) {
                            event.updateWith({status: 'fail'});
                        }
                    }
                });
            }

            shippingOptionChange(event) {
                let shippingOption = event.shippingOption;
                const data = {
                    fkwcs_nonce: fkwcs_data.fkwcs_nonce,
                    shipping_method: [shippingOption.id],
                    payment_request_type: this.express_request_type,
                    is_product_page: this.is_product_page,
                };

                return $.ajax({
                    type: 'POST',
                    data: data,
                    url: this.ajaxEndpoint('fkwcs_update_shipping_option'),
                    success: (response) => {
                        if ('success' === response.result) {
                            event.updateWith({
                                status: 'success', total: response.total, displayItems: response.displayItems
                            });
                        }
                        if ('fail' === response.result) {
                            event.updateWith({status: 'fail'});
                        }
                    }
                });
            }

            /**
             * console log error while any error occurred
             * @param error
             */
            makePaymentCatch(error) {
                console.log('error', error);
            }

            cancelPayment() {
                $(document.body).trigger('fkwcs_express_cancel_payment', this);

            }


            /**
             * Controller for error messages behaviour on multiple environment
             * @param message
             */
            showErrorMessage(message) {
                $('.woocommerce-error').remove();

                if ('no' !== fkwcs_data.is_product) {
                    let element = $('.product').first();
                    element.before(message);
                    window.scrollTo({top: 100, behavior: 'smooth'});
                } else {
                    let $form = $('form.checkout').closest('form');
                    $form.before(message);
                    window.scrollTo({top: 100, behavior: 'smooth'});
                }
            }

            getCartDetails() {
                let data = {
                    fkwcs_nonce: fkwcs_data.fkwcs_nonce,
                };
                let current = this;

                $.ajax({
                    type: 'POST', data: data, url: this.ajaxEndpoint('fkwcs_get_cart_details'), success: (response) => {
                        if (response.success) {
                            /**
                             * return back to String FW to show current items along with the status
                             */
                            current.setRequestData(response.data);
                            current.setupPaymentRequest();
                        }
                    }
                });
            }

            /**
             * WooCommerce events to modify data onto
             */
            wcEvents() {
                let self = this;
                $(document.body).on('updated_checkout', function (e, v) {
                    try {
                        if (v && v.fragments) {
                            self.setRequestData(v.fragments.fkwcs_cart_details);
                            self.setupPaymentRequest();
                            if (false === v.fragments.fkwcs_cart_details.is_fkwcs_need_payment) {
                                document.getElementById('fkwcs_stripe_smart_button_wrapper').classList.add("fkwcs_hide_button");
                            } else {
                                document.getElementById('fkwcs_stripe_smart_button_wrapper').classList.remove("fkwcs_hide_button");
                            }
                        }
                    } catch (err) {

                    }
                });

                $(document.body).on('updated_cart_totals', function () {
                    self.getCartDetails();
                });


                $(document.body).on('wc_fragments_refreshed added_to_cart removed_from_cart wc_fragments_loaded', (e, v) => {
                    setTimeout(() => {
                        if (typeof wc_cart_fragments_params === 'undefined') {
                            return false;
                        }
                        if (typeof (Storage) !== "undefined") {
                            let json = sessionStorage.getItem(wc_cart_fragments_params.fragment_name);
                            json = JSON.parse(json);
                            if (typeof json !== "object") {
                                return;
                            }
                            this.cart_request_data = json.fkwcs_cart_details;
                            if ('yes' === fkwcs_data.is_product) {
                                return;
                            }

                            self.setRequestData(json.fkwcs_cart_details);
                            self.setupPaymentRequest();

                        }
                    }, 300);
                });


                /**
                 * FK Cart events added here to handle buttons and their data
                 */
                $(document.body).on('fkwcs_express_button_init', () => {
                    const fkcartSliderModal = $('#fkcart-modal');

                    if ('yes' === fkwcs_data.is_product && !fkcartSliderModal.hasClass('fkcart-show')) {

                        return;
                    }

                    if (Object.keys(this.cart_request_data).length > 0) {
                        this.setRequestData(this.cart_request_data);
                    }
                    this.setupPaymentRequest();
                });


                $(document.body).on('fkwcs_express_button_update_cart_details', (e, v) => {
                    //return if cart details not found
                    if (!v.hasOwnProperty('fkwcs_cart_details')) {
                        return;
                    }
                    this.setRequestData(v.fkwcs_cart_details);
                    this.setupPaymentRequest();
                });

                $(document.body).on('fkcart_fragments_refreshed', (e, v) => {
                    const fkcartSliderModal = $('#fkcart-modal');
                    //return if cart details not found
                    if (typeof v === "undefined" || !v.hasOwnProperty('fkwcs_cart_details')) {
                        return;
                    }
                    this.cart_request_data = v.fkwcs_cart_details;
                    if (fkcartSliderModal.hasClass('fkcart-show')) {
                        this.setRequestData(this.cart_request_data);
                        this.setupPaymentRequest();
                    }

                });
                $(document.body).on('fkwcs_google_ready_pay', function () {
                    self.is_google_ready_to_pay = true;
                });

                $(document.body).on('fkcart_cart_closed', () => {
                    if ('yes' !== fkwcs_data.is_product) {
                        return;
                    }
                    let qtyField = $('form.cart').find('.qty');
                    if (qtyField.length) {
                        qtyField.val(1);
                    }
                    this.request_data = Object.assign(this.dataCommon, {
                        total: fkwcs_data.single_product.total, requestShipping: ('yes' === fkwcs_data.single_product.requestShipping), displayItems: fkwcs_data.single_product.displayItems,
                    });
                    this.setupPaymentRequest();
                });

            }

            /**
             * Set Css Property
             * @param selector
             * @param property
             * @param value
             */
            setCss(selector, property, value) {
                if (!this.css_selector.hasOwnProperty(selector)) {
                    this.css_selector[selector] = {};
                }
                this.css_selector[selector][property] = value;
            }

            /**
             * Apply css using css selector object
             */
            applyCss() {
                for (let selector in this.css_selector) {
                    if (Object.keys(this.css_selector[selector]).length === 0) {
                        continue;
                    }
                    for (let property in this.css_selector[selector]) {
                        $(selector).css(property, this.css_selector[selector][property]);
                    }
                }
            }

            /**
             * Controller button to control CSS of the button on single product page
             */
            buttonOnProductPage() {

                /**
                 * bail out if not the product page
                 */
                if (fkwcs_data.is_product_page != 1 || $('form.cart button.single_add_to_cart_button').length === 0) {
                    return;
                }

                let ADCbutton = $('form.cart button.single_add_to_cart_button:visible');
                let Expressbtn = $('.fkwcs_smart_buttons');
                let width = this.style_value.button_length > 10 ? 'min-width' : 'width';
                let addToCartMinWidthType = 'px';

                if (false === Expressbtn.hasClass('fkwcs_smart_product_button')) {
                    return;
                }
                if ('above' === this.style_value.button_position) {
                    this.setCss('#fkwcs_stripe_smart_button_wrapper', 'width', '100%');
                    this.setCss('#fkwcs-payment-request-separator', 'width', '200px');
                    this.setCss('form.cart button.single_add_to_cart_button', width, '200px');
                    this.setCss('form.cart button.single_add_to_cart_button', 'float', 'left');
                    this.setCss('form.cart', 'display', 'inline-block');
                    this.setCss('.fkwcs_smart_buttons', width, '200px');
                } else {
                    let addToCartMinWidth = ADCbutton.outerWidth();

                    if ($('form.cart .quantity').length > 0) {
                        addToCartMinWidth = addToCartMinWidth + $('form.cart .quantity').width() + parseInt($('form.cart .quantity').css('marginRight').replace('px', ''));
                    }

                    if ('inline' === this.style_value.button_position) {
                        addToCartMinWidth = ADCbutton.outerWidth();
                        addToCartMinWidth = addToCartMinWidth < 120 ? 150 : addToCartMinWidth;

                        if ($('form.cart').width() < 500) {
                            this.makeButtonVisibleInline();
                        }
                        this.setCss('form.cart button.single_add_to_cart_button', width, addToCartMinWidth + 'px');
                    } else {
                        this.setCss('form.grouped_form button.single_add_to_cart_button', width, addToCartMinWidth + 'px');

                        /**
                         * Compatibility with Theme Kadence button
                         * @type {*|jQuery|HTMLElement}
                         */
                        let KDButton = $('.theme-kadence button.single_add_to_cart_button');
                        if (KDButton.length > 0) {
                            addToCartMinWidth = 100;
                            addToCartMinWidthType = '%';
                            this.setCss('.theme-kadence button.single_add_to_cart_button', width, addToCartMinWidth + addToCartMinWidthType);
                            this.setCss('.theme-kadence button.single_add_to_cart_button', 'margin-top', '20px');
                        }
                    }

                    this.setCss('#fkwcs_stripe_smart_button_wrapper', width, addToCartMinWidth + addToCartMinWidthType);
                    this.setCss('form.cart .fkwcs_smart_buttons', width, addToCartMinWidth + addToCartMinWidthType);
                    if ('below' === this.style_value.button_position) {
                        this.setCss('.theme-twentytwentytwo .fkwcs_smart_buttons', width, ADCbutton.outerWidth() + 'px');
                        this.setCss('.theme-twentytwentytwo #fkwcs-payment-request-separator', width, ADCbutton.outerWidth() + 'px');
                    }
                }
                this.applyCss();
                this.expressBtnStyle(Expressbtn, ADCbutton);

            }

            buttonOnCartPage() {
                if (fkwcs_data.is_cart !== "yes" || $('.wc-proceed-to-checkout .checkout-button').length === 0) {
                    return;
                }
                const BtnCart = $('.wc-proceed-to-checkout .checkout-button');
                const fkwcsExpressCheckoutButton = $('.fkwcs_smart_buttons');
                if ($('.place-order #place_order').outerHeight() > 30) {
                    this.setCss('.fkwcs_smart_buttons', 'height', BtnCart.outerHeight() + 'px');
                }
                this.setCss('.fkwcs_smart_buttons', 'font-size', BtnCart.css('font-size'));
                this.applyCss();
                this.expressBtnStyle(fkwcsExpressCheckoutButton, BtnCart);

            }

            buttonOnCheckoutPage() {
                let billing_fields = $('.woocommerce-billing-fields');
                if (fkwcs_data.is_checkout !== "yes" || billing_fields.length === 0) {
                    return;
                }
                this.setCss('#fkwcs_stripe_smart_button_wrapper', 'max-width', billing_fields.outerWidth(true));
                this.applyCss();
            }


            /**
             * Dynamic CSS for the express checkout button
             * @param smart_button
             * @param wc_button_class
             */
            expressBtnStyle(smart_button, wc_button_class) {

                let style_props = ['padding', 'border-radius', 'box-shadow', 'font-weight', 'text-shadow', 'font-size', 'line-height', 'padding'];
                $.each(style_props, function (k, v) {
                    smart_button.css(v, wc_button_class.css(v));
                });
                smart_button.css('max-height', wc_button_class.outerHeight() + 'px');
            }


            /**
             * Controller method to show button inline
             */
            makeButtonVisibleInline() {

                let addToCartButtonHeight = '';
                let availableWidth = '';
                let wrapper = $('#fkwcs_stripe_smart_button_wrapper');
                if (wrapper.length === 0) {
                    return;
                }
                if (wrapper.hasClass('inline')) {
                    let productWrapper = wrapper.parent();
                    let addToCartButtonElem = productWrapper.children('.single_add_to_cart_button');
                    let quantitySelector = productWrapper.children('.quantity');
                    let totalWidth = productWrapper.outerWidth();
                    let addToCartButtonWidth = addToCartButtonElem.outerWidth();
                    let quantityElemWidth = quantitySelector.outerWidth();
                    availableWidth = totalWidth - (addToCartButtonWidth + quantityElemWidth + 10);
                    this.setCss('#fkwcs_stripe_smart_button_wrapper .single_add_to_cart_button', 'marginRight', quantitySelector.css('marginRight'));

                    if (availableWidth > addToCartButtonWidth) {
                        this.setCss('#fkwcs_stripe_smart_button_wrapper .single_add_to_cart_button', 'margin', 0);
                        this.setCss('#fkwcs_stripe_smart_button_wrapper .single_add_to_cart_button', 'marginRight', quantitySelector.css('marginRight'));
                        this.setCss('#fkwcs_stripe_smart_button_wrapper .single_add_to_cart_button', 'clear', 'unset');
                        this.setCss('#fkwcs_stripe_smart_button_wrapper', 'margin', '0px');
                        this.setCss('#fkwcs_stripe_smart_button_wrapper', 'display', 'inline-block');
                    } else {
                        this.setCss('#fkwcs_stripe_smart_button_wrapper .single_add_to_cart_button', 'margin', '10px 0');
                        this.setCss('#fkwcs_stripe_smart_button_wrapper .single_add_to_cart_button', 'flex', 'initial');
                        this.setCss('#fkwcs_stripe_smart_button_wrapper .single_add_to_cart_button', 'clear', 'both');
                        this.setCss('#fkwcs_stripe_smart_button_wrapper .theme-flatsome .cart .quantity', 'width', '100%');
                        this.setCss('#fkwcs_stripe_smart_button_wrapper .theme-flatsome .cart .quantity', 'clear', 'both');
                        this.setCss('#fkwcs_stripe_smart_button_wrapper', 'maringTop', '10px');
                        this.setCss('#fkwcs_stripe_smart_button_wrapper', 'display', 'block');
                    }

                    addToCartButtonHeight = addToCartButtonElem.outerHeight();
                    if (addToCartButtonHeight > 60) {
                        this.setCss('#fkwcs_stripe_smart_button_wrapper .single_add_to_cart_button', 'height', '60');
                    }
                    if (addToCartButtonHeight < 35) {
                        this.setCss('#fkwcs_stripe_smart_button_wrapper .single_add_to_cart_button', 'height', '35');
                    }

                    $('#fkwcs_stripe_smart_button_wrapper').width(addToCartButtonWidth);
                }
                this.applyCss();
            }


            linkEnabled() {
                return 'yes' === fkwcs_data.link_button_enabled && 'yes' === fkwcs_data.express_pay_enabled;
            }


        }

        class FKWCS_Google_pay extends FKWCS_Smart_Buttons {
            constructor() {
                super();
            }

            init() {

                if (!fkwcs_data.hasOwnProperty('google_pay_as_express') || 'yes' !== fkwcs_data.google_pay_as_express) {
                    return;
                }

                this.google_pay_client = null;
                this.shipping_options = [];
                this.request_data = {};
                this.google_is_ready = null;
                this.add_to_cart_end_point = 'fkwcs_gpay_add_to_cart';
                this.gateway_id = 'fkwcs_stripe_google_pay';
                this.createPaymentClient();
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

            /**
             * Create GooGle Pay Button with custom wrapper
             * @param callback
             */
            createGooglePayButton(callback, identifier = '') {
                return $(`<div class='fkwcs_google_pay_wrapper fkwcs_smart_product_button ${identifier}'></div>`).html(this.google_pay_client.createButton(callback));
            }

            createPaymentClient() {
                try {
                    this.google_pay_client = new google.payments.api.PaymentsClient(this.getMerchantData());
                    let request_data = this.googlePayVersion();
                    request_data.allowedPaymentMethods = [this.getBaseCardBrand()];
                    this.google_pay_client.isReadyToPay(request_data).then(() => {
                        this.google_is_ready = true;
                        $(document.body).trigger('fkwcs_google_ready_pay', [this.google_pay_client]);
                        this.createCheckoutExpressBtn();
                    }).catch((err) => {
                        console.log('error', err);
                    });


                } catch (e) {
                    console.log(e);
                }
            }


            buttonOnProductPage() {

                /**
                 * bail out if not the product page
                 */
                if (fkwcs_data.is_product_page != 1 || $('form.cart button.single_add_to_cart_button').length === 0) {
                    return;
                }

                let ADCbutton = $('form.cart button.single_add_to_cart_button:visible');
                let Expressbtn = $('.fkwcs_smart_buttons');
                let width = this.style_value.button_length > 10 ? 'min-width' : 'width';
                let addToCartMinWidthType = 'px';

                if (false === Expressbtn.hasClass('fkwcs_smart_product_button')) {

                    return;
                }
                if ('above' === this.style_value.button_position) {
                    this.setCss('#fkwcs_stripe_smart_button_wrapper', 'width', '100%');
                    this.setCss('#fkwcs-payment-request-separator', 'width', '200px');
                    this.setCss('form.cart button.single_add_to_cart_button', width, '200px');
                    this.setCss('form.cart button.single_add_to_cart_button', 'float', 'left');
                    this.setCss('form.cart', 'display', 'inline-block');
                    this.setCss('.fkwcs_smart_buttons', width, '200px');
                } else {
                    let addToCartMinWidth = ADCbutton.outerWidth();

                    if ($('form.cart .quantity').length > 0) {
                        addToCartMinWidth = addToCartMinWidth + $('form.cart .quantity').width() + parseInt($('form.cart .quantity').css('marginRight').replace('px', ''));
                    }

                    if ('inline' === this.style_value.button_position) {
                        addToCartMinWidth = ADCbutton.outerWidth();
                        addToCartMinWidth = addToCartMinWidth < 120 ? 150 : addToCartMinWidth;

                        if ($('form.cart').width() < 500) {
                            this.makeButtonVisibleInline();
                        }
                        this.setCss('form.cart button.single_add_to_cart_button', width, addToCartMinWidth + 'px');
                        this.setCss('#fkwcs_stripe_smart_button_wrapper.fkwcs-product', 'display', 'inline-block');
                    } else {
                        this.setCss('form.grouped_form button.single_add_to_cart_button', width, addToCartMinWidth + 'px');

                        /**
                         * Compatibility with Theme Kadence button
                         * @type {*|jQuery|HTMLElement}
                         */
                        let KDButton = $('.theme-kadence button.single_add_to_cart_button');
                        if (KDButton.length > 0) {
                            addToCartMinWidth = 100;
                            addToCartMinWidthType = '%';
                            this.setCss('.theme-kadence button.single_add_to_cart_button', width, addToCartMinWidth + addToCartMinWidthType);
                            this.setCss('.theme-kadence button.single_add_to_cart_button', 'margin-top', '20px');
                        }
                    }

                    this.setCss('#fkwcs_stripe_smart_button_wrapper', width, addToCartMinWidth + addToCartMinWidthType);
                    this.setCss('form.cart .fkwcs_smart_buttons', width, addToCartMinWidth + addToCartMinWidthType);

                    setTimeout((addToCartMinWidth) => {
                        this.setCss('#fkwcs_stripe_smart_button_wrapper .gpay-card-info-container', width, addToCartMinWidth + 'px');
                        this.setCss('#fkwcs_stripe_smart_button_wrapper .gpay-card-info-container', 'min-width', addToCartMinWidth + 'px');
                        this.setCss('#fkwcs_stripe_smart_button_wrapper .gpay-card-info-iframe', 'width', addToCartMinWidth + 'px');
                        this.applyCss();
                    }, 300, addToCartMinWidth);

                    if ('below' === this.style_value.button_position) {
                        this.setCss('.theme-twentytwentytwo .fkwcs_smart_buttons', width, ADCbutton.outerWidth() + 'px');
                        this.setCss('.theme-twentytwentytwo #fkwcs-payment-request-separator', width, ADCbutton.outerWidth() + 'px');
                    }
                }
                this.applyCss();
                this.expressBtnStyle(Expressbtn, ADCbutton);

            }

            createCheckoutExpressBtn() {

                let single_wrapper = $("#fkwcs_stripe_smart_button_wrapper");
                single_wrapper.css("display", "block");
                let smart_button_wrapper = $('#fkwcs_custom_express_button');
                if (smart_button_wrapper.length > 0) {
                    if ('1' === fkwcs_data.is_product_page || 'yes' === fkwcs_data.is_product_page) {
                        smart_button_wrapper.after(this.createGooglePayButton(this.getSingleProductExpressOptions(), 'fkwcs-single-product'));
                        this.buttonOnProductPage();
                        let gpay_wrapper = $('form.cart .fkwcs_google_pay_wrapper');
                        $(document.body).off('show_variation').on('show_variation', function () {
                            gpay_wrapper.removeClass('fkwcs_disabled_btn');
                        });
                        $(document.body).off('hide_variation').on('hide_variation', function () {
                            gpay_wrapper.addClass('fkwcs_disabled_btn');
                        });

                    } else if ('1' === fkwcs_data.is_cart || 'yes' === fkwcs_data.is_cart) {
                        smart_button_wrapper.after(this.createGooglePayButton(this.getCartExpressOptions(), 'fkwcs-woocommerce-cart'));
                        $(document.body).on('wc_fragments_refreshed', (e, v) => {

                            setTimeout(() => {
                                if (!('sessionStorage' in window && window.sessionStorage !== null)) {
                                    return;
                                }
                                let session_data = sessionStorage.getItem(wc_cart_fragments_params.fragment_name);
                                if (null == session_data) {
                                    return;
                                }
                                session_data = JSON.parse(session_data);
                                if (typeof session_data !== "object" || !session_data.hasOwnProperty('fkwcs_google_pay_data')) {
                                    return;
                                }
                                fkwcs_data.gpay_cart_data = session_data.fkwcs_google_pay_data;
                                $("#fkwcs_stripe_smart_button_wrapper").css("display", "block");
                                $('.fkwcs-woocommerce-cart').remove();
                                $('#fkwcs_custom_express_button').after(this.createGooglePayButton(this.getCartExpressOptions(), 'fkwcs-woocommerce-cart'));
                            }, 1000);
                        });


                    }
                }

                $(document.body).on('added_to_cart fkcart_fragments_refreshed', (e, v) => {
                    //return if cart details not found
                    if (typeof v === "undefined" || !v.hasOwnProperty('fkwcs_google_pay_data')) {
                        return;
                    }
                    fkwcs_data.gpay_cart_data = v.fkwcs_google_pay_data;
                });

                $(document.body).on('fkwcs_generate_fkcart_mini_button', () => {
                    $('.fkwcs_fkcart_gpay_wrapper').html(this.createGooglePayButton(this.getCartExpressOptions(), 'mini-cart'));
                    let mini_cart_btn = $('#fkcart-modal .fkcart-checkout-wrap #fkcart-checkout-button');
                    let property = ['font-size', 'font-weight', 'border-radius', 'line-height'];
                    for (let i = 0; i < property.length; i++) {
                        $('.fkcart-checkout-wrap.fkcart-panel.fkwcs_fkcart_gpay_wrapper button').css(property[i], mini_cart_btn.css(property[i]));
                    }
                });

            }


            /**
             * Single Product Button creations
             * @returns {{onClick: any, buttonType: *, buttonColor: *}}
             */
            getSingleProductExpressOptions() {
                return {
                    buttonColor: fkwcs_data.google_pay_btn_color,
                    buttonType: fkwcs_data.google_pay_btn_theme,
                    buttonSizeMode: "fill",
                    onClick: this.startSingleProductCheckout.bind(this),
                };
            }

            /**
             * Funnelkit Cart/Mini Cart Product Button creations
             * @returns {{onClick: any, buttonType: *, buttonColor: *}}
             */
            getCartExpressOptions() {
                return {
                    buttonColor: fkwcs_data.google_pay_btn_color,
                    buttonType: fkwcs_data.google_pay_btn_theme,
                    buttonSizeMode: "fill",
                    onClick: this.startExpressCartGpayPayment.bind(this),
                };
            }

            startExpressCartGpayPayment() {
                const gpayData = fkwcs_data.gpay_cart_data || fkwcs_data.gpay_single_product;
                if (gpayData) {
                    this.update_transaction_data(gpayData);
                }
                this.startSingleProductGpayPayment();
            }


            startSingleProductCheckout() {
                try {
                    let is_Added = $.when(this.addToCartProduct());
                    is_Added.then((response) => {
                        this.update_transaction_data(response?.fragments?.fkwcs_google_pay_data);
                        this.startSingleProductGpayPayment();
                    });
                } catch (e) {

                }
            }

            /**
             * Start Gpay payment for single OR Cart Page
             */
            startSingleProductGpayPayment() {
                this.google_pay_client.loadPaymentData(this.buildGpayPaymentData()).then(this.productSingleProductGpayData.bind(this)).catch((error) => {
                    console.log('error', error);
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

            buildGpayPaymentData() {
                let request = $.extend({}, this.googlePayVersion(), {
                    emailRequired: true,
                    environment: ('test' === fkwcs_data.mode ? 'TEST' : 'PRODUCTION'),
                    merchantInfo: {
                        merchantName: fkwcs_data.google_pay.merchant_name,
                        merchantId: fkwcs_data.google_pay.merchant_id,
                    },
                    allowedPaymentMethods: [this.getBaseCardBrand()],
                });


                request.shippingAddressRequired = this.shippingAddressRequired();
                request.callbackIntents = ["PAYMENT_AUTHORIZATION"];
                request.allowedPaymentMethods[0].parameters.billingAddressRequired = true;
                if (request.allowedPaymentMethods[0].parameters.billingAddressRequired) {
                    request.allowedPaymentMethods[0].parameters.billingAddressParameters = {
                        format: "FULL",
                        phoneNumberRequired: true
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

            paymentDataChanged(data) {
                return new Promise((resolve) => {
                    let response = this.update_payment_data(data);
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

            /**
             * Update checkout field with address details and also return in json data.
             * @param type
             * @param addressData
             * @returns {{}}
             */
            mapAddress(type, addressData) {
                let json = {};
                if (addressData.hasOwnProperty('address1')) {
                    json[`line_1`] = addressData?.address1;
                }
                if (addressData.hasOwnProperty('address2')) {
                    json[`line_2`] = addressData?.address2 + addressData?.address3;
                }
                if (addressData.hasOwnProperty('locality')) {
                    json[`city`] = addressData?.locality;
                }
                if (addressData.hasOwnProperty('postalCode')) {
                    json[`postal_code`] = addressData?.postalCode;
                }
                if (addressData.hasOwnProperty('administrativeArea')) {
                    json[`state`] = addressData?.administrativeArea;
                }
                if (addressData.hasOwnProperty('countryCode')) {
                    json[`country`] = addressData?.countryCode;
                }
                if (addressData.hasOwnProperty('name')) {
                    json[`name`] = addressData.name;
                }
                return json;
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
                    user_details.email = paymentData?.email;
                }
                if (paymentData?.phone) {
                    user_details.phone = paymentData?.phone;
                }

                return user_details;
            }

            map_google_pay_address(shippingAddress) {
                return {'country': shippingAddress.countryCode, 'postcode': shippingAddress.postalCode, 'city': shippingAddress.locality, 'state': shippingAddress.administrativeArea};
            }

            update_payment_data(data, extraData) {
                return new Promise((resolve) => {
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
                            resolve(response);
                        }
                    });
                });
            }


            productSingleProductGpayData(paymentData) {
                let data = JSON.parse(paymentData.paymentMethodData.tokenizationData.token);

                let user_details = this.updateAddress(paymentData);
                let billing_address = {
                    name: user_details.billing.name,
                    email: user_details.email,
                    phone: user_details?.phone,
                    address: {
                        country: user_details.billing?.country,
                        city: user_details.billing?.city,
                        postal_code: user_details.billing?.postal_code,
                        state: user_details.billing?.state,
                        line1: user_details.billing?.line1,
                        line2: user_details.billing?.line2,
                    },
                };
                $('body').block({
                    message: null, overlayCSS: {
                        background: '#fff', opacity: 0.6
                    }
                });
                // convert token to payment method
                this.stripe.createPaymentMethod({
                    type: 'card',
                    card: {token: data.id},
                    billing_details: billing_address
                }).then((result) => {
                    if (result.error) {
                        return this.showError(result.error);
                    }
                    user_details.paymentMethod = result.paymentMethod.id;

                    $.ajax({
                        type: 'POST',
                        data: this.prepareCheckoutData(user_details),
                        dataType: 'text', // Set to 'text' to handle any extra text around JSON
                        url: this.ajaxEndpoint('fkwcs_gpay_button_payment_request'),
                        success: (responseText) => {
                            const parseJSONFromResponse = (response) => {
                                // Regular expression to find JSON-like content
                                const jsonMatch = response.match(/\{(?:[^{}]|(\{[^{}]*\}))*\}/);

                                if (jsonMatch) {
                                    try {
                                        // Attempt to parse the matched JSON
                                        return JSON.parse(jsonMatch[0]);
                                    } catch (e) {
                                        console.error("Failed to parse JSON:", e);
                                        return null;
                                    }
                                } else {
                                    console.warn("No JSON object found in response.");
                                    return null;
                                }
                            };

                            // Parse the JSON from response text
                            const response = parseJSONFromResponse(responseText);
                            // Proceed only if valid JSON was parsed
                            if (response && response.result === 'success') {

                                if (false === this.confirmPaymentIntent(result, response.redirect)) {
                                    window.location = response.redirect;
                                }
                            } else {
                                $('body').unblock();
                                window.location.reload();
                            }
                        }
                    });

                }).catch(() => {
                    $('body').unblock();
                });
            }

            prepareCheckoutData(user_details) {

                /**
                 * Gather Data from the chosen method
                 */
                const paymentMethod = user_details.paymentMethod;
                const email = user_details.email;
                const phone = user_details?.phone;
                const billing = user_details.billing;
                const name = user_details.billing.name;
                const shipping = user_details.shipping;
                /**
                 * Prepare Data
                 * @type {{billing_last_name: (*|string), billing_phone: (*|string|string), payment_request_type: null, billing_country: (*|string), billing_city: (*|string), fkwcs_nonce: *, billing_company: string, billing_state: (*|string), terms: number, billing_address_1: (*|string), shipping_method: *[], order_comments: string, billing_email: (*|string), billing_address_2: (*|string), billing_postcode: (*|string), fkwcs_source, billing_first_name: (*|string), payment_method: string}}
                 */
                let data = {
                    fkwcs_nonce: fkwcs_data.fkwcs_nonce,
                    billing_first_name: null !== name ? name.split(' ').slice(0, 1).join(' ') : 'test',
                    billing_last_name: null !== name ? name.split(' ').slice(1).join(' ') : 'test',
                    billing_company: '',
                    billing_email: ($('input[name="billing_email"]').length > 0 && $('input[name="billing_email"]').val() !== '') ? $('input[name="billing_email"]').val() : email,
                    billing_phone: phone ? phone.replace('/[() -]/g', '') : '',
                    order_comments: '',
                    payment_method: this.gateway_id,
                    terms: 1,
                    fkwcs_source: paymentMethod,
                    payment_request_type: this.gateway_id,
                };

                /**
                 * Prepare billing address
                 * @type {*}
                 */
                data = this.prepareBillingAddress(data, billing);
                /**
                 * Prepare Shipping address
                 * @type {*}
                 */
                data = this.prepareShippingAddress(data, shipping);

                if (fkwcs_data.is_product === 'yes') {
                    data.page_from = 'product';
                } else {
                    data.page_from = 'cart';
                }

                data = JSON.parse(JSON.stringify(data));
                return data;
            }


            shippingAddressRequired() {
                return ('yes' === fkwcs_data.shipping_required);
            }

            prepareBillingAddress(address_data, billing) {
                console.trace();
                address_data = super.prepareBillingAddress(address_data, billing);
                address_data.billing_address_1 = null !== billing ? billing.line_1 : '';
                address_data.billing_address_2 = null !== billing ? billing.line_2 : '';
                return address_data;
            }

            prepareShippingAddress(address_data, shipping_data) {
                if (shipping_data) {
                    address_data.shipping_first_name = shipping_data.name.split(' ').slice(0, 1).join(' ');
                    address_data.shipping_last_name = shipping_data.name.split(' ').slice(1).join(' ');
                    address_data.shipping_country = shipping_data.country;
                    address_data.shipping_address_1 = shipping_data.line_1;
                    address_data.shipping_address_2 = shipping_data.line_2;
                    address_data.shipping_city = shipping_data.city;
                    address_data.shipping_state = shipping_data.state;

                    if (shipping_data.hasOwnProperty('shipping_method')) {
                        address_data.shipping_method = Object.values(shipping_data.shipping_method);
                    }
                    if (address_data.hasOwnProperty('billing_phone')) {
                        address_data.shipping_phone = address_data.billing_phone;
                    }
                    address_data.shipping_postcode = shipping_data.postal_code;
                    address_data.ship_to_different_address = 1;
                }
                return address_data;
            }

            /**
             * Cb to handle response from the AJAX request on payment method
             * @param event
             * @param hash
             */
            confirmPaymentIntent(event, hash) {
                let hashpartials = hash.match(/^#?fkwcs-confirm-(pi|si)-([^:]+):(.+):(.+):(.+):(.+)$/);
                if (!hashpartials || 5 > hashpartials.length) {
                    window.location.redirect = hash;
                    $('body').unblock();
                    return false;
                }
                let type = hashpartials[1];
                let intentClientSec = hashpartials[2];
                let redirectURI = decodeURIComponent(hashpartials[3]);
                this.confirmPayment(event, intentClientSec, redirectURI, type);
            }

            /**
             * Attempt to confirm the payment intent using Stripe methods
             * @param clientSecret
             * @param redirectURL
             * @param intent_type
             */
            confirmPayment(event, clientSecret, redirectURL, intent_type) {

                let cardPayment = null;
                if (intent_type == 'si') {
                    cardPayment = this.stripe.handleCardSetup(clientSecret, {payment_method: event.paymentMethod.id}, {handleActions: false});
                } else {
                    cardPayment = this.stripe.confirmCardPayment(clientSecret, {payment_method: event.paymentMethod.id}, {handleActions: false});
                }
                cardPayment.then((result) => {
                    if (result.error) {
                        /**
                         * Insert logs to the server and show error messages
                         */
                        this.logError(result.error);
                        $('body').unblock();
                        return;
                    }

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
                                /**
                                 * Insert logs to the server and show error messages
                                 */
                                this.logError(result.error);
                                $('body').unblock();
                                return;
                            }
                            window.location = redirectURL;
                        });

                        return;
                    } else {
                        $('body').unblock();
                    }
                    window.location = redirectURL;
                });
            }

            setupPaymentRequest() {

            }

            setRequestData() {

            }

        }

        new FKWCS_Smart_Buttons();
        try {

            if (typeof fkwcs_data.google_pay !== 'undefined' && !('live' === fkwcs_data.mode && '' === fkwcs_data.google_pay.merchant_id)) {
                new FKWCS_Google_pay();
            }

        } catch (e) {
            console.log('Error Captured During Gpay initialization', e);
        }
    }

)(jQuery);