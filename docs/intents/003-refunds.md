# Intent 003: Dynamic full and partial refunds

## Outcome

An administrator can request a full or partial SumUp refund from CiviCRM. CiviCRM records the negative Payment only after SumUp accepts the request and the extension identifies the corresponding refund event. When a specific transaction rejects a partial amount (e.g. `min_refundable_amount`), a clear and actionable error message is presented to the user.

## Contract

- `supportsRefund()` exposes the native CiviCRM and MJWShared refund workflow.
- Refund capability depends only on the merchant API credentials and SDK; an
  unrelated Widget, Wallet or Solo configuration must never downgrade the
  operation to a manual CiviCRM-only refund.
- The processor accepts the SumUp transaction ID stored on the CiviCRM Payment and remains compatible with early payments that stored the transaction code.
- The transaction is read from SumUp before refunding to verify merchant, currency, original amount and remaining refundable balance.
- `doRefund()` verifies that the requested amount is positive, has at most two decimal places, and does not exceed the remaining refundable balance before calling SumUp.
- When the requested amount equals the total remaining refundable balance, the API `amount` parameter is omitted to signal a complete refund. For partial amounts, the exact float amount is passed to SumUp.
- If SumUp rejects a partial refund with a `min_amount` constraint (such as recurring card charges requiring full refunds), the error response is parsed to provide an explicit user message with the required minimum amount.
- A transaction-scoped lock prevents concurrent refund requests from overspending the remaining balance.
- The SumUp refund endpoint may return an empty JSON object or no body. After a successful `2xx` response, the processor re-reads the transaction and uses the new `REFUND` event when it is already visible.
- The returned `refund_trxn_id` is derived from the SumUp transaction and refund-event identifiers. If SumUp has accepted the refund but has not exposed the event yet, a distinct local request reference prevents the accepted bank operation from being reported as failed to CiviCRM.
- CiviCRM remains responsible for recording the negative Payment and deciding whether the contribution is partially or fully refunded.

## Safety

- A refund is rejected when the requested amount is not positive, has more than two decimal places, uses another currency, exceeds the provider balance, or targets another merchant.
- Existing refund events are snapshotted before the request so an earlier refund cannot be mistaken for the new operation.
- An API exception does not create a CiviCRM refund Payment. A successful empty response is treated as the authoritative acceptance response documented by SumUp.
- Provider error details (such as `min_refundable_amount` or rejection reasons) are surfaced safely without exposing secrets, card data, or sensitive tokens.
- External refund notifications are processed under a transaction-scoped lock. The extension compares the authoritative total of SumUp refund events with the negative payments already recorded in CiviCRM and records only the missing delta.
- A notification without a visible SumUp refund event remains retryable. The original transaction amount is never substituted for missing refund data.
- The original positive CiviCRM payment is resolved by an exact transaction identifier. Legacy comma-separated references are accepted only after tokenised comparison, and ambiguous matches fail explicitly.
- Logs contain identifiers and amounts but no payer or card data.

## Acceptance

- A partial refund within the refundable balance is forwarded to SumUp.
- A full refund consumes the remaining refundable balance.
- A request exceeding the remaining refundable balance is rejected locally.
- Reusing an old transaction code resolves the authoritative SumUp transaction ID before refunding.
- If SumUp enforces a minimum amount on a given transaction, a clear message explaining the minimum required amount is returned.
- Concurrent requests for the same transaction cannot both pass the balance check.
