(function () {
    // Imports
    const { __ }                            = wp.i18n;
    const { decodeEntities }                = wp.htmlEntities;
    const { registerPaymentMethod }         = wc.wcBlocksRegistry;
    const { applyFilters }                  = wp.hooks;

    // Data
    const settings = window.wc.wcSettings.getPaymentMethodData( 'payex_checkout', {} );
    const title = decodeEntities(settings.title) || 'Swedbank Pay Payments';

    const Content = () => {
        return decodeEntities( settings.description || '' );
    };

    const Label = props => {
        const icon = React.createElement(
            'img',
            { alt: title, title: title, className: 'swedbank-pay-payments-logo', src: SwedbankPay_Blocks_Integration.logo_src}
        );

        const { PaymentMethodLabel } = props.components;
        //const label = React.createElement(PaymentMethodLabel, { text: title, icon: icon });
        const label = React.createElement(PaymentMethodLabel, { text: title });

        return applyFilters( 'swedbank_pay_checkout_label', label, settings );
    };

    const SwedbankPayPaymentMethod = {
        name: 'payex_checkout',
        label: React.createElement( Label, null ),
        content: React.createElement( Content, null ),
        edit: React.createElement( Content, null ),
        placeOrderButtonLabel: SwedbankPay_Blocks_Integration.proceed_to,
        ariaLabel: SwedbankPay_Blocks_Integration.payment_via,
        canMakePayment: () => true,
    };

    registerPaymentMethod( SwedbankPayPaymentMethod );
}());
