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
        $providerParams = [];
        if ($providerId > 0) {
            $providerParams['provider_id'] = $providerId;
        }

        if (class_exists('CRM_SMS_BAO_SmsProvider')) {
            try {
                $provider = null;
                if ($providerId > 0) {
                    $provider = \CRM_SMS_BAO_SmsProvider::getProvider($providerParams);
                } else {
                    $active = \CRM_SMS_BAO_SmsProvider::activeProviders();
                    if (!empty($active)) {
                        $firstId = (int) array_key_first($active);
                        $provider = \CRM_SMS_BAO_SmsProvider::getProvider(['provider_id' => $firstId]);
                    }
                }

                if (is_object($provider) && method_exists($provider, 'send')) {
                    $provider->send($toPhone, $messageText);
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
