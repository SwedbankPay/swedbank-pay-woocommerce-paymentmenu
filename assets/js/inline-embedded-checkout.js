jQuery(document).ready(function ($) {
    const sbie = {
        params: {},
        checkout: null,
        isOpen: false,
        containerSelector: '#payex_container',
        selectedMethod: null,
        payButtonPressed: false,
        paymentOrderId: null,
        onPaidRedirectUrl: null,

        /**
         * Initialize the inline embedded checkout script.
         *
         * @returns {void}
         */
        init: function () {
            sbie.params = swedbank_pay_params !== 'undefined' ? swedbank_pay_params : {};

            // Set the initially selected payment method.
            sbie.selectedMethod = sbie.getSelectedPaymentMethod();
            $('body').on('change', 'input[name="payment_method"]', sbie.onPaymentMethodChange);
            $('body').on('update_checkout', sbie.onUpdateCheckout);
            $('body').on('updated_checkout', sbie.onUpdatedCheckout);

            // Register the listener for the form submission, and prevent default if the selected payment method is Swedbank Pay.
            $('form.checkout').on('checkout_place_order_payex_checkout', sbie.onSubmitForm);

            // Toggle the WooCommerce elements based on the initially selected payment method.
            sbie.toggleWooCommerceElements();
            sbie.ifPaymentComplete();
        },

        /**
         * Log messages to the console if script debugging is enabled.
         *
         * @param  {...any} data The data to log.
         * @returns {void}
         */
        consoleLog: function (...data) {
            if ( sbie.params.script_debug ) {
                console.log(...data);
            }
        },

        /**
         * Check if the selected payment method is Swedbank Pay.
         *
         * @returns {boolean}
         */
        isSelectedPaymentMethodSwedbankPay: function () {
            const selectedMethod = sbie.getSelectedPaymentMethod();
            return selectedMethod === 'payex_checkout';
        },

        /**
         * Get the selected payment method.
         *
         * @returns {string|null}
         */
        getSelectedPaymentMethod: function () {
            return $('input[name="payment_method"]:checked').val();
        },

        /**
         * Check if the selected payment method has changed.
         *
         * @returns {boolean}
         */
        hasSelectedPaymentMethodChanged: function () {
            const selectedMethod = sbie.getSelectedPaymentMethod();
            return selectedMethod !== sbie.selectedMethod;
        },

        /**
         * Handle the update checkout event.
         *
         * @returns {void}
         */
        onUpdateCheckout: function () {
            if (sbie.isSelectedPaymentMethodSwedbankPay()) {
                sbie.lockCheckout();
            }
        },

        /**
         * Handle the updated checkout event.
         *
         * @returns {void}
         */
        onUpdatedCheckout: function () {
            if (sbie.isSelectedPaymentMethodSwedbankPay()) {
                sbie.unlockCheckout();
            }

            sbie.toggleWooCommerceElements();
            sbie.ifPaymentComplete();
        },

        /**
         * Handle the payment method change event.
         *
         * @returns {void}
         */
        onPaymentMethodChange: function () {
            // If no change has been made, do nothing.
            if (!sbie.hasSelectedPaymentMethodChanged()) {
                return;
            }
            const selectedMethod = sbie.getSelectedPaymentMethod();

            // If the selected payment method is Swedbank Pay.
            if (sbie.isSelectedPaymentMethodSwedbankPay()) {
                sbie.unlockCheckout();
                sbie.hideWooCommerceElements();
            } else {
                sbie.lockCheckout();
                sbie.showWooCommerceElements();
            }

            sbie.selectedMethod = selectedMethod;
        },

        /**
         * Handle the form submission event.
         * Will either allow or prevent the form submission depending on if the button from the embedded checkout was pressed,
         * and the selected payment method is Swedbank Pay.
         *
         * @param {Event} e The event object.
         * @returns {boolean} True if the form should be submitted, false otherwise.
         */
        onSubmitForm: function (e) {
            if (sbie.isSelectedPaymentMethodSwedbankPay() && ! sbie.payButtonPressed) {
                return false;
            }
            return true;
        },

        /**
         * Handle the checkout success event from WooCommerce.
         *
         * @param {jQuery} wcForm The WooCommerce form object.
         * @param {Object} result The result object from WooCommerce.
         * @returns {boolean} True to indicate the event was handled.
         */
        onCheckoutSuccess: function (wcForm, result) {
            sbie.payButtonPressed = false;
            sbie.onPaidRedirectUrl = result.redirect_on_paid;
            // Disable the event listeners for the checkout result.
            $('body').off('checkout_error.swedbank');
            $('form.checkout').off('checkout_place_order_success.swedbank');

            sbie.checkout.resume({
                paymentOrderId: sbie.paymentOrderId,
                confirmation: true,
            });

            return true;
        },

        /**
         * Handle the checkout error event from WooCommerce.
         *
         * @returns {void}
         */
        onCheckoutError: function () {
            sbie.failPayment();
        },

        /**
         * Handle the payment button pressed event from the embedded checkout.
         *
         * @param {Object} data The data object from the payment button.
         * @returns {void}
         */
        onPaymentButtonPressed: function (data) {
            // Prevent multiple submissions.
            if (sbie.payButtonPressed) {
                return;
            }

            // Set the payment order ID.
            sbie.paymentOrderId = data.paymentOrder.id;

            // Register listeners for the checkout result.
            $('body').on('checkout_error.swedbank', sbie.onCheckoutError);
            $('form.checkout').on('checkout_place_order_success.swedbank', sbie.onCheckoutSuccess);

            // Check the terms checkbox.
            $('#terms').prop('checked', true);

            // Submit the form.
            sbie.payButtonPressed = true;
            $('form.checkout').submit();
        },

        /**
         * Handle the paid event from the embedded checkout.
         *
         * @param {Object} data The data object from the paid event.
         * @returns {void}
         */
        onPaid: function (data) {
            // If the onPaidRedirectUrl is empty, get it from the params instead.
            if (sbie.onPaidRedirectUrl === null) {
                sbie.onPaidRedirectUrl = sbie.params.thankyou_url;
            }

            if (sbie.onPaidRedirectUrl !== null) {
                window.location.href = sbie.onPaidRedirectUrl;
            }
        },

        /**
         * Handle the payment attempt failed event from the embedded checkout.
         *
         * @param {Object} data The data object from the payment attempt failed event.
         * @returns {void}
         */
        onPaymentAttemptFailed: function (data) {
            sbie.failPayment();
        },

        /**
         * Fail a payment attempt and resume the checkout without confirmation.
         *
         * @returns {void}
         */
        failPayment: function () {
            // Disable the event listeners for the checkout result.
            $('body').off('checkout_error.swedbank');
            $('form.checkout').off('checkout_place_order_success.swedbank');

            // Unblock the checkout and remove the processing class.
            $('form.checkout').unblock();
            $('form.checkout').removeClass('processing');

            if( sbie.payButtonPressed ) {
                sbie.checkout.resume({
                    paymentOrderId: sbie.paymentOrderId,
                    confirmation: false,
                });
            }

            sbie.payButtonPressed = false;
        },

        /**
         * Lock the checkout and prevent further actions.
         *
         * @returns {void}
         */
        lockCheckout: function () {
            // Try to close the checkout if it is open.
            if (sbie.checkout !== null && sbie.isOpen) {
                sbie.checkout.close();
                sbie.isOpen = false;
            }
        },

        /**
         * Unlock the checkout and allow further actions.
         *
         * @returns {void}
         */
        unlockCheckout: function () {
            // If the checkout has not yet been initialized, do so now.
            if (sbie.checkout === null) {
                sbie.initCheckout();
            }

            // If the checkout is initialized but not open, open it now.
            if (sbie.checkout !== null && !sbie.isOpen) {
                sbie.checkout.open();
                sbie.isOpen = true;
            }
        },

        /**
         * Initialize the embedded checkout.
         *
         * @returns {void}
         */
        initCheckout: function () {
            // If the selected payment method is not Swedbank Pay, do nothing.
            if (!sbie.isSelectedPaymentMethodSwedbankPay()) {
                return;
            }

            // If the checkout is already initialized, do nothing.
            if (sbie.checkout !== null) {
                return;
            }

            // Initialize the checkout.
            sbie.checkout = payex.hostedView.checkout({
                container: {
                    checkout: "payex_container"
                },
                culture: 'sv-SE',
                onPaymentButtonPressed: sbie.onPaymentButtonPressed,
                onPaid: sbie.onPaid,
                onPaymentAttemptFailed: sbie.onPaymentAttemptFailed,
                /*onAborted: function (data) { sbie.consoleLog('Checkout aborted', data); },
                onCheckoutLoaded: function (data) {
                    sbie.consoleLog('Checkout loaded', data);
                },
                onCheckoutResized: function (data) { sbie.consoleLog('Checkout resized', data); },
                onError: function (data) { sbie.consoleLog('Error', data); },
                onInstrumentSelected: function (data) { sbie.consoleLog('Instrument selected', data); },
                onOutOfViewOpen: function (data) { sbie.consoleLog('Out of view opened', data); },
                onOutOfViewRedirect: function (data) { sbie.consoleLog('Out of view redirected', data); },
                onPaymentAttemptAborted: function (data) { sbie.consoleLog('Payment attempt aborted', data); },
                onPaymentAttemptStarted: function (data) { sbie.consoleLog('Payment attempt started', data); },
                onTermsOfServiceRequested: function (data) { sbie.consoleLog('Terms of service requested', data); },*/
            });

            sbie.checkout.open();
            sbie.isOpen = true;
        },

        /**
         * Toggle the visibility of WooCommerce elements based on the selected payment method.
         *
         * @returns {void}
         */
        toggleWooCommerceElements: function () {
            // If the selected payment method is Swedbank Pay, hide the WooCommerce elements.
            if (sbie.isSelectedPaymentMethodSwedbankPay()) {
                sbie.hideWooCommerceElements();
            } else {
                sbie.showWooCommerceElements();
            }
        },

        /**
         * Hide the WooCommerce elements that should be hidden when the Swedbank Pay payment method is selected.
         *
         * @returns {void}
         */
        hideWooCommerceElements: function () {
            $('div.form-row.place-order').hide();
        },

        /**
         * Show the WooCommerce elements that should be shown when a non-Swedbank Pay payment method is selected.
         *
         * @returns {void}
         */
        showWooCommerceElements: function () {
            $('div.form-row.place-order').show();
        },

        /**
         * If payment is complete, lock the checkout to prevent further actions.
         *
         * @returns {void}
         */
        ifPaymentComplete: function () {
            if ( sbie.params.payment_complete ) {
                // Lock the checkout form.
                $('form.checkout').addClass('processing');
                $('form.checkout').block({
                    message: null,
                    overlayCSS: {
                        background: '#fff',
                        opacity: 0.6
                    }
                });

                // Ensure the embedded checkout is positioned above the overlay for the locked form.
                $('#payex_container').css('zIndex', '5000');
                $('#payex_container').css('position', 'relative');

                if( sbie.isOpen ) {
                    sbie.checkout.refresh();
                }
            }
        },
    };
    sbie.init();
});
