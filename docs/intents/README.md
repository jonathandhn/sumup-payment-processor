# SumUp intent status

These intent documents are the feature contracts used for the final test pass.
Acceptance criteria apply only to current intents and to the still-current
invariants explicitly retained by a superseded intent.

## Current contracts

- 002: embedded QuickForm and Afform checkout
- 003: refunds
- 004: Solo Cloud terminal fleet and explicit reader selection
- 005 (both documents): recurring card tokenization; webhook reconciliation
- 006 (both documents): merchant profile; saved card reuse
- 007 (both documents): Afform Solo/QR; recurring plan management
- 008 to 013: saved-card lifecycle, contact merge, recurring policy, signed
  links, standalone QR, and payment-link delivery
- 016: authoritative tax-inclusive full-payment amount
- 017: explicit partial-attempt amount exception
- 018: partner OAuth
- `mjwshared-1.6-webhook-interface.md`: durable webhook queue contract

## Superseded or moved

- 001 retains the original Widget trust and accounting invariants, but its API
  key configuration and old scope boundaries are superseded by later intents.
- Provider-neutral accordion and single-page checkout orchestration moved to
  `formbuilder-payment-extra/docs/intents/002-shared-single-page-payment-orchestration.md`.

## Test policy

Automated and integration tests are intentionally deferred to one final pass.
That pass must derive its scenarios from the current contracts above, including
failure, replay, amount, processor ownership, reader selection, OAuth, refund,
and chargeback cases.
