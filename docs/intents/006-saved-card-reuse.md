# Intent 006: SumUp saved-card visibility and reuse

## Outcome

A contact can see the SumUp cards that are currently active for their CiviCRM
identity and can explicitly reuse one for a new online contribution. An
administrator can see the same cards from the contact summary. No SumUp token
is exposed to the browser or placed in a URL.

## Identity and authorisation

- Self-service accepts either the logged-in contact or CiviCRM's standard
  `cid`/`cs` contact checksum.
- A contact-summary Afform may request another contact only when the current
  user has `access CiviContribute` and `edit contributions`.
- A replacement link still names one `ContributionRecur`; the API independently
  verifies that the schedule belongs to the authorised contact.
- A new-payment card choice is authorised by a short-lived signature over the
  CiviCRM contribution, SumUp checkout, processor and expiry. The selected
  local `PaymentToken` is then checked against the contribution contact and
  processor on the server.
- Browser data contains only the local token ID, card brand and last four
  digits. Provider tokens and API credentials remain server-side.

## Provider verification

- Local `PaymentToken` records are intersected with SumUp's active payment
  instruments for the deterministic merchant-scoped customer.
- A standard `CHECKOUT` is created with that `customer_id` before an existing
  card is offered.
- On explicit selection, the server processes the checkout with the verified
  `customer_id` and provider token.
- `PAID`, amount, currency, merchant and contribution are verified through an
  authoritative SumUp read before CiviCRM completes the contribution.
- A SumUp `next_step` is returned only for HTTPS and only for `GET` or `POST`.
  The payer follows it immediately for 3DS and then returns to the signed
  checkout landing URL.

## User interfaces

- The managed payment-method Afform is also placed on the CiviCRM contact
  summary and lists cards independently from recurring schedules.
- Its contact tab is labelled `SumUp`, remains visible when empty, and shows a
  local count of saved cards plus active recurring schedules. Building the
  contact page must not make a remote SumUp API request.
- Embedded QuickForm and Afform checkouts show active saved cards before the
  existing SumUp Widget. The payer may choose one card or continue with a new
  card.
- Hosted Checkout remains unchanged: it redirects immediately to SumUp and
  does not expose the local saved-card chooser.
- Back-office collection with a saved card is a separate follow-up: an
  administrator cannot complete the payer's 3DS challenge. It requires a
  customer-facing, signed confirmation link rather than silently charging from
  the administration form.

## Failure and recovery

- Missing, inactive or mismatched instruments are rejected before processing.
- Repeated calls reuse the registered checkout and are idempotent at CiviCRM's
  payment layer.
- A transport failure leaves the contribution and checkout Pending for webhook
  processing or authoritative reconciliation.
- Failure to list optional saved cards falls back to the existing new-card
  Widget and never blocks the contribution form.
