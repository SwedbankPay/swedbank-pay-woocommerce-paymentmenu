# Hooks

- [Actions](#actions)
- [Filters](#filters)

## Actions

### `swedbank_pay_scheduler_run_after`

*Fires after a queued Swedbank Pay callback has been processed and the payment has been finalized for the order.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$order` | `\WC_Order` | The order the callback was for.
`$gateway` | `\Swedbank_Pay_Payment_Gateway_Checkout` | The payment gateway of the order.
`$webhook_data` | `string` | The callback data from Swedbank Pay, in JSON format.

Source: [./includes/class-swedbank-pay-scheduler.php](../includes/class-swedbank-pay-scheduler.php), [line 124](../includes/class-swedbank-pay-scheduler.php#L124-L131)


---
## Filters

### `swedbank_pay_payee_reference`

*Filters the generated payee reference, the unique reference of a payment order or transaction in Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$reference` | `string` | The generated payee reference.
`$order_id` | `int\|string` | The WooCommerce order ID, or a random string when the reference is generated for a cart without an order.

Source: [./includes/functions.php](../includes/functions.php), [line 387](../includes/functions.php#L387-L393)


---
### `swedbank_pay_culture`

*Filters the culture code that sets the language of the Swedbank Pay checkout.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$culture` | `string` | The Swedbank Pay culture code mapped from the WordPress locale, e.g. 'sv-SE'.
`$locale` | `string` | The WordPress locale, e.g. 'sv_SE'.

**Changelog**

Version | Description
------- | -----------
`4.6.2` | 

Source: [./includes/class-swedbank-pay-payment-gateway-checkout.php](../includes/class-swedbank-pay-payment-gateway-checkout.php), [line 251](../includes/class-swedbank-pay-payment-gateway-checkout.php#L251-L258)


---
### `swedbank_pay_is_available`

*Filters whether the Swedbank Pay payment gateway is available in the checkout.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$is_available` | `bool` | Whether the gateway is available.
`$gateway` | `\Swedbank_Pay_Payment_Gateway_Checkout` | The gateway instance.

Source: [./includes/class-swedbank-pay-payment-gateway-checkout.php](../includes/class-swedbank-pay-payment-gateway-checkout.php), [line 533](../includes/class-swedbank-pay-payment-gateway-checkout.php#L533-L539)


---
### `swedbank_pay_dispatch_queue_at_shutdown`

*Filters whether the background queue should be dispatched right away, at the end of the current request.*

Return false to leave the queued items to the scheduled cron event instead.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$dispatch` | `bool` | Whether to dispatch the queue at shutdown. Default true.
`$queue` | `\SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Background_Queue` | The background queue instance.

Source: [./includes/class-swedbank-pay-background-queue.php](../includes/class-swedbank-pay-background-queue.php), [line 265](../includes/class-swedbank-pay-background-queue.php#L265-L273)


---
### `swedbank_pay_product_class`

*Filters the class of a product order line sent to Swedbank Pay when refunding an order.*

Only applied when the product has no class set in its '_swedbank_pay_product_class' meta.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$class` | `string` | The product class. Default 'ProductGroup1'.
`$product` | `\WC_Product` | The product of the order line.

Source: [./includes/class-swedbank-pay-payment-actions.php](../includes/class-swedbank-pay-payment-actions.php), [line 308](../includes/class-swedbank-pay-payment-actions.php#L308-L320)


---
### `swedbank_pay_product_class_shipping`

*Filters the class of the shipping order line sent to Swedbank Pay when refunding an order.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$class` | `string` | The order line class. Default 'ProductGroup1'.
`$order` | `\WC_Order` | The order being refunded.

Source: [./includes/class-swedbank-pay-payment-actions.php](../includes/class-swedbank-pay-payment-actions.php), [line 350](../includes/class-swedbank-pay-payment-actions.php#L350-L360)


---
### `swedbank_pay_product_class_fee`

*Filters the class of a fee order line sent to Swedbank Pay when refunding an order.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$class` | `string` | The order line class. Default 'ProductGroup1'.
`$order` | `\WC_Order` | The order being refunded.

Source: [./includes/class-swedbank-pay-payment-actions.php](../includes/class-swedbank-pay-payment-actions.php), [line 367](../includes/class-swedbank-pay-payment-actions.php#L367-L377)


---
### `swedbank_pay_product_class_coupon`

*Filters the class of a coupon order line sent to Swedbank Pay when refunding an order.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$class` | `string` | The order line class. Default 'ProductGroup1'.
`$order` | `\WC_Order` | The order being refunded.

Source: [./includes/class-swedbank-pay-payment-actions.php](../includes/class-swedbank-pay-payment-actions.php), [line 384](../includes/class-swedbank-pay-payment-actions.php#L384-L394)


---
### `swedbank_pay_product_class_other`

*Filters the class of an order line of any other type sent to Swedbank Pay when refunding an order.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$class` | `string` | The order line class. Default 'ProductGroup1'.
`$order` | `\WC_Order` | The order being refunded.

Source: [./includes/class-swedbank-pay-payment-actions.php](../includes/class-swedbank-pay-payment-actions.php), [line 401](../includes/class-swedbank-pay-payment-actions.php#L401-L411)


---
### `swedbank_pay_intl_tel_params`

*Filters the options handed to the intl-tel-input phone number field in the checkout.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$params` | `array` | The script URLs ('lib_script', 'utils_script', 'locale_script'), the locale for the country names ('name_locale'), the preselected country ('country') and the countries listed first ('country_order').

Source: [./includes/class-swedbank-pay-intl-tel.php](../includes/class-swedbank-pay-intl-tel.php), [line 170](../includes/class-swedbank-pay-intl-tel.php#L170-L175)


---
### `swedbank_pay_replace_base_url`

*Filters whether the payex.com domain in the API base URL should be replaced with swedbankpay.com.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$replace` | `bool` | Whether to replace the domain. Default true.

Source: [./includes/class-swedbank-pay-api.php](../includes/class-swedbank-pay-api.php), [line 220](../includes/class-swedbank-pay-api.php#L220-L225)


---
### `swedbank_pay_client`

*Filters the configured Swedbank Pay API client.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$client` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Client\Client` | The API client, with the access token, payee ID, mode and base URL set.

Source: [./includes/class-swedbank-pay-api.php](../includes/class-swedbank-pay-api.php), [line 231](../includes/class-swedbank-pay-api.php#L231-L236)


---
### `swedbank_pay_abort_reason`

*Filters the reason sent to Swedbank Pay when an embedded payment is aborted.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$abort_reason` | `string` | The abort reason, 'CancelledBySystem' or 'CancelledByConsumer'. Default 'CancelledBySystem'.

Source: [./includes/class-swedbank-pay-api.php](../includes/class-swedbank-pay-api.php), [line 462](../includes/class-swedbank-pay-api.php#L462-L467)


---
### `swedbank_pay_payee_reference`

*Filters the payee reference of the cancel transaction.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payee_reference` | `string` | The generated payee reference.

Source: [./includes/class-swedbank-pay-api.php](../includes/class-swedbank-pay-api.php), [line 1214](../includes/class-swedbank-pay-api.php#L1214-L1222)


---
### `swedbank_pay_split_instrument_gateway_is_available`

*Filters whether a separate payment method gateway, e.g. the card or Swish gateway, is available in the checkout.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$is_available` | `bool` | Whether the gateway is available.
`$gateway_id` | `string` | The ID of the gateway being checked, e.g. 'swedbank_pay_credit_card'.
`$gateway_instance` | `\Krokedil\Swedbank\Pay\Gateways\SplitInstrumentGateway` | The instance of the gateway being checked.

Source: [./src/Gateways/SplitInstrumentGateway.php](../src/Gateways/SplitInstrumentGateway.php), [line 133](../src/Gateways/SplitInstrumentGateway.php#L133-L140)


---
### `swedbank_pay_metadata`

*Filters the metadata of the payment order sent to Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$metadata` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderMetadata` | The payment order metadata.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Cart` | The cart helper building the payment data.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 100](../src/Helpers/Cart.php#L100-L106)


---
### `swedbank_pay_payee_reference`

*Filters the payee reference of the payment order.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payee_reference` | `string` | The payee reference stored in the session for the cart.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 120](../src/Helpers/Cart.php#L120-L128)


---
### `swedbank_pay_payee_name`

*Filters the payee name, the name of the store shown in Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payee_name` | `string` | The payee name. Default the site title.
`$gateway_id` | `string` | The ID of the payment gateway.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 130](../src/Helpers/Cart.php#L130-L140)


---
### `swedbank_pay_payee`

*Filters the payee information of the payment order sent to Swedbank Pay.*

Can be used to set or override the subsite of the payment order.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payee` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderPayeeInfo` | The payee information.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Cart` | The cart helper building the payment data.

Examples: 
- [Set or override the subsite for payment orders.](https://docs.krokedil.com/swedbank-pay-payment-menu/customization/actions-filters/#set-or-override-the-subsite-for-payment-orders)

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 149](../src/Helpers/Cart.php#L149-L158)


---
### `swedbank_pay_urls`

*Filters the URLs of the payment order sent to Swedbank Pay, e.g. the complete, callback and terms of service URLs.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$url_data` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderUrl` | The payment order URLs.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Cart` | The cart helper building the payment data.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 199](../src/Helpers/Cart.php#L199-L205)


---
### `swedbank_pay_payer`

*Filters the payer information of the payment order sent to Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payer` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderPayer` | The payer information, with the details of the customer.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Cart` | The cart helper building the payment data.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 239](../src/Helpers/Cart.php#L239-L245)


---
### `swedbank_pay_payment_description`

*Filters the description of the payment order. It is limited to 40 characters.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$description` | `string` | The description. Default 'Order #{payee reference}'.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 265](../src/Helpers/Cart.php#L265-L277)


---
### `swedbank_pay_order_amount`

*Filters the total amount of the payment order, in major units. It is converted to minor units before it is sent to Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$total` | `float` | The total amount, e.g. 199.95.
`$items` | `array` | The formatted order items.
`$cart` | `\WC_Cart` | The cart.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 296](../src/Helpers/Cart.php#L296-L308)


---
### `swedbank_pay_order_vat`

*Filters the total VAT amount of the payment order, in minor units.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$vat_amount` | `int` | The VAT amount.
`$items` | `array` | The formatted order items.
`$cart` | `\WC_Cart` | The cart.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 312](../src/Helpers/Cart.php#L312-L324)


---
### `swedbank_pay_payment_order`

*Filters the payment order sent to Swedbank Pay when a payment is created.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payment_order` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\Request\Paymentorder` | The payment order.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Cart` | The cart helper building the payment data.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 336](../src/Helpers/Cart.php#L336-L342)


---
### `swedbank_pay_order_amount`

*Filters the total amount of the payment order, in major units. It is converted to minor units before it is sent to Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$total` | `float` | The total amount, e.g. 199.95.
`$items` | `array` | The formatted order items.
`$cart` | `\WC_Cart` | The cart.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 363](../src/Helpers/Cart.php#L363-L375)


---
### `swedbank_pay_order_vat`

*Filters the total VAT amount of the payment order, in minor units.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$vat_amount` | `int` | The VAT amount.
`$items` | `array` | The formatted order items.
`$cart` | `\WC_Cart` | The cart.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 379](../src/Helpers/Cart.php#L379-L391)


---
### `swedbank_pay_payee`

*Filters the payee information of the payment order sent to Swedbank Pay.*

Can be used to set or override the subsite of the payment order.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payee` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderPayeeInfo` | The payee information.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Cart` | The cart helper building the payment data.

Examples: 
- [Set or override the subsite for payment orders.](https://docs.krokedil.com/swedbank-pay-payment-menu/customization/actions-filters/#set-or-override-the-subsite-for-payment-orders)

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 402](../src/Helpers/Cart.php#L402-L411)


---
### `swedbank_pay_update_payment_order`

*Filters the payment order sent to Swedbank Pay when the payment is updated with the current cart.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payment_order` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\Request\Paymentorder` | The payment order.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Cart` | The cart helper building the payment data.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 415](../src/Helpers/Cart.php#L415-L421)


---
### `swedbank_pay_transaction_data`

*Filters the transaction data sent to Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$transaction_data` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Transaction\Resource\Request\Transaction` | The transaction data.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Cart` | The cart helper building the payment data.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 443](../src/Helpers/Cart.php#L443-L449)


---
### `swedbank_pay_generate_uuid`

*Filters the payer reference, the reference that identifies the customer in Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payer_reference` | `string\|int` | The payer reference. The user ID for logged in customers, otherwise a unique ID based on the email address.
`$user_id` | `int` | The user ID of the customer, 0 for guests.

Source: [./src/Helpers/Cart.php](../src/Helpers/Cart.php), [line 473](../src/Helpers/Cart.php#L473-L479)


---
### `swedbank_pay_metadata`

*Filters the metadata of the payment order sent to Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$metadata` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderMetadata` | The payment order metadata.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Order` | The order helper building the payment data.

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 106](../src/Helpers/Order.php#L106-L112)


---
### `swedbank_pay_order_reference`

*Filters the order reference of the payment order, the reference of the order in the store.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$order_reference` | `string` | The order number.

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 125](../src/Helpers/Order.php#L125-L133)


---
### `swedbank_pay_payee_reference`

*Filters the payee reference of the payment order.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payee_reference` | `string` | The generated payee reference.

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 135](../src/Helpers/Order.php#L135-L143)


---
### `swedbank_pay_payee_name`

*Filters the payee name, the name of the store shown in Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payee_name` | `string` | The payee name. Default the site title.
`$gateway_id` | `string` | The ID of the payment gateway.

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 146](../src/Helpers/Order.php#L146-L156)


---
### `swedbank_pay_payee`

*Filters the payee information of the payment order sent to Swedbank Pay.*

Can be used to set or override the subsite of the payment order.

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payee` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderPayeeInfo` | The payee information.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Order` | The order helper building the payment data.

Examples: 
- [Set or override the subsite for payment orders.](https://docs.krokedil.com/swedbank-pay-payment-menu/customization/actions-filters/#set-or-override-the-subsite-for-payment-orders)

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 165](../src/Helpers/Order.php#L165-L174)


---
### `swedbank_pay_urls`

*Filters the URLs of the payment order sent to Swedbank Pay, e.g. the complete, callback and terms of service URLs.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$url_data` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderUrl` | The payment order URLs.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Order` | The order helper building the payment data.

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 218](../src/Helpers/Order.php#L218-L224)


---
### `swedbank_pay_payer`

*Filters the payer information of the payment order sent to Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payer` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\PaymentorderPayer` | The payer information, with the details of the customer.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Order` | The order helper building the payment data.

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 258](../src/Helpers/Order.php#L258-L264)


---
### `swedbank_pay_payment_description`

*Filters the description of the payment order. It is limited to 40 characters.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$description` | `string` | The description. Default 'Order #{order number}'.
`$order` | `\WC_Order` | The order.

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 284](../src/Helpers/Order.php#L284-L298)


---
### `swedbank_pay_order_amount`

*Filters the total amount of the payment order, in major units. It is converted to minor units before it is sent to Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$total` | `float` | The total amount, e.g. 199.95.
`$items` | `array` | The formatted order items.
`$order` | `\WC_Order` | The order.

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 318](../src/Helpers/Order.php#L318-L330)


---
### `swedbank_pay_order_vat`

*Filters the total VAT amount of the payment order, in minor units.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$vat_amount` | `int` | The VAT amount.
`$items` | `array` | The formatted order items.
`$order` | `\WC_Order` | The order.

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 334](../src/Helpers/Order.php#L334-L346)


---
### `swedbank_pay_payment_order`

*Filters the payment order sent to Swedbank Pay when a payment is created.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payment_order` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\Request\Paymentorder` | The payment order.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Order` | The order helper building the payment data.

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 357](../src/Helpers/Order.php#L357-L363)


---
### `swedbank_pay_payee_reference`

*Filters the payee reference of the transaction.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payee_reference` | `string` | The generated payee reference.

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 379](../src/Helpers/Order.php#L379-L387)


---
### `swedbank_pay_transaction_data`

*Filters the transaction data sent to Swedbank Pay, e.g. when capturing, cancelling or refunding a payment.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$transaction_data` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Transaction\Resource\Request\Transaction` | The transaction data.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\Order` | The order helper building the payment data.

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 399](../src/Helpers/Order.php#L399-L405)


---
### `swedbank_pay_generate_uuid`

*Filters the payer reference, the reference that identifies the customer in Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$payer_reference` | `string\|int` | The payer reference. The user ID for logged in customers, otherwise a unique ID based on the email address.
`$user_id` | `int` | The user ID of the customer, 0 for guests.

Source: [./src/Helpers/Order.php](../src/Helpers/Order.php), [line 429](../src/Helpers/Order.php#L429-L435)


---
### `swedbank_pay_order_items`

*Filters the order items sent to Swedbank Pay.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$order_items` | `\KrokedilSwedbankPayDeps\SwedbankPay\Api\Service\Paymentorder\Resource\Collection\OrderItemsCollection` | The order items.
`$helper` | `\Krokedil\Swedbank\Pay\Helpers\PaymentDataHelper` | The helper building the payment data, either a Cart or an Order helper.

Source: [./src/Helpers/PaymentDataHelper.php](../src/Helpers/PaymentDataHelper.php), [line 80](../src/Helpers/PaymentDataHelper.php#L80-L86)


---
### `swedbank_pay_invalid_phone_message`

*Filters the message shown to the customer when the phone number has an invalid format.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$message` | `string` | The error message.

Source: [./src/Utility/ErrorUtility.php](../src/Utility/ErrorUtility.php), [line 20](../src/Utility/ErrorUtility.php#L20-L25)


---
### `swedbank_pay_invalid_street_address_message`

*Filters the message shown to the customer when the street address is too long or contains invalid characters.*

**Arguments**

Argument | Type | Description
-------- | ---- | -----------
`$message` | `string` | The error message.

Source: [./src/Utility/ErrorUtility.php](../src/Utility/ErrorUtility.php), [line 35](../src/Utility/ErrorUtility.php#L35-L40)


---


