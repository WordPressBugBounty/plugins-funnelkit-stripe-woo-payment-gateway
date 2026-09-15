/**
 * Admin "Pay for Order" backbone modal.
 *
 * Drives the modal rendered by admin/parts/html-order-pay.php: mounts a Stripe
 * Card Element, tokenizes a new card (or uses a saved token), submits to the
 * fkwcs_admin_pay_order AJAX handler, steps through 3D Secure when required and
 * reloads the order screen on success.
 *
 * Authored in ES5-compatible jQuery (shipped raw, no build step) to match the
 * existing admin.js convention.
 *
 * @since 1.14.1
 */
(function ($) {
	'use strict';

	var data = window.fkwcs_admin_order_data || {};

	// Nothing to do when Stripe is not configured for the active mode.
	if (!data.pub_key || typeof window.Stripe !== 'function') {
		return;
	}

	var stripe = window.Stripe(data.pub_key);
	var elements = null;
	var cardElement = null;
	var submitting = false;

	/**
	 * Resolve a localized string with a safe fallback.
	 */
	function i18n(key, fallback) {
		return (data.i18n && data.i18n[key]) ? data.i18n[key] : fallback;
	}

	function getModal() {
		return $('.wc-backbone-modal-content');
	}

	function blockModal() {
		var $modal = getModal();
		if ($modal.length && $.fn.block) {
			$modal.block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } });
		}
	}

	function unblockModal() {
		var $modal = getModal();
		if ($modal.length && $.fn.unblock) {
			$modal.unblock();
		}
	}

	function showError(message) {
		var $error = $('.fkwcs-pay-order-error');
		if ($error.length) {
			$error.text(message).show();
		}
	}

	function clearError() {
		$('.fkwcs-pay-order-error').hide().text('');
	}

	function fail(message) {
		submitting = false;
		unblockModal();
		showError(message || i18n('error_generic', 'Something went wrong. Please try again.'));
	}

	/**
	 * Render the order customer's saved cards as selectable radios.
	 *
	 * Token fields are inserted as text nodes (never raw HTML) to avoid any
	 * injection from stored card metadata.
	 */
	function renderSavedCards() {
		var $wrap = $('.fkwcs-saved-cards');
		if (!$wrap.length) {
			return;
		}
		$wrap.empty();

		var tokens = data.tokens || [];
		for (var i = 0; i < tokens.length; i++) {
			var token = tokens[i];
			var label = $('<label class="fkwcs-pay-order-option fkwcs-saved-card-option"></label>');
			var input = $('<input type="radio" name="fkwcs_pay_source" />').attr('value', token.id);
			if (i === 0) {
				input.prop('checked', true);
			}
			var text = ' ' + token.brand + ' •••• ' + token.last4 + ' (' + token.exp + ')';
			label.append(input).append(document.createTextNode(text));
			$wrap.append(label);
		}
	}

	/**
	 * Lazily create + mount the Card Element the first time a new card is chosen.
	 */
	function ensureCardElement() {
		if (cardElement) {
			return;
		}
		elements = stripe.elements();
		// Match the woo-stripe (Payment Plugins) admin modal Card Element config:
		//  - hidePostalCode: true  -> never prompt for ZIP/postal code
		//  - disableLink: true     -> hide the Stripe Link "Autofill" button
		cardElement = elements.create('card', {
			hidePostalCode: true,
			disableLink: true,
			style: {
				base: {
					color: '#32325d',
					fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
					fontSmoothing: 'antialiased',
					fontSize: '18px',
					'::placeholder': { color: '#aab7c4' }
				}
			}
		});
		cardElement.mount('#fkwcs-card-element');
	}

	function updateSourceUI() {
		var value = $('input[name="fkwcs_pay_source"]:checked').val();
		if ('new' === value) {
			$('.fkwcs-new-card-wrap').show();
			ensureCardElement();
		} else {
			$('.fkwcs-new-card-wrap').hide();
		}
	}

	/**
	 * Prepare a freshly-opened modal: reset state, list saved cards, pick default.
	 */
	function initModal() {
		submitting = false;
		cardElement = null;
		elements = null;
		clearError();
		renderSavedCards();

		// Default to the first saved card, otherwise the new-card path.
		if (!(data.tokens && data.tokens.length)) {
			$('input[name="fkwcs_pay_source"][value="new"]').prop('checked', true);
		}
		updateSourceUI();
	}

	/**
	 * POST the collected payment data to the admin handler.
	 */
	function postPayment(chargeType, sourceParams) {
		var payload = $.extend(
			{
				action: 'fkwcs_admin_pay_order',
				_wpnonce: data.nonce,
				order_id: data.order_id,
				fkwcs_charge_type: chargeType
			},
			sourceParams
		);

		$.ajax({ method: 'POST', url: data.ajax_url, dataType: 'json', data: payload })
			.done(function (response) {
				handleResponse(response);
			})
			.fail(function () {
				fail(i18n('error_generic', 'Something went wrong. Please try again.'));
			});
	}

	/**
	 * Interpret the handler response: 3DS branch, plain success or failure.
	 */
	function handleResponse(response) {
		if (!response || !response.success) {
			var message = (response && response.data && response.data.message) ? response.data.message : i18n('error_generic', 'Something went wrong. Please try again.');
			fail(message);
			return;
		}

		var result = response.data || {};

		if ('fail' === result.result) {
			fail(result.message || i18n('error_generic', 'Something went wrong. Please try again.'));
			return;
		}

		if (result.fkwcs_intent_secret) {
			handleThreeDSecure(result);
			return;
		}

		window.location.reload();
	}

	/**
	 * Complete a requires_action intent client-side, then finalize server-side.
	 */
	function handleThreeDSecure(result) {
		stripe.confirmCardPayment(result.fkwcs_intent_secret).then(function (outcome) {
			if (outcome.error) {
				fail(outcome.error.message);
				return;
			}

			// Reuse the storefront verify endpoint (is_ajax = no redirect) so the
			// order is completed exactly like the checkout 3DS continuation.
			if (result.fkwcs_verification_url) {
				$.get(result.fkwcs_verification_url + '&is_ajax').always(function () {
					window.location.reload();
				});
			} else {
				window.location.reload();
			}
		});
	}

	function onSubmit(e) {
		e.preventDefault();
		if (submitting) {
			return;
		}
		clearError();

		var chargeType = $('input[name="fkwcs_charge_type"]:checked').val();
		if ('manual' !== chargeType) {
			chargeType = 'automatic';
		}

		var source = $('input[name="fkwcs_pay_source"]:checked').val();
		if (!source) {
			showError(i18n('no_method', 'Please select a payment method.'));
			return;
		}

		submitting = true;
		blockModal();

		if ('new' === source) {
			stripe.createPaymentMethod('card', cardElement).then(function (result) {
				if (result.error) {
					fail(result.error.message);
					return;
				}
				postPayment(chargeType, { fkwcs_source: result.paymentMethod.id });
			});
		} else {
			postPayment(chargeType, { token_id: source });
		}
	}

	$(function () {
		$(document.body).on('click', '.fkwcs-open-pay-order', function (e) {
			e.preventDefault();
			$(this).WCBackboneModal({ template: 'fkwcs-order-pay' });
		});

		$(document.body).on('wc_backbone_modal_loaded', function (e, target) {
			if ('fkwcs-order-pay' === target) {
				initModal();
			}
		});

		$(document.body).on('change', 'input[name="fkwcs_pay_source"]', updateSourceUI);
		$(document.body).on('click', '.fkwcs-submit-pay-order', onSubmit);
	});
}(jQuery));
