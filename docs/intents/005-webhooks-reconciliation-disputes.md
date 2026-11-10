# Intent 005: Webhooks, external refunds, and disputes

## Outcome

SumUp notifications are durably queued by MJWShared and treated only as a
signal to read authoritative provider state. CiviCRM can complete registered
checkouts, record refunds made outside CiviCRM, and mark an unambiguous payment
as disputed without trusting financial values from the webhook body.

## Accepted events

- `CHECKOUT_STATUS_CHANGED` re-reads a registered online checkout.
- `solo.transaction.updated` re-reads a registered Solo checkout.
- `TRANSACTION_REFUNDED`, `REFUND_SUCCESSFUL`, and `REFUND` re-read the SumUp
  transaction and record only the unrecorded refund delta.
- `CHARGEBACK` and `DISPUTE` re-read the SumUp transaction and update the one
  matching CiviCRM contribution.
- Other event names are acknowledged without being queued. In particular,
  generic transaction-success or transaction-failure events are not accepted
  until a deterministic registry correlation is implemented.

## State and accounting contract

- The webhook endpoint stores accepted payloads in MJWShared and returns
  promptly. The queue worker performs all provider reads and CiviCRM writes.
- A checkout notification can produce `PAID`, `FAILED`, `EXPIRED`, or
  `CANCELLED`. Only a still-payable contribution is changed by a terminal
  failure or cancellation.
- Payment amount, currency, merchant, contribution reference, and transaction
  ID come from the SumUp API and the local `SumupCheckout` registry, not from
  the webhook payload.
- Refund reconciliation creates a negative CiviCRM Payment only for the delta
  visible at SumUp and not already recorded locally.
- This extension does not currently refresh processing fees, net amounts, or
  payout dates from settlement events.

## Chargeback contract

- The transaction is retrieved from SumUp and must belong to the configured
  merchant.
- An exact positive Payment transaction ID is preferred. Legacy comma-separated
  references are supported only by exact token comparison.
- Zero or multiple matches are errors; the handler never chooses the first
  fuzzy match.
- A transaction-scoped lock serializes replays. A contribution already marked
  `Chargeback`, or carrying the extension's fallback cancellation reason, is
  returned idempotently.
- The contribution becomes `Chargeback`, falling back to `Cancelled` only when
  the CiviCRM installation has no Chargeback status.

## Acceptance

- A duplicate MJWShared event cannot create a second Payment or refund.
- A transaction ID that is only a substring of another payment cannot attach a
  chargeback to that payment.
- A chargeback with no unique local positive Payment remains in error for
  operator reconciliation.
- Malformed or unsupported webhook events do not enter the processing queue.
