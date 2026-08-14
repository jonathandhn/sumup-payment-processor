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

    public static function getMerchantCountryCode(string $sumupProfileCountry): string
    {
        $countryCode = strtoupper(trim($sumupProfileCountry));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new \Civi\Payment\Exception\PaymentProcessorException(
                E::ts('SumUp did not return a valid merchant country code.')
            );
        }

        return $countryCode;
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
