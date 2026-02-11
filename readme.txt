=== Swedbank Pay Payment Menu ===
Contributors: swedbankpay
Tags: ecommerce, swedbank, payex, payment gateway, woocommerce
Requires at least: 5.3
Tested up to: 6.9
Requires PHP: 7.4
WC requires at least: 5.5.1
WC tested up to: 10.5.1
Stable tag: 4.3.2
License: Apache License 2.0
License URI: http://www.apache.org/licenses/LICENSE-2.0

This plugin provides the Swedbank Pay Payment Menu for WooCommerce.

== Description ==

Swedbank Pay Payments Gateway for WooCommerce. We support the following methods through our plugin:

* Credit and Debit cards (Visa, Mastercard, Visa Electron, Maestro, American Express, Dancard, among others).
* Invoice (Sweden and Norway)
* CreditAccount (Sweden)
* Swish (Sweden)
* Vipps (Norway)
* MobilePay (Denmark and Finland)
* Trustly
* Apple Pay
* Click to Pay
* Google Pay

** Google Pay, American Express and Dancard requires separate agreement with each issuer. Please contact us for more information.

You can contact us through here, https://www.swedbankpay.com/woocommerce.


##Who are we?
Swedbank Pay is one of Europe’s largest payment service providers handling 3,5    billion transactions every year with world class availability due to state of the art technology. With Swedbank Pay Payment Menu you will get access to a wide range of payment methods, both global ones as well as the Scandinavian – All in one single agreement from one single provider.

##Broad & Scalable offering!
Swedbank Pay Payment Menu offers a sleek payment window covering any payment method you may need to address the Scandinavian market. If you expand your business to other countries or other environments such as in-store or in-app we have the solutions for you.

##Local knowledge!
We have local representation in all Scandinavian countries to ensure local expertise. If you need any help our competent merchant support will assist you.

== Changelog ==

[See changelog for all versions](https://raw.githubusercontent.com/SwedbankPay/swedbank-pay-woocommerce-paymentmenu/main/changelog.txt).

== Screenshots ==

- Configuration
- Checkout
- Payment page

== Installation ==
After you have completed the installation of our plugin, it is now time to start configuring and connecting the plugin towards our services.

If you have an active agreement with us already, you can then use the hyperlinks underneath the fields "Payee ID" and "Access Token" to retrieve and generate these values. Payee ID is used to designate and identify the account that the plugin should report the transaction to and the access token will validate that you have the authority to make transactions for that account. You are only required to do this once during the setup.

If you are in the process of becoming a customer or simply curious about how our services work, feel free to use the generic details below so that you can generate our selection and make transactions.

**PayeeId:**
6bbb4542-f42d-49b6-a481-2af773d42719

**Access token:**
711d04061a42ed2b83d978c0a2215e5f6499040698576dc61b18d0551199116d

You can find information about our cards and other methods here,
https://developer.swedbankpay.com/resources/test-data

* Please note that in order to fully test our service for invoice, a valid BankID user needs to be configured in their development sandbox.




*After performing the tasks above, it is now time to finalize the configuration.*

**Description** - Use this field to describe what methods are available to your customer through our services.

**Language** - Decide which language you would like to be the default. Currently there is support Swedish, Norweigan, Danish, Finnish and English. We always offer every payer the option of choosing any of the other supported languages during the payment process so your default will not hinder them from finalizing their transaction.

**Instant Capture** - Be aware that Captures on orders are only allowed to be made if, you as the Merchant have fulfilled your end of the transaction. For physical products, that means when the order is shipped out to the customer. For digital products or services, you are allowed to use this feature but the responsibility to follow the laws and regulations presented by local and global authorities.

**Terms of Service** - Provide us with an URL that contains a PDF or similar resource containing your conditions.

**LogoUrl** - Provide us with an URL that contains a .PNG or .JPG with your logotype.

You are now done with configuring our plugin.

= Minimum Requirements =
* PHP 7.4 or greater is recommended
* WooCommerce 5 or greater is recommended

== Upgrade Notice ==
= 1.2.0 =
Please update to version 1.2.0.

== Changelog ==
= 2026.02.11    - version 4.3.2 =
* Enhancement   - Updated the code to be inline with WordPress coding standards.
* Enhancement   - Removed the custom database table for transactions, since it was not being used for any functionality other then storing data.
* Fix           - Fixed an issue where the phonenumber was not correctly formatted correctly when paying for an existing order.

= 2025.12.10    - version 4.3.1 =
* Enhancement   - Reduced the amount of requests made to Swedbank Pay when the customer gets to the thankyou page for an order that could happen in some cases.
* Fix           - Fixed an issue where some payments would automatically be set to Completed in WooCommerce when the callback from Swedbank Pay was processed for some payment methods.
* Fix           - Fixed an issue where the overlay from WooCommerce would cover some modal windows shown by Swedbank Pay during the checkout when using the Seamless Menu checkout flow.
* Fix           - Fixed an issue where the description for shipping methods was not included in the order lines sent to Swedbank Pay.

= 2025.12.02    - version 4.3.0 =
* Feature       - Added a new checkout flow, Seamless Menu, which provides a more integrated payment experience within the WooCommerce checkout page.
* Feature       - Added an option to automatically capture the payment when the order is marked as Completed in WooCommerce. This can be toggled in the plugin settings. Note, you can still manually capture payments if needed through the metabox action buttons.
* Feature       - Added an option to automatically cancel the payment when the order is marked as Cancelled in WooCommerce. This can be toggled in the plugin settings. Note, you can still manually cancel payments if needed through the metabox action buttons.
* Tweak         - Replaced jquery-blockui with wc-jquery-blockui for eligible WooCommerce versions.
* Tweak         - Added UTM parameters in support form link for better tracking of support requests.

= 2025.10.23    - version 4.2.1 =
* Tweak         - Replaced the internal support form page with a direct link to the external website's support page.

= 2025.10.16    - version 4.2.0 =
* Tweak         - Refund processing has been optimized for improved speed and reliability.
* Tweak         - IP address validation has been removed and replaced with a new mechanism that validates callback data, preventing valid callbacks from being incorrectly rejected.
* Fix           - API requests no longer fail when the order line's description is too long. The item name is now used and trimmed if it exceeds the allowed length.
* Fix           - Admin scripts now load only on Swedbank Pay orders, preventing read-only fields on other orders.
* Fix           - Fixed a critical error when cancelling or refunding an order.

= 2025.09.30    - version 4.1.1 =
* Tweak         - This version include no changes, only modifications to the changelog.

= 2025.09.30    - version 4.1.0 =
* Feature       - Added support for WooCommerce Subscriptions.
* Tweak         - The order status is now determined directly by WooCommerce. Previously, an authorized order was set to "on-hold".
* Tweak         - A captured order will now be set to "completed" instead of the previous "processing" status.

= 2025.09.02    - version 4.0.1 =
* Fix           - Update files included in the release.

= 2025.09.01    - version 4.0.0 =
* Feature       - Added support for the WooCommerce Action Scheduler.
* Feature       - Added setting to exclude order lines from the payment request to improve compatibility gift cards.
* Feature       - Improved tracing and logging of errors for easier troubleshooting.
* Feature       - Added an entry in the system report for encountered errors, and the plugin settings.
* Feature       - Added PHP scoping for prevent dependencies conflict with other plugins or themes.
* Fix           - Addressed various PHP warnings.
* Fix           - Addressed warning related to referencing text domain before loaded.
