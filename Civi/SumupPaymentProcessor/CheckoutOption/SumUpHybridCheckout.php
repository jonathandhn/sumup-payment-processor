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
    /**
     * Checkout Option for Hybrid Split View (Card Reader + Dynamic QR Code).
     */
    class SumUpHybridCheckout implements CheckoutOptionInterface, AfformCheckoutOptionInterface
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
            return E::ts('%1 (Card reader & QR Code)', [1 => $this->getConnectionLabel()]);
        }

        public function getFrontendLabel(): string
        {
            return E::ts('%1 (Card reader / QR Code)', [
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
                'description' => E::ts('In-person payment on card reader with instant QR code fallback.'),
                'template' => '~/afSumUp/sumup_hybrid_checkout.html',
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
                throw new \CRM_Core_Exception(E::ts('No paired SumUp card reader is available.'));
            }
            $session->setCheckoutParam('sumup_reader_id', $readerId);

            $reader = SumupReader::get(false)
                ->addSelect('id', 'canonical_name', 'site_code')
                ->addWhere('id', '=', $readerId)
                ->addWhere('payment_processor_id', '=', (int) $connection['id'])
                ->addWhere('pairing_status', '=', 'paired')
                ->addWhere('is_active', '=', true)
                ->execute()
                ->first();
            if (!$reader) {
                throw new \CRM_Core_Exception(E::ts('The selected SumUp card reader is unavailable.'));
            }

            $contribution = \Civi\Api4\Contribution::get(false)
                ->addSelect('id', 'total_amount', 'currency', 'contribution_recur_id', 'contact_id')
                ->addWhere('id', '=', $session->getContributionId())
                ->execute()
                ->single();

            if (!empty($contribution['contribution_recur_id'])) {
                throw new \CRM_Core_Exception(E::ts(
                    'SumUp card readers only support one-time payments.'
                    . ' Recurring contributions require online card checkout.'
                ));
            }

            $processor = $this->getProcessor($session);
            $clientTransactionId = $processor->startSoloCheckoutForContribution(
                $session->getContributionId(),
                $readerId
            );
            $session->setCheckoutParam('sumup_reader_checkout_id', $clientTransactionId);

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
                'sumup_hybrid_checkout',
                [
                    'token' => $session->tokenise(),
                    'reader_id' => $readerId,
                    'reader_name' => (string) ($reader['canonical_name'] ?? 'Solo'),
                    'site_code' => (string) ($reader['site_code'] ?? ''),
                    'solo_image_url' => E::url('images/sumup-solo.png'),
                    'amount' => number_format((float) $contribution['total_amount'], 2, '.', ''),
                    'currency' => (string) $contribution['currency'],
                    'qr_url' => $qrUrl,
                    'qr_svg' => $this->generateQrSvg($qrUrl),
                    'client_transaction_id' => $clientTransactionId,
                    'allow_send_email' => (bool) \Civi::settings()->get('sumup_qr_allow_send_email'),
                    'allow_send_sms' => (bool) \Civi::settings()->get('sumup_qr_allow_send_sms'),
                    'contact_email' => $contactEmail,
                    'contact_phone' => $contactPhone,
                    'message' => E::ts(
                        'Payment of %1 %2 sent to card reader %3.',
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
            if (in_array($result['status'], ['FAILED', 'CANCELLED', 'EXPIRED'], true)) {
                $session->setCheckoutParam('sumup_reader_attempt_status', $result['status']);
                return;
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
