# Intent 001: SumUp Widget and wallet checkout

## Problem and outcome

CiviCRM needs a first-class SumUp payment processor that accepts a one-off card or wallet payment without sending the payer through a CiviCRM credit-card form. The payer sees SumUp's Card Widget, Apple Pay and Google Pay buttons, or both on a local CiviCRM page; CiviCRM records the payment only after an authoritative SumUp API read.

## Scope

- Configure one SumUp merchant code and API key per CiviCRM payment processor, plus a public merchant key for wallet modes.
- Create a SumUp checkout server-side for an existing Pending contribution.
- Render the selected SumUp Widget, wallet, or combined mode on a signed, CMS-agnostic CiviCRM URL.
- Persist the selected mode on the checkout attempt so configuration changes do not alter an in-flight checkout.
- Support card authentication and immediate browser-side re-verification.
- Queue SumUp checkout notifications in MJWShared and process them asynchronously.
- Persist every SumUp checkout attempt independently from CiviCRM Payments.
- Complete or fail the exact CiviCRM contribution idempotently.

## Non-goals

- Hosted Checkout and Solo Cloud API.
- Recurring payments, stored cards, refunds, and point-of-sale user interfaces.
- A generic abstraction shared with other payment service providers.

The checkout modes implemented by this lot are `widget`, `widget_wallet`, and `wallet`. The future `hosted` and `solo_cloud` values must fail explicitly until implemented.

## Entry points and actors

- CiviCRM calls `doPayment()` after creating a Pending contribution.
- The extension creates the SumUp checkout using the PHP SDK.
- The payer completes the selected SumUp checkout mode on `civicrm/sumup/widget`.
- SumUp POSTs `CHECKOUT_STATUS_CHANGED` to MJWShared's processor IPN URL.
- MJWShared invokes `processWebhookEvent()` from its durable queue.

## State and accounting contract

- The contribution remains Pending while the SumUp checkout is Pending.
- A browser `success` event triggers a server API read; it does not complete the contribution directly.
- A webhook is a notification; it also triggers a server API read.
- A PAID checkout creates one positive CiviCRM Payment with the SumUp transaction code.
- `SumupCheckout` retains every attempt, including Pending, Failed, Expired, and superseded checkouts.
- Replays return success when that exact positive payment already exists.
- FAILED or EXPIRED only changes a still-Pending contribution to Failed.
- Completed and Refunded contributions are never downgraded.

## Provider contract

Creation sends a unique `checkout_reference`, amount, ISO-4217 currency, merchant code, description, MJWShared callback URL, and signed browser return URL. Verification must match:

The completed CiviCRM Payment stores a concrete SQL transaction timestamp; API
date aliases such as `now` must not produce a zero accounting date.

- checkout ID;
- checkout reference and encoded contribution ID;
- configured merchant code;
- amount to two decimal places;
- currency;
- status `PAID` before accounting;
- non-empty transaction code or transaction ID.

## Failure, retries, and reconciliation

- API failures leave the contribution Pending and show a retry-safe message.
- The MJWShared handler acknowledges quickly and performs provider reads in the queue worker.
- Duplicate browser returns and duplicate webhook deliveries are idempotent.
- A newer attempt does not erase an older attempt; an older locally registered checkout can still be reconciled.
- A checkout created remotely but not attached locally can still be correlated from its signed SumUp checkout reference.
- Future reconciliation will reuse the same authoritative verification service.

## Privacy and security

- The API key stays server-side.
- The public merchant key is exposed only to the wallet SDK, as designed by SumUp.
- Card fields are rendered by SumUp; the extension never receives PAN or CVC.
- The local checkout URL is signed from CiviCRM's durable site key and remains valid across the public browser return without depending on a QuickForm session cookie.
- Raw webhook bodies are stored only in MJWShared's protected queue and are not logged.
- Logs use contribution, processor, checkout, and transaction identifiers only.

## Acceptance

- A valid sandbox card completes one Pending contribution and creates one Payment.
- Deliberate SumUp failure amount `11.00` does not complete the contribution.
- Reloading the Widget page and replaying a webhook cannot create a second Payment.
- A changed checkout ID, contribution ID, amount, currency, merchant, reference, or signature is rejected.
- Card authentication can return to the signed Widget URL and complete after server verification.
- Wallet-only mode reports when no compatible wallet is available; combined mode leaves the Card Widget available as fallback.
- Wallet eligibility is decided by the server for each checkout. Apple Pay and
  Google Pay are available only for ordinary one-off payments, never for a
  recurring-card setup or card replacement.
- An unavailable wallet leaves no mounted button, separator or clickable blank
  container in the document. A wallet submission is single-flight until it
  succeeds, is cancelled or fails.
- Test and live processor instances use their own configured SumUp credentials without cross-environment fallback.
- MJWShared is a required dependency and there is no direct webhook fallback.

## Future test mapping

Tests will cover checkout-reference parsing, signed URL validation, provider verification, amount/currency mismatches, PAID idempotence, terminal failure guards, malformed webhook bodies, duplicate webhook delivery, and SDK/network exceptions.
