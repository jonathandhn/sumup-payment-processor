<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupCheckout;

use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Checkout\CheckoutSession;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

/**
 * Send a secure payment link by SMS or Email from QR views.
 *
 * @method $this setToken(string $token)
 * @method $this setChannel(string $channel)
 * @method $this setRecipient(string $recipient)
 */
final class SendPaymentLink extends AbstractAction
{
    protected string $token = '';

    protected string $channel = 'email';

    protected string $recipient = '';

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        if (trim($this->token) === '') {
            throw new PaymentProcessorException(E::ts('The checkout session token is missing.'));
        }

        $channel = strtolower(trim($this->channel));
        if (!in_array($channel, ['email', 'sms'], true)) {
            throw new PaymentProcessorException(E::ts('Invalid channel (must be email or sms).'));
        }

        $recipient = trim($this->recipient);
        if ($recipient === '') {
            throw new PaymentProcessorException(E::ts('Recipient address or phone number is required.'));
        }

        $allowEmail = (bool) \Civi::settings()->get('sumup_qr_allow_send_email');
        $allowSms = (bool) \Civi::settings()->get('sumup_qr_allow_send_sms');
        if ($channel === 'email' && !$allowEmail) {
            throw new PaymentProcessorException(E::ts('Sending payment links by Email is disabled in settings.'));
        }
        if ($channel === 'sms' && !$allowSms) {
            throw new PaymentProcessorException(E::ts('Sending payment links by SMS is disabled in settings.'));
        }

        $session = CheckoutSession::restoreFromToken($this->token);
        $contributionId = $session->getContributionId();
        if ($contributionId <= 0) {
            throw new PaymentProcessorException(E::ts('Invalid contribution session.'));
        }

        $contribution = Contribution::get(false)
            ->addSelect('id', 'total_amount', 'currency', 'payment_processor_id', 'contact_id')
            ->addWhere('id', '=', $contributionId)
            ->execute()
            ->single();

        $processorId = (int) ($contribution['payment_processor_id'] ?? 0);
        if ($processorId <= 0) {
            $processorId = (int) $session->getCheckoutParam('payment_processor_id');
        }
        if ($processorId <= 0) {
            $activeProc = \Civi\Api4\PaymentProcessor::get(false)
                ->addSelect('id')
                ->addWhere('class_name', 'LIKE', 'Payment_Sum%')
                ->addWhere('is_active', '=', true)
                ->setLimit(1)
                ->execute()
                ->first();
            $processorId = !empty($activeProc['id']) ? (int) $activeProc['id'] : 0;
        }

        $key = \CRM_Core_Payment_Sumup::getBrowserReturnSigningKey();
        $sig = substr(hash_hmac('sha256', $contributionId . ':' . $processorId, $key), 0, 12);
        $payUrl = \CRM_Utils_System::url(
            'civicrm/sumup/widget',
            ['c' => $contributionId, 'p' => $processorId, 's' => $sig],
            true,
            null,
            false,
            true
        );

        $amount = number_format((float) $contribution['total_amount'], 2, '.', '');
        $currency = strtoupper((string) $contribution['currency']);

        [$domainName, $domainEmail] = \CRM_Core_BAO_Domain::getNameAndEmail(true);

        if ($channel === 'email') {
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new PaymentProcessorException(E::ts('Invalid recipient email address.'));
            }

            $subject = E::ts('Your payment link (%1 %2)', [1 => $amount, 2 => $currency]);
            $html = sprintf(
                '<div style="font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 500px; '
                . 'margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;">'
                . '<h2 style="color: #101010; margin-top: 0;">%s</h2>'
                . '<p style="color: #404040; font-size: 16px;">%s</p>'
                . '<div style="text-align: center; margin: 30px 0;">'
                . '<a href="%s" style="background: #006f69; color: #fff; text-decoration: none; '
                . 'padding: 12px 24px; border-radius: 6px; font-weight: bold; display: inline-block;">%s</a>'
                . '</div>'
                . '<p style="color: #707070; font-size: 13px;">%s</p>'
                . '</div>',
                htmlspecialchars($domainName, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars(
                    E::ts('Here is your secure link to complete your payment of %1 %2:', [
                        1 => $amount,
                        2 => $currency,
                    ]),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                htmlspecialchars($payUrl, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars(E::ts('Pay %1 %2 now', [1 => $amount, 2 => $currency]), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($payUrl, ENT_QUOTES, 'UTF-8')
            );
            $text = sprintf(
                "%s\n\n%s\n%s",
                $domainName,
                E::ts('Complete your payment of %1 %2 at:', [1 => $amount, 2 => $currency]),
                $payUrl
            );

            $mailParams = [
                'from' => sprintf('"%s" <%s>', $domainName, $domainEmail),
                'toEmail' => $recipient,
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
            ];

            $sent = \CRM_Utils_Mail::send($mailParams);
            if (!$sent) {
                throw new PaymentProcessorException(E::ts('Failed to send payment link by email.'));
            }
        } elseif ($channel === 'sms') {
            $smsText = sprintf(
                "%s: %s %s",
                $domainName,
                E::ts('Complete your payment of %1 %2:', [1 => $amount, 2 => $currency]),
                $payUrl
            );

            \CRM_SumupPaymentProcessor_SmsHelper::sendSms($recipient, $smsText);
        }

        $result[] = [
            'sent' => true,
            'channel' => $channel,
            'recipient' => $recipient,
            'message' => E::ts('Payment link sent to %1.', [1 => $recipient]),
        ];
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore
}
