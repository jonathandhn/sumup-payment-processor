<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
class CRM_SumupPaymentProcessor_SmsHelper
{
    /**
     * @return array<int, string>
     */
    public static function getProviderOptions(): array
    {
        $options = [0 => (string) E::ts('- Default / System SMS Provider -')];
        try {
            if (class_exists('\Civi\Api4\SmsProvider')) {
                $providers = \Civi\Api4\SmsProvider::get(false)
                    ->addSelect('id', 'title', 'name')
                    ->addWhere('is_active', '=', true)
                    ->execute();
                foreach ($providers as $provider) {
                    $options[(int) $provider['id']] = (string) ($provider['title'] ?: $provider['name']);
                }
            }
        } catch (\Throwable $e) {
            // SMS component not enabled
        }

        return $options;
    }

    /**
     * Send an SMS payment link to a phone number.
     *
     * @throws \CRM_Core_Exception
     */
    public static function sendSms(string $toPhone, string $messageText): void
    {
        $toPhone = preg_replace('/[^0-9+]/', '', trim($toPhone)) ?? '';
        if (strlen($toPhone) < 8) {
            throw new \CRM_Core_Exception(E::ts('Invalid recipient phone number.'));
        }

        $providerId = (int) Civi::settings()->get('sumup_qr_sms_provider_id');
        if ($providerId <= 0 && class_exists('CRM_SMS_BAO_SmsProvider')) {
            $active = \CRM_SMS_BAO_SmsProvider::activeProviders();
            if (!empty($active)) {
                $providerId = (int) array_key_first($active);
            }
        }

        if ($providerId <= 0) {
            throw new \CRM_Core_Exception(E::ts('No active CiviCRM SMS Provider is configured or selected.'));
        }

        // Normalize phone number (E.164 without internal spaces/dashes)
        $cleanPhone = (string) preg_replace('/[^0-9+]/', '', trim($toPhone));
        if (str_starts_with($cleanPhone, '00')) {
            $cleanPhone = '+' . substr($cleanPhone, 2);
        } elseif (
            !str_starts_with($cleanPhone, '+')
            && strlen($cleanPhone) === 10
            && str_starts_with($cleanPhone, '0')
        ) {
            // French standard mobile (06 / 07) -> +336 / +337
            $cleanPhone = '+33' . substr($cleanPhone, 1);
        }

        $recipients = [
            [
                'to' => $cleanPhone,
                'phone' => $cleanPhone,
                'number' => $cleanPhone,
            ],
        ];
        $header = [
            'From' => 'SumUp',
            'from' => 'SumUp',
            'To' => $cleanPhone,
            'to' => $cleanPhone,
        ];

        if (class_exists('CRM_SMS_Provider')) {
            try {
                $providerParams = ['id' => $providerId, 'provider_id' => $providerId];
                if (class_exists('\Civi\Api4\SmsProvider')) {
                    $providerData = \Civi\Api4\SmsProvider::get(false)
                        ->addWhere('id', '=', $providerId)
                        ->execute()
                        ->first();
                    if (!empty($providerData)) {
                        $providerParams = array_merge($providerData, $providerParams);
                    }
                }

                $provider = \CRM_SMS_Provider::singleton($providerParams);
                if (is_object($provider) && method_exists($provider, 'send')) {
                    try {
                        $provider->send($recipients, $header, $messageText, null, null);
                    } catch (\Throwable $e1) {
                        try {
                            $provider->send($cleanPhone, $header, $messageText, null, null);
                        } catch (\Throwable $e2) {
                            throw $e1;
                        }
                    }
                    return;
                }
            } catch (\Throwable $e) {
                \Civi::log()->warning('SumUp SMS sending failed via provider: ' . $e->getMessage());
                throw new \CRM_Core_Exception(E::ts('Failed to send SMS: %1', [1 => $e->getMessage()]));
            }
        }

        throw new \CRM_Core_Exception(E::ts('No active CiviCRM SMS Provider is configured.'));
    }
}
