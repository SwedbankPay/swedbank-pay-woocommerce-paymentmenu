jQuery( function( $ ) {
    'use strict';

    window.sb_payment_status_check = {
        xhr: false,
        attempts: 0,

        /**
         * Initialize the checking
         */
        init: function() {
            this.checkPayment( function ( err, data ) {
                var status_elm = $( '#order-status-checking' ),
                    success_elm = $( '#order-success' ),
                    failed_elm = $( '#order-failed' );

                switch ( data.state ) {
                    case 'paid':
                        status_elm.hide();
                        success_elm.show();
                        break;
                    case 'failed':
                    case 'aborted':
                        status_elm.hide();
                        failed_elm.append("<p>" + data.message + "</p>");
                        failed_elm.show();
                        break;
                    default:
                        window.sb_payment_status_check.attempts++;

                        if ( window.sb_payment_status_check.attempts > 6) {
                            return;
                        }

                        setTimeout(function () {
                            window.sb_payment_status_check.init();
                        }, 10000);
                }
            } );
        },

        /**
         * Check payment
         * @return {JQueryPromise<any>}
         */
        checkPayment: function ( callback ) {
            $( '.woocommerce-order' ).block( {
                message: Swedbank_Pay_Payment_Status_Check.check_message,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            } );

            return $.ajax( {
                type: 'POST',
                url: Swedbank_Pay_Payment_Status_Check.ajax_url,
                data: {
                    action: 'swedbank_pay_check_payment_status',
                    nonce: Swedbank_Pay_Payment_Status_Check.nonce,
                    order_id: Swedbank_Pay_Payment_Status_Check.order_id,
                    order_key: Swedbank_Pay_Payment_Status_Check.order_key,
                },
                dataType: 'json'
            } ).always( function() {
                $( '.woocommerce-order' ).unblock();
            } ).done( function ( response ) {
                callback( null, response.data );
            } );
        },
    };

    $(document).ready( function () {
        window.sb_payment_status_check.init();
    } );
} );
