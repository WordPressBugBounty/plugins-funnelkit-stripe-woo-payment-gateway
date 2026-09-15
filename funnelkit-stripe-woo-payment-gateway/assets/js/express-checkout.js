(function ($) {


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

    // Configuration for different payment methods
    const PAYMENT_METHOD_CONFIGS = {
        applePay: {
            applePay: 'always',
            googlePay: 'never',
            link: 'never',
            amazonPay: 'never',
            paypal: 'never',
            klarna: 'never'
        },
        googlePay: {
            applePay: 'never',
            googlePay: 'always',
            link: 'never',
            amazonPay: 'never',
            paypal: 'never',
            klarna: 'never'
        },
        link: {
            applePay: 'never',
            googlePay: 'never',
            link: 'auto',
            amazonPay: 'never',
            paypal: 'never',
            klarna: 'never'
        },
        amazonPay: {
            applePay: 'never',
            googlePay: 'never',
            link: 'never',
            amazonPay: 'auto',
            paypal: 'never',
            klarna: 'never'
        },
        mixed: {
            applePay: 'always',
            googlePay: 'always',
            link: 'auto',
            amazonPay: 'auto',
            paypal: 'never',
            klarna: 'never'
        }
    };

    // Selector configurations for different contexts
    const SELECTOR_CONFIGS = {
        fkcart: {
            applePay: {
                selector: '#fkcart_fkwcs_smart_button_apple_pay .fkwcs_smart_buttons.fkwcs_smart_cart_button',
                parent: '#fkcart_fkwcs_smart_button_apple_pay'
            },
            googlePay: {
                selector: '#fkcart_fkwcs_smart_button_google_pay .fkwcs_smart_buttons.fkwcs_smart_cart_button',
                parent: '#fkcart_fkwcs_smart_button_google_pay'
            },
            link: {
                selector: '#fkcart_fkwcs_smart_button_link .fkwcs_smart_buttons.fkwcs_smart_cart_button',
                parent: '#fkcart_fkwcs_smart_button_link'
            },
            amazonPay: {
                selector: '#fkcart_fkwcs_smart_button_amazon_pay .fkwcs_smart_buttons.fkwcs_smart_cart_button',
                parent: '#fkcart_fkwcs_smart_button_amazon_pay'
            }
        },
        aeroCheckout: {
            applePay: {
                selector: '#wfacp_smart_button_fkwcs_apple_pay .fkwcs_smart_buttons,.fkwcs_express_smart_button_wrapper.fkwcs_stripe_smart_button_wrapper.fkwcs_apple_pay_button .fkwcs_smart_buttons',
                parent: '#wfacp_smart_button_fkwcs_apple_pay,.fkwcs_express_smart_button_wrapper.fkwcs_stripe_smart_button_wrapper.fkwcs_apple_pay_button'
            },
            googlePay: {
                selector: '#wfacp_smart_button_fkwcs_stripe_google_pay .fkwcs_smart_buttons,.fkwcs_express_smart_button_wrapper.fkwcs_stripe_smart_button_wrapper.fkwcs_google_pay_button .fkwcs_smart_buttons',
                parent: '#wfacp_smart_button_fkwcs_stripe_google_pay,.fkwcs_express_smart_button_wrapper.fkwcs_stripe_smart_button_wrapper.fkwcs_google_pay_button'
            },
            link: {
                selector: '#wfacp_smart_button_fkwcs_link .fkwcs_smart_buttons,.fkwcs_express_smart_button_wrapper.fkwcs_stripe_smart_button_wrapper.fkwcs_link_pay_button .fkwcs_smart_buttons',
                parent: '#wfacp_smart_button_fkwcs_link,.fkwcs_express_smart_button_wrapper.fkwcs_stripe_smart_button_wrapper.fkwcs_link_pay_button'
            },
            amazonPay: {
                selector: '#wfacp_smart_button_fkwcs_amazon_pay .fkwcs_smart_buttons,.fkwcs_express_smart_button_wrapper.fkwcs_stripe_smart_button_wrapper.fkwcs_amazon_pay_button .fkwcs_smart_buttons',
                parent: '#wfacp_smart_button_fkwcs_amazon_pay,.fkwcs_express_smart_button_wrapper.fkwcs_stripe_smart_button_wrapper.fkwcs_amazon_pay_button'
            }
        },
        /**
         * Single product page — matches _simple_button_wrapper markup (not FK Cart drawer).
         */
        product: {
            applePay: {
                selector: 'div.fkwcs_stripe_smart_button_wrapper .fkwcs-product.fkwcs_apple_pay_button .fkwcs_smart_buttons',
                parent: 'div.fkwcs_stripe_smart_button_wrapper .fkwcs-product.fkwcs_apple_pay_button'
            },
            googlePay: {
                selector: 'div.fkwcs_stripe_smart_button_wrapper .fkwcs-product.fkwcs_google_pay_button .fkwcs_smart_buttons',
                parent: 'div.fkwcs_stripe_smart_button_wrapper .fkwcs-product.fkwcs_google_pay_button'
            },
            link: {
                selector: 'form.cart .fkwcs-product.fkwcs_link_pay_button .fkwcs_smart_buttons',
                parent: 'form.cart .fkwcs-product.fkwcs_link_pay_button'
            },
            amazonPay: {
                selector: 'div.fkwcs_stripe_smart_button_wrapper .fkwcs-product.fkwcs_amazon_pay_button .fkwcs_smart_buttons',
                parent: 'div.fkwcs_stripe_smart_button_wrapper .fkwcs-product.fkwcs_amazon_pay_button'
            }
        }
    };

    /**
     * Treat a localized flag as truthy across every serialized form it can take.
     * wp_localize_script coerces a PHP boolean true to the string "1", while
     * add_js_params (when applied) emits "yes" — so the cart/product flags can
     * arrive as 'yes', '1', or a real boolean. Mirrors the product-branch idiom.
     */
    const isTruthyFlag = (v) => v === 'yes' || v === '1' || v === true;


    function generateExpressButton(instance, selector, selector_parent, payment_methods, button_type, context = '') {
        const p = new Promise((resolve) => {
            try {
                const $selector = $(selector);
                const $selectorParent = $(selector_parent);

                if ($selector.length === 0) {
					console.log('selector not found', selector);
                    resolve({'result': 'failed', 'message': 'selector not found', 'button_type': button_type});
                    return;
                }
                const reqData = instance.getRequestData();
                /** Express wallets must use cart order_data currency (AJAX), not fkwcs_data alone — it is only set on full page load (breaks Aelia / currency switch). */
                const walletCurrency = (reqData && reqData.currency)
                    ? String(reqData.currency).toLowerCase()
                    : (typeof fkwcs_data.currency === 'string' ? fkwcs_data.currency.toLowerCase() : '');
                const amtNum = reqData && reqData.total && reqData.total.amount !== undefined && reqData.total.amount !== null
                    ? Number(reqData.total.amount)
                    : NaN;

                const noCharge = !reqData || !reqData.total || !Number.isFinite(amtNum) || amtNum <= 0;
                const isPaymentNeeded = reqData && reqData.is_fkwcs_need_payment === true;
                /** Free-trial fallback: cart total is 0 but a card must still be collected (e.g. WC Subscriptions free trial). Stripe's Express Checkout Element requires amount > 0, so use a placeholder; the real amount is determined server-side at order creation. */
                const stripeAmount = (Number.isFinite(amtNum) && amtNum > 0) ? amtNum : (isPaymentNeeded ? 100 : amtNum);
                /** Amazon Pay free trial / $0-now: nothing to charge but a mandate must be collected.
                 * Run the Express Checkout Element in setup mode so createConfirmationToken produces a
                 * setup-type token the server confirms as a SetupIntent (no charge). Other wallets keep
                 * their existing payment-mode flow. */
                const isAmazonSetup = ('amazonPay' === button_type) && noCharge && isPaymentNeeded;

                if (noCharge && !isPaymentNeeded) {
                    if (instance.express_buttons[selector]) {
                        try {
                            instance.express_buttons[selector].unmount();
                        } catch (_e) {
                            /* intentional */
                        }
                        delete instance.express_buttons[selector];
                    }
                    if (instance.express_element[selector]) {
                        delete instance.express_element[selector];
                    }
                    if (instance.express_buttons_shows && instance.express_buttons_shows[selector]) {
                        delete instance.express_buttons_shows[selector];
                    }
                    $selectorParent.find('.fkwcs_smart_button_trigger').addClass('hide');
                    $selectorParent.hide();
                    $selector.hide();
                    resolve({'result': 'failed', 'message': 'amount is zero', 'button_type': button_type, 'selector': $selector, 'selectorParent': $selectorParent, 'availablePaymentMethods': false});
                    return;
                }

                // Amazon Pay: an element's mode (payment vs setup) is fixed at creation, but the cart
                // can cross the $0 boundary after the button exists (free-trial item added, 100%
                // coupon, or the reverse). A wrong-mode element produces a confirmation token the
                // server-side intent rejects ("The provided setup_future_usage (off_session) does not
                // match..."), so tear it down and rebuild in the right mode. Never mid-click — that
                // would destroy the live Amazon checkout session.
                if ('amazonPay' === button_type && instance.express_buttons[selector] && !instance._expressClickActive) {
                    const builtMode = instance.express_element_mode && instance.express_element_mode[selector];
                    const wantMode = isAmazonSetup ? 'setup' : 'payment';
                    if (builtMode && builtMode !== wantMode) {
                        try {
                            instance.express_buttons[selector].unmount();
                        } catch (_e) {
                            /* intentional */
                        }
                        delete instance.express_buttons[selector];
                        delete instance.express_element[selector];
                        if (instance.express_buttons_shows && instance.express_buttons_shows[selector]) {
                            delete instance.express_buttons_shows[selector];
                        }
                    }
                }

                if (instance.express_buttons[selector]) {
					if (isAmazonSetup || !(Number(stripeAmount) > 0)) {
						instance.express_element[selector].update({currency: walletCurrency});
					} else {
						instance.express_element[selector].update({amount: stripeAmount, currency: walletCurrency});
					}
                    if (context === 'fkcart' || context === 'cart') {
                        // Remount only when a fragment refresh destroyed the mounted iframe;
                        // remounting a healthy button tears it down and re-renders from scratch
                        // (skeleton flash + 1-2s delay on every regenerate call).
                        //
                        // Never remount while a wallet click/popup is being processed either (the
                        // click's add-to-cart triggers this refresh). Re-mounting mid-click breaks
                        // the wallet flow — fatal for Amazon Pay, whose live checkout session would
                        // be destroyed.
                        if ($selector.find('iframe').length === 0 && !instance._expressClickActive) {
                            // Show the grey skeleton overlay and reserve a stable height
                            // BEFORE unmounting, so the container does not collapse to 0px
                            // and leave a blank gap while the Link button re-mounts.
                            $selectorParent.css('min-height', '42px');
                            $selectorParent.find('.fkwcs_smart_button_trigger').removeClass('hide').show();
                            instance.express_buttons[selector].unmount();
                            instance.express_buttons[selector].mount(selector);
                            if (context === 'cart') {
                                $selectorParent.find('.fkwcs_smart_button_trigger').addClass('hide');
                            }
                        }
                        $selector.addClass('fkwcs_smart_button_shown');
                        $selector.show();
                    }
					$selectorParent.find('.fkwcs_smart_button_trigger').show();
                    let cachedMethods = instance.express_buttons_shows[selector] ? instance.express_buttons_shows[selector].availablePaymentMethods : undefined;
                    const hasAvailable = cachedMethods === true ||
                        (typeof cachedMethods === 'object' && cachedMethods !== null &&
                            Object.values(cachedMethods).some(v => v === true));
                    if (hasAvailable) {
                        $selectorParent.attr('data-fkwcs-available', 'yes');
                    }
                    resolve({'result': 'pass', 'already_exist': 'yes', 'message': 'button already exists', 'selector': $selector, 'selectorParent': $selectorParent, 'button_type': button_type, 'availablePaymentMethods': cachedMethods});
                    return;
                }


                const elementOptions = {
                    mode: isAmazonSetup ? 'setup' : 'payment',
                    currency: walletCurrency,
                    appearance: {
                        variables: {
                            fontSizeBase: '13px',
                        },
                    },
                    paymentMethodCreation: 'manual',
                };
                // Stripe's setup mode does not accept an amount; payment mode requires it.
                if (!isAmazonSetup) {
                    elementOptions.amount = stripeAmount;
                }

                // Amazon Pay's Express Checkout Element does not render when
                // paymentMethodCreation:'manual' is set (Stripe returns no available methods).
                if ('amazonPay' === button_type) {
                    delete elementOptions.paymentMethodCreation;
                }

                let apple_theme = 'black';
                let google_theme = 'black';
                if ('light-outline' === fkwcs_data.style.theme) {
                    apple_theme = 'white-outline';
                    google_theme = 'white';
                } else if ('light' === fkwcs_data.style.theme) {
                    apple_theme = 'white';
                    google_theme = 'white';
                }
                const expressOptions = {
                    buttonHeight: 42,
                    layout: {
                        maxColumns: 3,
                        maxRows: 1
                    },
                    buttonTheme: {
                        applePay: apple_theme,
                        googlePay: google_theme,
                    },
                    buttonType: {
                        applePay: fkwcs_data.style.apple_button_type || 'plain',
                        googlePay: fkwcs_data.style.google_pay_button_type || 'plain',
                    },
                    paymentMethods: payment_methods
                };
                const fkCartElements = instance.stripe.elements(elementOptions);
                const fkCartExpressElement = fkCartElements.create('expressCheckout', expressOptions);
                instance.express_element[selector] = fkCartElements;
                // Record the mode this element was BUILT with — the mode-flip teardown above
                // compares against it to rebuild Amazon Pay when the cart crosses the $0 boundary.
                instance.express_element_mode = instance.express_element_mode || {};
                instance.express_element_mode[selector] = isAmazonSetup ? 'setup' : 'payment';
                const update_element = (response) => {
                    if (!response || !response.total) {
                        return;
                    }
                    const cur = response.currency
                        ? String(response.currency).toLowerCase()
                        : walletCurrency;
                    // Setup-mode elements (Amazon free trial) and any $0 total take no amount —
                    // Stripe rejects update({amount:0}). Refresh the currency only in that case.
                    if (isAmazonSetup || !(Number(response.total.amount) > 0)) {
                        instance.express_element[selector].update({currency: cur});
                        return;
                    }
                    instance.express_element[selector].update({amount: response.total.amount, currency: cur});
                }

                fkCartExpressElement.on('ready', (data) => {
                    let availablePaymentMethods = data.availablePaymentMethods || false;
					instance.express_buttons_shows[selector] = {'parent': selector_parent, 'availablePaymentMethods': availablePaymentMethods, 'button_type': button_type};

                    if ('yes' === fkwcs_data.apple_pay_individual && availablePaymentMethods) {
                        if (typeof availablePaymentMethods === 'object') {
                            availablePaymentMethods.googlePay = false;
                            availablePaymentMethods.link = false;
                            availablePaymentMethods.amazonPay = false;
                        }
                    }

                    let hasAnyMethod = false;
                    if (typeof availablePaymentMethods === 'object' && availablePaymentMethods !== null) {
                        for (let method in availablePaymentMethods) {
                            if (availablePaymentMethods[method] === true) {
                                hasAnyMethod = true;
                                break;
                            }
                        }
                    } else if (availablePaymentMethods === true) {
                        hasAnyMethod = true;
                    }

                    if (!hasAnyMethod) {
						$selectorParent.find('.fkwcs_smart_button_trigger').append('<span class="wfacp_reject_button"></span>');
                        $selectorParent.find('.fkwcs_smart_button_trigger').addClass('hide');
                        $selectorParent.hide();
                        $selector.hide();
                        resolve({'result': 'pass', 'message': 'no available payment methods', 'selector': $selector, 'selectorParent': $selectorParent, 'availablePaymentMethods': availablePaymentMethods, 'noMethods': true, 'button_type': button_type});
                        return;
                    }

					$selectorParent.attr('data-fkwcs-available', 'yes');
					if(context=='native' || context === 'fkcart' || context === 'product'){
						// Fully reveal this slot the moment its ECE is ready. The Promise.race
						// timeout (PER_PROMISE_TIMEOUT_MS) may have already hidden it if the element
						// was slow to report availability (common with several ECEs competing on one
						// page) — in which case onAllDone revealed only the fast buttons. Re-showing
						// here recovers slow-but-available buttons (e.g. Apple Pay / Google Pay)
						// instead of leaving them hidden with data-fkwcs-available="yes".
						// For fkcart this also stops one slow wallet probe (GPay can take 10s+)
						// from holding every other drawer button hostage until onAllDone.
						$selectorParent.show().css('display', 'block');
						$selector.show().css({'display': 'block', 'visibility': 'visible'});
						$selector.find('iframe').each(function () {
							this.style.setProperty('margin', '0', 'important');
							this.style.setProperty('width', '100%', 'important');
						});
						$selector.addClass('fkwcs_smart_button_shown');
						if (context === 'fkcart') {
							// Swap the drawer slot's grey skeleton shimmer for the button now.
							$selector.parents('.fkwcs_stripe_smart_button_wrapper').show();
							$selectorParent.find('.fkwcs_smart_button_trigger').addClass('hide').hide();
						}
					}
                    instance.expressButtonReady(availablePaymentMethods, fkCartElements);
                    resolve({'result': 'pass', 'message': 'button created', 'selector': $selector, 'selectorParent': $selectorParent, 'availablePaymentMethods': availablePaymentMethods, 'button_type': button_type});
                });

                fkCartExpressElement.on('confirm', event => instance.onPaymentMethodFkcart(event, instance.express_element[selector], context));
                fkCartExpressElement.on('shippingaddresschange', event => instance.shippingAddressChange(event, update_element));
                fkCartExpressElement.on('shippingratechange', event => instance.shippingOptionChange(event, update_element));

                fkCartExpressElement.on('click', event => {
                    event.selectorContext = selector;
                    // Pass the button's real context so expressClick only adds-to-cart for the PRODUCT-page
                    // button. The slide-cart/cart/checkout buttons hardcoded '' and so wrongly re-added the
                    // product on click → fragment refresh → slide-cart re-init → Amazon session destroyed.
                    instance.expressClick(event, context, update_element);
                });

                fkCartExpressElement.on('cancel', event => instance.cancelPayment(event));
                // An ECE that fails to load (wallet not activated on the Stripe account,
                // Apple Pay on an unverified domain) never fires 'ready' — without this
                // its promise hangs until the PER_PROMISE_TIMEOUT_MS race timeout. This is a
                // merchant configuration state, not a payment failure, so the button is hidden
                // silently; payment errors are surfaced by abortPayment() instead.
                fkCartExpressElement.on('loaderror', () => {
                    $selectorParent.find('.fkwcs_smart_button_trigger').addClass('hide');
                    $selectorParent.hide();
                    $selector.hide();
                    resolve({'result': 'failed', 'message': 'loaderror', 'selector': $selector, 'selectorParent': $selectorParent, 'availablePaymentMethods': false, 'noMethods': true, 'button_type': button_type});
                });
                fkCartExpressElement.mount(selector);
                instance.express_buttons[selector] = fkCartExpressElement;

            } catch (e) {

                resolve({'result': 'failed', 'message': e, 'button_type': button_type});
            }
        });
        p._meta = { button_type, selector, selectorParent: selector_parent, context };
        return p;
    }


    class FKWCS_Smart_Buttons {
        constructor() {
            this.express_buttons = {};
            this.express_element = {};
            this.express_buttons_shows = {};
            this.current_processing_elements = null;
            this.fkCartElements = null;
            this.css_selector = {};

            this.block_data = {
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6,
                },
            };
            this.context = '';
            this.button_id = 'fkwcs_stripe_smart_button';
            this.payment_request = null;
            this.express_request_type = null;
            this.express_button_wrapper = null;
            this.is_product_page = false;
            this.style_value = fkwcs_data.style;
            this.request_data = {};
            this.single_product_add_to_cart_click = false;
            this.cart_request_data = {};
            this.add_to_cart_end_point = 'fkwcs_add_to_cart';
            this.checkout_button_promise = [];
            this.is_google_ready_to_pay = false; //This variable is used to determine if native Google Pay integration is available.;
            this.smart_button_id = '#wfacp_smart_buttons';
            this.loading_gif = 'wfacp-dynamic-checkout-loading';
            /**
             * Setup data for the product page
             */
            this.dataCommon = {
                currency: fkwcs_data.currency,
                country: fkwcs_data.country_code,
                requestPayerName: true,
                requestPayerEmail: true,
                requestPayerPhone: true,
            };
            /**
             * bail out if stripe public key not configured
             */
            if ('' === fkwcs_data.pub_key) {
                return;
            }

            try {
                this.stripe = window.fkwcsGetStripe(fkwcs_data.pub_key, {
                    locale: fkwcs_data.locale,
                });
                this.init();
            } catch (e) {
                if ('yes' === fkwcs_data.debug_log) {
                    console.log('Stripe Error', e);
                }
            }
			document.addEventListener('DOMContentLoaded', () => {
				this.setupExpressCheckoutButton('init');
				this.wcEvents();
				this.warmupExpressElements();
			});
        }

        init() {

            if ('yes' === fkwcs_data.is_product) {
                this.request_data = Object.assign(this.dataCommon, {
                    total: fkwcs_data.single_product.total, requestShipping: ('yes' === fkwcs_data.single_product.requestShipping), displayItems: fkwcs_data.single_product.displayItems || [],
                });
            } else if (isTruthyFlag(fkwcs_data.is_cart) || 'yes' === fkwcs_data.is_checkout) {
                // A partial payload (e.g. card gateway disabled and cart_data absent) must degrade
                // gracefully — optional-chain the deref so init() never throws before the mount gates run.
                this.request_data = Object.assign(this.dataCommon, {
                    total: fkwcs_data?.cart_data?.order_data?.total || 0, requestShipping: ('yes' === fkwcs_data.shipping_required), displayItems: fkwcs_data?.cart_data?.displayItems || [],
                });
                if (fkwcs_data.cart_data && fkwcs_data.cart_data.order_data && fkwcs_data.cart_data.order_data.currency) {
                    const c = String(fkwcs_data.cart_data.order_data.currency).toLowerCase();
                    this.request_data.currency = c;
                    fkwcs_data.currency = c;
                }
                this.request_data.is_fkwcs_need_payment = !!(fkwcs_data.cart_data && fkwcs_data.cart_data.is_fkwcs_need_payment);
                if (fkwcs_data.cart_data) {
                    this.syncExpressCheckoutVisibility(fkwcs_data.cart_data);
                }

            }

            if ('yes' === fkwcs_data.express_pay_enabled || 'yes' === fkwcs_data.apple_pay_individual) {
                this.setupPaymentRequest();
            }
        }

        /**
         * Product/cart/checkout pages create real express elements at page load, which
         * also pre-loads Stripe's wallet scripts and availability probes. Archive/home
         * pages create nothing until add-to-cart, so the FK Cart drawer's first button
         * pays the full cold-start cost there (~1s slower, measured). Mount one hidden
         * throwaway element at load to give those pages the same head start; it is
         * removed as soon as Stripe reports it ready or failed.
         */
        warmupExpressElements() {
            if ('yes' === fkwcs_data.is_checkout || 'yes' === fkwcs_data.is_product || isTruthyFlag(fkwcs_data.is_cart)) {
                return;
            }
            if ($('#fkcart-modal').length === 0) {
                return;
            }
            if (!this.applePayEnabled() && 'yes' !== fkwcs_data.enable_gateways?.fkwcs_stripe_google_pay && !this.linkEnabled()) {
                return;
            }
            try {
                const holder = document.createElement('div');
                holder.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:320px;height:60px;overflow:hidden;';
                document.body.appendChild(holder);
                const elements = this.stripe.elements({
                    mode: 'payment',
                    amount: 100,
                    currency: typeof fkwcs_data.currency === 'string' && fkwcs_data.currency ? fkwcs_data.currency.toLowerCase() : 'usd',
                    paymentMethodCreation: 'manual',
                });
                const warm = elements.create('expressCheckout', {paymentMethods: PAYMENT_METHOD_CONFIGS.mixed});
                const cleanup = () => {
                    try {
                        warm.unmount();
                    } catch (_e) {
                        /* intentional */
                    }
                    holder.remove();
                };
                warm.on('ready', cleanup);
                warm.on('loaderror', cleanup);
                warm.mount(holder);
                setTimeout(cleanup, 20000);
            } catch (_e) {
                /* warm-up is best-effort only */
            }
        }

        ajaxEndpoint(action) {
            let url = '';
            const we = fkwcs_data && fkwcs_data.wc_endpoints;
            if (we && typeof we === 'object' && Object.prototype.hasOwnProperty.call(we, action)) {
                url = we[action];
            }
            return url;
        }

        /**
         * Align DOM with server is_fkwcs_need_payment (runs before wallet setup so already-mounted nodes hide on €0).
         */
        syncExpressCheckoutVisibility(data) {
            if (!data || !Object.prototype.hasOwnProperty.call(data, 'is_fkwcs_need_payment')) {
                return;
            }
            const hide = false === data.is_fkwcs_need_payment;
            $('#fkwcs_stripe_smart_button_wrapper').toggleClass('fkwcs_hide_button', hide);
            $('#wfacp_smart_buttons').toggleClass('fkwcs_hide_button', hide);
            $('#fkwcs-payment-request-separator')[hide ? 'hide' : 'show']();
        }

        setRequestData(data,event) {
            if (null == data || 'object' !== typeof data || !Object.prototype.hasOwnProperty.call(data, 'order_data')) {
                return;
            }

            this.request_data = Object.assign(this.dataCommon, {
                total: data.order_data.total,
                currency: data.order_data.currency,
                country: data.order_data.country_code,
                requestShipping: ('yes' === data.shipping_required),
                displayItems: data.order_data.displayItems || [],
            });
            this.request_data.is_fkwcs_need_payment = data.is_fkwcs_need_payment === true;

            if (data.order_data.currency) {
                fkwcs_data.currency = String(data.order_data.currency).toLowerCase();
            }

            this.syncExpressCheckoutVisibility(data);
            this.setupExpressCheckoutButton('',event);
            this.setupPaymentRequest();

        }

        getRequestData() {
            return this.request_data;
        }


        generateExpressButton() {
            if ('yes' === fkwcs_data.is_checkout) {
                return;
            }
            let promises = [];
            if (this.applePayEnabled()) {
                let apple_pay = generateExpressButton(this, SELECTOR_CONFIGS.fkcart.applePay.selector, '#fkcart_fkwcs_smart_button_apple_pay', PAYMENT_METHOD_CONFIGS.applePay, 'applePay','fkcart');
                promises.push(apple_pay);
            }
                if ('yes' === fkwcs_data.enable_gateways?.fkwcs_stripe_google_pay) {
                    let gpay = generateExpressButton(this, SELECTOR_CONFIGS.fkcart.googlePay.selector, '#fkcart_fkwcs_smart_button_google_pay', PAYMENT_METHOD_CONFIGS.googlePay, 'googlePay','fkcart');
                    promises.push(gpay);
                }
            if (this.linkEnabled()) {

                let link_pay = generateExpressButton(this, SELECTOR_CONFIGS.fkcart.link.selector, '#fkcart_fkwcs_smart_button_link', PAYMENT_METHOD_CONFIGS.link, 'link','fkcart');
                promises.push(link_pay);
            }
            if (this.amazonPayEnabled()) {
                let amazon_pay = generateExpressButton(this, SELECTOR_CONFIGS.fkcart.amazonPay.selector, '#fkcart_fkwcs_smart_button_amazon_pay', PAYMENT_METHOD_CONFIGS.amazonPay, 'amazonPay','fkcart');
                promises.push(amazon_pay);
            }
            this.handleButtonPromise(promises);


        }



      handleButtonPromise(promises) {

            if (promises.length === 0) {
                $(document.body).trigger('fkwcs_new_express_no_smart_buttons_generated');
                return;
            }
            const PER_PROMISE_TIMEOUT_MS = 8000;

			let buttons = {};
			let hasApplePay = false;
			let visible_buttons = [];
			let new_responses = [];
			let resolved_count = 0;
			const total = promises.length;

			// The express buttons on the native WooCommerce checkout live inside this
			// fieldset (cart / product / FunnelKit checkout do not have it), so its
			// presence uniquely identifies the native checkout. On the native checkout we
			// render the buttons directly with no loading shimmer/skeleton overlay.
			const isNativeCheckout = $('#fkwcs-expresscheckout-fieldset').length > 0;

			const processResponse = function(response) {
				if (null === response) {
					return;
				}
				let $selectorParent = $(response.selectorParent);
				let $selector = $(response.selector);
				if (response.noMethods || false === response.availablePaymentMethods) {
					if ($selectorParent.attr('data-fkwcs-available') === 'yes') {
						return;
					}
					$selectorParent.hide();
					$selector.hide();
					return;
				}
				if (response.hasOwnProperty('availablePaymentMethods')) {
					if ('yes' === fkwcs_data.apple_pay_individual && response.availablePaymentMethods) {
						if (typeof response.availablePaymentMethods === 'object') {
							response.availablePaymentMethods.googlePay = false;
							response.availablePaymentMethods.link = false;
							response.availablePaymentMethods.amazonPay = false;
							if (response.availablePaymentMethods.applePay === true) {
								hasApplePay = true;
							}
						}
					}

					if (response.button_type && typeof response.availablePaymentMethods === 'object' && response.availablePaymentMethods !== null && response.availablePaymentMethods.hasOwnProperty(response.button_type) && response.availablePaymentMethods[response.button_type] === false) {
						$selectorParent.hide();
						$selector.hide();
						return;
					}

					let hasMethodInResponse = false;
					if (typeof response.availablePaymentMethods === 'object' && response.availablePaymentMethods !== null) {
						for (let i in response.availablePaymentMethods) {
							if (response.availablePaymentMethods[i] === true) {
								buttons[i] = true;
								hasMethodInResponse = true;
							}
						}
					} else if (response.already_exist === 'yes') {
						buttons[response.button_type] = true;
						hasMethodInResponse = true;
					}

					if (!hasMethodInResponse) {
						$selectorParent.hide();
						$selector.hide();
						return;
					}

					new_responses.push(response);
				}
			};

			const onAllDone = function() {


				// The native checkout renders buttons directly with no loading shimmer, so
				// reveal them immediately; other contexts keep their original short delay.
				const revealDelay = isNativeCheckout ? 0 : 300;

				/**
				 * Phase 1 — Prepare each available slot. The native checkout shows no
				 * skeleton overlay; other contexts show the loading placeholder.
				 */
				for(let response of new_responses) {
					let $selectorParent = $(response.selectorParent);
					if($selectorParent.attr('data-fkwcs-available') !== 'yes'){
						continue;
					}
					$selectorParent.show().css('display', 'block');
					const smartButtonContainer = $selectorParent.closest('.wfacp_smart_button_container');
					if (smartButtonContainer.length) {
						smartButtonContainer.show();
					}

					if(response.button_type === 'googlePay'){
						$(document.body).trigger('fkwcs_express_google_need_to_show_button');
					}
					if (!isNativeCheckout) {
						$selectorParent.find('.fkwcs_smart_button_trigger').append('<span></span>');
						$selectorParent.find('.fkwcs_smart_button_trigger').removeClass('hide').show();
					}
					visible_buttons.push($selectorParent);
				}

				// If Apple Pay individual is enabled but Apple Pay is not available, hide buttons
				if ('yes' === fkwcs_data.apple_pay_individual && !hasApplePay) {
					$('.fkwcs_smart_button_trigger').addClass('hide').hide();
					$('.fkwcs_smart_buttons').hide();
					return;
				}

				/**
				 * Phase 2 — Reveal the Stripe-mounted buttons.
				 */
				setTimeout(() => {
					for(let response of new_responses) {
						let $selectorParent = $(response.selectorParent);
						if($selectorParent.attr('data-fkwcs-available') !== 'yes'){
							continue;
						}
						let $selector = $(response.selector);
						$selector.show().css({'display': 'block', 'visibility': 'visible'});
						$selectorParent.find('.fkwcs_smart_buttons').show().css('visibility', 'visible');
						$selector.parents('.fkwcs_stripe_smart_button_wrapper').show();
						$selector.find('.__PrivateStripeElement').each(function() {
							var marginVal = $selector.hasClass('fkwcs_smart_checkout_button') ? '0' : '0 -4px';
							this.style.setProperty('margin', marginVal, 'important');
						});
						$selector.find('iframe').each(function() {
							this.style.setProperty('margin', '0', 'important');
							this.style.setProperty('width', '100%', 'important');
						});
						$selector.addClass('fkwcs_smart_button_shown');
					}

					$(document.body).trigger('fkwcs_new_express_smart_buttons_showed', [true, buttons]);
					$(document.body).trigger('fkwcs_smart_buttons_showed', [true, buttons]);

					if (isNativeCheckout) {
						// No shimmer on the native checkout — ensure no skeleton overlay remains.
						$('#fkwcs-expresscheckout-fieldset .fkwcs_smart_button_trigger').addClass('hide').hide();
					} else {
						setTimeout(() => {
							$('.fkwcs_smart_button_trigger').addClass('hide').hide();
						}, visible_buttons.length*500);
					}
				}, revealDelay);
			};

			promises.forEach(function(p) {
				Promise.race([
					p,
					new Promise((resolve) => setTimeout(() => {
                        resolve({
                            result: 'timeout',
                            noMethods: true,
                            availablePaymentMethods: false,
                            button_type: p._meta ? p._meta.button_type : 'unknown',
                            selector: p._meta ? p._meta.selector : undefined,
                            selectorParent: p._meta ? p._meta.selectorParent : undefined,
                            context: p._meta ? p._meta.context : undefined,
                        });
                    }, PER_PROMISE_TIMEOUT_MS))
				]).then(function(response) {
					processResponse(response);
					resolved_count++;
					if (resolved_count === total) {
						onAllDone();
					}
				}).catch(function(e) {
					if ('yes' === fkwcs_data.debug_log) {
						console.log('handleButtonPromise error', e);
					}
					resolved_count++;
					if (resolved_count === total) {
						onAllDone();
					}
				});
			});
        }

		setupExpressCheckoutAeroCheckout(action = '', _event='') {
            if (this._isGooglePayInstance) {
                return;
            }
            try {



                const setupButtons = () => {
                    let promises = [];
                    if (this.applePayEnabled()) {
                        let apple_pay = generateExpressButton(this, SELECTOR_CONFIGS.aeroCheckout.applePay.selector, SELECTOR_CONFIGS.aeroCheckout.applePay.parent, PAYMENT_METHOD_CONFIGS.applePay, 'applePay','aeroCheckout');
                        promises.push(apple_pay);
                    }
                                            if ('yes' === fkwcs_data.enable_gateways?.fkwcs_stripe_google_pay) {
                            let gpay = generateExpressButton(this, SELECTOR_CONFIGS.aeroCheckout.googlePay.selector, SELECTOR_CONFIGS.aeroCheckout.googlePay.parent, PAYMENT_METHOD_CONFIGS.googlePay, 'googlePay','aeroCheckout');
                            promises.push(gpay);
                        }

                    if (this.linkEnabled()) {
                        let link_pay = generateExpressButton(this, SELECTOR_CONFIGS.aeroCheckout.link.selector, SELECTOR_CONFIGS.aeroCheckout.link.parent, PAYMENT_METHOD_CONFIGS.link, 'link','aeroCheckout');
                        promises.push(link_pay);
                    }
                    if (this.amazonPayEnabled()) {
                        let amazon_pay = generateExpressButton(this, SELECTOR_CONFIGS.aeroCheckout.amazonPay.selector, SELECTOR_CONFIGS.aeroCheckout.amazonPay.parent, PAYMENT_METHOD_CONFIGS.amazonPay, 'amazonPay','aeroCheckout');
                        promises.push(amazon_pay);
                    }
					this.handleButtonPromise(promises);

                };

                if (action === 'init') {
                    if (document.readyState !== 'loading') {
                        setupButtons();
                    } else {
                        window.addEventListener('DOMContentLoaded', setupButtons);
                    }
                } else {
                    setupButtons();
                }

            } catch (e) {
                console.log('info',e);
            }
        }

        setupExpressCheckoutNativeCheckout(_event) {

			let context='native';

            const setupButtons = () => {
                let promises = [];
                if (this.applePayEnabled()) {
                    let apple_pay = generateExpressButton(this, '.fkwcs_stripe_smart_button_wrapper.checkout .fkwcs_apple_pay_button .fkwcs_smart_checkout_button', '.fkwcs_stripe_smart_button_wrapper.checkout .fkwcs_apple_pay_button', PAYMENT_METHOD_CONFIGS.applePay, 'applePay', context);
                    promises.push(apple_pay);
                }
                    if ('yes' === fkwcs_data.enable_gateways?.fkwcs_stripe_google_pay) {
                        let gpay = generateExpressButton(this, '.fkwcs_stripe_smart_button_wrapper.checkout .fkwcs_google_pay_button .fkwcs_smart_checkout_button', '.fkwcs_stripe_smart_button_wrapper.checkout .fkwcs_google_pay_button', PAYMENT_METHOD_CONFIGS.googlePay, 'googlePay', context);
                        promises.push(gpay);
                    }

                if (this.linkEnabled()) {
                    let link_pay = generateExpressButton(this, '.fkwcs_stripe_smart_button_wrapper.checkout .fkwcs_link_pay_button .fkwcs_smart_checkout_button', '.fkwcs_stripe_smart_button_wrapper.checkout .fkwcs_link_pay_button', PAYMENT_METHOD_CONFIGS.link, 'link', context);
                    promises.push(link_pay);
                }

                if (this.amazonPayEnabled()) {
                    let amazon_pay = generateExpressButton(this, '.fkwcs_stripe_smart_button_wrapper.checkout .fkwcs_amazon_pay_button .fkwcs_smart_checkout_button', '.fkwcs_stripe_smart_button_wrapper.checkout .fkwcs_amazon_pay_button', PAYMENT_METHOD_CONFIGS.amazonPay, 'amazonPay', context);
                    promises.push(amazon_pay);
                }

                this.handleButtonPromise(promises);
                // Out-of-box de-dupe: if another express-checkout section (e.g. FunnelKit PayPal)
                // already renders a heading with the same label, hide ours to avoid two identical
                // "Express Checkout" headings. Keeps the fieldset box + separator intact.
                this.dedupeExpressCheckoutHeading();
            };

            if (document.readyState !== 'loading') {
                setupButtons();
            } else {
                window.addEventListener('DOMContentLoaded', setupButtons);
            }
        }

        dedupeExpressCheckoutHeading() {
            const $fieldset = $('#fkwcs-expresscheckout-fieldset');
            // Native standard-checkout path only — never assume this DOM exists inside an Aero page.
            if ($fieldset.length === 0) {
                return;
            }
            const $legend = $fieldset.children('legend').first();
            if ($legend.length === 0) {
                return;
            }
            const ourLabel = $.trim($legend.text()).toLowerCase();
            if (ourLabel === '') {
                return;
            }
            let duplicateFound = false;
            // Look for a sibling heading/legend OUTSIDE our fieldset carrying the same label.
            $('legend, h1, h2, h3, h4, h5, h6').each(function () {
                if (this === $legend[0] || $.contains($fieldset[0], this)) {
                    return;
                }
                if ($.trim($(this).text()).toLowerCase() === ourLabel) {
                    duplicateFound = true;
                    return false;
                }
            });
            if (duplicateFound) {
                $legend.hide();
            }
        }

        setupExpressOnCart() {
            const setupButtons = () => {
                let promises = [];
                if (this.applePayEnabled()) {
                    let apple_pay = generateExpressButton(this, '.fkwcs_apple_pay_button.cart .fkwcs_smart_buttons', '.fkwcs_apple_pay_button.cart', PAYMENT_METHOD_CONFIGS.applePay, 'applePay', 'cart');
                    promises.push(apple_pay);
                }
                    if ('yes' === fkwcs_data.enable_gateways?.fkwcs_stripe_google_pay) {
                        let gpay = generateExpressButton(this, '.fkwcs_google_pay_button.cart .fkwcs_smart_buttons', '.fkwcs_google_pay_button.cart', PAYMENT_METHOD_CONFIGS.googlePay, 'googlePay', 'cart');
                        promises.push(gpay);
                    }
                if (this.linkEnabled()) {
                    let link_pay = generateExpressButton(this, '.fkwcs_link_pay_button.cart .fkwcs_smart_buttons', '.fkwcs_link_pay_button.cart', PAYMENT_METHOD_CONFIGS.link, 'link', 'cart');
                    promises.push(link_pay);
                }
                if (this.amazonPayEnabled()) {
                    let amazon_pay = generateExpressButton(this, '.fkwcs_amazon_pay_button.cart .fkwcs_smart_buttons', '.fkwcs_amazon_pay_button.cart', PAYMENT_METHOD_CONFIGS.amazonPay, 'amazonPay', 'cart');
                    promises.push(amazon_pay);
                }
                this.handleButtonPromise(promises);
            };

            if (document.readyState !== 'loading') {
                setupButtons();
            } else {
                window.addEventListener('DOMContentLoaded', setupButtons);
            }
        }

        setupExpressOnProduct() {
            const setupButtons = () => {
                let promises = [];
                if (this.applePayEnabled()) {
                    let apple_pay = generateExpressButton(this, SELECTOR_CONFIGS.product.applePay.selector, SELECTOR_CONFIGS.product.applePay.parent, PAYMENT_METHOD_CONFIGS.applePay, 'applePay', 'product');
                    promises.push(apple_pay);
                }
									if ('yes' === fkwcs_data.enable_gateways?.fkwcs_stripe_google_pay) {
						let gpay = generateExpressButton(this, SELECTOR_CONFIGS.product.googlePay.selector, SELECTOR_CONFIGS.product.googlePay.parent, PAYMENT_METHOD_CONFIGS.googlePay, 'googlePay', 'product');
						promises.push(gpay);
					}
                if (this.linkEnabled()) {
                    let link_pay = generateExpressButton(this, SELECTOR_CONFIGS.product.link.selector, SELECTOR_CONFIGS.product.link.parent, PAYMENT_METHOD_CONFIGS.link, 'link', 'product');
                    promises.push(link_pay);
                }
                if (this.amazonPayEnabled()) {
                    let amazon_pay = generateExpressButton(this, SELECTOR_CONFIGS.product.amazonPay.selector, SELECTOR_CONFIGS.product.amazonPay.parent, PAYMENT_METHOD_CONFIGS.amazonPay, 'amazonPay', 'product');
                    promises.push(amazon_pay);
                }
                this.handleButtonPromise(promises);
            };

            if (document.readyState !== 'loading') {
                setupButtons();
            } else {
                window.addEventListener('DOMContentLoaded', setupButtons);
            }
        }

        setupExpressCheckoutButton(action = '',event='') {
            if ('yes' === fkwcs_data.is_checkout) {
                if ($('#wfacp_aero_checkout_id').length > 0 && typeof wfacp_frontend=='object' && ('true' == wfacp_frontend.enable_smart_buttons || '1' == wfacp_frontend.enable_smart_buttons)) {
                    this.setupExpressCheckoutAeroCheckout(action,event);
                } else {
                    this.setupExpressCheckoutNativeCheckout(event);
                }

            } else if (isTruthyFlag(fkwcs_data.is_cart)) {
                this.setupExpressOnCart();

            } else if ('yes' === fkwcs_data.is_product_page || '1' === fkwcs_data.is_product_page) {
                this.setupExpressOnProduct();
            }

            this.buttonOnProductPage();
            this.buttonOnCartPage();
            this.buttonOnCheckoutPage();
        }

        setupPaymentRequest() {

        }


        expressButtonReady(availablePaymentMethods, fkCartElements) {
            if (availablePaymentMethods) {
                // If Apple Pay individual is enabled but Apple Pay is not available, hide buttons
                if ('yes' === fkwcs_data.apple_pay_individual) {
                    let hasApplePay = false;
                    if (typeof availablePaymentMethods === 'object') {
                        hasApplePay = availablePaymentMethods.applePay === true;
                    } else if (availablePaymentMethods === true) {
                        // If it's just true, we need to check if Apple Pay is actually available
                        // This will be handled by Stripe Elements itself, but we can't determine here
                        // So we'll let it pass and Stripe will handle it
                        hasApplePay = true;
                    }
                    if (!hasApplePay) {
                        $('.fkwcs_smart_buttons').hide();
                        return;
                    }
                }

                if ('yes' === fkwcs_data.is_product) {
                    this.productEvents(fkCartElements);
                }
            }

        }

        expressClick(event, context = '', update_element = '') {
            // The express click adds the product to cart, which triggers a cart fragment refresh that
            // re-runs button generation. Re-mounting a wallet button WHILE its click/popup is in progress
            // breaks it — for Amazon Pay it tears down the active checkout session ("You can't continue").
            // Flag the click so the existing-button path skips the unmount+remount until it settles.
            this._expressClickActive = true;
            clearTimeout(this._expressClickTimer);
            this._expressClickTimer = setTimeout(() => { this._expressClickActive = false; }, 30000);
            $('.fkwcs_smart_product_button').trigger('click');

            let shippingAddressRequired = 'yes' === (fkwcs_data && fkwcs_data.shipping_required);

            // Honor the "Disable Shipping Info in Payment Wallet" setting for the wallet actually
            // being used. Keying off the clicked wallet (event.expressPaymentType) covers both the
            // express button and the inline methods. The old code keyed off the selected
            // payment-method radio, so it missed the express-button case for Apple Pay and did not
            // handle Google Pay at all.
            if ('yes' === fkwcs_data.is_checkout) {
                if ('apple_pay' === event.expressPaymentType && 'yes' === fkwcs_data.apple_pay_disable_shipping) {
                    shippingAddressRequired = false;
                }
                if ('google_pay' === event.expressPaymentType && 'yes' === fkwcs_data.google_pay_disable_shipping) {
                    shippingAddressRequired = false;
                }
            }

            const options = {
                emailRequired: true,
                phoneNumberRequired: true,
                shippingAddressRequired: shippingAddressRequired,
            };
            //Set pending Shipping for fetching during the payment button need a shipping
            if (shippingAddressRequired) {
                options.shippingRates = [{id: 'pending', displayName: 'Pending', amount: 0}];
            }
            event.resolve(options);
            // Only the PRODUCT-page express button should add the currently viewed product to the
            // cart on click. The slide-cart (fkcart) / cart / checkout buttons must pay for the
            // EXISTING cart — adding again there refreshes cart fragments and re-mounts the express
            // element, which kills a just-opened Google Pay popup and destroys the live Amazon Pay
            // session ("can't continue"). GPay (native) already only adds on the product page.
            if ('yes' === fkwcs_data.is_product && 'product' === context) {
                this.addToCartProduct(update_element);
            }
        }


        /**
         * Determine whether the current product is a variable product for which no
         * variation has been selected yet (i.e. variation_id is empty/0).
         *
         * @return {boolean}
         */
        isVariableProductWithoutSelection() {
            let variation_form = $('form.variations_form.cart');
            if (variation_form.length === 0) {
                return false;
            }
            return !(parseInt(variation_form.find('input.variation_id, input[name="variation_id"]').val(), 10) > 0);
        }

        productEvents(event) {
            let self = this;

            let single_add_to_cart_button = $('div.fkwcs_smart_product_button');
            // For variable products, keep the express buttons disabled until a valid variation is selected.
            if (self.isVariableProductWithoutSelection()) {
                single_add_to_cart_button.addClass('fkwcs_disabled_btn');
            }
            // Namespaced so the native Google Pay handler (which also listens on
            // show_variation/hide_variation) doesn't clobber this one via .off().
            $(document.body).off('show_variation.fkwcsExpress').on('show_variation.fkwcsExpress', function (event, variation, purchasable) {

                if (purchasable) {
                    single_add_to_cart_button.removeClass('fkwcs_disabled_btn');
                } else {
                    single_add_to_cart_button.addClass('fkwcs_disabled_btn');
                }
            });
            $(document.body).off('hide_variation.fkwcsExpress').on('hide_variation.fkwcsExpress', function () {

                single_add_to_cart_button.addClass('fkwcs_disabled_btn');
            });
            $(document.body).off('woocommerce_variation_has_changed').on('woocommerce_variation_has_changed', function () {
                self.updateSelectedProductsData(event);
            });
            $('form.cart .quantity').off('input').on('input', '.qty', function () {
                self.updateSelectedProductsData(event);
            });
            $(document.body).on('click', 'div.single_add_to_cart_button', function () {
                self.single_product_add_to_cart_click = true;// Detect container click manually;
            })

        }

        updateSelectedProductsData(expressCheckoutElement) {
            $.when(this.prepareSelectedProductData()).then((response) => {
                /**
                 * Trigger error here
                 */
                if (response.error) {
                    this.showErrorMessage(response.error);
                } else {
                    /**
                     * update the payment request
                     */
                    $.when(expressCheckoutElement.update({
                        total: response.total,
                        displayItems: response.displayItems || [],
                    })).then(function () {
                    });
                }
            });
        }

        parseJSONFromResponse(response) {
            // Regular expression to find JSON-like content
            const jsonMatch = response.match(/\{(?:[^{}]|(\{[^{}]*\}))*\}/);
            if (jsonMatch) {
                try {
                    // Attempt to parse the matched JSON
                    return JSON.parse(jsonMatch[0]);
                } catch (e) {
                    console.error('Failed to parse JSON:', e);
                    return null;
                }
            } else {
                console.warn('No JSON object found in response.');
                return null;
            }
        }

        /**
         * Process Payment when funnelkit slide cart button click
         * @param event
         * @param fkCartElements Stripe.elements instance for the fkCart
         * @param context The button's registration context (e.g. 'fkcart', 'cart', 'product', 'native') — identifies where the click actually came from for logging.
         */
        onPaymentMethodFkcart(event, fkCartElements, context = '') {
            this.processPayment(event, fkCartElements, context);
        }

        /**
         * CB for the payment method selection during express checkout button
         * @param event
         */
        onPaymentMethod(event) {
            this.processPayment(event, this.elements)
        }

        processPayment(event, element, context = '') {
            this.current_processing_elements = element;
            let FormEl = $('form.woocommerce-checkout');
            FormEl.addClass('processing');
            FormEl.block(this.block_data);

            // Amazon Pay's element is not in paymentMethodCreation:'manual' mode, so
            // createPaymentMethod() throws. Use a confirmation token (same as the official
            // Stripe plugin's Express Checkout Element); the server creates + confirms the
            // intent and returns the Amazon redirect URL.
            if (event && 'amazon_pay' === event.expressPaymentType) {
                this.processAmazonExpress(event, element, FormEl, context);
                return;
            }

            let payment_submit = element.submit();
            let createPayment_method = payment_submit.then(() => {
                return this.stripe.createPaymentMethod({elements: element});
            });
            let order_created = createPayment_method.then((paymentMethodObj) => {
                let n_event = {...event, paymentMethod: paymentMethodObj.paymentMethod};
                let payment_data = this.paymentMethodData(n_event, context);

                const checkout_nonce = payment_data['woocommerce-process-checkout-nonce'] || (fkwcs_data.checkout_nonce || '');
                if (checkout_nonce) {
                    payment_data['woocommerce-process-checkout-nonce'] = checkout_nonce;
                }

                return $.when($.ajax({
                    type: 'POST',
                    data: payment_data,
                    dataType: 'text', // Set to 'text' to handle any extra text around JSON
                    url: this.ajaxEndpoint('wc_stripe_create_order')
                }));
            });

            order_created.then((responseText) => {
                // Parse the JSON from response text
                const response = this.parseJSONFromResponse(responseText);
                // Proceed only if valid JSON was parsed
                if (response && response.result === 'success') {
                    if (false === this.confirmPaymentIntent(event, response.redirect)) {
                        FormEl.addClass('processing');
                        FormEl.block(this.block_data);
                        window.location = response.redirect;
                    }
                } else {
                    FormEl.removeClass('processing');
                    FormEl.unblock();
                    this.abortPayment(event, response ? response.messages : 'Error processing payment');
                    // The express-button order failed server-side (e.g. shipping address required
                    // because it was hidden in the wallet). Route the customer to the inline wallet
                    // gateway of the SAME type so that, after they fill the missing details, they can
                    // finish through the working checkout-form flow instead of re-tapping the button.
                    this.maybeSelectWalletGatewayFallback(event);
                }
            })
            createPayment_method.catch((error) => {
                FormEl.removeClass('processing');
                FormEl.unblock();
                console.log(error)
                this.abortPayment(event, this.formatStripeError(error));
            });
            payment_submit.catch((error) => {
                FormEl.removeClass('processing');
                FormEl.unblock();
                console.log(error)
                this.abortPayment(event, this.formatStripeError(error));
            });
        }

        /**
         * Amazon Pay express checkout — confirmation-token flow (mirrors the official Stripe plugin).
         * createConfirmationToken works on a non-manual element; the server creates + confirms the
         * PaymentIntent with the token and returns the Amazon redirect URL.
         */
        processAmazonExpress(event, element, FormEl, context = '') {
            element.submit().then(() => {
                return this.stripe.createConfirmationToken({elements: element});
            }).then((result) => {
                if (result.error) {
                    FormEl.removeClass('processing');
                    FormEl.unblock();
                    this.abortPayment(event, this.formatStripeError(result));
                    return;
                }
                let payment_data = this.paymentMethodData(event, context);
                payment_data.payment_method = 'fkwcs_stripe_amazon_pay';
                payment_data.fkwcs_confirmation_token = result.confirmationToken.id;
                delete payment_data.fkwcs_source;
                const checkout_nonce = payment_data['woocommerce-process-checkout-nonce'] || (fkwcs_data.checkout_nonce || '');
                if (checkout_nonce) {
                    payment_data['woocommerce-process-checkout-nonce'] = checkout_nonce;
                }
                $.ajax({
                    type: 'POST',
                    data: payment_data,
                    dataType: 'text',
                    url: this.ajaxEndpoint('wc_stripe_create_order')
                }).done((responseText) => {
                    const response = this.parseJSONFromResponse(responseText);
                    if (response && response.result === 'success') {
                        window.location = response.redirect;
                    } else {
                        FormEl.removeClass('processing');
                        FormEl.unblock();
                        this.abortPayment(event, response ? response.messages : 'Error processing payment');
                    }
                }).fail(() => {
                    FormEl.removeClass('processing');
                    FormEl.unblock();
                });
            }).catch((error) => {
                FormEl.removeClass('processing');
                FormEl.unblock();
                console.log(error);
            });
        }

        /**
         * A fresh guest's page-render checkout nonce predates the WC session that any
         * add-to-cart request creates, so WooCommerce would verify it against a different
         * user id and reject the express order (failure + refresh:true). Every add-to-cart
         * fragment payload (the product page's own fkwcs_add_to_cart call, a normal
         * WooCommerce add-to-cart, or an FK Cart fragment refresh) carries a nonce minted
         * during that request — same session identity the verification will use — so swap
         * it into fkwcs_data.checkout_nonce wherever such fragments land.
         * fkwcs_google_pay_data.nonces is the legacy carrier kept as a fallback (only
         * present when the Google Pay gateway is enabled).
         *
         * @param fragments
         */
        refreshCheckoutNonceFromFragments(fragments) {
            if (!fragments) {
                return;
            }
            const fresh_nonces = fragments.fkwcs_nonces || (fragments.fkwcs_google_pay_data && fragments.fkwcs_google_pay_data.nonces) || null;
            if (fresh_nonces && fresh_nonces.checkout_nonce) {
                fkwcs_data.checkout_nonce = fresh_nonces.checkout_nonce;
            }
        }

        addToCartProduct(update_element = '') {
            let productId = $('.single_add_to_cart_button').val();
            let single_var = $('.single_variation_wrap');

            let variation_form = $('form.variations_form.cart')

            if (variation_form.length > 0 && parseInt(variation_form.find('.variation_id').val(), 10) === 0) {
                console.log('variation_id is empty');
                return;
            }
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
                if (field.name === 'sublium-option-plan') {
                    productData[field.name] = field.value;
                }
            });
            return $.ajax({
                type: 'POST',
                data: productData,
                url: this.ajaxEndpoint(this.add_to_cart_end_point),
                success: (response) => {
                    if (response && response.fragments) {
                        this.refreshCheckoutNonceFromFragments(response.fragments);
                    }
                    if (typeof update_element == "function") {
                        update_element(response.fragments.fkwcs_cart_details.order_data);
                    }
                    if (typeof Storage !== 'undefined' && response && response.fragments) {
                        try {
                            if (typeof wc_cart_fragments_params !== 'undefined') {
                                sessionStorage.setItem(wc_cart_fragments_params.fragment_name, JSON.stringify(response.fragments));
                                localStorage.setItem(wc_cart_fragments_params.cart_hash_key, response.cart_hash);
                                sessionStorage.setItem(wc_cart_fragments_params.cart_hash_key, response.cart_hash);
                            }

                            if (typeof fkcart_app_data !== 'undefined') {
                                sessionStorage.setItem(fkcart_app_data.fragment_name, JSON.stringify(response.fragments));
                                localStorage.setItem(fkcart_app_data.cart_hash_key, response.cart_hash);
                                sessionStorage.setItem(fkcart_app_data.cart_hash_key, response.cart_hash);
                            }
                        } catch (e) {
                        }
                    }
                    try {
                        $(document.body).trigger('added_to_cart', [
                            response.fragments,
                            response.cart_hash,
                            $('.single_add_to_cart_button'),
                        ]);
                    } catch (e) {
                    }
                },
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
                type: 'POST',
                data: data,
                url: this.ajaxEndpoint('fkwcs_selected_product_data'),
            });
        }

        logError(error, failed = false) {
            $.ajax({
                type: 'POST',
                url: fkwcs_data.admin_ajax,
                data: {
                    action: 'fkwcs_js_errors',
                    _security: fkwcs_data.js_nonce,
                    failed: failed,
                    error: error,
                },
            });
        }

        getVariationAttributes() {
            let variation_forms = $('.variations_form');
            let select_list = variation_forms.find('.variations select');
            let attributes = {};
            let count = 0,
                chosen = 0;
            select_list.each(function () {
                let name = $(this).data('attribute_name') || $(this).attr('name');
                attributes[name] = $(this).val() || '';
                count++;
            });
            return {
                count,
                chosenCount: chosen,
                attributes,
            };
        }

        /**
         * Prepare Payment method data to pass onto confirm button
         * @param event
         * @returns {*|{billing_last_name: (*|string), billing_phone: (*|string|string), payment_request_type: null, billing_country: (*|string), billing_city: (*|string), fkwcs_nonce: *, billing_company: string, billing_state: (*|string), terms: number, billing_address_1: (*|string), shipping_method: *[], order_comments: string, billing_email: (*|string), billing_address_2: (*|string), billing_postcode: (*|string), fkwcs_source, billing_first_name: (*|string), payment_method: string}}
         * @constructor
         */
        paymentMethodData(event, context = '') {
            /**
             * Gather Data from the chosen method
             */
            const paymentMethod = event.paymentMethod;
            const billingDetails = event.billingDetails;
            const email = billingDetails.email;
            const phone = billingDetails.phone;
            const billing = billingDetails.address;
            const name = billingDetails.name;
            const shipping = event && event.shippingAddress;
            this.express_request_type = event.expressPaymentType;
            /**
             * Prepare Data
             * @type {{billing_last_name: (*|string), billing_phone: (*|string|string), payment_request_type: null, billing_country: (*|string), billing_city: (*|string), fkwcs_nonce: *, billing_company: string, billing_state: (*|string), terms: number, billing_address_1: (*|string), shipping_method: *[], order_comments: string, billing_email: (*|string), billing_address_2: (*|string), billing_postcode: (*|string), fkwcs_source, billing_first_name: (*|string), payment_method: string}}
             */
            let data = {
                fkwcs_nonce: fkwcs_data.fkwcs_nonce,
                billing_first_name: null !== name ? name.split(' ').slice(0, 1).join(' ') : 'test',
                billing_last_name: null !== name ? name.split(' ').slice(1).join(' ') : 'test',
                billing_company: '',
                billing_email: null !== email ? email : '',
                billing_phone: null !== phone ? phone : '',
                order_comments: '',
                payment_method: 'fkwcs_stripe',
                terms: 1,
                fkwcs_source: paymentMethod ? paymentMethod.id : '',
                payment_request_type: event.expressPaymentType,
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
            if (this.express_request_type === 'amazon_pay') {
                data.payment_method = 'fkwcs_stripe_amazon_pay';
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
                 * Check if a regular payment method radio button is selected
                 * If yes, prioritize WooCommerce checkout form data over express checkout data
                 */
                let selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
                let isRegularPaymentMethodSelected = selectedPaymentMethod &&
                    (selectedPaymentMethod === 'fkwcs_stripe_apple_pay' ||
                     selectedPaymentMethod === 'fkwcs_stripe_google_pay');

                /**
                 * Here the shippingoption that we get in return from payment request button is the prior one, so we need to set it checked
                 */
                if (event.shippingRate && event.shippingRate.id) {
                    $('input[name="shipping_method[0]"][value="' + event.shippingRate.id + '"]').prop('checked', true);
                }

                let formData = $('form[name=checkout]').serializeArray();

                /**
                 * If regular payment method is selected, prioritize form data over express checkout data
                 * Otherwise, use express checkout data as primary source and form data as fallback
                 */
                if (isRegularPaymentMethodSelected) {
                    // Start with form data as base - this ensures WooCommerce checkout form data takes priority
                    let formDataObj = {};
                    $.each(formData, function (i, field) {
                        formDataObj[field.name] = field.value;
                    });

                    // Ensure payment_method from form is used (not from express checkout)
                    if (selectedPaymentMethod) {
                        formDataObj.payment_method = selectedPaymentMethod;
                    }

                    // Merge express checkout data only for fields that are empty in form data
                    // But keep express checkout specific fields like fkwcs_source for payment processing
                    $.each(data, function (key, value) {
                        if (key === 'fkwcs_source' || key === 'payment_request_type' || key === 'fkwcs_nonce') {
                            // Keep express checkout specific fields needed for payment processing
                            formDataObj[key] = value;
                        } else if (!formDataObj.hasOwnProperty(key) || formDataObj[key] === '' || formDataObj[key] === null) {
                            // Only use express checkout data if form field is empty or missing
                            formDataObj[key] = value;
                        }
                        // Otherwise, form data takes priority (already in formDataObj)
                    });

                    data = formDataObj;
                } else {
                    // Express button: if the customer already filled the checkout form, that address
                    // is intentional and must win. Start from the form and pull express-checkout data
                    // only for the payment-processing fields and for fields the form left empty.
                    let formDataObj = {};
                    $.each(formData, function (i, field) {
                        formDataObj[field.name] = field.value;
                    });
                    $.each(data, function (key, value) {
                        if (key === 'fkwcs_source' || key === 'payment_request_type' || key === 'fkwcs_nonce' || key === 'payment_method') {
                            // Payment-critical fields must always come from the express checkout data
                            formDataObj[key] = value;
                        } else if (!formDataObj.hasOwnProperty(key) || formDataObj[key] === '' || formDataObj[key] === null) {
                            // Only use the wallet value where the form field is empty or missing
                            formDataObj[key] = value;
                        }
                        // Otherwise the customer-entered form value wins
                    });
                    data = formDataObj;
                }

                data.page_from = 'checkout';
            } else if ('fkcart' === context) {
                // The FunnelKit Cart slide-out drawer can open from the product page (add to
                // cart without a reload) or the cart page, so fkwcs_data.is_product/is_checkout
                // would misattribute the click to whichever page is still loaded underneath it.
                // The button's own registration context is the reliable signal for the drawer.
                data.page_from = 'fkcart';
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
            data.payment_request_type = this.express_request_type;

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
                address_data.shipping_first_name = shipping_data.name.split(' ').slice(0, 1).join(' ');
                address_data.shipping_last_name = shipping_data.name.split(' ').slice(1).join(' ');
                address_data.shipping_company = shipping_data && shipping_data.organization;
                address_data.shipping_country = shipping_data.address.country;
                address_data.shipping_address_1 = shipping_data.address.line1;
                address_data.shipping_address_2 = shipping_data.address.line2;
                address_data.shipping_city = shipping_data.address.city;
                address_data.shipping_state = shipping_data.address.state;
                address_data.shipping_postcode = shipping_data.address.postal_code;
                address_data.ship_to_different_address = 1;
                /**
                 * The wallet collected the shipping contact, so its phone belongs to the shipping
                 * section too (wallets expose a single phone, on billingDetails). Without this,
                 * checkouts with a required shipping_phone field (e.g. WFACP) would fail
                 * WooCommerce validation ("Shipping Phone is a required field").
                 */
                if (address_data.hasOwnProperty('billing_phone') && address_data.billing_phone) {
                    address_data.shipping_phone = address_data.billing_phone;
                }
            } else if ('yes' === fkwcs_data.express_copy_billing_to_shipping) {
                /**
                 * No shipping data from the wallet ("Disable Shipping Info in Payment Wallet"): the
                 * customer fills the shipping section — name, address, phone, any required field —
                 * on the checkout form, so the wallet's billing data is NOT copied into shipping by
                 * default; an empty required field should surface a validation error, not silently
                 * receive billing data. Merchants can opt in to copying the wallet's billing details
                 * into the shipping fields via the `fkwcs_express_copy_billing_to_shipping` filter
                 * (see Helper localized data). Form-typed values still win in the request merge, so
                 * the copy only fills fields the customer left empty.
                 */
                var fkwcsBillingToShipping = {
                    shipping_first_name: 'billing_first_name',
                    shipping_last_name: 'billing_last_name',
                    shipping_company: 'billing_company',
                    shipping_country: 'billing_country',
                    shipping_address_1: 'billing_address_1',
                    shipping_address_2: 'billing_address_2',
                    shipping_city: 'billing_city',
                    shipping_state: 'billing_state',
                    shipping_postcode: 'billing_postcode',
                    shipping_phone: 'billing_phone'
                };
                Object.keys(fkwcsBillingToShipping).forEach(function (shippingKey) {
                    var billingKey = fkwcsBillingToShipping[shippingKey];
                    if (address_data.hasOwnProperty(billingKey) && address_data[billingKey]) {
                        address_data[shippingKey] = address_data[billingKey];
                    }
                });
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
                window.location.href = hash;
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
        confirmPayment(event, clientSecret, redirectURL, intent_type, retry = false) {
            let confirm_data = {
                'elements': this.current_processing_elements,
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

                } else {

                    let intent = result['si' === intent_type ? 'setupIntent' : 'paymentIntent'];
                    if (false === retry && (intent.status === 'requires_action' || intent.status === 'requires_source_action')) {
                        this.confirmPayment(event, clientSecret, redirectURL, intent_type, true);
                    } else {
                        FormEl.addClass('processing');
                        FormEl.block(this.block_data);
                        window.location = redirectURL;
                    }
                }
            });
        }

        abortPayment(event, message) {
            // Signal failure to the Express Checkout Element so the wallet sheet closes WITHOUT
            // a success tick. Resolving as success (the previous behaviour) told the wallet the
            // payment went through even though the order failed server-side.
            if (typeof event.paymentFailed === 'function') {
                event.paymentFailed({reason: 'fail'});
            } else if (typeof event.resolve === 'function') {
                event.resolve({status: 'fail'});
            }
            this.showErrorMessage(message);
        }

        /**
         * After an express-button order attempt fails on the checkout page, select the inline
         * wallet gateway (radio) that matches the wallet the customer just used, so they can
         * complete via the standard checkout-form flow once they fix the reported issue
         * (typically a shipping address that was hidden in the wallet sheet).
         *
         * Does nothing outside the checkout page, or when the matching inline gateway is not
         * present/visible in the payment list (e.g. the merchant only enabled the express
         * button and not the "show as regular gateway" option).
         *
         * @param {object} event Stripe Express Checkout Element event (has expressPaymentType).
         * @return {void}
         */
        maybeSelectWalletGatewayFallback(event) {
            if ('yes' !== fkwcs_data.is_checkout) {
                return;
            }
            const type = (event && event.expressPaymentType) || this.express_request_type;
            let gatewayId = '';
            if ('apple_pay' === type) {
                gatewayId = 'fkwcs_stripe_apple_pay';
            } else if ('google_pay' === type) {
                gatewayId = 'fkwcs_stripe_google_pay';
            }
            if ('' === gatewayId) {
                return;
            }
            const $li = $('li.payment_method_' + gatewayId);
            // Available only if the gateway row exists, is not flagged hidden and is visible.
            if (0 === $li.length || $li.hasClass('fkwcs_display_none') || !$li.is(':visible')) {
                return;
            }
            const $radio = $li.find('input[name="payment_method"]');
            if (0 === $radio.length || $radio.is(':checked')) {
                return;
            }
            // Trigger click + change so both WooCommerce and the inline wallet gateway class
            // react (mounting the wallet button in place of Place Order).
            $radio.prop('checked', true).trigger('click').trigger('change');
        }

        mapShipping(options) {
            // ECE shippingRates accepts only id, displayName, amount and deliveryEstimate.
            // The server payload uses `label` and may carry `detail` (tax suffix) or
            // `description` (placeholder rate); any unknown key makes the ECE reject the
            // whole resolve() call, so fold those into deliveryEstimate and drop the rest.
            return options.map((item) => {
                const rate = {
                    id: item.id,
                    displayName: item.label || item.displayName || '',
                    amount: parseInt(item.amount, 10) || 0,
                };
                const estimate = item.detail || item.description || '';
                if (estimate) {
                    rate.deliveryEstimate = estimate;
                }
                return rate;
            });
        }

        /**
         * Shipping address selection change, responsible for new shipping methods
         * @param event
         * @returns {*}
         */
        shippingAddressChange(event, cb = '') {
            let address = event.address;
            let post_code = address.postalCode;
            if (address && address.postal_code) {
                post_code = address.postal_code;
            }
            let state = address && address.state;
            if (address && address.region) {
                state = address.region;
            }
            let data = {
                fkwcs_nonce: fkwcs_data.fkwcs_nonce,
                country: address.country,
                state: state,
                postcode: post_code,
                city: address.city,
                address: (address && address.addressLine && address.addressLine[0]) ? address.addressLine[0] : '',
                address_2: (address && address.addressLine && address.addressLine[1]) ? address.addressLine[1] : '',
                payment_request_type: event.express_request_type,
                is_product_page: this.is_product_page,
            };

            /**
             * If it's a checkout page, serialize the form and send it as post_data
             * This ensures checkout add-ons and other form fields are preserved
             */
            if (fkwcs_data.is_checkout === 'yes') {
                let formData = $("form[name=checkout]").serialize();
                data.post_data = formData;
            }

            $.ajax({
                type: 'POST',
                data: data,
                url: this.ajaxEndpoint('fkwcs_update_shipping_address'),
                success: (response) => {
                    if (response && 'success' === response.result) {
                        /**
                         * return back to String FW to show current items along with the status
                         */
                        if (typeof cb === 'function') {
                            cb(response);
                        }
                        if (this.elements && Number(response.total.amount) > 0) {
                            this.elements.update({amount: response.total.amount});
                        }
                        if (this.fkCartElements && Number(response.total.amount) > 0) {
                            this.fkCartElements.update({amount: response.total.amount});
                        }
                        event.resolve({shippingRates: this.mapShipping(response.shipping_methods)});
                        return;
                    }
                    // Any non-success response (including unexpected shapes) must reject —
                    // with neither resolve nor reject the wallet sheet hangs until timeout.
                    event.reject({status: 'fail'});
                },
                error: () => {
                    // Transport failure: reject so the wallet sheet closes instead of hanging.
                    event.reject({status: 'fail'});
                }
            });
        }

        shippingOptionChange(event, cb = '') {
            let shippingOption = event.shippingRate;
            const data = {
                fkwcs_nonce: fkwcs_data.fkwcs_nonce,
                shipping_method: [shippingOption.id],
                payment_request_type: this.express_request_type,
                is_product_page: this.is_product_page,
            };

            /**
             * If it's a checkout page, serialize the form and send it as post_data
             * This ensures checkout add-ons and other form fields are preserved
             */
            if (fkwcs_data.is_checkout === 'yes') {
                let formData = $("form[name=checkout]").serialize();
                data.post_data = formData;
            }

            $.ajax({
                type: 'POST',
                data: data,
                url: this.ajaxEndpoint('fkwcs_update_shipping_option'),
                success: (response) => {
                    if (response && 'success' === response.result) {
                        if (this.elements && Number(response.total.amount) > 0) {
                            this.elements.update({amount: response.total.amount});
                        }
                        if (this.fkCartElements && Number(response.total.amount) > 0) {
                            this.fkCartElements.update({amount: response.total.amount});
                        }
                        if (typeof cb === 'function') {
                            cb(response);
                        }
                        // Bare resolve() is the documented ECE form; 'status' is a legacy
                        // PaymentRequest key that Stripe merely tolerates today.
                        event.resolve();
                        return;
                    }
                    // Any non-success response (including unexpected shapes) must reject —
                    // with neither resolve nor reject the wallet sheet hangs until timeout.
                    event.reject({status: 'fail'});
                },
                error: () => {
                    // Transport failure: reject so the wallet sheet closes instead of hanging.
                    event.reject({status: 'fail'});
                },
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
            // Click/popup finished (cancelled) — allow the button to refresh/remount again.
            this._expressClickActive = false;
            clearTimeout(this._expressClickTimer);
            $(document.body).trigger('fkwcs_express_cancel_payment', this);
        }

        /**
         * Normalise a Stripe.js / AJAX error object into displayable error markup.
         * The message text is HTML-escaped before interpolation - it may echo
         * user-influenced input (e.g. card holder data) back from the API.
         * @param error
         * @returns {string}
         */
        formatStripeError(error) {
            let msg = '';
            if (error && error.error && error.error.message) {
                msg = error.error.message;
            } else if (error && error.message) {
                msg = error.message;
            }
            if (!msg) {
                msg = 'Error processing payment';
            }
            msg = $('<div/>').text(msg).html();
            return '<ul class="woocommerce-error" role="alert"><li>' + msg + '</li></ul>';
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
                window.scrollTo({
                    top: 100,
                    behavior: 'smooth',
                });
            } else {
                // Prefer the checkout form, but fall back to containers that exist on the basket
                // page (and elsewhere) so the error is always visible — `form.checkout` does not
                // exist on the cart page, which previously swallowed the error entirely.
                let $target = $('form.checkout').closest('form');
                if (!$target.length) {
                    $target = $('form.woocommerce-cart-form, .woocommerce-notices-wrapper, .fkwcs-express-checkout-wrapper').first();
                }
                if ($target.length) {
                    $target.before(message);
                } else {
                    $('body').prepend(message);
                }
                window.scrollTo({
                    top: 100,
                    behavior: 'smooth',
                });
            }
        }

        getCartDetails() {
            let data = {
                fkwcs_nonce: fkwcs_data.fkwcs_nonce,
            };
            let current = this;
            $.ajax({
                type: 'POST',
                data: data,
                url: this.ajaxEndpoint('fkwcs_get_cart_details'),
                success: (response) => {
                    if (response.success) {
                        /**
                         * return back to String FW to show current items along with the status
                         */
                        current.setRequestData(response.data);
                        current.setupPaymentRequest();
                    }
                },
            });
        }

        /**
         * WooCommerce events to modify data onto
         */
        wcEvents() {
            let self = this;
            window.addEventListener('keydown', function (e) {
                if ((e.key === 'F5' || (e.ctrlKey && e.key === 'r') || (e.metaKey && e.key === 'r')) && fkwcs_data.is_checkout === 'yes') {
                    $('.fkwcs_smart_button_trigger').removeClass('hide').show();
                }
            });

            window.addEventListener('unload', function () {
                if (fkwcs_data.is_checkout === 'yes') {
                    $('.fkwcs_smart_button_trigger').removeClass('hide').show();
                }
            });


            $(document.body).on('updated_checkout', function (_e, v) {
                try {
                    const d = v && v.fragments && v.fragments.fkwcs_cart_details;
                    if (d && d.order_data) {
                        self.setRequestData(d, 'updated_checkout');
                    } else {
                        $('#fkwcs_stripe_smart_button_wrapper').removeClass('fkwcs_hide_button');
                        $('#wfacp_smart_buttons').removeClass('fkwcs_hide_button');
                        $('#fkwcs-payment-request-separator').show();
                        self.getCartDetails();
                    }
                } catch (err) {
                    $('#fkwcs_stripe_smart_button_wrapper').removeClass('fkwcs_hide_button');
                    $('#wfacp_smart_buttons').removeClass('fkwcs_hide_button');
                    $('#fkwcs-payment-request-separator').show();
                    self.getCartDetails();
                }
            });

            $(document.body).on('updated_cart_totals', () => {
                self.getCartDetails();
            });
            $(document.body).on('wc_fragments_refreshed added_to_cart removed_from_cart wc_fragments_loaded', () => {
                    setTimeout(() => {
						let cart_fragment_name = 'wc_cart_fragments';
                        if (typeof wc_cart_fragments_params !== 'undefined') {
							cart_fragment_name = wc_cart_fragments_params.fragment_name;
                        }
							if(typeof fkcart_app_data !== 'undefined'){
								cart_fragment_name = fkcart_app_data.fragment_name;
							}

                        if (typeof Storage !== 'undefined') {
                            let json = sessionStorage.getItem(cart_fragment_name);
                            try {
                                json = JSON.parse(json);
                            } catch (e) {
                                return;
                            }
                            if (json === null || 'object' !== typeof json || Array.isArray(json) || !Object.prototype.hasOwnProperty.call(json, 'fkwcs_cart_details')) {
                                return;
                            }
                            this.cart_request_data = json.fkwcs_cart_details;

                            // The checkout page re-arms exclusively from the server-authoritative
                            // updated_checkout fragments (plus the localized cart_data at load).
                            // sessionStorage cart fragments can be arbitrarily stale here, and
                            // re-arming from them briefly re-priced the wallet elements with an
                            // outdated total — and could flip Amazon Pay's element back to payment
                            // mode on a $0-now (free trial) cart, producing a confirmation token
                            // the SetupIntent confirm rejects. Mirrors the added_to_cart guard.
                            if ('yes' === fkwcs_data.is_product || 'yes' === fkwcs_data.is_checkout) {
                                return;
                            }

                            self.setRequestData(json.fkwcs_cart_details);
                            self.setupPaymentRequest();

                        }
                    }, 300);
                });

            $(document.body).on('fkcart_update_wc_fragments', (e, v) => {
                let cart_fragment_name = 'wc_cart_fragments';
                if (typeof wc_cart_fragments_params !== 'undefined') {
                    cart_fragment_name = wc_cart_fragments_params.fragment_name;
                }
                if (typeof fkcart_app_data !== 'undefined') {
                    cart_fragment_name = fkcart_app_data.fragment_name;
                }
                let data = sessionStorage.getItem(cart_fragment_name);
                if (data) {
                    try {
                        data = JSON.parse(data);
                    } catch (err) {
                        return;
                    }
                }
                if (typeof data !== 'object' || data === null || Object.keys(data).length === 0) {
                    return;
                }
                if (v && Object.prototype.hasOwnProperty.call(v, 'fkwcs_cart_details')) {
                    data.fkwcs_cart_details = v.fkwcs_cart_details;
                }
                if (v && Object.prototype.hasOwnProperty.call(v, 'fkwcs_google_pay_data')) {
                    data.fkwcs_google_pay_data = v.fkwcs_google_pay_data;
                }
                sessionStorage.setItem(cart_fragment_name, JSON.stringify(data));
            });

            /**
             * FK Cart events added here to handle buttons and their data
             */
            $(document.body).on('fkwcs_express_button_init', () => {
                const fkcartSliderModal = $('#fkcart-modal');

                if ('yes' === fkwcs_data.is_product && !fkcartSliderModal.hasClass('fkcart-show')) {
                    return;
                }
                // Only build the slide-cart express buttons once the cart amount/data is available.
                // Generating with empty cart data makes the Express Checkout Element (Apple/Link/Amazon)
                // bail "amount is zero" and get hidden, then a late re-attempt times out — so on first
                // open only Google Pay (native) showed. When the data isn't ready yet, fkcart_fragments_refreshed
                // builds them as soon as it lands.
                if (Object.keys(this.cart_request_data).length > 0) {
                    this.setRequestData(this.cart_request_data, 'fkcart');
                    this.generateExpressButton('fkcart');
                }
            });
            /**
             * Warm the FK Cart drawer buttons the moment add-to-cart fragments land:
             * FK Cart only fires fkwcs_express_button_init from its 500ms-debounced
             * init_cart_dependency, but Stripe's wallet availability probes start at
             * element creation — every ms saved here comes straight off the time the
             * shopper waits for buttons in the drawer. The added_to_cart payload
             * carries fkwcs_cart_details directly; the later init call then hits the
             * already-mounted fast path (iframe still attached → no remount).
             */
            $(document.body).on('added_to_cart', (e, fragments) => {
                // Refresh ahead of the early-returns below: a normal (non-express) Add to
                // Cart click on the product page creates the WC session too, and the
                // FK Cart drawer it opens carries a wallet button the shopper can click
                // immediately — it needs this request's session-bound nonce, not the
                // page-render one.
                this.refreshCheckoutNonceFromFragments(fragments);
                if ('yes' === fkwcs_data.is_checkout) {
                    return;
                }
                if (!fragments || !Object.prototype.hasOwnProperty.call(fragments, 'fkwcs_cart_details')) {
                    return;
                }
                // Only when the drawer is about to open (or already open) — otherwise
                // leave the page's own request data (e.g. product) untouched.
                const willOpen = (typeof fkcart_app_data !== 'undefined' && fkcart_app_data.should_open_cart === 'yes') || $('#fkcart-modal').hasClass('fkcart-show');
                if (!willOpen) {
                    return;
                }
                // Defer one tick so FK Cart's own added_to_cart handler has injected
                // the fragment HTML (button slots) regardless of handler binding order.
                setTimeout(() => {
                    if ($('#fkcart-modal').find('.fkwcs_smart_cart_button').length === 0) {
                        return;
                    }
                    this.cart_request_data = fragments.fkwcs_cart_details;
                    this.setRequestData(this.cart_request_data, 'fkcart');
                    this.generateExpressButton('fkcart');
                }, 0);
            });
            $(document.body).on('fkwcs_express_button_update_cart_details', (e, v) => {
                this.refreshCheckoutNonceFromFragments(v);
                //return if cart details not found
                if (null == v || !Object.prototype.hasOwnProperty.call(v, 'fkwcs_cart_details')) {
                    return;
                }
                this.setRequestData(v.fkwcs_cart_details);
                this.generateExpressButton('fkcart');
            });
            $(document.body).on('fkcart_fragments_refreshed', (e, v) => {
                this.refreshCheckoutNonceFromFragments(v);
                const fkcartSliderModal = $('#fkcart-modal');
                //return if cart details not found
                if (null == v || !Object.prototype.hasOwnProperty.call(v, 'fkwcs_cart_details')) {
                    return;
                }
                this.cart_request_data = v.fkwcs_cart_details;
                if (fkcartSliderModal.hasClass('fkcart-show')) {
                    this.setRequestData(this.cart_request_data);
                    this.generateExpressButton('fkcart');
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
                    total: fkwcs_data.single_product.total,
                    requestShipping: 'yes' === fkwcs_data.single_product.requestShipping,
                    displayItems: fkwcs_data.single_product.displayItems || [],
                });
                this.setRequestData();
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
            // Function intentionally disabled
            return;
        }

        buttonOnCartPage() {
            // Function intentionally disabled
            return;
        }

        buttonOnCheckoutPage() {
            let billing_fields = $('.woocommerce-billing-fields');
            if (fkwcs_data.is_checkout !== 'yes' || billing_fields.length === 0) {
                return;
            }
           // this.setCss('#fkwcs_stripe_smart_button_wrapper', 'max-width', billing_fields.outerWidth(true));
            this.applyCss();
        }

        /**
         * Dynamic CSS for the express checkout button
         */
        expressBtnStyle() {
            /* let appearance = this.expressBtnStyleToStripeElement(wc_button_class);
             this.elements.update({appearance});*/
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
                    this.setCss('#fkwcs_stripe_smart_button_wrapper', 'marginTop', '10px');
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
            let linkOn = fkwcs_data.link_button_enabled === 'yes' || fkwcs_data.link_button_enabled === '1';
            let expressOn = fkwcs_data.express_pay_enabled === 'yes' || fkwcs_data.express_pay_enabled === '1';
            return linkOn && expressOn;
        }

        amazonPayEnabled() {
            let amazonOn = fkwcs_data.amazon_pay_button_enabled === 'yes' || fkwcs_data.amazon_pay_button_enabled === '1';
            let expressOn = fkwcs_data.express_pay_enabled === 'yes' || fkwcs_data.express_pay_enabled === '1';
            return amazonOn && expressOn;
        }

        applePayEnabled() {
            return 'yes' === fkwcs_data.enable_gateways?.fkwcs_stripe_apple_pay;
        }

    }


   new FKWCS_Smart_Buttons();


})(jQuery);
