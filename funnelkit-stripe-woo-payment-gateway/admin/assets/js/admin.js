(function ($) {

    let style = fkwcs_admin_data.settings;
    const icons = fkwcs_admin_data.icons;
    let smart_button_result = {'apple_pay': false, 'google_pay': false};
    if ($('a[href="' + fkwcs_admin_data.site_url + '&tab=fkwcs_api_settings"]').hasClass('nav-tab-active')) {
        $('a[href="' + fkwcs_admin_data.site_url + '&tab=checkout"]').addClass('nav-tab-active');
    }

    if ($('.multiselect').length) {
        $('.multiselect').selectWoo();
    }


    $('.fkwcs-allowed-countries').on('change', switch_countries);


    function switch_countries() {

        const getOption = $('.fkwcs-allowed-countries').val();
        const exceptCountries = $('.fkwcs-except-countries').parents('tr');
        const specificCountries = $('.fkwcs-specific-countries').parents('tr');
        if (getOption === 'all_except') {
            exceptCountries.show();
            specificCountries.hide();
        } else if (getOption === 'specific') {
            exceptCountries.hide();
            specificCountries.show();
        } else {
            exceptCountries.hide();
            specificCountries.hide();
        }
    }

    switch_countries();

    function generateCheckoutDemo() {
        try {
            let iconUrl = '';
            let buttonClass = '';
            let requestType = '';

            const prButton = $('.fkwcs-payment-request-custom-button-render');

            prButton.on('click', function (e) {
                e.preventDefault();
            });

            if ($('.fkwcs_express_checkout_preview_wrapper .fkwcs_express_checkout_preview').length > 0) {
                $('.fkwcs-payment-request-custom-button-admin').show();
                $('.fkwcs_button_preview_label').css({display: 'block'});
                $('.fkwcs_preview_notice').css({display: 'block'});
                $('.fkwcs_express_checkout_preview_wrapper .fkwcs_express_checkout_preview').fadeIn();
                $('.fkwcs_preview_title').html($('#fkwcs_express_checkout_title').val());

                const buttonWidth = $('#fkwcs_express_checkout_button_width').val() ? $('#fkwcs_express_checkout_button_width').val() + 'px' : '100%';
                const buttonWidthOriginVal = $('#fkwcs_express_checkout_button_width').val();

                if (buttonWidthOriginVal > 380) {
                    prButton.css('max-width', buttonWidth);
                    prButton.css('width', '100%');
                } else if ('' !== buttonWidthOriginVal && buttonWidthOriginVal < 101) {
                    prButton.css('width', '112px');
                    prButton.css('min-width', '112px');
                } else {
                    prButton.css('min-width', buttonWidth);
                }

                // Show Apple Pay, GooglePay button for now
                result = {applePay: true, googlePay: true};

                if (result.applePay) {
                    requestType = 'apple_pay';
                    buttonClass = 'fkwcs-express-checkout-applepay-button';
                    iconUrl = 'dark' === style.theme ? icons.applepay_light : icons.applepay_gray;
                    $('.fkwcs-express-checkout-button-icon:first').attr('src', iconUrl);
                }

                if (result.googlePay) {
                    requestType = 'google_pay';
                    buttonClass = 'fkwcs-express-checkout-googlepay-button';
                    iconUrl = 'dark' === style.theme ? icons.gpay_light : icons.gpay_gray;
                    $('.fkwcs-express-checkout-button-icon:last').attr('src', iconUrl);
                }

                removeAllButtonThemes();
                $('.fkwcs-payment-request-custom-button-render').addClass('fkwcs-express-' + requestType);

                $('.fkwcs-payment-request-custom-button-render').addClass(buttonClass + '--' + style.theme);
                $('.fkwcs-payment-request-custom-button-render .fkwcs-express-checkout-button-label').html(style.text);
                $('.fkwcs_express_checkout_preview_wrapper').show();

            }

        } catch (e) {
            console.log(e);
        }
    }

    function removeAllButtonThemes() {
        let btn = $('.fkwcs-payment-request-custom-button-render');
        if (btn.length == 0) {
            return;
        }
        btn.removeClass('fkwcs-express-checkout-payment-button--dark');
        btn.removeClass('fkwcs-express-checkout-payment-button--light');
        btn.removeClass('fkwcs-express-checkout-payment-button--light-outline');
        btn.removeClass('fkwcs-express-checkout-googlepay-button--dark');
        btn.removeClass('fkwcs-express-checkout-googlepay-button--light');
        btn.removeClass('fkwcs-express-checkout-googlepay-button--light-outline');
        btn.removeClass('fkwcs-express-checkout-applepay-button--dark');
        btn.removeClass('fkwcs-express-checkout-applepay-button--light');
        btn.removeClass('fkwcs-express-checkout-applepay-button--light-outline');
    }

    function removeCheckoutPreviewElement() {
        $('.fkwcs_preview_title, .fkwcs_preview_tagline, .fkwcs_preview_notice').remove();
        $('.fkwcs-payment-request-custom-button-admin').css({margin: '0 auto', float: 'none', width: '100%'});
    }


    function toggleDescriptor() {

        if ($('#fkwcs_stripe_statement_descriptor_should_customize').length === 0) {

            return;
        }
        let isChecked = $('#fkwcs_stripe_statement_descriptor_should_customize').is(":checked");
        if (true === isChecked) {
            $('.fkwcs_statement_desc_options').closest('tr').show();
            $('#fkwcs_card_custom_descriptor').show();
        } else {
            $('.fkwcs_statement_desc_options').closest('tr').hide();
            $('#fkwcs_card_custom_descriptor').hide();
        }
        let valOfprefix = $('#fkwcs_stripe_statement_descriptor_prefix').val();
        let val = $('#fkwcs_stripe_statement_descriptor_suffix').val().toUpperCase().trim();
        var finalString = '';
        if (val === '{{WOO_ORDER_ID}}') {

            let firstletter = valOfprefix.charAt(0);
            finalString = valOfprefix + '* ' + firstletter + ' ';
            finalString += val.replace('{{WOO_ORDER_ID}}', '#123456');


        } else {
            if (isNaN(Math.abs(val))) {
                finalString = valOfprefix + '* ' + val;

            } else {
                let firstletter = valOfprefix.charAt(0);
                finalString = valOfprefix + '* ' + firstletter + ' ' + val;

            }
        }


        $('#fkwcs_custom_statement_desc_val').text(finalString.substring(0, 22));

    }

    function toggleOptions() {
        const pages = $('#fkwcs_express_checkout_location option:selected').toArray().map((item) => item.value);

        let page_descriptions = $('#fkwcs_express_checkout_product_page-description');
        if (jQuery.inArray('product', pages) !== -1) {
            $('.fkwcs_product_options').each(function () {
                $(this).parents('tr').show();
            });
            page_descriptions.show();
            page_descriptions.prev('h2').show();
        } else {
            $('.fkwcs_product_options').each(function () {
                $(this).parents('tr').hide();
            });
            page_descriptions.hide();
            page_descriptions.prev('h2').hide();
        }

        if (jQuery.inArray('cart', pages) !== -1) {
            $('.fkwcs_cart_options').each(function () {
                $(this).parents('tr').show();
            });
            page_descriptions.show();
            page_descriptions.prev('h2').show();
        } else {
            $('.fkwcs_cart_options').each(function () {
                $(this).parents('tr').hide();
            });
            page_descriptions.hide();
            page_descriptions.prev('h2').hide();
        }

        if (jQuery.inArray('checkout', pages) !== -1) {
            $('.fkwcs_checkout_options').each(function () {
                $(this).parents('tr').show();
                addCheckoutPreviewElement();
                $('#fkwcs_express_checkout_title').trigger('keyup');
            });
            page_descriptions.show();
            page_descriptions.prev('h2').show();
        } else {
            $('.fkwcs_checkout_options').each(function () {
                $(this).parents('tr').hide();
                removeCheckoutPreviewElement();
            });
            page_descriptions.hide();
            page_descriptions.prev('h2').hide();
        }
    }

    function addCheckoutPreviewElement() {
        removeCheckoutPreviewElement();
    }

    function checkPaymentRequestAvailibility() {
        if (fkwcs_admin_data.pub_key === '') {
            return;
        }
        var stripe = Stripe(fkwcs_admin_data.pub_key);

        var paymentRequest = stripe.paymentRequest({
            country: 'US', currency: 'usd', total: {
                label: 'Demo total', amount: 1099,
            }, requestPayerName: true, requestPayerEmail: true,
        });

        paymentRequest.canMakePayment().then(function (result) {
            if (!result) {
                return;
            }

            if (result.googlePay) {
                smart_button_result.google_pay = result.googlePay;
            }
            if (result.applePay) {
                smart_button_result.apple_pay = result.applePay;
            }
        });

    }

    function HideShowKeys(cond = '') {
        if (cond === true) {
            toggleTestKeys(1);
            toggleLiveKeys(0);
            if (fkwcs_admin_data.is_connected === '') {
                const connectButton = '<button name="connect" class="button-primary" type="button" id="fkwcs_test_connection" data-mode="manual">' + fkwcs_admin_data.test_btn_label + '</button>';
                $('.woocommerce .submit').append(connectButton);
                $('.woocommerce-save-button').hide();
            }
            $('#fkwcs_stripe_statement_descriptor_full').closest('tr').hide();
            $('#fkwcs_stripe_statement_descriptor_should_customize').closest('tr').hide();
            $('.fkwcs-cards-wrap').hide();

        }


        if (cond === false) {
            $('#fkwcs_test_pub_key').closest('tr').hide();
            $('#fkwcs_test_secret_key').closest('tr').hide();
            $('#fkwcs_pub_key').closest('tr').hide();
            $('#fkwcs_secret_key').closest('tr').hide();

            if (fkwcs_admin_data.is_connected === '') {
                $('#fkwcs_mode').closest('tr').hide();
                $('label[for=fkwcs_webhook_url]').closest('tr').hide();
                $('#fkwcs_live_webhook_secret').closest('tr').hide();
                $('#fkwcs_test_webhook_secret').closest('tr').hide();
                $('#fkwcs_create_webhook_button').closest('tr').hide();
                $('#fkwcs_delete_webhook_button').closest('tr').hide();
                $('#fkwcs_debug_log').closest('tr').hide();
                $('#fkwcs_test_connection').closest('tr').hide();
                $('#fkwcs_currency_fee').closest('tr').hide();
                $('#fkwcs_stripe_statement_descriptor_full').closest('tr').hide();
                $('#fkwcs_stripe_statement_descriptor_should_customize').closest('tr').hide();
                $('.fkwcs-cards-wrap').hide();
            }
        }
    }

    function toggleLiveKeys(toggle = 0) {
        if (toggle) {
            $('#fkwcs_pub_key').closest('tr').show();
            $('#fkwcs_secret_key').closest('tr').show();
            $('#fkwcs_live_webhook_secret').closest('tr').show();
        } else {
            $('#fkwcs_pub_key').closest('tr').hide();
            $('#fkwcs_secret_key').closest('tr').hide();
            $('#fkwcs_live_webhook_secret').closest('tr').hide();
        }
    }

    function toggleTestKeys(toggle = 0) {
        if (toggle) {
            $('#fkwcs_test_pub_key').closest('tr').show();
            $('#fkwcs_test_secret_key').closest('tr').show();
            $('#fkwcs_test_webhook_secret').closest('tr').show();
        } else {
            $('#fkwcs_test_pub_key').closest('tr').hide();
            $('#fkwcs_test_secret_key').closest('tr').hide();
            $('#fkwcs_test_webhook_secret').closest('tr').hide();
        }
    }

    function show_smart_button_connection() {
        let g = $('#is_google_pay_available');
        if (smart_button_result.google_pay) {
            g.addClass('fkwcs-express-button-active');
            g.children('.fkwcs_btn_connection').html('&#9989;');
        } else {
            g.addClass('fkwcs-express-button-not-active');
        }
        let a = $('#is_apple_pay_available');
        if (smart_button_result.apple_pay) {
            a.addClass('fkwcs-express-button-active');
            a.children('.fkwcs_btn_connection').html('&#9989;');
        } else {
            a.addClass('fkwcs-express-button-not-active');
        }
    }

    function show_google_pay_button() {

        if (fkwcs_admin_data.pub_key === '') {
            return;
        }
        let data = {
            environment: 'TEST',
            merchantId: '',
            merchantName: '',
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
        let version_data = {
            "apiVersion": 2,
            "apiVersionMinor": 0
        };
        let brand_data = {
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
                    "stripe:publishableKey": fkwcs_admin_data.pub_key
                }
            }
        };
        const button_settings = () => {
            let btn_color = $('#woocommerce_fkwcs_stripe_google_pay_button_color');
            let btn_theme = $('#woocommerce_fkwcs_stripe_google_pay_button_theme');
            return {
                buttonColor: btn_color.val(),
                buttonType: btn_theme.val(),
                onClick: () => {
                    console.log('Gpay Not Working in Admin Area');
                }
            };
        };
        const createButton = (google_pay_client) => {
            $('#woocommerce_fkwcs_stripe_google_pay_button_dummy_button').replaceWith('<div id="woocommerce_fkwcs_stripe_google_pay_button_dummy_button"></div>');
            $('#woocommerce_fkwcs_stripe_google_pay_button_color , #woocommerce_fkwcs_stripe_google_pay_button_theme')?.on('change', () => {
                $('#woocommerce_fkwcs_stripe_google_pay_button_dummy_button').html($(google_pay_client.createButton(button_settings())));
            });
            $('#woocommerce_fkwcs_stripe_google_pay_button_dummy_button').html($(google_pay_client.createButton(button_settings())));
        };
        const init = () => {
            try {

                let google_pay_client = new google.payments.api.PaymentsClient(data);
                let request_data = version_data;
                version_data.allowedPaymentMethods = [brand_data];
                google_pay_client.isReadyToPay(request_data).then(() => {
                    createButton(google_pay_client);
                }).catch((err) => {
                    console.log(err);
                });


            } catch (e) {
                console.log(e);
            }


        };
        $.getScript('https://pay.google.com/gp/p/js/pay.js', init);
    }

    if (fkwcs_admin_data.is_connected === '1') {
        $('.fkwcs_connect_btn').closest('tr').hide();
    }

    if (fkwcs_admin_data.is_connected === '' && 'fkwcs_api_settings' === fkwcs_admin_data.fkwcs_admin_settings_tab) {
        $('.woocommerce-save-button').hide();
    }

    if (fkwcs_admin_data.is_manually_connected) {
        HideShowKeys(true);
        setTimeout(function () {
            $("#fkwcs_mode").trigger('change');
        }, 100);
    } else {
        HideShowKeys(false);
        $('.fkwcs_inline_notice').hide();
    }

    $(document).ready(function () {


        generateCheckoutDemo(style);
        toggleOptions();
        toggleDescriptor();
        checkPaymentRequestAvailibility();
        show_google_pay_button();

        $('#fkwcs_express_checkout_button_text, #fkwcs_express_checkout_button_theme').change(function () {
            style = {
                text: '' === $('#fkwcs_express_checkout_button_text').val() ? fkwcs_admin_data.messages.default_text : $('#fkwcs_express_checkout_button_text').val(),
                theme: $('#fkwcs_express_checkout_button_theme').val(),
            };
            $('.fkwcs_express_checkout_preview_wrapper .fkwcs-payment-request-custom-button-admin').hide();
            generateCheckoutDemo(style);
        });

        $('#fkwcs_express_checkout_location').change(function () {
            toggleOptions();
        });
        $('#fkwcs_stripe_statement_descriptor_suffix').keydown(function (e) {
            let getMaxCount = $('#fkwcs_stripe_statement_descriptor_prefix').val().length + 2 + $(this).val().length;

            if (e.keyCode !== 8 && e.keyCode !== 86 && e.keyCode !== 46 && getMaxCount >= 22) {

                e.preventDefault();
                return false;
            }
            toggleDescriptor();

        });
        $('#fkwcs_stripe_statement_descriptor_suffix').keyup(function (e) {
            toggleDescriptor();

        });

        $(document).on('change', '#fkwcs_stripe_statement_descriptor_should_customize', function () {
            toggleDescriptor();
        });


        // Forcefully trigger change function to load elements
        $('#fkwcs_express_checkout_button_text, #fkwcs_express_checkout_button_theme').trigger('change');

        $('#fkwcs_express_checkout_title').keyup(function () {
            $('.fkwcs_preview_title').html($(this).val());
        });


        $('#fkwcs_express_checkout_button_width').change(function () {
            let buttonWidth = $(this).val();

            if ('' === buttonWidth) {
                buttonWidth = '100%';
            }
            let button_render = $('.fkwcs-payment-request-custom-button-render');
            if (buttonWidth > 380) {
                button_render.css('max-width', buttonWidth);
                button_render.css('width', '100%');
            } else if (buttonWidth < 100 && '' !== buttonWidth) {
                button_render.css('width', '112px');
                button_render.css('min-width', '112px');
            } else {
                button_render.width(buttonWidth);
            }
        });
        $('button.fkwcs_test_visibility').on('click', function () {
            $('.fkwcs-btn-type-info-wrapper').hide();
            let spinner = $('.fkwcs_express_checkout_connection_div');
            spinner.addClass('fkwcs_show_spinner');

            setTimeout(function () {
                show_smart_button_connection();
                $('.fkwcs-btn-type-info-wrapper').show();
                spinner.removeClass('fkwcs_show_spinner');
            }, 1500, spinner);

        });


        let form_type = $('#woocommerce_fkwcs_stripe_payment_form:checked');
        if (form_type.length > 0) {
            $('.link_fields_wrapper').show();
        } else {
            $('.link_fields_wrapper').hide();
        }


        let radio_fields = $('.fkwcs_admin_field_radio1');
        if (radio_fields.length > 0) {
            radio_fields.each(function () {
                let v = $(this).attr('value');
                if ('yes' === v) {
                    $(this).prop('checked', true);
                } else {
                    $(this).prop('checked', false);
                }
            });

        }
    });

    $(document).on('change', '#fkwcs_mode', function (e) {

        let connection_status = $('div.account_status').data('account-connect');
        if ('yes' === connection_status) {
            // Account is connected then return here;
            return;
        }


        if ('test' === $(this).val()) {
            toggleTestKeys(1);
            toggleLiveKeys(0);
        } else {
            toggleTestKeys(0);
            toggleLiveKeys(1);
        }

    });

    $(document).on('click', '#fkwcs_disconnect_acc', function (e) {
        e.preventDefault();
        $.ajax({
            type: 'GET', dataType: 'json', url: fkwcs_admin_data.ajax_url, data: {action: 'fkwcs_disconnect_account', _security: fkwcs_admin_data.fkwcs_admin_nonce}, beforeSend: () => {
                $('body').css('cursor', 'progress');
            }, success(response) {
                if (response.success === true) {
                    const icon = '✔';
                    alert(fkwcs_admin_data.stripe_disconnect + ' ' + icon);
                    window.location.href = fkwcs_admin_data.dashboard_url;
                } else if (response.success === false) {
                    alert(response.data.message);
                }
                $('body').css('cursor', 'default');
            }, error() {
                $('body').css('cursor', 'default');
                alert(fkwcs_admin_data.generic_error);
            },
        });
    });

    $(document).on('click', '#fkwcs_test_connection', function (e) {
        e.preventDefault();
        const fkwcsTestSecretKey = $('#fkwcs_test_secret_key').val();
        const fkwcsSecretKey = $('#fkwcs_secret_key').val();
        const fkwcsTestPubKey = $('#fkwcs_test_pub_key').val();
        const fkwcsPubKey = $('#fkwcs_pub_key').val();
        const messages = [];

        const mode = $('#fkwcs_mode').val();


        if (('test' === mode && '' !== fkwcsTestSecretKey && '' !== fkwcsTestPubKey) || ('live' === mode && '' !== fkwcsSecretKey && '' !== fkwcsPubKey)) {
            $.blockUI({message: ''});
            const mode = ('undefined' === typeof $(this).data('mode')) ? '' : $(this).data('mode');
            $.ajax({
                type: 'GET',
                dataType: 'json',
                url: fkwcs_admin_data.ajax_url,
                data: {action: 'fkwcs_test_stripe_connection', _security: fkwcs_admin_data.fkwcs_admin_nonce, fkwcs_test_sec_key: fkwcsTestSecretKey, fkwcs_secret_key: fkwcsSecretKey},
                beforeSend: () => {
                    $('body').css('cursor', 'progress');
                },
                success(response) {
                    const messages = [];
                    const res = response.data.data;
                    let br = '';
                    let icon = '❌';
                    if (res.live.status !== 'invalid') {
                        if (res.live.status === 'success') {
                            icon = '✔';
                        } else {
                            $('#fkwcs_secret_key').val('');
                            $('#fkwcs_pub_key').val('');
                        }
                        messages.push(res.live.mode + ' ' + icon + '\n' + res.live.message);
                        br = '----\n';
                    } else {
                        if ('manual' !== mode) {
                            messages.push(res.live.mode + ' ' + icon + '\n' + fkwcs_admin_data.stripe_key_unavailable);
                            br = '----\n';
                        }
                        $('#fkwcs_secret_key').val('');
                        $('#fkwcs_pub_key').val('');
                    }
                    icon = '❌';
                    if (res.test.status !== 'invalid') {
                        if (res.test.status === 'success') {
                            icon = '✔';
                        } else {
                            $('#fkwcs_test_secret_key').val('');
                            $('#fkwcs_test_pub_key').val('');
                        }
                        messages.push(br + res.test.mode + ' ' + icon + '\n' + res.test.message);
                    } else {
                        if ('manual' !== mode) {
                            messages.push(br + res.test.mode + ' ' + icon + '\n' + fkwcs_admin_data.stripe_key_unavailable);
                        }
                        $('#fkwcs_test_secret_key').val('');
                        $('#fkwcs_test_pub_key').val('');
                    }
                    $.unblockUI();
                    alert(messages.join('\n'));
                    $('body').css('cursor', 'default');
                    if ('manual' === mode && ('success' === res.live.status || 'success' === res.test.status)) {
                        $('.woocommerce-save-button').trigger('click');
                    }
                },
                error() {
                    $('body').css('cursor', 'default');
                    $.unblockUI();
                    alert(fkwcs_admin_data.stripe_key_error + fkwcs_admin_data.fkwcs_mode);
                },
            });
        } else {
            alert(fkwcs_admin_data.stripe_key_notice);
        }
    });

    $(document).on('click', '.fkwcs_dismiss_notice', function (e) {
        e.preventDefault();
        let notice_id = $(this).data('notice');
        $.ajax({
            type: 'GET', dataType: 'json', url: fkwcs_admin_data.ajax_url, data: {
                'notice_identifier': notice_id, action: 'fkwcs_dismiss_notice', _security: fkwcs_admin_data.fkwcs_admin_nonce
            }, success(response) {
                $('.fkwcs_dismiss_notice_wrap_' + notice_id).remove();
            }, error() {
            },
        });

    });
    $(document).on('click', '.fkwcs_apple_pay_domain_verification', function (e) {
        e.preventDefault();


        $.blockUI({message: ''});
        $.ajax({
            type: 'GET', dataType: 'json', url: fkwcs_admin_data.ajax_url, data: {action: 'fkwcs_apple_pay_domain_verification', _security: fkwcs_admin_data.fkwcs_admin_nonce}, beforeSend: () => {
                $('body').css('cursor', 'progress');
            }, success(response) {
                $.unblockUI();
                alert(response.data.msg);
                $('body').css('cursor', 'default');
                window.location.reload();

            }, error() {
                $('body').css('cursor', 'default');
                $.unblockUI();
                alert(fkwcs_admin_data.stripe_notice_re_verify);
            },
        });

    });

    $(document).on('click', '#fkwcs_create_webhook_button', function (e) {
        e.preventDefault();
        const fkwcsTestSecretKey = $('#fkwcs_test_secret_key').val();
        const fkwcsSecretKey = $('#fkwcs_secret_key').val();
        const fkwcsTestPubKey = $('#fkwcs_test_pub_key').val();
        const fkwcsPubKey = $('#fkwcs_pub_key').val();
        const fkwcsTestWebhookKey = $('#fkwcs_test_webhook_secret').val();
        const fkwcsLiveWebhookKey = $('#fkwcs_live_webhook_secret').val();

        const messages = [];
        const mode = $('#fkwcs_mode').val();


        if (('test' === mode && '' !== fkwcsTestSecretKey && '' !== fkwcsTestPubKey) || ('live' === mode && '' !== fkwcsSecretKey && '' !== fkwcsPubKey)) {
            $.blockUI({message: ''});
            $.ajax({
                type: 'GET', dataType: 'json', url: fkwcs_admin_data.ajax_url, data: {
                    action: 'fkwcs_create_webhook',
                    _security: fkwcs_admin_data.fkwcs_admin_nonce,
                    fkwcs_test_sec_key: fkwcsTestSecretKey,
                    fkwcs_secret_key: fkwcsSecretKey,
                    mode: mode,
                    fkwcs_test_webhook_key: fkwcsTestWebhookKey,
                    fkwcs_live_webhook_secret: fkwcsLiveWebhookKey
                }, beforeSend: () => {
                    $('body').css('cursor', 'progress');
                }, success(response) {
                    $.unblockUI();
                    alert(response.data.msg);
                    $('body').css('cursor', 'default');
                    window.location.reload();
                },
            });
        } else {
            alert(fkwcs_admin_data.stripe_key_notice);
        }
    });

    $(document).on('click', '#fkwcs_delete_webhook_button', function (e) {
        e.preventDefault();
        const fkwcsTestSecretKey = $('#fkwcs_test_secret_key').val();
        const fkwcsSecretKey = $('#fkwcs_secret_key').val();
        const fkwcsTestPubKey = $('#fkwcs_test_pub_key').val();
        const fkwcsPubKey = $('#fkwcs_pub_key').val();

        const messages = [];
        const mode = $('#fkwcs_mode').val();

        if ('test' === mode || 'live' === mode) {
            $.blockUI({message: ''});
            $.ajax({
                type: 'GET',
                dataType: 'json',
                url: fkwcs_admin_data.ajax_url,
                data: {action: 'fkwcs_delete_webhook', _security: fkwcs_admin_data.fkwcs_admin_nonce, fkwcs_mode: mode},
                beforeSend: () => {
                    $('body').css('cursor', 'progress');
                },
                success(response) {
                    let br = '';
                    $.unblockUI();
                    alert(response.data.msg);
                    $('body').css('cursor', 'default');
                    window.location.reload();
                },
                error() {
                    $('body').css('cursor', 'default');
                    $.unblockUI();
                    alert(fkwcs_admin_data.stripe_key_error + fkwcs_admin_data.fkwcs_mode);
                },
            });
        } else {
            alert(fkwcs_admin_data.stripe_key_notice);
        }
    });

    $(document).on('click change', '.fkwcs_form_type_selection', function (e) {

        let checked_length = $('.fkwcs_form_type_selection:checked').length;
        let is_checked = $(this).is(":checked");
        let id = $(this).attr('id');

        if ('woocommerce_fkwcs_stripe_payment_form' === id) {
            $('.link_fields_wrapper').show();
        } else {
            $('.link_fields_wrapper').hide();
        }

        if (checked_length === 0 && false === is_checked) {
            $(this).prop('checked', true);
            return false;
        }

        $('.fkwcs_form_type_selection').not(this).prop('checked', false);
    });
    $(document).on('click change', '.fkwcs_link_type_selection', function (e) {
        let checked_length = $('.fkwcs_link_type_selection:checked').length;
        let is_checked = $(this).is(":checked");

        if (checked_length === 0 && false === is_checked) {
            $(this).prop('checked', true);
            return false;
        }
        $('.fkwcs_link_type_selection').not(this).prop('checked', false);
    });

    (function () {
        if ($('.fkwcs_form_type_selection:checked').length === 0) {
            return;
        }
        let checkedElem = $('.fkwcs_form_type_selection:checked').eq(0);
        let checked_length = checkedElem.length;
        let is_checked = checkedElem.is(":checked");
        let id = checkedElem.attr('id');
        if ('woocommerce_fkwcs_stripe_payment_form' === id) {
            $('.link_fields_wrapper').show();
        } else {
            $('.link_fields_wrapper').hide();
        }

        if (checked_length === 0 && false === is_checked) {
            checkedElem.prop('checked', true);
            return false;
        }
        $('.fkwcs_form_type_selection').not(checkedElem).prop('checked', false);
    })();


    window.addEventListener('load', function () {
        const fkwcsMode = $('#fkwcs_mode');
        const appendDescription = () => {
            if (fkwcsMode.val() === 'test_admin_only') {
                fkwcsMode.parent().append('<div class="fkwcs_test_admin_only_desc">' + fkwcs_admin_data.test_mode_admin_only_html + '</div>');
            }
        };

        fkwcsMode.on('change', function () {
            $('.fkwcs_test_admin_only_desc').remove();
            appendDescription();
        });

        appendDescription();
    });

    $('.fkwcs_link_type_selection:checked').trigger('click');


}(jQuery));
document.addEventListener('DOMContentLoaded', function() {
    try {
        if (!document.querySelector('.subsubsub')) {
            const ul = document.createElement('ul');
            ul.classList.add('subsubsub');

            const settings = JSON.parse(fkwcsAdminNav.settings);
            const urlParams = new URLSearchParams(window.location.search);
            const currentSection = urlParams.get('section');
            const adminURL = fkwcsAdminNav.adminUrl;
            let matched = false;

            Object.entries(settings).forEach(([section, label], index, array) => {
                const li = document.createElement('li');
                const a = document.createElement('a');
                a.href = adminURL + section;
                a.textContent = label;

                if (section === currentSection) {
                    a.classList.add('current');
                    matched = true;
                }

                li.appendChild(a);
                ul.appendChild(li);

                if (index < array.length - 1) {
                    const separator = document.createTextNode(' | ');
                    ul.appendChild(separator);
                }
            });

            if (!matched) {
                const firstLink = ul.querySelector('a');
                if (firstLink) {
                    firstLink.classList.add('current');
                }
            }

            const targetElement = document.querySelector('h1.screen-reader-text');
            if (targetElement) {
                targetElement.parentNode.insertBefore(ul, targetElement.nextSibling);

                const br = document.createElement('br');
                br.classList.add('clear');
                targetElement.parentNode.insertBefore(br, ul.nextSibling);
            }
        }
    } catch (e) {
        console.log('FKWCS Admin Navigation Error:', e);
    }
});