<?php

declare(strict_types=1);

namespace Civi\SumupPaymentProcessor\CheckoutOption;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Checkout\AfformCheckoutOptionInterface;
use Civi\Checkout\CheckoutOptionInterface;
use Civi\Checkout\CheckoutSession;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

if (
    interface_exists('Civi\\Checkout\\CheckoutOptionInterface')
    && interface_exists('Civi\\Checkout\\AfformCheckoutOptionInterface')
) {
    final class SumUpEmbeddedCheckout implements CheckoutOptionInterface, AfformCheckoutOptionInterface
    {
        /**
         * @param array<string, mixed>|null $liveConnection
         * @param array<string, mixed>|null $testConnection
         */
        public function __construct(
            private readonly ?array $liveConnection,
            private readonly ?array $testConnection
        ) {
        }

        public function getLabel(): string
        {
            if (
                \CRM_SumupPaymentProcessor_CheckoutMode::usesHosted(
                    \CRM_SumupPaymentProcessor_CheckoutMode::getConfiguredMode()
                )
            ) {
                return E::ts('%1 (Page de paiement SumUp)', [1 => $this->getConnectionLabel()]);
            }
            return E::ts('%1 (Carte bancaire en ligne)', [1 => $this->getConnectionLabel()]);
        }

        public function getFrontendLabel(): string
        {
            return E::ts('%1 (Paiement sécurisé par carte)', [
                1 => $this->getConnectionLabel(),
            ]);
        }

        public function getPaymentMethod(): ?string
        {
            $connection = $this->getDisplayConnection();
            if (empty($connection['payment_instrument_id'])) {
                return null;
            }
            $instrument = \Civi\Api4\OptionValue::get(false)
                ->addSelect('name')
                ->addWhere('option_group_id:name', '=', 'payment_instrument')
                ->addWhere('value', '=', (int) $connection['payment_instrument_id'])
                ->execute()
                ->first();

            return !empty($instrument['name']) ? (string) $instrument['name'] : null;
        }

        public function getPaymentProcessorId(): ?int
        {
            $connection = $this->liveConnection ?: $this->testConnection;
            return !empty($connection['id']) ? (int) $connection['id'] : null;
        }

        public function supportsRecurring(): bool
        {
            return true;
        }

        public function validate(AfformValidateEvent $event): void
        {
            // CiviCRM validates the contribution. SumUp validates payment details.
        }

        /**
         * @return array<string, mixed>
         */
        public function getAfformSettings(bool $testMode): array
        {
            if (
                \CRM_SumupPaymentProcessor_CheckoutMode::usesHosted(
                    \CRM_SumupPaymentProcessor_CheckoutMode::getConfiguredMode()
                )
            ) {
                return ['description' => E::ts('You will be redirected to SumUp to complete your secure payment.')];
            }
            return ['template' => '~/afSumUp/sumup_embedded_checkout.html'];
        }

        public function getAfformModule(): ?string
        {
            return \CRM_SumupPaymentProcessor_CheckoutMode::usesHosted(
                \CRM_SumupPaymentProcessor_CheckoutMode::getConfiguredMode()
            ) ? null : 'afSumUp';
        }

        public function startCheckout(CheckoutSession $session): void
        {
            $processor = $this->getProcessor($session);
            $session->setCheckoutParam('sumup_return', 'success');
            $returnUrl = $session->getLandingUrl();
            $session->setCheckoutParam('sumup_return', 'cancel');
            $cancelUrl = $session->getLandingUrl();
            $session->setCheckoutParam('sumup_return', null);

            if (
                \CRM_SumupPaymentProcessor_CheckoutMode::usesHosted(
                    \CRM_SumupPaymentProcessor_CheckoutMode::getConfiguredMode()
                )
            ) {
                $session->setResponseItem('redirect', $processor->startHostedCheckoutForContribution(
                    $session->getContributionId(),
                    $returnUrl,
                    $cancelUrl
                ));
                return;
            }
            $session->setResponseItem(
                'sumup_embedded_checkout',
                $processor->startEmbeddedCheckoutForContribution(
                    $session->getContributionId(),
                    $returnUrl,
                    $cancelUrl
                )
            );
            $session->setResponseItem('message', false);
        }

        public function continueCheckout(CheckoutSession $session): void
        {
            try {
                $contribution = \Civi\Api4\Contribution::get(false)
                    ->addSelect('contribution_status_id:name')
                    ->addWhere('id', '=', $session->getContributionId())
                    ->addWhere('is_test', 'IN', [true, false])
                    ->execute()
                    ->single();
                if (($contribution['contribution_status_id:name'] ?? '') === 'Completed') {
                    $session->success();
                    return;
                }
                $processor = $this->getProcessor($session);
                $checkout = \CRM_SumupPaymentProcessor_CheckoutStore::getLatestByContributionId(
                    $session->getContributionId(),
                    $processor->getProcessorId()
                );
                $result = $processor->verifyAndApplyCheckout(
                    $checkout['checkout_id'],
                    $session->getContributionId()
                );
            } catch (\Throwable $exception) {
                \Civi::log()->warning('Unable to retrieve the SumUp checkout status: ' . $exception->getMessage());
                // The restored CheckoutSession is already pending. Do not write
                // Pending back to a contribution that a webhook may have just completed.
                return;
            }

            if ($result['status'] === 'PAID') {
                $session->success();
                return;
            }
            if ($result['status'] === 'FAILED') {
                $session->fail();
                return;
            }
            if ($result['status'] === 'EXPIRED') {
                $session->cancel();
                return;
            }
            if ($session->getCheckoutParam('sumup_return') === 'cancel') {
                $session->cancel();
                return;
            }
            // Keep the restored pending session without rewriting contribution status.
        }

        private function getProcessor(CheckoutSession $session): \CRM_Core_Payment_Sumup
        {
            $connection = $this->getConnectionDetails($session->isTestMode());
            $processor = \Civi\Payment\System::singleton()->getByName(
                (string) $connection['name'],
                $session->isTestMode()
            );
            if (!$processor instanceof \CRM_Core_Payment_Sumup) {
                throw new \CRM_Core_Exception(E::ts('Unable to load the SumUp payment processor.'));
            }
            return $processor;
        }

        /** @return array<string, mixed> */
        private function getConnectionDetails(bool $testMode): array
        {
            $connection = $testMode ? $this->testConnection : $this->liveConnection;
            if (!$connection) {
                throw new \CRM_Core_Exception(E::ts('No active SumUp payment processor is available for this mode.'));
            }
            return $connection;
        }

        /** @return array<string, mixed> */
        private function getDisplayConnection(): array
        {
            $connection = $this->liveConnection ?: $this->testConnection;
            if (!$connection) {
                throw new \CRM_Core_Exception(E::ts('No active SumUp payment processor is available.'));
            }
            return $connection;
        }

        private function getConnectionLabel(): string
        {
            return (string) ($this->getDisplayConnection()['title'] ?? 'SumUp');
        }
    }
}
