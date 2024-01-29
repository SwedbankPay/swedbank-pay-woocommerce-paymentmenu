jQuery(document).ready(function ($) {
    $.ajax( {
        url: SwedbankPay_Admin.ajax_url,
        data: {
            action: 'swedbank_pay_get_refund_mode',
            nonce: SwedbankPay_Admin.nonce,
            order_id: SwedbankPay_Admin.order_id
        },
        success: function ( response ) {
            if ( ! response.success ) {
                alert( response.data );
                return false;
            }

            switch (response.data['mode']) {
                case 'items':
                    $('.refund_line_total.wc_input_price').prop('readonly', true);
                    $('.refund_line_tax.wc_input_price').prop('readonly', true);
                    $('#refund_amount').prop('readonly', true);

                    break;
                case 'amount':
                    $('#refund_amount').prop('readonly', false);
                    $('.refund_order_item_qty').prop('readonly', true).val(0);
                    $('.refund_line_total.wc_input_price').prop('readonly', true);
                    $('.refund_line_tax.wc_input_price').prop('readonly', true);
                    break;
            }
        }
    } );

    $( document ).on( 'click', '#swedbank_pay_capture', function (e) {
        e.preventDefault();

        var nonce = $( this ).data( 'nonce' );
        var order_id = $( this ).data( 'order-id' );
        var self = $( this );
        $.ajax( {
            url: SwedbankPay_Admin.ajax_url,
            type: 'POST',
            data: {
                action: 'swedbank_pay_capture',
                nonce: nonce,
                order_id: order_id
            },
            beforeSend: function () {
                self.data( 'text', self.html() );
                self.html( SwedbankPay_Admin.text_wait );
                self.prop( 'disabled', true );
            },
            success: function ( response ) {
                self.html( self.data('text') );
                self.prop( 'disabled', false );
                if ( !response.success ) {
                    alert( response.data );
                    return false;
                }

                window.location.href = location.href;
            }
        } );
    } );

    $( document ).on( 'click', '#swedbank_pay_cancel', function (e) {
        e.preventDefault();

        var nonce = $( this ).data( 'nonce' );
        var order_id = $( this ).data( 'order-id' );
        var self = $( this );
        $.ajax( {
            url: SwedbankPay_Admin.ajax_url,
            type: 'POST',
            data: {
                action: 'swedbank_pay_cancel',
                nonce: nonce,
                order_id: order_id
            },
            beforeSend: function () {
                self.data( 'text', self.html() );
                self.html( SwedbankPay_Admin.text_wait );
                self.prop( 'disabled', true );
            },
            success: function ( response ) {
                self.html( self.data('text') );
                self.prop( 'disabled', false );
                if ( ! response.success ) {
                    alert( response.data );
                    return false;
                }

                window.location.href = location.href;
            }
        } );
    } );

    $( document ).on( 'click', '#swedbank_pay_refund', function (e) {
        e.preventDefault();

        var nonce = $( this ).data( 'nonce' );
        var order_id = $( this ).data( 'order-id' );
        var self = $( this );
        $.ajax( {
            url: SwedbankPay_Admin.ajax_url,
            type: 'POST',
            data: {
                action: 'swedbank_pay_refund',
                nonce: nonce,
                order_id: order_id
            },
            beforeSend: function () {
                self.data( 'text', self.html() );
                self.html( SwedbankPay_Admin.text_wait );
                self.prop( 'disabled', true );
            },
            success: function ( response ) {
                self.html( self.data('text') );
                self.prop( 'disabled', false );
                if ( ! response.success ) {
                    alert( response.data );
                    return false;
                }

                window.location.href = location.href;
            }
        } );
    } );
});
