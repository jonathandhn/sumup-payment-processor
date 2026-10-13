<?php

declare(strict_types = 1);

use Civi\Api4\PaymentProcessor;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
final class CRM_SumupPaymentProcessor_OAuth {
  private const AUTHORIZE_URL = 'https://api.sumup.com/authorize';
  private const TOKEN_URL = 'https://api.sumup.com/token';
  private const API_URL = 'https://api.sumup.com';
  private const STATE_TTL = '10 minutes';
  private const EXPIRY_MARGIN = 60;

  private CRM_SumupPaymentProcessor_ProcessorAuthStore $store;

  public function __construct(private int $paymentProcessorId) {
    if ($paymentProcessorId <= 0) {
      throw new PaymentProcessorException(E::ts('A SumUp payment processor is required.'));
    }
    $this->store = new CRM_SumupPaymentProcessor_ProcessorAuthStore();
  }

  public function buildAuthorizationUrl(): string {
    CRM_SumupPaymentProcessor_PartnerCredentials::assertConfigured();
    $state = bin2hex(random_bytes(32));
    $redirectUri = $this->getRedirectUri();
    Civi::cache('long')->set(
        $this->stateCacheKey($state),
        [
          'payment_processor_id' => $this->paymentProcessorId,
          'redirect_uri' => $redirectUri,
          'created_at' => time(),
        ],
        DateInterval::createFromDateString(self::STATE_TTL)
    );

    return self::AUTHORIZE_URL . '?' . http_build_query([
      'client_id' => CRM_SumupPaymentProcessor_PartnerCredentials::getClientId(),
      'response_type' => 'code',
      'redirect_uri' => $redirectUri,
      'scope' => implode(' ', CRM_SumupPaymentProcessor_PartnerCredentials::SCOPES),
      'state' => $state,
    ]);
  }

  /**
   * @return array<string,mixed> */
  public static function completeCallback(string $code, string $state): array {
    if ($code === '' || $state === '') {
      throw new PaymentProcessorException(E::ts('The SumUp authorization callback is incomplete.'));
    }
    $cacheKey = 'sumup.oauth.state.' . hash('sha256', $state);
    $stateData = Civi::cache('long')->get($cacheKey);
    Civi::cache('long')->delete($cacheKey);
    if (!is_array($stateData) || empty($stateData['payment_processor_id']) || empty($stateData['redirect_uri'])) {
      throw new PaymentProcessorException(E::ts('The SumUp authorization state is invalid or expired.'));
    }

    $oauth = new self((int) $stateData['payment_processor_id']);
    $token = $oauth->requestToken([
      'grant_type' => 'authorization_code',
      'client_id' => CRM_SumupPaymentProcessor_PartnerCredentials::getClientId(),
      'client_secret' => CRM_SumupPaymentProcessor_PartnerCredentials::getClientSecret(),
      'code' => $code,
      'redirect_uri' => (string) $stateData['redirect_uri'],
    ]);
    $merchantCode = $oauth->retrieveMerchantCode((string) $token['access_token']);
    $link = $oauth->normaliseToken($token, $merchantCode, NULL);
    $link['payment_processor_id'] = $oauth->paymentProcessorId;
    $oauth->store->save($oauth->paymentProcessorId, $link);
    PaymentProcessor::update(FALSE)
      ->addValue('user_name', $merchantCode)
      ->addValue('password', '')
      ->addWhere('id', '=', $oauth->paymentProcessorId)
      ->addWhere('class_name', '=', 'Payment_Sumup')
      ->execute();

    return $link;
  }

  public function getAccessToken(): string {
    $link = $this->store->get($this->paymentProcessorId);
    if ($link === NULL) {
      throw new PaymentProcessorException(E::ts(
        'Connect this payment processor to SumUp before accepting payments.'
      ));
    }
    if ($link['status'] !== 'connected') {
      throw new PaymentProcessorException(E::ts(
        'Reconnect this payment processor to SumUp before accepting payments.'
      ));
    }
    if ($link['access_token'] !== '' && (int) $link['expires_at'] > time() + self::EXPIRY_MARGIN) {
      return (string) $link['access_token'];
    }

    return $this->refreshAccessToken($link);
  }

  /**
   * @return array<string,mixed>|null */
  public function getConnection(): ?array {
    return $this->store->get($this->paymentProcessorId);
  }

  public function isConnected(): bool {
    return $this->store->isConnected($this->paymentProcessorId);
  }

  public function disconnect(): void {
    $this->store->delete($this->paymentProcessorId);
    PaymentProcessor::update(FALSE)
      ->addValue('user_name', '')
      ->addValue('password', '')
      ->addWhere('id', '=', $this->paymentProcessorId)
      ->addWhere('class_name', '=', 'Payment_Sumup')
      ->execute();
  }

  public function getRedirectUri(): string {
    $storedRedirectUri = CRM_SumupPaymentProcessor_PartnerCredentials::getStoredRedirectUri();
    return $storedRedirectUri !== '' ? $storedRedirectUri : $this->getCalculatedRedirectUri();
  }

  public function getCalculatedRedirectUri(): string {
    $url = CRM_Utils_System::url(
        'civicrm/sumup/oauth/callback',
        '',
        TRUE,
        NULL,
        FALSE,
        TRUE
    );
    if (str_starts_with($url, 'http://')) {
      return 'https://' . substr($url, strlen('http://'));
    }
    return $url;
  }

  /**
   * @param array<string,mixed> $link */
  private function refreshAccessToken(array $link): string {
    if ((string) $link['refresh_token'] === '') {
      $this->store->markReconnectRequired($this->paymentProcessorId, 'Missing refresh token.');
      throw new PaymentProcessorException(E::ts('Reconnect this payment processor to SumUp.'));
    }
    $lock = Civi::lockManager()->acquire('sumup.oauth.refresh.' . $this->paymentProcessorId, 10);
    if (!$lock->isAcquired()) {
      throw new PaymentProcessorException(E::ts('The SumUp connection is being renewed. Please retry.'));
    }
    try {
      $current = $this->store->get($this->paymentProcessorId);
      if (
        $current !== NULL
        && $current['access_token'] !== ''
        && (int) $current['expires_at'] > time() + self::EXPIRY_MARGIN
      ) {
        return (string) $current['access_token'];
      }
      $token = $this->requestToken([
        'grant_type' => 'refresh_token',
        'client_id' => CRM_SumupPaymentProcessor_PartnerCredentials::getClientId(),
        'client_secret' => CRM_SumupPaymentProcessor_PartnerCredentials::getClientSecret(),
        'refresh_token' => (string) $link['refresh_token'],
      ]);
      $refreshed = $this->normaliseToken($token, (string) $link['merchant_code'], $link);
      $refreshed['last_refresh_at'] = date('Y-m-d H:i:s');
      $this->store->save($this->paymentProcessorId, $refreshed);
      return (string) $refreshed['access_token'];
    }
    catch (PaymentProcessorException $exception) {
      if (str_contains(strtolower($exception->getMessage()), 'invalid_grant')) {
        $this->store->markReconnectRequired($this->paymentProcessorId, $exception->getMessage());
      }
      throw $exception;
    } finally {
      $lock->release();
    }
  }

  /**
   * @param array<string,string> $fields
   * @return array<string,mixed>
   */
  private function requestToken(array $fields): array {
    CRM_SumupPaymentProcessor_PartnerCredentials::assertConfigured();
    try {
      $response = (new GuzzleHttp\Client())->request('POST', self::TOKEN_URL, [
        'form_params' => $fields,
        'headers' => ['Accept' => 'application/json'],
        'http_errors' => FALSE,
        'timeout' => 15,
        'connect_timeout' => 5,
      ]);
    }
    catch (Throwable $exception) {
      throw new PaymentProcessorException(E::ts('Unable to contact the SumUp authorization service.'));
    }
    $payload = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($payload)) {
      $payload = [];
    }
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      $code = (string) ($payload['error'] ?? 'oauth_error');
      $description = (string) ($payload['error_description'] ?? 'Token request rejected.');
      throw new PaymentProcessorException(E::ts(
        'SumUp authorization failed: %1 (%2).',
        [1 => $code, 2 => $description]
      ));
    }
    $requiresRefreshToken = ($fields['grant_type'] ?? '') === 'authorization_code';
    if (empty($payload['access_token']) || ($requiresRefreshToken && empty($payload['refresh_token']))) {
      throw new PaymentProcessorException(E::ts('SumUp returned an incomplete OAuth token response.'));
    }
    return $payload;
  }

  private function retrieveMerchantCode(string $accessToken): string {
    foreach (['/v0.1/me', '/v0.1/memberships'] as $path) {
      try {
        $response = (new GuzzleHttp\Client())->request('GET', self::API_URL . $path, [
          'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
          ],
          'http_errors' => FALSE,
          'timeout' => 15,
          'connect_timeout' => 5,
        ]);
      }
      catch (Throwable) {
        continue;
      }
      if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
        continue;
      }
      $payload = json_decode((string) $response->getBody(), TRUE);
      $merchantCodes = [];
      $this->collectMerchantCodes($payload, $merchantCodes);
      $merchantCodes = array_values(array_unique($merchantCodes));
      if (count($merchantCodes) === 1) {
        return $merchantCodes[0];
      }
    }
    throw new PaymentProcessorException(E::ts(
        'SumUp did not return one unambiguous merchant account for this authorization.'
    ));
  }

  /**
   * @param array|bool|float|int|string|null $value
   * @phpstan-param array<mixed>|bool|float|int|string|null $value
   * @param list<string> $merchantCodes
   */
  private function collectMerchantCodes(mixed $value, array &$merchantCodes): void {
    if (!is_array($value)) {
      return;
    }
    foreach ($value as $key => $child) {
      if (
        ($key === 'merchant_code' || $key === 'merchantCode')
        && is_string($child)
        && preg_match('/^[A-Za-z0-9_-]{4,100}$/', $child)
      ) {
        $merchantCodes[] = strtoupper($child);
      }
      else {
        $this->collectMerchantCodes($child, $merchantCodes);
      }
    }
  }

  /**
   * @return list<string> */
  public static function getMissingScopes(string $scope): array {
    if ($scope === '') {
      return CRM_SumupPaymentProcessor_PartnerCredentials::SCOPES;
    }
    $granted = preg_split('/[ ,]+/', trim($scope)) ?: [];
    return array_values(array_diff(CRM_SumupPaymentProcessor_PartnerCredentials::SCOPES, $granted));
  }

  /**
   * @param array<string,mixed> $token
   * @param string $merchantCode
   * @param array<string,mixed>|null $previous
   * @return array<string,mixed>
   */
  private function normaliseToken(array $token, string $merchantCode, ?array $previous): array {
    $expiresIn = max(1, (int) ($token['expires_in'] ?? 3600));
    return [
      'merchant_code' => $merchantCode,
      'access_token' => (string) $token['access_token'],
      'refresh_token' => (string) ($token['refresh_token'] ?? $previous['refresh_token'] ?? ''),
      'scope' => (string) ($token['scope'] ?? $previous['scope'] ?? ''),
      'expires_at' => time() + $expiresIn,
      'status' => 'connected',
      'linked_at' => (string) ($previous['linked_at'] ?? date('Y-m-d H:i:s')),
      'last_refresh_at' => $previous['last_refresh_at'] ?? NULL,
      'last_error' => NULL,
    ];
  }

  private function stateCacheKey(string $state): string {
    return 'sumup.oauth.state.' . hash('sha256', $state);
  }

}
