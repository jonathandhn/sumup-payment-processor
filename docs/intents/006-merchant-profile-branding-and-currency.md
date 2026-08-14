# Intent 006: Authoritative merchant profile, customer-facing name and currency binding

## Outcome

Safeguard multi-merchant CiviCRM deployments and deliver clear branding to payers:

- CiviCRM administration surfaces consistently display the unique `merchant_code` (e.g. `MC34VNWN`) to avoid configuration and routing ambiguities.
- Public embedded card and wallet checkouts display the merchant's customer-facing business name (`business_profile.name`) rather than technical identifiers.
- Apple Pay and Google Pay use the merchant's 2-letter ISO country code returned by SumUp.
- Each payment processor validates that its configured `merchant_code` matches the authenticated SumUp account.
- Each SumUp processor represents one SumUp merchant account. A contribution in a different currency from that account's `default_currency` is rejected by one explicit and reversible guard before a checkout is created.
- The merchant profile is cached in CiviCRM's long cache (24 hours), but only after its merchant code, country and currency have been verified. An unavailable or invalid profile fails explicitly rather than inventing a country or currency.
- Multiple currencies remain possible through multiple CiviCRM processors backed by the corresponding SumUp merchant accounts.

## Architecture

### 1. Merchant Profile & Long Cache

`CRM_SumupPaymentProcessor_CheckoutService::getMerchantProfile()` retrieves the configured merchant from `GET /v1/merchants/{merchant_code}` through the typed SumUp PHP SDK and caches the verified result in `Civi::cache('long')` under `sumup.merchant_profile.{processor_id}`.

Returned structure:
- `merchant_code`: e.g. `MC34VNWN`
- `business_name`: customer-facing commercial name
- `company_name`: legal entity name
- `country`: ISO 3166-1 alpha-2 country code (e.g. `FR`)
- `currency`: ISO 4217 default account currency (e.g. `EUR`)

### 2. Country Resolution for Wallets

`CRM_SumupPaymentProcessor_CheckoutMode::getMerchantCountryCode()` accepts only the verified ISO alpha-2 value returned by SumUp. There is no manual country setting and no CiviCRM-domain or hard-coded fallback.

### 3. Currency Validation

Before initiating a checkout, the processor ensures the requested contribution currency matches the merchant's active currency. If mismatched, a precise `PaymentProcessorException` is raised before creating an orphaned SumUp session.

### 4. Afform composition

The extension publishes its CheckoutOptions through one AutoService listener. It does not replace the global CheckoutBlock administration template. SumUp, HelloAsso and other hosted or embedded processors can therefore coexist without the last-loaded extension erasing another processor's controls.
