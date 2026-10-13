<?php

declare(strict_types = 1);

use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
final class CRM_SumupPaymentProcessor_PartnerCredentials {
  public const APPLICATION_ID = 'CiviCRM';
  public const AFFILIATE_KEY = 'sup_afk_dfonDVPcG134Mvkc2CRinIjTJI08has6';

  private const CLIENT_ID_SETTING = 'sumup_oauth_client_id';
  private const CLIENT_SECRET_SETTING = 'sumup_oauth_client_secret';
  private const REDIRECT_URI_SETTING = 'sumup_oauth_redirect_uri';

  /**
   * @var list<string> */
  public const SCOPES = [
    'payments',
    'payment_instruments',
    'transactions.history',
    'readers.read',
    'readers.write',
    'user.profile_readonly',
  ];

  public static function getClientId(): string {
    self::assertConfigured();
    return trim((string) Civi::settings()->get(self::CLIENT_ID_SETTING));
  }

  public static function getClientSecret(): string {
    self::assertConfigured();
    return trim((string) Civi::settings()->get(self::CLIENT_SECRET_SETTING));
  }

  public static function getStoredClientId(): string {
    return trim((string) Civi::settings()->get(self::CLIENT_ID_SETTING));
  }

  public static function hasStoredClientSecret(): bool {
    return trim((string) Civi::settings()->get(self::CLIENT_SECRET_SETTING)) !== '';
  }

  public static function getStoredRedirectUri(): string {
    return trim((string) Civi::settings()->get(self::REDIRECT_URI_SETTING));
  }

  public static function isConfigured(): bool {
    return self::getStoredClientId() !== ''
            && self::hasStoredClientSecret()
            && self::getStoredRedirectUri() !== '';
  }

  public static function save(string $clientId, string $clientSecret, string $redirectUri): void {
    $clientId = trim($clientId);
    $clientSecret = trim($clientSecret);
    $redirectUri = trim($redirectUri);
    $storedClientId = self::getStoredClientId();
    $storedClientSecret = trim((string) Civi::settings()->get(self::CLIENT_SECRET_SETTING));

    if ($clientId === '') {
      throw new PaymentProcessorException(E::ts('The SumUp OAuth client ID is required.'));
    }
    if ($clientSecret === '') {
      $clientSecret = $storedClientSecret;
    }
    if ($clientSecret === '') {
      throw new PaymentProcessorException(E::ts('The SumUp OAuth client secret is required.'));
    }
    if (filter_var($redirectUri, FILTER_VALIDATE_URL) === FALSE) {
      throw new PaymentProcessorException(E::ts('The calculated SumUp callback URL is invalid.'));
    }

    Civi::settings()->set(self::CLIENT_ID_SETTING, $clientId);
    Civi::settings()->set(self::CLIENT_SECRET_SETTING, $clientSecret);
    if (self::getStoredRedirectUri() === '' || !hash_equals($storedClientId, $clientId)) {
      Civi::settings()->set(self::REDIRECT_URI_SETTING, $redirectUri);
    }
  }

  public static function assertConfigured(): void {
    if (!self::isConfigured()) {
      throw new PaymentProcessorException(E::ts(
        'Configure the SumUp OAuth client ID and client secret before connecting a merchant.'
      ));
    }
  }

}
