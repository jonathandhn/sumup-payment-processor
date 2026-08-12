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
- API credentials remain server-side. Cloud API additionally requires an
  Affiliate Key and Application ID for checkout creation.
- Amount, currency and description come from the saved CiviCRM contribution.
- Each attempt has a unique affiliate `foreign_transaction_id`.
- SumUp's HTTPS result URL is queued through MJWShared. CiviCRM completes a
  contribution only after server-side verification.

## First sandbox slice

- Pair one Virtual Solo through an API4 action.
- Persist and rename it deterministically for site `PAR` or `MRS`.
- Synchronise its pairing and device status.
- Render it in a separate Afform Solo checkout option.
- Starting the terminal payment and completing the contribution are the next
  vertical slice after the pairing and rendering contract is validated.

## Acceptance

- A Virtual Solo pairing code creates one local `SumupReader` record.
- Synchronising the same reader updates that record instead of duplicating it.
- The rendered label includes the site and deterministic terminal name.
- An Afform using the Solo option never lists readers belonging to another
  processor environment.
- PHPCS and PHPStan level 8 remain green.
