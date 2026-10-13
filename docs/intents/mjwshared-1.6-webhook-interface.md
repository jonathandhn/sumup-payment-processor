# Intent: MJWShared 1.6 webhook interface compatibility

## Outcome

SumUp queues webhook notifications without terminating the surrounding request
and exposes the webhook processor contract expected by MJWShared 1.6 while
remaining compatible with MJWShared 1.5.

## Contract

- `handlePaymentNotification()` returns normally after setting the HTTP status;
  it does not call `civiExit()`.
- `rejectDuplicateWebhookEvent()` delegates to MJWShared's canonical duplicate
  detector when the helper is available.
- MJWShared 1.5 installations use an equivalent local duplicate query.
- The payment processor implements `PaymentProcessorWebhookInterface` through a
  conditional adapter when that interface exists.
- Duplicate events are marked as processed and cannot apply accounting changes
  twice.
