# Contact merge-safe SumUp customers

## Intent

Keep every reusable SumUp payment instrument usable when its CiviCRM contact
is merged into another contact or converted through a workflow which changes
the contact identifier.

## Identity contract

- `PaymentToken.contact_id` remains the current CiviCRM owner. CiviCRM core
  reassigns this foreign key to the surviving contact during a merge.
- A SumUp payment-instrument token remains owned by the SumUp `customer_id`
  under which it was created. A CiviCRM merge must never pretend that the
  remote instrument moved to another SumUp customer.
- `civicrm_sumup_payment_token_customer` stores the durable one-to-one mapping
  between the local `PaymentToken.id` and its original SumUp `customer_id`.
  It deliberately contains no CiviCRM contact identifier.
- Existing mappings are backfilled from the checkout registry. A legacy token
  without usable history falls back to the deterministic customer of its
  current contact only after the remote instrument has been found there.

## Operational behaviour

- Card listing groups local tokens by their durable SumUp customer and reads
  every customer represented by the current contact's tokens.
- Recurring charges, saved-card payments, replacement and deactivation use the
  SumUp customer stored for the selected token.
- A newly authorised card is still created under the deterministic SumUp
  customer of the current surviving CiviCRM contact.
- A one-off checkout is not bound to the current contact's SumUp customer
  before a saved card is selected, allowing a card inherited through a merge
  to be processed with its original remote customer.

## Contact type changes

Changing Individual, Organization or Household while retaining the same
`contact_id` has no effect on this contract. If a conversion creates a new
contact and merges the old one into it, the standard merge behaviour above
applies.

## Safety boundaries

- Card brand and last four digits are display data and are never used to infer
  ownership.
- A local token cannot be silently rebound to a different SumUp customer.
- A remote read failure hides only the instruments belonging to the affected
  SumUp customer and is logged; it does not rewrite mappings.
- Changing the payer from an individual to an organization without merging
  the contacts is a separate business operation and must not transfer payment
  instruments implicitly.
