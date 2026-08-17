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

            $matrix = (new \QRcode($url, 'H'))->getBarcodeArray();
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

            // Authentic SumUp center logo overlay (23% of QR size)
            $officialIconPath = 'M 59.058594 54.925781 L 49.589844 64.335938 C 49.414062 64.5 49.140625 64.496094'
                . ' 48.96875 64.328125 C 46.492188 61.539062 46.59375 57.28125 49.273438 54.613281'
                . ' C 51.941406 51.960938 56.195312 51.847656 59 54.273438 C 59.015625 54.28125 59.03125 54.296875'
                . ' 59.046875 54.3125 C 59.214844 54.480469 59.21875 54.753906 59.058594 54.925781'
                . ' M 57.085938 69.964844 C 54.417969 72.617188 50.164062 72.734375 47.359375 70.308594'
                . ' C 47.34375 70.296875 47.328125 70.285156 47.3125 70.269531 C 47.144531 70.101562'
                . ' 47.140625 69.828125 47.304688 69.652344 L 56.769531 60.242188 C 56.945312 60.078125'
                . ' 57.21875 60.082031 57.390625 60.25 C 59.871094 63.039062 59.769531 67.300781'
                . ' 57.085938 69.964844 M 66.261719 47.117188 L 40.097656 47.117188 C 38.890625 47.117188'
                . ' 37.914062 48.085938 37.914062 49.285156 L 37.914062 75.292969 C 37.914062 76.492188'
                . ' 38.890625 77.460938 40.097656 77.460938 L 66.261719 77.460938 C 67.46875 77.460938'
                . ' 68.445312 76.492188 68.445312 75.292969 L 68.445312 49.285156 C 68.445312 48.085938'
                . ' 67.46875 47.117188 66.261719 47.117188';

            $logoBoxSize = (float) max(7.0, round($size * 0.23));
            if ((int) $logoBoxSize % 2 === 0) {
                $logoBoxSize += 1.0;
            }
            $logoBoxPos = ($size - $logoBoxSize) / 2.0;
            $cornerOuter = max(1.0, $logoBoxSize * 0.20);
            $innerSize = $logoBoxSize * 0.82;
            $scale = $innerSize / 30.53125;
            $cx = $size / 2.0;
            $cy = $size / 2.0;
            $tx = $cx - (53.179688 * $scale);
            $ty = $cy - (62.289062 * $scale);

            $logoSvg = sprintf(
                '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="%.2f" fill="#ffffff"/>'
                . '<g transform="translate(%.4f, %.4f) scale(%.6f)">'
                . '<path fill="#101010" fill-rule="nonzero" d="%s"/></g>',
                $logoBoxPos,
                $logoBoxPos,
                $logoBoxSize,
                $logoBoxSize,
                $cornerOuter,
                $tx,
                $ty,
                $scale,
                $officialIconPath
            );

            return sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" role="img" '
                . 'aria-label="SumUp QR code"><rect width="100%%" height="100%%" fill="#fff"/>'
                . '<path d="%2$s" fill="#000"/>%3$s</svg>',
                $size,
                implode('', $path),
                $logoSvg
            );
        }
    }
}
