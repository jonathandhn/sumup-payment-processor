# Intent 013: Send Payment Link by SMS or Email from QR Views

## Problem
On QR payment screens (both **Standalone QR** and **Hybrid Split View**), contributors or counter operators may experience difficulties scanning the physical screen with a smartphone camera, or the contributor may prefer receiving the payment link directly on their mobile device (SMS or Email) to pay in their own time.

## Proposed Solution
1. **Admin Settings**:
   - `sumup_qr_allow_send_email`: Enable sending the payment link by Email.
   - `sumup_qr_allow_send_sms`: Enable sending the payment link by SMS.
   - `sumup_qr_sms_provider_id`: Select the active SMS Provider / Gateway from CiviCRM to send the SMS messages.

2. **API4 Endpoint**:
   - `SumupCheckout.sendPaymentLink` takes `{ token, channel: 'email'|'sms', recipient: '...' }`.
   - Validates the active checkout session, constructs the signed payment URL, and dispatches the message via email or the selected CiviCRM SMS provider.

3. **User Experience**:
   - Beneath the QR code on the kiosk / Afform screen, an optional "Send link to phone" button allows entering an email address or mobile number.
   - An immediate visual confirmation is displayed upon sending.
   - The session continues polling so that when the recipient opens the link on their phone and pays, the screen still updates in real-time to **Payment Confirmed!** 🎉.
