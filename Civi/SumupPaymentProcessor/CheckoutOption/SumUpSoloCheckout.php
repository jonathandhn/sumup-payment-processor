<?php

declare(strict_types=1);

namespace Civi\SumupPaymentProcessor\CheckoutOption;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Api4\OptionValue;
use Civi\Api4\SumupReader;
use Civi\Checkout\AfformCheckoutOptionInterface;
use Civi\Checkout\CheckoutOptionInterface;
use Civi\Checkout\CheckoutSession;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

if (
    interface_exists('Civi\\Checkout\\CheckoutOptionInterface')
    && interface_exists('Civi\\Checkout\\AfformCheckoutOptionInterface')
) {
    final class SumUpSoloCheckout implements CheckoutOptionInterface, AfformCheckoutOptionInterface
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
            return E::ts('%1 (Solo terminal / Kiosk)', [1 => $this->getConnectionLabel()]);
        }

        public function getFrontendLabel(): string
        {
            return E::ts('%1 (Solo terminal / Kiosk)', [
                1 => $this->getConnectionLabel(),
            ]);
        }

        public function getPaymentMethod(): ?string
        {
            $connection = $this->getDisplayConnection();
            if (empty($connection['payment_instrument_id'])) {
                return null;
            }
            $instrument = OptionValue::get(false)
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
            return false;
        }

        public function validate(AfformValidateEvent $event): void
        {
            // The selected reader is revalidated after the contribution exists.
        }

        /**
         * @return array<string, mixed>
         */
        public function getAfformSettings(bool $testMode): array
        {
            return [
                'description' => E::ts('In-person payment via SumUp Solo terminal (one-time only).'),
                'template' => '~/afSumUp/sumup_solo_checkout.html',
            ];
        }

        public function getAfformModule(): string
        {
            return 'afSumUp';
        }

        public function startCheckout(CheckoutSession $session): void
        {
            $readerId = (int) $session->getCheckoutParam('sumup_reader_id');
            $connection = $this->getConnectionDetails($session->isTestMode());

            if ($readerId <= 0) {
                $defaultReader = SumupReader::get(false)
                    ->addSelect('id')
                    ->addWhere('payment_processor_id', '=', (int) $connection['id'])
                    ->addWhere('pairing_status', '=', 'paired')
                    ->addWhere('is_active', '=', true)
                    ->addOrderBy('site_code', 'ASC')
                    ->addOrderBy('canonical_name', 'ASC')
                    ->execute()
                    ->first();
                $readerId = !empty($defaultReader['id']) ? (int) $defaultReader['id'] : 0;
            }

            if ($readerId <= 0) {
                throw new \CRM_Core_Exception(E::ts('No paired SumUp Solo terminal is available.'));
            }

            $reader = SumupReader::get(false)
                ->addSelect('id', 'canonical_name', 'site_code')
                ->addWhere('id', '=', $readerId)
                ->addWhere('payment_processor_id', '=', (int) $connection['id'])
                ->addWhere('pairing_status', '=', 'paired')
                ->addWhere('is_active', '=', true)
                ->execute()
                ->first();
            if (!$reader) {
                throw new \CRM_Core_Exception(E::ts('The selected SumUp terminal is unavailable.'));
            }

            $contribution = \Civi\Api4\Contribution::get(false)
                ->addSelect('id', 'total_amount', 'currency', 'contribution_recur_id')
                ->addWhere('id', '=', $session->getContributionId())
                ->execute()
                ->single();

            if (!empty($contribution['contribution_recur_id'])) {
                throw new \CRM_Core_Exception(E::ts(
                    'SumUp Solo terminals only support one-time payments.'
                    . ' Recurring contributions require online card checkout.'
                ));
            }

            $processor = $this->getProcessor($session);
            $clientTransactionId = $processor->startSoloCheckoutForContribution(
                $session->getContributionId(),
                $readerId
            );
            $session->setCheckoutParam('sumup_reader_checkout_id', $clientTransactionId);

            $qrUrl = \CRM_Utils_System::url(
                'civicrm/sumup/widget',
                [
                    'contribution_id' => $session->getContributionId(),
                    'token' => $session->tokenise(),
                ],
                true,
                null,
                false,
                true
            );

            $session->setResponseItem(
                'sumup_solo_checkout',
                [
                    'token' => $session->tokenise(),
                    'reader_id' => $readerId,
                    'reader_name' => (string) ($reader['canonical_name'] ?? 'Solo'),
                    'site_code' => (string) ($reader['site_code'] ?? ''),
                    'amount' => number_format((float) $contribution['total_amount'], 2, '.', ''),
                    'currency' => (string) $contribution['currency'],
                    'qr_url' => $qrUrl,
                    'client_transaction_id' => $clientTransactionId,
                    'message' => E::ts(
                        'Payment of %1 %2 sent to terminal %3.',
                        [
                            1 => number_format((float) $contribution['total_amount'], 2, '.', ''),
                            2 => (string) $contribution['currency'],
                            3 => (string) ($reader['canonical_name'] ?? 'Solo'),
                        ]
                    ),
                ]
            );
            $session->setResponseItem('redirect', false);
            $session->setResponseItem('message', false);
        }

        public function continueCheckout(CheckoutSession $session): void
        {
            try {
                $contribution = \Civi\Api4\Contribution::get(false)
                    ->addSelect('contribution_status_id:name')
                    ->addWhere('id', '=', $session->getContributionId())
                    ->execute()
                    ->single();
                $status = (string) ($contribution['contribution_status_id:name'] ?? '');
                if ($status === 'Completed') {
                    $session->success();
                    return;
                }
                if ($status === 'Cancelled') {
                    $session->cancel();
                    return;
                }
                if ($status === 'Failed') {
                    $session->fail();
                    return;
                }

                $clientTransactionId = (string) $session->getCheckoutParam('sumup_reader_checkout_id');
                if (!\CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($clientTransactionId)) {
                    throw new \CRM_Core_Exception(E::ts('The SumUp terminal transaction identifier is missing.'));
                }
                $result = $this->getProcessor($session)->verifyAndApplyReaderCheckout($clientTransactionId);
            } catch (\SumUp\Exception\ApiException $exception) {
                if (
                    $exception->getCode() !== 404
                    && !$session->getCheckoutParam('sumup_reader_status_error_logged')
                ) {
                    \Civi::log()->warning(
                        'Unable to retrieve the SumUp terminal payment status: ' . $exception->getMessage()
                    );
                    $session->setCheckoutParam('sumup_reader_status_error_logged', true);
                }
                return;
            } catch (\Throwable $exception) {
                if (!$session->getCheckoutParam('sumup_reader_status_error_logged')) {
                    \Civi::log()->warning(
                        'Unable to retrieve the SumUp terminal payment status: ' . $exception->getMessage()
                    );
                    $session->setCheckoutParam('sumup_reader_status_error_logged', true);
                }
                return;
            }

            if ($result['status'] === 'PAID') {
                $session->success();
                return;
            }
            if ($result['status'] === 'FAILED') {
                $session->fail();
            }
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
