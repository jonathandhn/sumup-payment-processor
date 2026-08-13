<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
final class CRM_SumupPaymentProcessor_CheckoutMode
{
    public const WIDGET = 'widget';
    public const WIDGET_WALLET = 'widget_wallet';
    public const WALLET = 'wallet';
    public const HOSTED = 'hosted';
    public const SOLO = 'solo';

    /**
     * @return array<string, string>
     */
    public static function getOptions(): array
    {
        return [
            self::WIDGET => E::ts('Card Widget'),
            self::WIDGET_WALLET => E::ts('Card Widget and wallets'),
            self::WALLET => E::ts('Wallets only'),
            self::HOSTED => E::ts('SumUp Hosted Checkout'),
        ];
    }

    public static function getConfiguredMode(): string
    {
        $mode = (string) Civi::settings()->get('sumup_checkout_mode');

        return array_key_exists($mode, self::getOptions()) ? $mode : self::WIDGET;
    }

    public static function usesWidget(string $mode): bool
    {
        return in_array($mode, [self::WIDGET, self::WIDGET_WALLET], true);
    }

    public static function usesWallet(string $mode): bool
    {
        return in_array($mode, [self::WALLET, self::WIDGET_WALLET], true);
    }

    public static function usesHosted(string $mode): bool
    {
        return $mode === self::HOSTED;
    }

    public static function isValidAttemptMode(string $mode): bool
    {
        return $mode === self::SOLO || array_key_exists($mode, self::getOptions());
    }

    public static function getMerchantCountryCode(): string
    {
        $override = strtoupper(trim((string) Civi::settings()->get('sumup_merchant_country_code')));
        if ($override !== '') {
            return $override;
        }

        $countryCode = CRM_Core_DAO::singleValueQuery(
            'SELECT country.iso_code
               FROM civicrm_domain domain_record
               INNER JOIN civicrm_address address_record
                       ON address_record.contact_id = domain_record.contact_id
                      AND address_record.is_primary = 1
               INNER JOIN civicrm_country country ON country.id = address_record.country_id
              WHERE domain_record.id = %1
              ORDER BY address_record.id ASC
              LIMIT 1',
            [1 => [(int) CRM_Core_Config::domainID(), 'Integer']]
        );

        return strtoupper(trim(is_string($countryCode) ? $countryCode : ''));
    }

    public static function getLocale(): string
    {
        $locale = str_replace('_', '-', (string) CRM_Core_I18n::getLocale());
        $supported = [
            'bg-BG', 'cs-CZ', 'da-DK', 'de-AT', 'de-CH', 'de-DE', 'de-LU',
            'el-CY', 'el-GR', 'en-GB', 'en-IE', 'en-MT', 'en-US', 'es-CL',
            'es-ES', 'et-EE', 'fi-FI', 'fr-BE', 'fr-CH', 'fr-FR', 'fr-LU',
            'hu-HU', 'it-CH', 'it-IT', 'lt-LT', 'lv-LV', 'nb-NO', 'nl-BE',
            'nl-NL', 'pl-PL', 'pt-BR', 'pt-PT', 'sk-SK', 'sl-SI', 'sv-SE',
        ];

        return in_array($locale, $supported, true) ? $locale : 'en-GB';
    }
}
