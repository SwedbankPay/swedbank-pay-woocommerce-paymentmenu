Type: Fix
Needs Documentation: no

Fixed an issue in the inline embedded checkout where a completed payment (e.g. Swish) could be left unreconciled and the order cancelled, because a failed update replaced the payment order without aborting it first.
