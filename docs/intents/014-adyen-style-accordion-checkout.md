# Intent 014: Adyen-style accordion checkout for Afform

## Problem Statement

When completing a contribution on CiviCRM Afform with SumUp, the transition from Step 1 (contact/contribution details) to Step 2 (payment checkout) previously relied on hiding form elements using CSS classes. This created layout instability and could interfere with SumUp's internal iframe fieldsets.

## Desired Outcome

Implement an accordion-based multi-step checkout inspired by modern design systems (Adyen Pay by Link):
1. **Step 1: Contact & Details ("Récapitulatif")**:
   - Initial state: Full Afform inputs with action button to proceed.
   - Collapsed state: Compact summary bar displaying contact info, amount, and an edit button (`Modifier`) to re-open the step seamlessly.
2. **Step 2: Payment ("Choisissez un moyen de paiement")**:
   - Prominent header with step badge and total amount.
   - Structured payment options (saved cards, new card entry, wallets, QR code).
   - Clean transitions without destructive CSS selectors.

## Architecture

- Angular component `afSumUpEmbeddedCheckout` manages accordion states (`step1Active`, `step2Active`).
- Reversible transition on `Modifier` button without page reload.
- Mobile responsive layout with fluid spacing and zero horizontal overflow.
