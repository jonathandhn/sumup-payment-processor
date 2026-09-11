# Intent 016: Authoritative tax-inclusive checkout amount

## Problem

CiviCRM persists the accounting amount on `Contribution.total_amount`. Taxed
line items persist their tax-exclusive amount in `LineItem.line_total` and the
corresponding tax in `LineItem.tax_amount`.

The SumUp checkout creation path currently allows a browser-provided
`order_total`, or a sum of tax-exclusive line items, to replace the persisted
contribution amount. This can undercharge tax and can overcharge a
multi-entity checkout when equivalent Contribution and Participant line items
are both attached to the same contribution.

## Contract

- `Contribution.total_amount` is the only amount sent to SumUp.
- A browser-provided total never updates a persisted contribution during
  payment-processor execution.
- Line-item diagnostics use the tax-inclusive expression
  `line_total + tax_amount`.
- A line-item mismatch is logged for investigation and never silently changes
  the checkout amount.
- Currency comparisons use CiviCRM's currency precision.
- The existing Afform layout, summary and SumUp mounting behavior are not
  changed by this intent.

## Verification

- An untaxed contribution sends its persisted total.
- A taxed contribution sends the persisted tax-inclusive total.
- Mixed taxed and tax-exempt lines send the persisted contribution total.
- Duplicate Contribution and Participant line items cannot increase the
  amount charged by SumUp.
- A changed `checkout_params.order_total` cannot alter the contribution or the
  SumUp checkout amount.
- Provider callbacks and webhooks still complete a contribution only after an
  authoritative SumUp API read validates amount, currency, merchant and paid
  state.
