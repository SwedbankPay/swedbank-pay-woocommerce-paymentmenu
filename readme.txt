=== Swedbank Pay Payment Menu ===
Contributors: swedbankpay
Tags: ecommerce, e-commerce, commerce, woothemes, wordpress ecommerce, swedbank, payex, payment gateway, woocommerce
Requires at least: 5.3
Tested up to: 6.3.1
Stable tag: 1.0.1
License: Apache License 2.0
License URI: http://www.apache.org/licenses/LICENSE-2.0

This plugin provides the Swedbank Pay Payment Menu for WooCommerce.

== Description ==

Swedbank Pay Payments Gateway for WooCommerce. Payment gateway allows to accept payments through:
* Credit and Debit cards (Visa, Mastercard, Visa Electron, Maestro etc).
* Invoice
* Swish
* Vipps
* MobilePay
* Trustly
* Apple Pay
* Click to Pay

== Changelog ==

[See changelog for all versions](https://raw.githubusercontent.com/SwedbankPay/swedbank-pay-woocommerce-paymentmenu/main/changelog.txt).

= Screenshots =

= Minimum Requirements =

* PHP 7.0 or greater is recommended
* WooCommerce 5 or greater is recommended

== Installation ==
After you have completed the installation our plugin, it is now time to start configuring and connecting the plugin towards our services. 

If you have an active agreement with us already, you can then use the hyperlinks underneath the fields "Payee ID" and "Access Token" to retrieve and generate these values. Payee ID is used to designate and identify the account that the plugin should report the transaction to and the access token will validate that you have the authority to make transactions for that account. You are only required to do this once during the setup.

If you are in the process of becoming a customer or simply curious about how our services work, feel free to use the generic details below so that you can generate our payment menu.

PayeeId:
6bbb4542-f42d-49b6-a481-2af773d42719

Access token:
711d04061a42ed2b83d978c0a2215e5f6499040698576dc61b18d0551199116d

You can find information about our cards and other methods here,
https://developer.swedbankpay.com/resources/test-data

* Please note that in order to fully test our service for invoice, a valid BankID user needs to be configured in their development sandbox


After performing the tasks above, it is now time to finalize the configuration.

Description - Use this field to describe what methods are available to your customer through our services.

Language - Decide which language you would like to be the default. We currently support Swedish, Norweigan, Danish, Finnish and English. We always offer every payer the option of choosing any of the other supported languages during the payment process so your default will not hinder them from finalizing their transaction.

Instant Capture - Be aware that Captures on orders are only allowed to be made if, you as the Merchant have fulfilled your end of the transaction. For physical products, that means when the order is shipped out to the customer. For digital products or services, you are allowed to use this feature but the responsibility to follow the laws and regulations presented by local and global authorities.

Terms of Service - Provide us with an URL that contains a PDF or similar resource containing your conditions.

LogoUrl - Provide us with an URL that contains a .PNG or .JPG with your logotype.

You are now done with configuring our plugin.
