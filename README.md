# SumUp Payment Processor for CiviCRM

SumUp Payment Processor integrates SumUp online, mobile wallet, QR code, and in-person Solo terminal payments into CiviCRM. It supports donations, memberships, event registrations, traditional CiviCRM contribution pages, Drupal Webform, and FormBuilder/Afform across Drupal, WordPress, and CiviCRM Standalone.

CiviCRM remains the accounting source of truth. The extension creates checkouts server-side, verifies every transaction directly against the SumUp API before recording a payment, and processes webhooks asynchronously through the `MJWShared` (`PaymentprocessorWebhook`) queue.

## Features

- **1-click SumUp OAuth connection** for production accounts (plus API-key support for test/sandbox accounts).
- **Embedded Card Widget, Apple Pay, and Google Pay** (with automatic `/.well-known/apple-developer-merchantid-domain-association` serving), or full-page **SumUp Hosted Checkout**.
- **Saved cards and recurring contributions** with automated daily collection (`SumupRecurringCard.run`), self-service card replacement, amount updates, and scheduled reminders (email and SMS).
- **SumUp Solo Cloud API terminal fleet management** — pair, adopt, and assign Solo readers to site pools (`BAR`, `ACCUEIL`, etc.) for back-office or front-end kiosk payments.
- **Dynamic QR code & hybrid kiosk checkouts** — instant QR fallback when a card reader times out, with optional payment link delivery by **SMS or Email**.
- **Full and partial refunds**, external refund reconciliation, and chargeback/dispute handling.

## Partner Offer & Negotiated SumUp Pricing

As an official **CiviCRM Bronze Partner** and SumUp integration partner, **Jonathan Dahan** provides a negotiated SumUp partner contract with preferential transaction rates for organizations using this extension.

- **Currently open for immediate onboarding:** **France (FR)** and **United Kingdom (UK)**.
- **Worldwide coverage & pre-negotiated European schedule:** Rates are already established for additional markets (see table below) and worldwide onboarding is supported. Contact Jonathan Dahan to activate the partner offer for your organization or to open onboarding in another country.

| Country | Card-Present (Consumer) | Card-Present (Non-Consumer) | Online (Consumer) | Online (Non-Consumer) |
| :--- | :---: | :---: | :---: | :---: |
| **FR** (France) | 1.00% | 1.75% | 1.00% | 2.50% |
| **UK** (United Kingdom) | 1.20% | 1.69% | 1.20% | 2.50% |
| **DK** (Denmark) | 1.00% | 1.49% | 1.00% | 2.50% |
| **CH** (Switzerland) | 1.40% | 1.50% | 1.50% | 2.50% |
| **BE** (Belgium) | 1.10% | 1.69% | 1.10% | 2.50% |
| **DE** (Germany) | 1.10% | 1.39% | 1.10% | 2.50% |
| **ES** (Spain) | 1.10% | 1.49% | 1.10% | 2.50% |
| **IT** (Italy) | 1.30% | 1.95% | 1.30% | 2.50% |
| **NL** (Netherlands) | 1.10% | 1.49% | 1.10% | 2.50% |

To benefit from these rates, launch the offer in your country, or schedule a call:
- **CiviCRM Partner Profile & Booking:** [https://civicrm.com/partners/jonathan-dahan/](https://civicrm.com/partners/jonathan-dahan/)
- **Direct Contact:** [https://www.jonathan.paris/en/](https://www.jonathan.paris/en/)

## Requirements

- CiviCRM 6.16 or later.
- PHP 8.2 through 8.5.
- `mjwshared` extension (durable webhook queue).
- Optional: `formbuilder-payment-extra` for multi-option FormBuilder/Afform checkouts (Card/Wallets, Solo terminal, QR code, and Hybrid Kiosk).

## Quick Setup

1. Create a **SumUp** payment processor under **Administer > CiviCRM Payment Processors**:
   - **Live mode:** Save the processor and click **Connect to SumUp** to authorize your merchant account via OAuth.
   - **Test mode:** Enter your sandbox **Merchant code** and **API key**.
   - **Wallets (optional):** Enter your SumUp **Public merchant key** (`sup_pk_...`) on the processor, and configure your Apple Pay domain association text and/or Google Pay merchant ID under **Contributions > SumUp settings**.
2. Choose your checkout mode (**Card Widget**, **Card Widget and wallets**, **Wallets only**, or **SumUp Hosted Checkout**) under **Contributions > SumUp settings**.
3. Pair or adopt SumUp Solo terminals under **Contributions > SumUp Card Readers** (`civicrm/admin/sumup/readers`).

## Support the project

SumUp payment processor is independently developed and currently has no institutional or corporate sponsor. If your organization benefits from this work, you can help fund its maintenance, documentation, provider testing or future development.

To sponsor work on SumUp payment processor, activate the negotiated SumUp partner rates, or request professional support from a **CiviCRM Bronze Partner**, please [schedule a call on CiviCRM.com](https://civicrm.com/partners/jonathan-dahan/) or [contact Jonathan Dahan](https://www.jonathan.paris/en/).

## License

SumUp payment processor is distributed under the [GNU Affero General Public License v3.0](https://www.gnu.org/licenses/agpl-3.0.html).
