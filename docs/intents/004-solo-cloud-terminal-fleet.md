# Intent 004: Solo Cloud terminal fleet and Afform checkout

## Outcome

An administrator can manage their entire SumUp Solo terminal fleet across CiviCRM sites from a modern Afform UI (`civicrm/admin/sumup-readers`), pair new readers using their on-screen pairing code, adopt existing Cloud readers for a site, and unpair or delete readers. An Afform builder can offer **SumUp - Solo terminal** as a checkout option. An authorised operator sees only paired terminals for the selected site and can start an in-person payment on a SumUp Solo or Virtual Solo.

## Terminal identity

- `device.identifier` identifies the physical device and survives a new API
  pairing.
- SumUp's `reader_id` identifies a pairing and may change after re-pairing.
- Every local reader has a mandatory, immutable site code.
- The canonical label is deterministic:
  `TPE-{SITE}-{DEVICE_IDENTIFIER_SUFFIX}`.
- A terminal never falls back to another site. An unavailable Paris terminal
  must not cause a checkout to start in Marseille.

## Fleet administration & Afform management

- An administrator interface (`afSumupReaders`) at `civicrm/admin/sumup-readers` provides comprehensive fleet management:
  - processor selection (Live and Sandbox);
  - fleet status synchronisation (`SumupReader.synchronise`);
  - pairing new terminals with site code and on-screen pairing code (`SumupReader.pair`);
  - discovering unassigned merchant readers and adopting them for a site (`SumupReader.listDiscovered` and `SumupReader.adopt`);
  - disconnecting / unpairing terminals from SumUp Cloud API and CiviCRM (`SumupReader.unpair`);
  - automatic deactivation of local readers deleted directly on `me.sumup.com`.

## Afform checkout contract

- Solo is a distinct CheckoutOption using the existing SumUp processor; it is
  not another payment-processor record and not a global online checkout mode.
- The form builder may offer online SumUp and Solo SumUp independently.
- The browser submits only the local reader record ID in `checkout_params`.
- The server revalidates processor, test mode, site, pairing, activity and
  permission before starting a terminal checkout.
- If exactly one eligible reader exists it may be preselected. If several
  exist, the operator chooses one. If none exists, checkout is blocked.

## Provider contract

- Pairing, listing, status, checkout, termination and deletion use the maintained SumUp
  PHP SDK Readers service.
- API credentials remain server-side. Every Reader Checkout includes the
  Affiliate Key and matching Application ID required by SumUp Cloud API.
- Amount, currency and description come from the saved CiviCRM contribution.
- Each attempt has a unique affiliate `foreign_transaction_id`.
- SumUp's HTTPS result URL is queued through MJWShared. CiviCRM completes a
  contribution only after server-side verification.

## Acceptance

- A Virtual Solo pairing code creates one local `SumupReader` record.
- Synchronising the same reader updates that record instead of duplicating it.
- Deleting a reader from SumUp Cloud marks the local CiviCRM record inactive upon sync.
- The rendered label includes the site and deterministic terminal name.
- An Afform using the Solo option never lists readers belonging to another
  processor environment.
- An Afform Solo payment replaces the submitted form with a dedicated terminal
  waiting state, remains pending while no authoritative transaction exists, and completes only after
  the SumUp transaction matches merchant, amount, currency and contribution.
- CiviCRM's native test contribution form can send a payment to Virtual Solo
  without exposing or collecting card details in CiviCRM.
- PHPCS and PHPStan level 8 remain green.
