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
            return E::ts('%1 (Solo terminal)', [1 => $this->getConnectionLabel()]);
        }

        public function getFrontendLabel(): string
        {
            $connection = $this->getDisplayConnection();
            return E::ts('%1 - payment on terminal', [
                1 => (string) ($connection['frontend_title'] ?? $connection['title'] ?? 'SumUp'),
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

        public function validate(AfformValidateEvent $event): void
        {
            // The selected reader is revalidated after the contribution exists.
        }

        /**
         * @return array<string, mixed>
         */
        public function getAfformSettings(bool $testMode): array
        {
            $connection = $this->getConnectionDetails($testMode);
            $readers = SumupReader::get(false)
                ->addSelect('id', 'canonical_name', 'site_code', 'device_status', 'device_state')
                ->addWhere('payment_processor_id', '=', (int) $connection['id'])
                ->addWhere('pairing_status', '=', 'paired')
                ->addWhere('is_active', '=', true)
                ->addOrderBy('site_code', 'ASC')
                ->addOrderBy('canonical_name', 'ASC')
                ->execute();
            $options = [];
            foreach ($readers as $reader) {
                $state = trim((string) ($reader['device_state'] ?? $reader['device_status'] ?? ''));
                $options[] = [
                    'id' => (string) $reader['id'],
                    'label' => sprintf(
                        '%s - %s%s',
                        (string) $reader['site_code'],
                        (string) $reader['canonical_name'],
                        $state !== '' ? ' (' . $state . ')' : ''
                    ),
                ];
            }

            if ($options === []) {
                return [
                    'description' => E::ts('No paired SumUp Solo terminal is available for this processor.'),
                ];
            }

            return [
                'description' => E::ts('The payment will be sent to the selected in-person SumUp terminal.'),
                'fields' => [[
                    'name' => 'sumup_reader_id',
                    'title' => E::ts('Payment terminal'),
                    'htmlType' => 'select',
                    'is_required' => true,
                    'options' => $options,
                ]],
            ];
        }

        public function getAfformModule(): ?string
        {
            return null;
        }

        public function startCheckout(CheckoutSession $session): void
        {
            $readerId = (int) $session->getCheckoutParam('sumup_reader_id');
            $connection = $this->getConnectionDetails($session->isTestMode());
            $reader = SumupReader::get(false)
                ->addSelect('id', 'canonical_name')
                ->addWhere('id', '=', $readerId)
                ->addWhere('payment_processor_id', '=', (int) $connection['id'])
                ->addWhere('pairing_status', '=', 'paired')
                ->addWhere('is_active', '=', true)
                ->execute()
                ->first();
            if (!$reader) {
                throw new \CRM_Core_Exception(E::ts('The selected SumUp terminal is unavailable.'));
            }

            throw new \CRM_Core_Exception(E::ts(
                'The SumUp terminal selector is ready; starting the terminal payment is the next implementation slice.'
            ));
        }

        public function continueCheckout(CheckoutSession $session): void
        {
            // Terminal completion will be implemented with Reader Checkout verification.
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
