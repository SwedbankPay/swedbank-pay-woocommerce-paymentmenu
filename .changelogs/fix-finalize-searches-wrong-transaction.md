Type: Fix
Needs Documentation: no

Fixed an issue where the callback for a captured or refunded payment was logged as a failure, because the payment was looked up by its authorization instead of by the transaction the callback was about.
