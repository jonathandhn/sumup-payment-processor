<?php

declare(strict_types=1);

use Civi\Api4\Contribution;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
class CRM_SumupPaymentProcessor_Page_Widget extends CRM_Core_Page
{
    private const SIGNED_FIELDS = [
        'cancel_url',
        'contribution_id',
        'expires',
        'processor_id',
        'return_url',
    ];

    public function run(): void
    {
        try {
            $params = $this->signedParameters();
            $processor = \Civi\Payment\System::singleton()->getById($params['processor_id']);
            if (!$processor instanceof CRM_Core_Payment_Sumup) {
                throw new PaymentProcessorException(E::ts('Invalid SumUp payment processor.'));
            }

            $contribution = Contribution::get(false)
                ->addSelect('id', 'total_amount', 'currency', 'contribution_status_id:name')
                ->addWhere('id', '=', $params['contribution_id'])
                ->addWhere('is_test', 'IN', [true, false])
                ->execute()
                ->single();
            $checkoutRecord = CRM_SumupPaymentProcessor_CheckoutStore::getLatestByContributionId(
                $params['contribution_id'],
                $params['processor_id']
            );
            $checkoutId = $checkoutRecord['checkout_id'];
            $checkoutMode = $checkoutRecord['checkout_mode'];

            $result = $processor->verifyAndApplyCheckout($checkoutId, $params['contribution_id']);
            if ($result['status'] === 'PAID') {
                CRM_Utils_System::redirect($params['return_url']);
            }
            if (in_array($result['status'], ['FAILED', 'EXPIRED'], true)) {
                CRM_Utils_System::redirect($params['cancel_url']);
            }

            $this->assign('sumupCheckoutId', $checkoutId);
            $this->assign('sumupCancelUrl', $params['cancel_url']);
            $this->assign('sumupBrowserReturnUrl', CRM_Utils_System::url(
                'civicrm/sumup/widget',
                $_GET,
                true,
                null,
                false,
                true
            ));
            $this->assign('sumupAmount', number_format((float) $contribution['total_amount'], 2, '.', ''));
            $this->assign('sumupCurrency', strtoupper((string) $contribution['currency']));
            $this->assign('sumupLocale', CRM_SumupPaymentProcessor_CheckoutMode::getLocale());
            $this->assign('sumupCheckoutMode', $checkoutMode);
            $this->assign('sumupWalletsAllowed', $checkoutRecord['purpose'] === 'PAYMENT');
            $this->assign(
                'sumupUsesWidget',
                CRM_SumupPaymentProcessor_CheckoutMode::usesWidget($checkoutMode)
            );
            $this->assign(
                'sumupUsesWallet',
                CRM_SumupPaymentProcessor_CheckoutMode::usesWallet($checkoutMode)
            );
            $this->assign('sumupPublicMerchantKey', $processor->getPublicMerchantKey());
            $this->assign(
                'sumupMerchantCountryCode',
                CRM_SumupPaymentProcessor_CheckoutMode::getMerchantCountryCode()
            );

            CRM_Core_Resources::singleton()->addVars(
                'sumupSavedPayment',
                ['checkout_id' => $checkoutId] + $processor->getSavedCardCheckoutConfig(
                    $checkoutId,
                    $params['contribution_id']
                )
            );

            CRM_Core_Resources::singleton()->addStyleFile(E::LONG_NAME, 'ang/afSumUp/sumUp.css');
            CRM_Core_Resources::singleton()->addScriptFile(E::LONG_NAME, 'js/checkout.js', 110);
        } catch (Throwable $exception) {
            Civi::log()->warning('Unable to render SumUp checkout: ' . $exception->getMessage());
            CRM_Core_Session::setStatus(
                E::ts('The secure payment form is temporarily unavailable. Please try again.'),
                E::ts('SumUp'),
                'error'
            );
        }

        parent::run();
    }

    /**
     * @return array{contribution_id: int, processor_id: int, return_url: string, cancel_url: string, expires: int}
     */
    private function signedParameters(): array
    {
        $shortC = CRM_Utils_Request::retrieve('c', 'Positive', $this, false);
        $shortS = CRM_Utils_Request::retrieve('s', 'String', $this, false);
        if (!empty($shortC) && !empty($shortS)) {
            $contributionId = (int) $shortC;
            $sig = (string) $shortS;
            $key = CRM_Core_Payment_Sumup::getBrowserReturnSigningKey();
            $expected = substr(hash_hmac('sha256', (string) $contributionId, $key), 0, 12);
            if (!hash_equals($expected, $sig)) {
                throw new PaymentProcessorException(E::ts('The SumUp payment link signature is invalid.'));
            }

            $contribution = Contribution::get(false)
                ->addSelect('id', 'payment_processor_id')
                ->addWhere('id', '=', $contributionId)
                ->execute()
                ->single();
            if (empty($contribution['payment_processor_id'])) {
                throw new PaymentProcessorException(E::ts('Payment processor not found for this contribution.'));
            }

            $processorId = (int) $contribution['payment_processor_id'];
            $returnUrl = CRM_Utils_System::url('civicrm/donate', '', true, null, false, true);
            $cancelUrl = CRM_Utils_System::url('civicrm/donate', '', true, null, false, true);

            $processor = \Civi\Payment\System::singleton()->getById($processorId);
            if ($processor instanceof CRM_Core_Payment_Sumup) {
                try {
                    $processor->startEmbeddedCheckoutForContribution(
                        $contributionId,
                        $returnUrl,
                        $cancelUrl
                    );
                } catch (\Throwable) {
                    // Checkout may already exist
                }
            }

            return [
                'contribution_id' => $contributionId,
                'processor_id' => $processorId,
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'expires' => time() + 7200,
            ];
        }

        $params = [
            'contribution_id' => (int) CRM_Utils_Request::retrieve('contribution_id', 'Positive', $this, true),
            'processor_id' => (int) CRM_Utils_Request::retrieve('processor_id', 'Positive', $this, true),
            'return_url' => (string) CRM_Utils_Request::retrieve('return_url', 'String', $this, true),
            'cancel_url' => (string) CRM_Utils_Request::retrieve('cancel_url', 'String', $this, true),
            'expires' => (int) CRM_Utils_Request::retrieve('expires', 'Positive', $this, true),
        ];
        $signature = (string) CRM_Utils_Request::retrieve('_sgn', 'String', $this, true);
        if (!preg_match('/^[A-Za-z0-9]{4}_[a-f0-9]{32}$/', $signature)) {
            throw new PaymentProcessorException(E::ts('The SumUp payment link signature is invalid.'));
        }
        $signer = new CRM_Utils_Signer(
            CRM_Core_Payment_Sumup::getBrowserReturnSigningKey(),
            self::SIGNED_FIELDS
        );
        if (!$signer->validate($signature, $params) || $params['expires'] < time()) {
            throw new PaymentProcessorException(E::ts('The SumUp payment link is invalid or expired.'));
        }
        if (
            !str_starts_with($params['return_url'], 'https://')
            || !str_starts_with($params['cancel_url'], 'https://')
        ) {
            throw new PaymentProcessorException(E::ts('SumUp return URLs must use HTTPS.'));
        }

        return $params;
    }
}
