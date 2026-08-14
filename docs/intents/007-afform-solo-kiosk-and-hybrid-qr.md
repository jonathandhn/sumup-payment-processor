# Intent 007: Afform Solo kiosk mode, reader visualization and hybrid QR instant payment

## Outcome

Elevate in-person Afform checkouts (self-service kiosks, event registration desks, donation stations) into an intuitive, responsive hybrid experience:

- When a payer or operator initiates a terminal checkout, the page remains active and enters an immersive payment presentation instead of closing abruptly.
- The interface features a vector visualization of the SumUp Solo terminal displaying the exact payment amount, site code, and animated status cue ("Please insert, tap, or swipe your card...").
- A side-by-side **Instant QR Payment** code is generated in parallel, allowing donors or customers to complete the payment on their own smartphone (via Apple Pay, Google Pay, or Card Widget) if they prefer contactless mobile payment or if terminal input is inconvenient.
- **Dual Independent Timers**:
  - The physical Solo reader hardware timeout is approximately 60 seconds. When this window expires without a card presented, the left card transitions to a clear *Terminal session timed out* state with a one-click *Resend to terminal* button.
  - The **QR Code mobile session remains active for 5 minutes (300s)**, giving the payer ample time to scan the code, enter credentials, and complete mobile bank 3D Secure verification without interruption.
- **Live Bidirectional Synchronization**:
  - The kiosk continuously polls the checkout status. As soon as the smartphone completes the payment via 3DS, the kiosk interface instantly transitions to the public confirmation receipt with the transaction reference.
- **Dedicated Mobile Success Page**:
  - When the payer completes the payment on their smartphone via the QR code, the mobile browser renders a dedicated *Payment Confirmed!* confirmation view rather than redirecting to an empty donation form.

## Architecture

### 1. Backend Checkout Response

`Civi\SumupPaymentProcessor\CheckoutOption\SumUpSoloCheckout::startCheckout()` returns:
- `token`: CiviCRM CheckoutSession token
- `reader_name`: Canonical name of the assigned Solo
- `site_code`: Site / pool code (e.g. `BAR`, `ACCUEIL`)
- `amount`: Formatted transaction amount
- `currency`: ISO currency code
- `qr_url`: Signed short browser URL for instant smartphone payment (`/civicrm/sumup/widget?c=...&p=...&s=...`)
- `client_transaction_id`: SumUp reader checkout identifier

### 2. Frontend Hybrid Layout

`afSumUpSoloCheckout` renders:
- **Left Column**: SumUp Solo terminal card with live state indicators, 60s reader countdown, and in-place *Resend to terminal* action upon terminal expiry.
- **Right Column**: Smartphone Instant QR code with Apple Pay / Google Pay / Card badges, remaining fully active for 5 minutes.
- **Success View**: Public confirmation badge with transaction receipt details and clean form reset button.
- **Failure View**: Explanatory message and one-click retry on terminal or return to form.

### 3. Dual Record Handling (Online vs Reader)

To allow seamless coexistence of the physical reader and the mobile QR payment on the same contribution:
- The terminal checkout is recorded in `civicrm_sumup_checkout` with `checkout_mode = 'SOLO'`.
- The online embedded card checkout for mobile scanning is pre-created upfront with `checkout_mode = 'WIDGET'` or `'HOSTED'`.
- When `/civicrm/sumup/widget` renders for a QR code visitor, `CRM_SumupPaymentProcessor_CheckoutStore::getLatestOnlineByContributionId()` retrieves the online card checkout and bypasses the Solo reader record, ensuring no conflict with SumUp's Hosted/Card API.
