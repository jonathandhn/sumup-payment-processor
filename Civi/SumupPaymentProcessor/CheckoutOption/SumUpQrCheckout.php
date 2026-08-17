<?php

declare(strict_types=1);

namespace Civi\SumupPaymentProcessor\CheckoutOption;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Api4\OptionValue;
use Civi\Checkout\AfformCheckoutOptionInterface;
use Civi\Checkout\CheckoutOptionInterface;
use Civi\Checkout\CheckoutSession;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

if (
    interface_exists('Civi\\Checkout\\CheckoutOptionInterface')
    && interface_exists('Civi\\Checkout\\AfformCheckoutOptionInterface')
) {
    /**
     * Checkout Option for Standalone Dynamic QR Code payments.
     */
    class SumUpQrCheckout implements CheckoutOptionInterface, AfformCheckoutOptionInterface
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
            return E::ts('%1 (Dynamic QR Code)', [1 => $this->getConnectionLabel()]);
        }

        public function getFrontendLabel(): string
        {
            return E::ts('%1 (QR Code / Smartphone)', [
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
            // CiviCRM validates contribution amount and contact.
        }

        /**
         * @return array<string, mixed>
         */
        public function getAfformSettings(bool $testMode): array
        {
            return [
                'description' => E::ts('Scan a dynamic QR code to pay on your phone.'),
                'template' => '~/afSumUp/sumup_qr_checkout.html',
            ];
        }

        public function getAfformModule(): string
        {
            return 'afSumUp';
        }

        public function startCheckout(CheckoutSession $session): void
        {
            $contribution = \Civi\Api4\Contribution::get(false)
                ->addSelect('id', 'total_amount', 'currency', 'contribution_recur_id', 'contact_id')
                ->addWhere('id', '=', $session->getContributionId())
                ->execute()
                ->single();

            if (!empty($contribution['contribution_recur_id'])) {
                throw new \CRM_Core_Exception(E::ts(
                    'QR Code checkout only supports one-time payments.'
                    . ' Recurring contributions require online card checkout.'
                ));
            }

            $processor = $this->getProcessor($session);
            $contributionId = $session->getContributionId();
            $processorId = (int) $processor->getProcessorId();
            $key = \CRM_Core_Payment_Sumup::getBrowserReturnSigningKey();
            $sig = substr(hash_hmac('sha256', $contributionId . ':' . $processorId, $key), 0, 12);
            $qrUrl = \CRM_Utils_System::url(
                'civicrm/sumup/widget',
                ['c' => $contributionId, 'p' => $processorId, 's' => $sig],
                true,
                null,
                false,
                true
            );

            $contactId = (int) ($contribution['contact_id'] ?? 0);
            $contactEmail = '';
            $contactPhone = '';
            if ($contactId > 0) {
                $contact = \Civi\Api4\Contact::get(false)
                    ->addSelect('email_primary.email', 'phone_primary.phone')
                    ->addWhere('id', '=', $contactId)
                    ->execute()
                    ->first();
                $contactEmail = (string) ($contact['email_primary.email'] ?? '');
                $contactPhone = (string) ($contact['phone_primary.phone'] ?? '');
            }

            $session->setResponseItem(
                'sumup_qr_checkout',
                [
                    'token' => $session->tokenise(),
                    'amount' => number_format((float) $contribution['total_amount'], 2, '.', ''),
                    'currency' => (string) $contribution['currency'],
                    'qr_url' => $qrUrl,
                    'qr_svg' => $this->generateQrSvg($qrUrl),
                    'allow_send_email' => (bool) \Civi::settings()->get('sumup_qr_allow_send_email'),
                    'allow_send_sms' => (bool) \Civi::settings()->get('sumup_qr_allow_send_sms'),
                    'contact_email' => $contactEmail,
                    'contact_phone' => $contactPhone,
                    'message' => E::ts(
                        'Please scan the QR code to complete your payment of %1 %2.',
                        [
                            1 => number_format((float) $contribution['total_amount'], 2, '.', ''),
                            2 => (string) $contribution['currency'],
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
                    ->addSelect('contribution_status_id:name', 'payment_processor_id')
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

                $processorId = (int) ($contribution['payment_processor_id'] ?? 0);
                if ($processorId <= 0) {
                    return;
                }

                $checkout = \CRM_SumupPaymentProcessor_CheckoutStore::getLatestOnlineByContributionId(
                    $session->getContributionId(),
                    $processorId
                );

                $checkoutState = (string) $checkout['state'];
                if ($checkoutState === 'PAID') {
                    $session->success();
                } elseif (in_array($checkoutState, ['FAILED', 'CANCELLED', 'EXPIRED'], true)) {
                    $session->fail();
                }
            } catch (\Throwable $e) {
                \Civi::log()->warning('QR checkout status check: ' . $e->getMessage());
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

        private function generateQrSvg(string $url): string
        {
            if (!class_exists('QRcode')) {
                throw new \CRM_Core_Exception(E::ts('The local QR code encoder is unavailable.'));
            }

            $matrix = (new \QRcode($url, 'M'))->getBarcodeArray();
            $rows = (int) ($matrix['num_rows'] ?? 0);
            $columns = (int) ($matrix['num_cols'] ?? 0);
            $modules = $matrix['bcode'] ?? null;
            if ($rows <= 0 || $columns <= 0 || !is_array($modules)) {
                throw new \CRM_Core_Exception(E::ts('Unable to generate the local QR code.'));
            }

            $quietZone = 4;
            $size = max($rows, $columns) + (2 * $quietZone);
            $path = [];
            foreach ($modules as $rowIndex => $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $columnIndex => $module) {
                    if ((int) $module === 1) {
                        $x = (int) $columnIndex + $quietZone;
                        $y = (int) $rowIndex + $quietZone;
                        $path[] = sprintf('M%d %dh1v1h-1z', $x, $y);
                    }
                }
            }

            return sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" role="img" '
                . 'aria-label="QR code"><rect width="100%%" height="100%%" fill="#fff"/>'
                . '<path d="%2$s" fill="#000"/></svg>',
                $size,
                implode('', $path)
            );
        }
    }
}
