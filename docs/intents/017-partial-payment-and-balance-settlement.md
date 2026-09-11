# Intent 017: Partial payment and balance settlement support

## Problem

When a back-office administrator or customer settles a partial balance or an installment on an existing contribution (e.g. contribution of 100 €, 80 € already paid, requested balance payment of 20 €), CiviCRM passes the requested transaction attempt amount (20 €) in `$params` / `$propertyBag->getAmount()`.

Previously, the processor ignored this attempt amount and reloaded `Contribution.total_amount` (100 €) across Solo reader checkouts, online checkouts, registry verification, and `Payment::create`, causing over-charging and corrupted financial records.

Furthermore, any partial payment handling must preserve the tax-inclusive accounting model (established in Intent 016) and never drop or misallocate `tax_amount`.

## Contract

- `doPayment()` captures the requested attempt amount (`$propertyBag->getAmount()` or `$params['amount']`), falling back to `Contribution.total_amount` (TTC) if unspecified or zero.
- Reader checkouts (Solo) and online checkouts (Hosted, Embedded, Widget) create the SumUp checkout for the requested attempt amount.
- `CRM_SumupPaymentProcessor_CheckoutStore::recordCreated()` persists the exact attempt amount in `civicrm_sumup_checkout.amount`.
- SumUp checkout verification (`assertMatchesContribution`, `validateCheckout`, `validateSoloReaderCheckout`) and webhooks validate the provider amount against the recorded attempt amount (`$registry['amount']`), not `Contribution.total_amount`.
- `Payment::create` in CiviCRM records the actual paid attempt amount (`$registry['amount']`), allowing CiviCRM to transition contribution status appropriately (e.g. `Partially paid` to `Completed` when balance reaches 0, or remaining `Partially paid`).
- Tax-inclusive integrity is preserved: line items and totals always account for `tax_amount`, and partial payments never alter the total contribution accounting structure.
- If a contribution is already `Completed` or `Refunded`, attempting an additional payment without a matching existing SumUp transaction is rejected.

## Verification

- Settling a 20 € balance on a 100 € contribution sends 20 € to SumUp (Solo or online).
- `SumupCheckout` registry stores 20.00 €.
- Webhooks and browser callbacks validate 20.00 € against SumUp API.
- `Payment::create` logs a payment of 20.00 €.
- Full one-time payments (taxed, tax-exempt, or mixed) continue to process the entire contribution total seamlessly.
- PHPStan level 8 and PHP_CodeSniffer pass without errors.
