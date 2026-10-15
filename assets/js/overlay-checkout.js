jQuery(function ($) {
    const sbo = {
        params: typeof swedbank_pay_overlay_params !== 'undefined' ? swedbank_pay_overlay_params : {},
        checkout: null,
        redirectUrl: null,
        onPaidRedirectUrl: null,
        script: null,
        previousFocus: null,

        /**
         * Initialize the overlay checkout script.
         *
         * @returns {void}
         */
        init: function () {
            $('form.checkout').on('checkout_place_order_success', sbo.onCheckoutSuccess);
        },

        /**
         * Open the overlay instead of redirecting when the payment order has been created.
         *
         * @param {Event} e The event object.
         * @param {Object} result The result object from WooCommerce.
         * @returns {boolean} True to let WooCommerce continue.
         */
        onCheckoutSuccess: function (e, result) {
            // Not an overlay payment, e.g. another gateway or a mobile device.
            if (!result.swedbank_pay_view_checkout) {
                return true;
            }

            sbo.redirectUrl = result.redirect;
            sbo.onPaidRedirectUrl = result.redirect_on_paid;

            // WooCommerce follows the redirect, so replace it with a hash to stay on the page.
            result.redirect = '#swedbank-pay-overlay';

            sbo.openOverlay(result.swedbank_pay_view_checkout);

            return true;
        },

        /**
         * Create the overlay and load the payment menu into it.
         *
         * @param {string} scriptUrl The view-checkout script URL for the payment order.
         * @returns {void}
         */
        openOverlay: function (scriptUrl) {
            sbo.previousFocus = document.activeElement;

            const $closeButton = $('<button type="button" class="swedbank-pay-overlay-close"></button>')
                .attr('aria-label', sbo.params.close_label)
                .html('&times;')
                .on('click', sbo.closeOverlay);

            // Sentinels wrap Tab focus, since keys pressed in the payment menu iframe never reach this page.
            const $startSentinel = $('<span tabindex="0"></span>').on('focus', sbo.focusLast);
            const $endSentinel = $('<span tabindex="0"></span>').on('focus', sbo.focusFirst);

            $('<div id="swedbank-pay-overlay" role="dialog" aria-modal="true"></div>')
                .attr('aria-label', sbo.params.dialog_label)
                .on('keydown', sbo.onKeydown)
                .append(
                    $startSentinel,
                    $('<div class="swedbank-pay-overlay-content"></div>')
                        .append($closeButton)
                        .append('<div id="swedbank-pay-overlay-container"></div>'),
                    $endSentinel
                )
                .appendTo('body');

            // Deferred, as WooCommerce resets the focus when it sets the redirect hash.
            setTimeout(sbo.focusFirst, 0);

            // Each payment order has its own script.
            sbo.removeScript();

            sbo.script = document.createElement('script');
            sbo.script.id = 'swedbank-pay-overlay-script';
            sbo.script.src = scriptUrl;
            sbo.script.onload = sbo.initCheckout;
            sbo.script.onerror = sbo.fallbackToRedirect;
            document.body.appendChild(sbo.script);
        },

        /**
         * Detach and remove the payment menu script so a pending load does nothing.
         *
         * @returns {void}
         */
        removeScript: function () {
            if (sbo.script !== null) {
                sbo.script.onload = null;
                sbo.script.onerror = null;
                sbo.script.remove();
                sbo.script = null;
            }
        },

        /**
         * Close the overlay on Escape.
         *
         * @param {KeyboardEvent} e The keydown event.
         * @returns {void}
         */
        onKeydown: function (e) {
            if (e.key === 'Escape') {
                sbo.closeOverlay();
            }
        },

        /**
         * Move focus to the close button.
         *
         * @returns {void}
         */
        focusFirst: function () {
            $('#swedbank-pay-overlay .swedbank-pay-overlay-close').trigger('focus');
        },

        /**
         * Move focus to the payment menu, or the close button while it is loading.
         *
         * @returns {void}
         */
        focusLast: function () {
            const $iframe = $('#swedbank-pay-overlay-container iframe');

            if ($iframe.length) {
                $iframe.trigger('focus');
            } else {
                sbo.focusFirst();
            }
        },

        /**
         * Initialize the payment menu in the overlay.
         *
         * @returns {void}
         */
        initCheckout: function () {
            if (!$('#swedbank-pay-overlay').length) {
                return;
            }

            sbo.checkout = payex.hostedView.checkout({
                container: {
                    checkout: 'swedbank-pay-overlay-container'
                },
                culture: sbo.params.culture,
                onPaid: sbo.onPaid,
                onAborted: sbo.closeOverlay,
                onError: sbo.fallbackToRedirect,
            });

            sbo.checkout.open();
        },

        /**
         * Handle the paid event from the payment menu.
         *
         * @returns {void}
         */
        onPaid: function () {
            window.location.href = sbo.onPaidRedirectUrl;
        },

        /**
         * Close the overlay and let the customer continue with the checkout.
         *
         * @returns {void}
         */
        closeOverlay: function () {
            sbo.removeScript();

            if (sbo.checkout !== null) {
                sbo.checkout.close();
                sbo.checkout = null;
            }

            $('#swedbank-pay-overlay').remove();
            $('form.checkout').removeClass('processing').unblock();

            if (sbo.previousFocus) {
                sbo.previousFocus.focus();
                sbo.previousFocus = null;
            }
        },

        /**
         * Continue the payment in the redirect menu if the overlay cannot be used.
         *
         * @returns {void}
         */
        fallbackToRedirect: function () {
            // The customer closed the overlay before the payment menu failed.
            if (!$('#swedbank-pay-overlay').length) {
                return;
            }

            window.location.href = sbo.redirectUrl;
        },
    };

    sbo.init();
});
