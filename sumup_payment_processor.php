<?php

declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'sumup_payment_processor.civix.php';

$sumupAutoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($sumupAutoload)) {
  require_once $sumupAutoload;
}
// phpcs:enable

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function sumup_payment_processor_civicrm_config(\CRM_Core_Config $config): void {
  _sumup_payment_processor_civix_civicrm_config($config);

  CRM_SumupPaymentProcessor_Page_ApplePayDomainAssociation::checkAndServeEarly();

  \Civi::dispatcher()->addListener(
        'hook_civicrm_tabset',
        'sumup_payment_processor_decorate_contact_tab',
        -100
    );
}

/**
 * Add a local item count to the SumUp contact tab.
 *
 * The tab itself is added by Afform. This listener runs afterwards and avoids
 * a remote SumUp request while the contact summary is loading.
 */
function sumup_payment_processor_decorate_contact_tab(\Civi\Core\Event\GenericHookEvent $event): void {
  if ($event->tabsetName !== 'civicrm/contact/view') {
    return;
  }

  $contactId = (int) ($event->context['contact_id'] ?? 0);
  if ($contactId <= 0) {
    return;
  }

  foreach ($event->tabs as &$tab) {
    if (($tab['id'] ?? NULL) !== 'sumup_payment_methods') {
      continue;
    }

    $tab['title'] = E::ts('SumUp');
    $tab['count'] = sumup_payment_processor_get_contact_tab_count($contactId);
    break;
  }
  unset($tab);
}

/**
 * Count locally-known SumUp cards and active recurring schedules.
 */
function sumup_payment_processor_get_contact_tab_count(int $contactId): int {
  try {
    $processors = \Civi\Api4\PaymentProcessor::get(FALSE)
      ->addSelect('id')
      ->addWhere('class_name', '=', 'Payment_Sumup')
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute();
    $processorIds = [];
    foreach ($processors as $processor) {
      $processorIds[] = (int) $processor['id'];
    }
    if ($processorIds === []) {
      return 0;
    }

    $cards = \Civi\Api4\PaymentToken::get(FALSE)
      ->addSelect('id')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('payment_processor_id', 'IN', $processorIds)
      ->execute();
    $schedules = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('id')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('payment_processor_id', 'IN', $processorIds)
      ->addWhere('contribution_status_id:name', '=', 'In Progress')
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute();

    return $cards->count() + $schedules->count();
  }
  catch (\Throwable $exception) {
    \Civi::log()->warning(sprintf(
        'Unable to count SumUp payment methods for contact %d: %s',
        $contactId,
        $exception->getMessage()
    ));
    return 0;
  }
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * @phpstan-param array<string,mixed> $angularModules
 */
function sumup_payment_processor_civicrm_angularModules(array &$angularModules): void {
  if (!sumup_payment_processor_supports_afform_checkout()) {
    return;
  }
  $module = include __DIR__ . '/ang/afSumUp.ang.php';
  $module['ext'] = E::LONG_NAME;
  $angularModules['afSumUp'] = $module;
}

function sumup_payment_processor_supports_afform_checkout(): bool {
  return interface_exists('Civi\\Checkout\\CheckoutOptionInterface')
        && interface_exists('Civi\\Checkout\\AfformCheckoutOptionInterface');
}

/**
 * Implements hook_civicrm_buildForm().
 */
function sumup_payment_processor_civicrm_buildForm(string $formName, mixed &$form): void {
  if ($formName === 'CRM_Admin_Form_PaymentProcessor') {
    sumup_payment_processor_add_oauth_processor_panel($form);
    return;
  }

  if (
        !in_array(
            $formName,
            [
              'CRM_Contribute_Form_Contribution_Main',
              'CRM_Event_Form_Registration_Register',
            ],
            TRUE
        )
    ) {
    return;
  }

  try {
    $processors = \Civi\Api4\PaymentProcessor::get(FALSE)
      ->addSelect('id')
      ->addWhere('class_name', '=', 'Payment_Sumup')
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute();
  }
  catch (\Throwable) {
    return;
  }
  $processorIds = [];
  foreach ($processors as $processor) {
    if (!empty($processor['id'])) {
      $processorIds[] = (int) $processor['id'];
    }
  }
  if (!$processorIds) {
    return;
  }

  \Civi::resources()->addStyleFile(E::LONG_NAME, 'ang/afSumUp/sumUp.css');
  \Civi::resources()->addScriptFile(E::LONG_NAME, 'js/civicrmSumUp.js');
  if (
        !CRM_SumupPaymentProcessor_CheckoutMode::usesHosted(
            CRM_SumupPaymentProcessor_CheckoutMode::getConfiguredMode()
        )
    ) {
    \Civi::resources()->addVars('sumupQuickForm', ['processorIds' => $processorIds]);
    \Civi::resources()->addScriptFile(E::LONG_NAME, 'js/checkout.js');
    \Civi::resources()->addScriptFile(E::LONG_NAME, 'js/quickform-checkout.js');
  }
}

/**
 * Replace the sandbox API credential fields with the OAuth connection panel.
 */
function sumup_payment_processor_add_oauth_processor_panel(CRM_Core_Form $form): void {
  $paymentProcessorType = $form->getVar('_paymentProcessorDAO');
  if (!$paymentProcessorType || $paymentProcessorType->class_name !== 'Payment_Sumup') {
    return;
  }

  $liveProcessorId = (int) $form->getVar('_id');
  if ($liveProcessorId <= 0) {
    return;
  }

  try {
    $liveProcessor = \Civi\Api4\PaymentProcessor::get(FALSE)
      ->addSelect('name', 'domain_id')
      ->addWhere('id', '=', $liveProcessorId)
      ->execute()
      ->single();
    $query = \Civi\Api4\PaymentProcessor::get(FALSE)
      ->addSelect('id', 'name', 'user_name')
      ->addWhere('class_name', '=', 'Payment_Sumup')
      ->addWhere('is_test', '=', TRUE)
      ->addWhere('name', '=', (string) $liveProcessor['name']);
    if (!empty($liveProcessor['domain_id'])) {
      $query->addWhere('domain_id', '=', (int) $liveProcessor['domain_id']);
    }
    $testProcessor = $query->setLimit(1)->execute()->first();
    if (!$testProcessor) {
      return;
    }
    $testProcessorId = (int) $testProcessor['id'];
    $connection = (new CRM_SumupPaymentProcessor_OAuth($testProcessorId))->getConnection();
  }
  catch (Throwable $exception) {
    Civi::log()->warning('Unable to build the SumUp OAuth processor panel: ' . $exception->getMessage());
    return;
  }

  $oauthUrl = CRM_Utils_System::url(
        'civicrm/admin/sumup/oauth',
        'reset=1&processor_id=' . $testProcessorId,
        TRUE,
        NULL,
        FALSE,
        TRUE
    );
  $panel = [];
  $panel[] = '<fieldset id="sumup-sandbox-oauth-block"><legend>'
        . E::ts('SumUp OAuth connection for the test processor') . '</legend>';
  $panel[] = '<div class="crm-block crm-form-block">';
  $panel[] = '<p class="description">' . E::ts(
        'The test processor uses the SumUp authorization screen. The merchant code and tokens are obtained '
        . 'from SumUp; no API key is entered in this form.'
    ) . '</p>';
  $panel[] = '<p class="status warning">' . E::ts(
        'SumUp determines whether the authorized merchant is sandbox or live. Verify the merchant code before '
        . 'initiating a payment.'
    ) . '</p>';
  if ($connection !== NULL) {
    $panel[] = '<p class="status success">' . E::ts(
        'Connected to merchant %1.',
        [1 => htmlspecialchars((string) $connection['merchant_code'], ENT_QUOTES, 'UTF-8')]
    ) . '</p>';
    $missingScopes = CRM_SumupPaymentProcessor_OAuth::getMissingScopes((string) $connection['scope']);
    if ($missingScopes !== []) {
      $panel[] = '<p class="status warning">' . E::ts(
      'Missing OAuth scopes: %1.',
      [1 => htmlspecialchars(implode(', ', $missingScopes), ENT_QUOTES, 'UTF-8')]
      ) . '</p>';
    }
  }
  else {
    $panel[] = '<p class="status">' . E::ts('No SumUp merchant is connected yet.') . '</p>';
  }
  $panel[] = '<p><a class="button crm-form-submit" href="'
        . htmlspecialchars($oauthUrl, ENT_QUOTES, 'UTF-8') . '">'
        . ($connection === NULL ? E::ts('Connect to SumUp') : E::ts('Manage SumUp connection'))
        . '</a></p>';
  $panel[] = '</div></fieldset>';
  $panelHtml = implode('', $panel);

  Civi::resources()->addScript(
        "CRM.\$(function(\$) {
            \$('#sumup-sandbox-oauth-block').remove();
            \$('.crm-paymentProcessor-form-block-test_user_name, '
                + '.crm-paymentProcessor-form-block-test_password').hide();
            var panelHtml = " . json_encode($panelHtml) . ";
            var \$target = \$('.crm-paymentProcessor-form-block fieldset').last();
            if (\$target.length) {
                \$target.after(panelHtml);
            }
            else {
                \$('.crm-paymentProcessor-form-block .crm-submit-buttons').before(panelHtml);
            }
        });"
    );
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function sumup_payment_processor_civicrm_install(): void {
  _sumup_payment_processor_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function sumup_payment_processor_civicrm_enable(): void {
  _sumup_payment_processor_civix_civicrm_enable();
}
