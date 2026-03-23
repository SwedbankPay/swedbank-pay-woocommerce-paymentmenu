(function () {
    // Imports
    const { __ } = wp.i18n;
    const { decodeEntities } = wp.htmlEntities;
    const { registerPaymentMethod } = wc.wcBlocksRegistry;
    const { applyFilters } = wp.hooks;

    // Data
    const settings = window.wc.wcSettings.getPaymentMethodData('swedbank_split_instruments', {});
    Object.keys(settings).forEach((key) => {
        const {gateway_id, title, description, enabled} = settings[key];
        const Content = () => {
            return decodeEntities(description || '');
        };

        const Label = props => {
            const { PaymentMethodLabel } = props.components;
            //const label = React.createElement(PaymentMethodLabel, { text: title, icon: icon });
            const label = React.createElement(PaymentMethodLabel, { text: title });

            return applyFilters('swedbank_pay_checkout_label', label, settings[key]);
        };

        /**
         * Replace the %s placeholder with the title of the payment method in the label text for the button and aria-label.
         *
         * @param {string} text The text containing the %s placeholder.
         *
         * @returns {string} The label with the title of the payment method.
         */
        const getLabelText = ( text ) => {
            return text.replace('%s', title);
        }

        const options = {
            name: gateway_id,
            label: React.createElement(Label, null),
            content: React.createElement(Content, null),
            edit: React.createElement(Content, null),
            placeOrderButtonLabel: getLabelText(SplitInstrumentBlockSupport.proceed_to),
            ariaLabel: getLabelText(SplitInstrumentBlockSupport.payment_via),
            canMakePayment: () => enabled,
        };

        registerPaymentMethod(options);
    });
}());
