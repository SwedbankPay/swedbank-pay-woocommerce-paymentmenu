Type: Fix
Needs Documentation: no

Fixed an issue in seamless checkout where a completed payment could be left unreconciled and the order cancelled, because a failed update replaced the payment order without aborting it first.
