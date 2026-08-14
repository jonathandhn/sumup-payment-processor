<?php

declare(strict_types=1);

use Civi\Api4\Contribution;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
class CRM_SumupPaymentProcessor_Page_QrRedirect extends CRM_Core_Page
{
    public function run(): void
    {
        try {
            $contributionId = (int) ($_GET['c'] ?? 0);
            $sig = (string) ($_GET['s'] ?? '');
            if ($contributionId <= 0 || $sig === '') {
                throw new PaymentProcessorException(E::ts('Invalid QR code payment link.'));
            }

            $key = CRM_Core_Payment_Sumup::getBrowserReturnSigningKey();
            $expected = substr(hash_hmac('sha256', (string) $contributionId, $key), 0, 12);
            if (!hash_equals($expected, $sig)) {
                throw new PaymentProcessorException(E::ts('Expired or invalid payment signature.'));
            }

            $contribution = Contribution::get(false)
                ->addSelect('id', 'payment_processor_id', 'contribution_status_id:name')
                ->addWhere('id', '=', $contributionId)
                ->execute()
                ->single();

            if (empty($contribution['payment_processor_id'])) {
                throw new PaymentProcessorException(E::ts('Payment processor not found for this contribution.'));
            }

            $processor = \Civi\Payment\System::singleton()->getById((int) $contribution['payment_processor_id']);
            if (!$processor instanceof CRM_Core_Payment_Sumup) {
                throw new PaymentProcessorException(E::ts('Invalid SumUp payment processor.'));
            }

            $returnUrl = CRM_Utils_System::url('civicrm/donate', '', true, null, false, true);
            $cancelUrl = CRM_Utils_System::url('civicrm/donate', '', true, null, false, true);

            $processor->startEmbeddedCheckoutForContribution(
                $contributionId,
                $returnUrl,
                $cancelUrl
            );

            $signedWidgetUrl = $processor->buildSignedWidgetUrl(
                $contributionId,
                $returnUrl,
                $cancelUrl
            );

            CRM_Utils_System::redirect($signedWidgetUrl);
        } catch (\Throwable $exception) {
            Civi::log()->warning('SumUp QR redirect failed: ' . $exception->getMessage());
            CRM_Core_Session::setStatus(
                E::ts('The secure payment link is invalid or expired.'),
                E::ts('SumUp'),
                'error'
            );
            CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/donate', '', true, null, false, true));
        }
    }
}
