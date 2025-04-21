jQuery(document).ready(function ($) {
    // HPOS failback
    if ( ! SwedbankPay_Admin_Refund.order_id ) {
        SwedbankPay_Admin_Refund.order_id = $( '#post_ID' ).val();
    }

    $(document).on('click', '#swedbank_pay_refund_partial', function (e) {
        e.preventDefault();

        var nonce = $(this).data('nonce');
        var order_id = $(this).data('order-id');
        var self = $(this);
        $.ajax({
            url: SwedbankPay_Admin_Refund.ajax_url,
            type: 'POST',
            data: {
                action: 'swedbank_pay_refund_partial',
                nonce: nonce,
                order_id: order_id,
                amount: $('#swedbank_refund_amount').val(),
                vat_amount: $('#swedbank_refund_vat_amount').val(),
            },
            beforeSend: function () {
                self.data('text', self.html());
                self.html(SwedbankPay_Admin_Refund.text_wait);
                self.prop('disabled', true);
            },
            success: function (response) {
                self.html(self.data('text'));
                self.prop('disabled', false);
                if (!response.success) {
                    alert(response.data);
                    return false;
                }

                window.location.href = location.href;
            }
        });
    });

    $( document ).on( 'keyup', '.refund_order_item_qty', function() {
        var value = $( this ).val();
        value.replaceAll(',', '')
            .replaceAll('.', '')
        $( this ).val(value);
    });
});
