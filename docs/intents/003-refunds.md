# Intent 003: Full and partial refunds

## Outcome

An administrator can request a full or partial SumUp refund from CiviCRM. CiviCRM records the negative Payment only after SumUp accepts the request and the extension identifies the corresponding refund event.

## Contract

- `supportsRefund()` exposes the native CiviCRM and MJWShared refund workflow.
- The processor accepts the SumUp transaction ID stored on the CiviCRM Payment and remains compatible with early payments that stored the transaction code.
- The transaction is read from SumUp before refunding to verify merchant, currency, original amount and remaining refundable balance.
- Full refunds omit the API amount. Partial refunds send the exact two-decimal amount.
- A transaction-scoped lock prevents concurrent refund requests from overspending the remaining balance.
- The SumUp refund endpoint returns no body. After its successful `204` response, the processor re-reads the transaction and uses the new `REFUND` event when it is already visible.
- The returned `refund_trxn_id` is derived from the SumUp transaction and refund-event identifiers. If SumUp has accepted the refund but has not exposed the event yet, a distinct local request reference prevents the accepted bank operation from being reported as failed to CiviCRM.
- CiviCRM remains responsible for recording the negative Payment and deciding whether the contribution is partially or fully refunded.

## Safety

- A refund is rejected when the requested amount is not positive, has more than two decimal places, uses another currency, exceeds the provider balance, or targets another merchant.
- Existing refund events are snapshotted before the request so an earlier refund cannot be mistaken for the new operation.
- An API exception does not create a CiviCRM refund Payment. A successful empty response is treated as the authoritative acceptance response documented by SumUp.
- Logs contain identifiers and amounts but no payer or card data.

## Acceptance

- A partial refund returns a distinct refund transaction reference and leaves the remaining balance refundable.
- A later partial refund cannot exceed the remaining SumUp balance.
- A full refund consumes the exact remaining balance.
- Reusing an old transaction code resolves the authoritative SumUp transaction ID before refunding.
- Concurrent requests for the same transaction cannot both pass the balance check.
