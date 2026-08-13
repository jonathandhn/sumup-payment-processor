# Intent 004: Solo Cloud terminal fleet and Afform checkout

## Outcome

An Afform builder can offer **SumUp - Solo terminal** as a checkout option. An
authorised operator sees only paired terminals for the selected site and can
start an in-person payment on a SumUp Solo or Virtual Solo.

## Terminal identity

- `device.identifier` identifies the physical device and survives a new API
  pairing.
- SumUp's `reader_id` identifies a pairing and may change after re-pairing.
- Every local reader has a mandatory, immutable site code.
- The canonical label is deterministic:
  `TPE-{SITE}-{DEVICE_IDENTIFIER_SUFFIX}`.
- A terminal never falls back to another site. An unavailable Paris terminal
  must not cause a checkout to start in Marseille.

## Afform contract

- Solo is a distinct CheckoutOption using the existing SumUp processor; it is
  not another payment-processor record and not a global online checkout mode.
- The form builder may offer online SumUp and Solo SumUp independently.
- The browser submits only the local reader record ID in `checkout_params`.
- The server revalidates processor, test mode, site, pairing, activity and
  permission before starting a terminal checkout.
- If exactly one eligible reader exists it may be preselected. If several
  exist, the operator chooses one. If none exists, checkout is blocked.

## Provider contract

- Pairing, listing, status, checkout and termination use the maintained SumUp
  PHP SDK Readers service.
- API credentials remain server-side. Every Reader Checkout includes the
  Affiliate Key and matching Application ID required by SumUp Cloud API.
- Amount, currency and description come from the saved CiviCRM contribution.
- Each attempt has a unique affiliate `foreign_transaction_id`.
- SumUp's HTTPS result URL is queued through MJWShared. CiviCRM completes a
  contribution only after server-side verification.

## First sandbox slice

- Pair one Virtual Solo through an API4 action.
- Adopt an already paired physical Solo only through an explicit API4 action
  naming its remote reader ID and CiviCRM site code.
- Persist and rename it deterministically for site `PAR` or `MRS`.
- Synchronise its pairing and device status.
- Render it in a separate Afform Solo checkout option.
- Start the same Reader Checkout from Afform and the native back-office form.
- Store the exact client transaction ID in the signed CheckoutSession so the
  landing page never resolves an attempt by contribution alone.
- Keep the Afform contribution Pending while the terminal is waiting for the
  cardholder, then verify the transaction through SumUp before success.
- Map SumUp `PENDING` to a local pending attempt; only explicit `FAILED` or
  `CANCELLED` terminal states may fail the CiviCRM contribution.
- Replace the raw card fields with the paired-reader selector when an operator
  opens CiviCRM's native back-office contribution form. Do not add a dedicated
  test-collection shortcut to the global Contributions menu.
- Create the contribution as Pending, send its amount to the selected Solo and
  persist the returned client transaction ID together with the reader ID.
- Queue `solo.transaction.updated` through MJWShared, read the authoritative
  transaction from SumUp, then complete or fail the contribution idempotently.

## Acceptance

- A Virtual Solo pairing code creates one local `SumupReader` record.
- Synchronising the same reader updates that record instead of duplicating it.
- The rendered label includes the site and deterministic terminal name.
- An Afform using the Solo option never lists readers belonging to another
  processor environment.
- An Afform Solo payment replaces the submitted form with a dedicated terminal
  waiting state, remains pending while no authoritative transaction exists, and completes only after
  the SumUp transaction matches merchant, amount, currency and contribution.
- CiviCRM's native test contribution form can send a payment to Virtual Solo
  without exposing or collecting card details in CiviCRM.
- PHPCS and PHPStan level 8 remain green.
