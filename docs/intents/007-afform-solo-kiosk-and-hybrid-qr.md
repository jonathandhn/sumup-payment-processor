# Intent 007: Afform Solo kiosk mode, reader visualization and hybrid QR instant payment

## Outcome

Elevate in-person Afform checkouts (self-service kiosks, event registration desks, donation stations) into an intuitive, responsive hybrid experience:

- When a payer or operator initiates a terminal checkout, the page remains active and enters an immersive payment presentation instead of closing abruptly.
- The interface features a vector visualization of the SumUp Solo terminal displaying the exact payment amount, site code, and animated status cue ("Please insert, tap, or swipe your card...").
- A side-by-side **Instant QR Payment** code is generated in parallel, allowing donors or customers to complete the payment on their own smartphone (via Apple Pay, Google Pay, or Card Widget) if they prefer contactless mobile payment or if terminal input is inconvenient.
- Status is polled smoothly in real time: as soon as either the Solo terminal or the QR mobile payment completes, the interface instantly transitions to a public confirmation receipt.
- In case of a terminal timeout or payment refusal, the interface displays actionable guidance with an immediate one-click retry button.

## Architecture

### 1. Backend Checkout Response

`Civi\SumupPaymentProcessor\CheckoutOption\SumUpSoloCheckout::startCheckout()` returns:
- `token`: CiviCRM CheckoutSession token
- `reader_name`: Canonical name of the assigned Solo
- `site_code`: Site / pool code (e.g. `BAR`, `ACCUEIL`)
- `amount`: Formatted transaction amount
- `currency`: ISO currency code
- `qr_url`: Signed browser URL for instant smartphone payment fallback
- `client_transaction_id`: SumUp reader checkout identifier

### 2. Frontend Hybrid Layout

`afSumUpSoloCheckout` renders:
- **Left Column**: SumUp Solo terminal card with live state indicators and clear reader instructions.
- **Right Column**: Smartphone Instant QR code with Apple Pay / Google Pay / Card badges.
- **Success View**: Public confirmation badge with transaction receipt details and kiosk reset button.
- **Failure View**: Explanatory message and one-click retry on terminal or QR code.
