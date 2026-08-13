# Saved card lifecycle

## Intent

Show SumUp saved-card authorisations in enough context for a member or an
administrator to understand why the same masked card can appear more than
once, while keeping every provider token distinct and removable only when it
is safe to do so.

## Provider contract

- SumUp may create several payment-instrument tokens for the same physical
  card. A token is the identity of an authorisation; card brand and last four
  digits are display metadata and must never be used as a database identity.
- `GET /v0.1/customers/{customer_id}/payment-instruments` is authoritative for
  whether a token is active. Instruments explicitly returned with
  `active = false` must not be offered for payment.
- `DELETE /v0.1/customers/{customer_id}/payment-instruments/{token}`
  deactivates an instrument. It does not delete SumUp transaction history.

## CiviCRM contract

- `PaymentToken` remains the local reusable-token record. Contributions and
  payments remain the accounting history.
- Each active SumUp token is enriched with the active `ContributionRecur`
  records which reference it.
- Cards with the same processor, environment, brand and last four digits may
  be grouped for display only. The group retains its individual local token
  IDs and no tokens are merged in storage.
- The interface states both the number of saved authorisations and the number
  of active recurring payments represented by a display group.
- An individual token can be removed only when no `In Progress`
  `ContributionRecur` references it.
- Removal first deactivates the instrument at SumUp. Only after SumUp confirms
  deactivation, or already reports the instrument inactive or absent, is the
  local reusable `PaymentToken` deleted.
- Removing a reusable token never deletes contributions, payments or SumUp
  transaction history.
- A token used by an active schedule has no removal action. The schedule must
  first be stopped or moved to another verified token.

## Replacement boundary

The existing replacement flow changes one recurring payment at a time. A
future bulk replacement may let the payer select several schedules, but must
update each schedule only after the new token has been verified and must
deactivate each old token only after it has no remaining active references.

## Failure handling

- Remote read failure hides the provider's cards rather than presenting stale
  local tokens as usable.
- Remote deactivation failure keeps the local token and returns a visible
  error.
- Repeated removal of an already inactive or absent remote instrument safely
  removes the stale local reusable-token record.
