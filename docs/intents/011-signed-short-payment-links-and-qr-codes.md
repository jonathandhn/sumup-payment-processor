# Intent 011: Signed short payment links and standalone QR code payments

## Outcome

Provide a lightweight, secure, and universal **3rd payment modality** in addition to embedded forms and physical terminals: **Signed Short Payment Links and QR Codes**.

This modality allows donors and customers to settle pending contributions from any device without requiring a CiviCRM user session, while maintaining strict cryptographic security against tampering.

It supports both:
1. **Interactive Kiosk display** (instant on-screen QR code during live terminal transactions).
2. **Asynchronous / External sharing** (PDF invoices, email payment requests, SMS payment reminders, printed event badges, flyers, and physical letters).

## Architecture

### 1. Short URL Structure & HMAC Security

The payment link is formatted as:
```text
https://example.org/civicrm/sumup/widget?c=176&p=11&s=1fa6565a5644
```

- **`c` (Contribution ID)**: Integer ID of the target `civicrm_contribution` record.
- **`p` (Processor ID)**: Integer ID of the `civicrm_payment_processor` record.
- **`s` (HMAC Signature)**: 12-character hexadecimal signature computed as:
  ```php
  substr(hash_hmac('sha256', $contributionId . ':' . $processorId, CRM_Core_Payment_Sumup::getBrowserReturnSigningKey()), 0, 12);
  ```

#### Security Properties:
- **Tamper-proof**: Any alteration of `c` or `p` invalidates the signature, immediately returning an error without exposing internal data.
- **No privilege elevation**: The link only grants permission to view the payment widget and pay the exact amount of that specific pending contribution.
- **Zero credential exposure**: No API keys, merchant tokens, or card numbers are ever embedded in the URL.
- **Low density for QR scanning**: Kept strictly under 60 characters, producing a low-density (Version 3) QR matrix that mobile cameras scan reliably from distance, poor lighting, or damaged printouts.

### 2. Lifecycle and Status Transitions

1. **Pending Payment (`Pending`)**:
   - The link loads the SumUp Card Widget (with Apple Pay, Google Pay, and Credit Card options).
   - If an online checkout session does not already exist or has expired in SumUp, `CRM_Core_Payment_Sumup::startEmbeddedCheckoutForContribution()` provisions a fresh checkout session on the fly.
2. **Completed Payment (`Completed`)**:
   - Once payment is verified and completed via SumUp (including 3D Secure), `Widget.php` marks the transaction paid in CiviCRM.
   - The page displays a dedicated confirmation receipt view (*"Payment Confirmed! Thank you! Your payment has been approved and recorded successfully."*) with the amount and currency.
   - Re-payment is automatically locked and prevented.
3. **Cancelled / Failed / Deleted**:
   - If the contribution is cancelled or no longer exists, the page refuses payment initiation.

### 3. External Sharing Channels (Beyond Kiosk)

- **PDF Invoices & Receipts**: Embedding `{contribution.sumup_payment_link}` or the QR image into invoice templates for one-scan settlement.
- **SMS Reminders**: Ultra-short link fits into a single 160-character SMS for payment reminders.
- **Email Workflow Messages**: Action buttons in contribution confirmation or renewal notices.
- **Printed Materials**: Badges, gala programs, pledge cards, and donation envelopes.

### 4. Developer & Hook API

```php
$contributionId = (int) $contribution['id'];
$processorId = (int) $processor->getProcessorId();
$key = CRM_Core_Payment_Sumup::getBrowserReturnSigningKey();
$sig = substr(hash_hmac('sha256', $contributionId . ':' . $processorId, $key), 0, 12);

$url = CRM_Utils_System::url(
    'civicrm/sumup/widget',
    ['c' => $contributionId, 'p' => $processorId, 's' => $sig],
    true,
    null,
    false,
    true
);
```
