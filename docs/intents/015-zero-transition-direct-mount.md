# Intent 015: Zero-Transition Direct Mount SumUp Card Widget in Afform

## Context
In current Afform checkouts, a two-step flow is used:
1. User enters contact info and chooses amount.
2. User clicks "Continue to payment", the form compacts, and the SumUp Card Widget mounts.
3. User completes card payment.

This creates friction for modern single-page donation/event forms.

## Goal
Allow the SumUp Card Widget to mount directly upon form load in the right-hand panel of the Bento layout, eliminating the intermediate "Continue to payment" transition.

## Flow & Architecture
1. **Immediate Mount:**
   - When the Afform loads with an active amount, the client requests a checkout session intent for the current amount & currency.
   - `SumUpCard.mount()` is invoked directly into the payment panel.
2. **Live Amount Synchronization:**
   - As the donor changes amounts or selects event ticket options, the cart summary updates in real time.
   - If the total amount changes, the widget is re-initialized with the updated amount.
3. **Single Action Finalization:**
   - The user fills their contact details on the left, card info on the right, and clicks "Pay [Total] €" directly.
   - CiviCRM creates and finalizes the contribution and payment seamlessly.
