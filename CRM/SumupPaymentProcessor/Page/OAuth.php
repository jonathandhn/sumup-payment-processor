<?php

declare(strict_types = 1);

use Civi\Api4\PaymentProcessor;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
final class CRM_SumupPaymentProcessor_Page_OAuth extends CRM_Core_Page {
  private const FORM_TOKEN_SESSION_KEY = 'sumup_oauth_form_token';

  public function run(): void {
    try {
      if (CRM_Utils_System::currentPath() === 'civicrm/sumup/oauth/callback') {
        $this->handleCallback();
        return;
      }

      $paymentProcessorId = $this->getPaymentProcessorId();
      if ($this->isPostRequest()) {
        $this->handlePost($paymentProcessorId);
      }
      $action = CRM_Utils_Request::retrieve('oauth_action', 'String', $this, FALSE, 'status');
      if ($action === 'connect') {
        CRM_Utils_System::redirect((new CRM_SumupPaymentProcessor_OAuth($paymentProcessorId))
          ->buildAuthorizationUrl());
      }

      $this->renderStatus($paymentProcessorId);
    }
    catch (Throwable $exception) {
      CRM_Core_Session::setStatus(
        $exception->getMessage(),
        E::ts('SumUp OAuth connection'),
        'error'
      );
      $this->renderProcessorList();
    }

    parent::run();
  }

  private function handleCallback(): void {
    $providerError = CRM_Utils_Request::retrieve('error', 'String', $this, FALSE, '');
    if (is_string($providerError) && $providerError !== '') {
      $description = (string) CRM_Utils_Request::retrieve(
        'error_description',
        'String',
        $this,
        FALSE,
        ''
      );
      throw new PaymentProcessorException(E::ts(
        'SumUp authorization was rejected: %1%2.',
        [
          1 => $providerError,
          2 => $description !== '' ? ' (' . $description . ')' : '',
        ]
      ));
    }
    $code = CRM_Utils_Request::retrieve('code', 'String', $this, TRUE);
    $state = CRM_Utils_Request::retrieve('state', 'String', $this, TRUE);
    $link = CRM_SumupPaymentProcessor_OAuth::completeCallback($code, $state);
    $paymentProcessorId = (int) $link['payment_processor_id'];
    CRM_Core_Session::setStatus(
        E::ts('SumUp merchant %1 is now connected.', [1 => $link['merchant_code']]),
        E::ts('SumUp'),
        'success'
    );
    CRM_Utils_System::redirect($this->statusUrl($paymentProcessorId));
  }

  private function renderStatus(int $paymentProcessorId): void {
    $processor = $this->getProcessor($paymentProcessorId);
    $oauth = new CRM_SumupPaymentProcessor_OAuth($paymentProcessorId);
    $connection = $oauth->getConnection();
    $missingScopes = $connection === NULL
            ? []
            : CRM_SumupPaymentProcessor_OAuth::getMissingScopes((string) $connection['scope']);
    $connectUrl = CRM_Utils_System::url(
        'civicrm/admin/sumup/oauth',
        'reset=1&oauth_action=connect&processor_id=' . $paymentProcessorId,
        TRUE,
        NULL,
        FALSE,
        TRUE
    );
    $formAction = $this->statusUrl($paymentProcessorId);
    $clientId = CRM_SumupPaymentProcessor_PartnerCredentials::getStoredClientId();
    $storedRedirectUri = CRM_SumupPaymentProcessor_PartnerCredentials::getStoredRedirectUri();

    CRM_Utils_System::setTitle(E::ts('SumUp OAuth connection'));
    $this->assign('processor', $processor);
    $this->assign('connection', $connection);
    $this->assign('missingScopes', $missingScopes);
    $this->assign('connectUrl', $connectUrl);
    $this->assign('formAction', $formAction);
    $this->assign('formToken', $this->getFormToken());
    $this->assign('clientId', $clientId);
    $this->assign('hasClientSecret', CRM_SumupPaymentProcessor_PartnerCredentials::hasStoredClientSecret());
    $this->assign('credentialsConfigured', CRM_SumupPaymentProcessor_PartnerCredentials::isConfigured());
    $this->assign('redirectUri', $oauth->getCalculatedRedirectUri());
    $this->assign('storedRedirectUri', $storedRedirectUri);
  }

  private function renderProcessorList(): void {
    $processors = PaymentProcessor::get(FALSE)
      ->addSelect('id', 'name', 'is_test', 'user_name')
      ->addWhere('class_name', '=', 'Payment_Sumup')
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->addOrderBy('is_test', 'DESC')
      ->execute()
      ->getArrayCopy();
    foreach ($processors as &$processor) {
      $processor['oauth_url'] = $this->statusUrl((int) $processor['id']);
    }
    unset($processor);
    CRM_Utils_System::setTitle(E::ts('SumUp OAuth connection'));
    $this->assign('processors', $processors);
  }

  private function handlePost(int $paymentProcessorId): void {
    $submittedToken = (string) ($_POST['sumup_oauth_form_token'] ?? '');
    if ($submittedToken === '' || !hash_equals($this->getFormToken(), $submittedToken)) {
      throw new PaymentProcessorException(E::ts('The SumUp connection form token is invalid.'));
    }
    $action = (string) ($_POST['sumup_oauth_form_action'] ?? '');
    if ($action === 'save_credentials') {
      $oauth = new CRM_SumupPaymentProcessor_OAuth($paymentProcessorId);
      CRM_SumupPaymentProcessor_PartnerCredentials::save(
        (string) ($_POST['sumup_oauth_client_id'] ?? ''),
        (string) ($_POST['sumup_oauth_client_secret'] ?? ''),
        $oauth->getCalculatedRedirectUri()
      );
      CRM_Core_Session::setStatus(
        E::ts('The SumUp OAuth client credentials have been saved.'),
        E::ts('SumUp'),
        'success'
      );
      return;
    }
    if ($action === 'disconnect') {
      (new CRM_SumupPaymentProcessor_OAuth($paymentProcessorId))->disconnect();
      CRM_Core_Session::setStatus(
        E::ts('The SumUp merchant has been disconnected.'),
        E::ts('SumUp'),
        'success'
      );
      return;
    }
    throw new PaymentProcessorException(E::ts('The SumUp connection form action is invalid.'));
  }

  /**
   * @return array<string,mixed> */
  private function getProcessor(int $paymentProcessorId): array {
    $processor = PaymentProcessor::get(FALSE)
      ->addSelect('id', 'name', 'is_test', 'user_name', 'is_active')
      ->addWhere('id', '=', $paymentProcessorId)
      ->addWhere('class_name', '=', 'Payment_Sumup')
      ->execute()
      ->single();
    return $processor;
  }

  private function getPaymentProcessorId(): int {
    $paymentProcessorId = (int) CRM_Utils_Request::retrieve(
        'processor_id',
        'Positive',
        $this,
        FALSE,
        0
    );
    if ($paymentProcessorId <= 0) {
      $this->renderProcessorList();
      parent::run();
      exit;
    }
    $this->getProcessor($paymentProcessorId);
    return $paymentProcessorId;
  }

  private function statusUrl(int $paymentProcessorId): string {
    return CRM_Utils_System::url(
        'civicrm/admin/sumup/oauth',
        'reset=1&processor_id=' . $paymentProcessorId,
        TRUE,
        NULL,
        FALSE,
        TRUE
    );
  }

  private function getFormToken(): string {
    $session = CRM_Core_Session::singleton();
    $token = (string) $session->get(self::FORM_TOKEN_SESSION_KEY);
    if ($token === '') {
      $token = bin2hex(random_bytes(24));
      $session->set(self::FORM_TOKEN_SESSION_KEY, $token);
    }
    return $token;
  }

  private function isPostRequest(): bool {
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
  }

}
