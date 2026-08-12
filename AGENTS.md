# SumUp payment processor working agreement

## Product contract

- CiviCRM is the accounting source of truth.
- Describe each feature in `docs/intents/` before implementing it.
- The SumUp browser callback is an immediate verification opportunity, never proof by itself.
- Complete a contribution only after retrieving the checkout from SumUp and validating its identity, merchant, amount, currency, and paid state.
- Queue every SumUp webhook in MJWShared. Do not add a private queue or a fallback webhook consumer.
- Never expose or log API keys, card data, or unfiltered provider payloads.

## Compatibility and quality

- Minimum CiviCRM: 6.16.
- Supported PHP: 8.2 through 8.5.
- New maintained code must pass `composer cs` and PHPStan level 8.
- Civix-generated files are excluded from style and static analysis.
- Keep provider-specific freedom behind clear SumUp classes; do not create an abstract multi-PSP framework prematurely.

## Local references

- CiviCRM payment extensions: `../helloasso-payment-processor`, `../nz.co.fuzion.cmcic`, `../stancer`, `../stripe`.
- SumUp PHP SDK: `../reference/sumup/sumup-php`.
- SumUp TypeScript SDK: `../reference/sumup/sumup-ts`.

## Git

- Keep changes small and commits feature-scoped.
- Do not commit, push, or publish unless explicitly requested.
