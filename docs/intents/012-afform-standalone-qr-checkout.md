# Intent 012: Afform Standalone Dynamic QR Code Checkout

## Problem
Currently, the QR Code is displayed only as a fallback alongside the physical SumUp Solo card reader in `SumUpSoloCheckout`. 
However, many use cases (desktop donation forms, self-service kiosks without card readers, event screens, reception counters, or print/poster stations) require a **standalone Dynamic QR Code checkout option** in Afform / Form Builder without referencing or waking up a physical card reader.

## Proposed Solution
Introduce a dedicated Checkout Option `SumUpQrCheckout` (`sumup_qr_checkout_$name`) in Afform:

1. **Option Registration**:
   - Label: `SumUp (Dynamic QR Code)`
   - Frontend Label: `SumUp (QR Code / Smartphone)`
   - Description: `Scan a dynamic QR code to pay on your phone.`

2. **User Flow**:
   - Contributor fills the Afform form and submits with the QR Code option.
   - The Afform form transitions to the QR kiosk view (`afSumUpQrCheckout`).
   - A dynamic, high-contrast SVG QR Code is generated with the payment URL.
   - The contributor scans the QR code with their mobile device (Apple Pay, Google Pay, or Card).
   - The Afform screen polls the contribution / checkout status and updates in real-time to **Payment Confirmed!** 🎉 as soon as the mobile payment succeeds.
   - Contributor can cancel and return to the form at any moment.

## Security & Architectural Guarantees
- CiviCRM remains the accounting source of truth.
- The QR payment URL uses a signed token (`civicrm/sumup/pay?token=...`) ensuring integrity.
- Contribution completion is triggered only after verifying the paid checkout with SumUp.
