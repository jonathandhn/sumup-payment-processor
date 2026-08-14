# Intent 005: Extended webhooks, accounting reconciliation and disputes

## Outcome

CiviCRM automatically captures provider lifecycle events from SumUp webhooks, reconciling processing fees, payout dates for revenue recognition, external refunds, chargebacks/disputes, and explicit cancellations.

## Accounting Reconciliation (Payout & Fees)

- When completing a contribution, the extension inspects the authoritative SumUp transaction for `feeAmount` and `payoutDate` (from transaction root or `PAYOUT` events).
- If SumUp reports processing fees, `fee_amount` is recorded and `net_amount` is computed as `total_amount - fee_amount`.
- If a scheduled or completed payout date exists, `revenue_recognition_date` on `civicrm_contribution` is set to the payout date, enabling accurate deferred revenue accounting.
- Subsequent payout/settlement webhooks refresh these accounting fields idempotently.

## External Refunds (`REFUND` / `TRANSACTION_REFUNDED`)

- When a refund is initiated outside CiviCRM (e.g. from the SumUp mobile app or `me.sumup.com`), SumUp emits a refund notification.
- The webhook handler matches the original contribution by transaction ID / reference.
- If the refund is not already recorded in CiviCRM, the extension creates the negative Payment record and updates the contribution status accordingly.

## Disputes & Chargebacks (`CHARGEBACK` / `DISPUTE`)

- When a cardholder files a dispute, SumUp sends a chargeback notification.
- The extension identifies the associated contribution and updates its status to `Chargeback` (or `Cancelled` if Chargeback status is unavailable), logging the event and reason.

## Explicit Cancellations (`CANCELLED` / `ABORTED`)

- When an online or terminal checkout is explicitly cancelled by the customer or operator, the pending contribution is transitioned to `Cancelled`.

## Safety & Idempotency

- All webhook events are queued in MJWShared before processing; no direct synchronous execution occurs on the webhook endpoint.
- Handlers use transaction-scoped locks to prevent race conditions.
- Provider state is re-verified from the SumUp API before applying any financial updates.
