# Intent 006: Merchant profile, Doing Business As, country precedence and currency binding

## Outcome

Safeguard multi-merchant CiviCRM deployments and deliver clear branding to payers:

- CiviCRM administration surfaces consistently display the unique `merchant_code` (e.g. `MC34VNWN`) to avoid configuration and routing ambiguities.
- Public payment pages, widget checkouts and digital wallet sheets (Apple Pay / Google Pay) display the merchant's commercial name (**Doing Business As** / `doing_business_as.business_name`) rather than technical identifiers.
- Apple Pay and Google Pay resolve the merchant's 2-letter ISO country code using strict precedence:
  1. SumUp merchant profile country (`country` from `GET /v0.1/me/merchant-profile`).
  2. CiviCRM organization domain default country.
- Each payment processor validates that its configured `merchant_code` matches the authenticated SumUp account.
- SumUp processors bind to their account's supported currency (EUR, GBP, etc.). Discrepancies are reported with clear provider-level guidance while keeping multi-currency expansion paths open.
- The merchant profile is cached in CiviCRM's long cache (24 hours) with graceful fallback to prevent any payment latency.

## Architecture

### 1. Merchant Profile & Long Cache

`CRM_SumupPaymentProcessor_CheckoutService::getMerchantProfile()` retrieves the profile from `GET /v0.1/me/merchant-profile` and caches it in `Civi::cache('long')` under `sumup.merchant_profile.{processor_id}`.

Returned structure:
- `merchant_code`: e.g. `MC34VNWN`
- `doing_business_as`: commercial name (e.g. `Bar Associatif Transform`)
- `company_name`: legal entity name
- `country`: ISO country code (e.g. `FR` / `FRA`)
- `currency`: Primary account currency (e.g. `EUR`)

### 2. Country Resolution for Wallets

`CRM_Core_Payment_Sumup::getMerchantCountryCode()` applies the fallback hierarchy:
1. ISO-2 conversion of SumUp profile `country` (e.g. `FRA` -> `FR`).
2. `CRM_Core_BAO_Domain::getDomain()->country_id` mapped to ISO-2.
3. Default `FR` if unconfigured.

### 3. Currency Validation

Before initiating a checkout, the processor ensures the requested contribution currency matches the merchant's active currency. If mismatched, a precise `PaymentProcessorException` is raised before creating an orphaned SumUp session.
