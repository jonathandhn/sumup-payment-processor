# Intent: compatibilité MJWShared 1.6 – PaymentProcessorWebhookInterface

Remplace les civiExit() dans handlePaymentNotification(), ajoute rejectDuplicateWebhookEvent()
avec fallback 1.5.x, et adaptateur conditionnel (motif HelloAsso).
