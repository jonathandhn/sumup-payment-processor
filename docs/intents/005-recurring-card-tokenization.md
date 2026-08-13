# Intent 005: SumUp recurring card tokenization

## Outcome

A donor or member can establish a CiviCRM recurring contribution with the
SumUp Payment Widget. SumUp collects explicit card-storage consent and 3DS,
CiviCRM stores only the reusable payment token, and later instalments are
created and charged by CiviCRM without collecting card details again.

## Provider contract

The implementation follows SumUp's documented saved-card sequence:

1. Create or retrieve a SumUp customer with a deterministic, non-personal
   identifier derived from the CiviCRM domain, payment-processor and contact
   IDs.
2. Create a checkout containing that `customer_id` and
   `purpose: SETUP_RECURRING_PAYMENT`.
3. Mount the Payment Widget so SumUp collects consent and performs 3DS.
4. Retrieve and verify the resulting `payment_instrument.token` for that same
   customer and merchant.
5. Create each actual payment checkout and process it server-side with both the
   saved token and its matching `customer_id`.

The setup checkout is an authorization which SumUp reimburses immediately. It
is not a paid CiviCRM contribution and must never create a positive CiviCRM
Payment. The initial contribution is completed only after a separate actual
checkout processed with the saved token is authoritatively `PAID`.

## CiviCRM model

- `ContributionRecur` remains the schedule and business source of truth.
- A CiviCRM `PaymentToken` stores the SumUp token, contact and payment
  processor. PAN and CVC never enter CiviCRM.
- The token's native `masked_account_number` stores the provider card brand
  and last four digits when SumUp returns them. Its native `expiry_date`
  remains empty because the SumUp payment-instrument response does not expose
  an expiry date.
- `ContributionRecur.payment_token_id` points to the verified active token.
- A schedule is operational only when it is in progress, has a verified
  `payment_token_id` and has a next scheduled contribution date. The member
  interface must not label an incomplete schedule as active or claim that it
  continues until cancellation.
- `installments = 1` is a finite schedule containing only the initial payment;
  it is correctly completed after that payment. An open-ended recurring plan
  uses `installments = 0` and must receive a next scheduled contribution date.
- The SumUp customer ID is deterministic and merchant-scoped; it contains no
  name, email, telephone number or other personal data.
- Every setup and charge checkout remains registered in `SumupCheckout`, with
  a distinct attempt purpose and provider state.
- Test and live processors use separate customers and tokens. There is no
  cross-environment fallback.

## Checkout exposure

- Set `PaymentProcessorType.is_recur` only when the full setup and scheduled
  charge path is implemented.
- Offer recurrence through the Card Widget only. Hosted Checkout, wallets and
  Solo do not silently substitute for the documented tokenization UI.
- The server marks recurring setup and card-replacement checkout
  configurations as wallet-ineligible. The browser must not mount Swift
  Checkout for them even when the processor's general mode includes wallets.
- On a recurring form, the Widget checkout creates the reusable card contract
  before charging the initial contribution.
- A one-off checkout stays unchanged and does not create a customer or token
  unless recurrence was explicitly selected.

## Scheduled charges

- `SumupRecurringCard.run` processes due SumUp card schedules in bounded
  batches and may target one explicit `contribution_recur_id` for support and
  testing. It is the cron entry point; no API3 payment action is introduced.
- Each due date creates one deterministic Pending contribution and one unique
  SumUp checkout reference before any remote charge.
- A scoped lock and the local checkout registry prevent concurrent or repeated
  debits for the same schedule occurrence.
- Only an API-verified `PAID` checkout completes the contribution.
- A timeout or `202` response remains Pending and is reconciled through the
  checkout registry and MJWShared webhook queue.
- Explicit failure increments the recurring failure state without creating a
  positive CiviCRM Payment. A failed occurrence is retried after three days and
  the schedule is stopped after three explicit provider failures. Transport or
  local errors leave the same attempt recoverable and do not consume a failure.
- The API defaults to a seven-day stale guard and remains inactive until an
  operator schedules the API4 command after sandbox validation.

## Self-service contract

- A contact can later list masked active cards, deactivate a token and replace
  a card through a new SumUp setup checkout.
- A replacement token is attached to `ContributionRecur` only after SumUp has
  verified it; the previous token remains usable until that atomic switch.
- Deactivation calls SumUp's customer payment-instrument endpoint and updates
  the local `PaymentToken` state without deleting financial history.

## Payment remediation

- A technical timeout, provider `5xx` response or unverified state does not
  consume a customer failure. The same deterministic attempt remains Pending
  and is recovered by a later authoritative read.
- An explicit failed saved-card checkout or a `CheckoutAccepted` response that
  requires payer action opens one `SumupRemediation` record for the schedule
  occurrence. Automatic collection pauses until that record is resolved.
- The missed contribution remains Pending. It is not replaced by another
  accounting occurrence merely because the payment method changed.
- Remediation never exposes a provider token or trusts a browser callback. The
  logged-in contact opens the replacement Afform, completes a fresh
  `SETUP_RECURRING_PAYMENT` Widget checkout, and the server verifies the new
  active token before switching `ContributionRecur.payment_token_id`.
- A charge reference is deterministic for schedule, due date and CiviCRM
  `PaymentToken` ID. Retrying the same token reuses the same checkout; a newly
  verified token creates one new checkout for the same missed contribution.
- After the atomic switch, the remediation record is resolved, failure fields
  are cleared and the missed occurrence becomes eligible again. The old SumUp
  instrument is deactivated only when no other active CiviCRM schedule uses it.
- Merely opening a voluntary card replacement does not pause collection. Only
  an actual saved-card failure or required customer authentication blocks the
  recurring job while remediation is open.
- Card expiration cannot be predicted from SumUp's payment-instrument response
  because it does not expose an expiry date. It is handled as a failed or
  inactive instrument when SumUp reports it.

## CiviCRM financial card metadata

- SumUp online card payments use CiviCRM's `Credit Card` payment instrument,
  including when the physical card is a debit card. `Debit Card` does not
  expose CiviCRM's native card-detail fields.
- After SumUp authoritatively reports a successful transaction, the extension
  reads its transaction details and records the last four digits in
  `FinancialTrxn.pan_truncation` and a supported network in
  `FinancialTrxn.card_type_id`.
- SumUp networks are mapped to the corresponding CiviCRM card family only when
  that option exists: Visa, MasterCard, Amex or Discover. An unknown or locally
  unavailable network is left empty rather than guessed.
- Failure to retrieve optional card metadata is logged but never turns an
  already verified successful payment into a failure.

## Managed Afforms

- `afSumupPaymentMethods` lists only the logged-in contact's SumUp schedules,
  masked card, next date and open remediation state.
- `afSumupReplaceCard` accepts a schedule ID but the API independently verifies
  ownership against the logged-in contact before creating or completing a
  replacement checkout.
- The first Afform links to the second. The replacement Afform embeds the SumUp
  Card Widget and never creates a CiviCRM contribution or Payment.
- Both forms accept either the logged-in CiviCRM contact or a standard
  `cid`/`cs` contact checksum. The checksum is validated by CiviCRM on every
  API action and is never sent to SumUp.

## Security and recovery

- API credentials and saved tokens stay server-side.
- Widget callbacks are UX signals only; the server retrieves the checkout and
  validates merchant, customer, token, contribution, amount and currency.
- Webhooks are queued only through MJWShared and trigger an authoritative SumUp
  read before changing CiviCRM.
- Duplicate setup callbacks, webhook replays and scheduled-job retries are
  idempotent.
- Logs contain CiviCRM, checkout, customer and masked token identifiers only.

## Acceptance

- A sandbox recurring form displays SumUp's explicit saved-card consent.
- The setup checkout produces one active token for the expected customer.
- Its automatically reimbursed authorization creates no CiviCRM Payment.
- The initial actual checkout creates exactly one positive Payment and leaves
  the recurring contribution active.
- Re-running the same scheduled occurrence cannot debit it twice.
- A deliberate SumUp failure leaves the occurrence recoverable and does not
  complete the contribution.
- A browser closure and a missed webhook can be repaired from `SumupCheckout`
  without guessing from the contribution status.
- PHPCS and PHPStan level 8 remain green.
