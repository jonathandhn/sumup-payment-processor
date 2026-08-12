<?php

declare(strict_types=1);

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
function sumup_payment_processor_civicrm_config(\CRM_Core_Config $config): void
{
    _sumup_payment_processor_civix_civicrm_config($config);

    if (sumup_payment_processor_supports_afform_checkout()) {
        \Civi::dispatcher()->addListener(
            'civi.checkout.options',
            'sumup_payment_processor_register_afform_checkout_options'
        );
    }
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * @param array<string, mixed> $angularModules
 */
function sumup_payment_processor_civicrm_angularModules(array &$angularModules): void
{
    if (!sumup_payment_processor_supports_afform_checkout()) {
        return;
    }
    $module = include __DIR__ . '/ang/afSumUp.ang.php';
    $module['ext'] = E::LONG_NAME;
    $angularModules['afSumUp'] = $module;
}

function sumup_payment_processor_supports_afform_checkout(): bool
{
    return version_compare(\CRM_Utils_System::version(), '6.14', '>=')
        && interface_exists('Civi\\Checkout\\CheckoutOptionInterface')
        && interface_exists('Civi\\Checkout\\AfformCheckoutOptionInterface')
        && class_exists('Civi\\Checkout\\CheckoutSession')
        && class_exists('Civi\\Afform\\Event\\AfformValidateEvent')
        && class_exists('Civi\\Api4\\PaymentProcessor');
}

/**
 * @param \Civi\Core\Event\GenericHookEvent $event
 */
function sumup_payment_processor_register_afform_checkout_options($event): void
{
    if (!sumup_payment_processor_supports_afform_checkout()) {
        return;
    }
    $processors = \Civi\Api4\PaymentProcessor::get(false)
        ->addWhere('class_name', '=', 'Payment_Sumup')
        ->addWhere('is_active', '=', true)
        ->addWhere('is_test', 'IN', [true, false])
        ->execute();
    $pairs = [];
    foreach ($processors as $processor) {
        $pairs[$processor['name']][$processor['is_test'] ? 'test' : 'live'] = $processor;
    }
    foreach ($pairs as $name => $pair) {
        $event->options['sumup_embedded_checkout_' . $name] =
            new \Civi\SumupPaymentProcessor\CheckoutOption\SumUpEmbeddedCheckout(
                $pair['live'] ?? null,
                $pair['test'] ?? null
            );
        $event->options['sumup_solo_checkout_' . $name] =
            new \Civi\SumupPaymentProcessor\CheckoutOption\SumUpSoloCheckout(
                $pair['live'] ?? null,
                $pair['test'] ?? null
            );
    }
}

/**
 * Implements hook_civicrm_buildForm().
 */
function sumup_payment_processor_civicrm_buildForm(string $formName, mixed &$form): void
{
    if (
        !in_array(
            $formName,
            [
                'CRM_Contribute_Form_Contribution_Main',
                'CRM_Event_Form_Registration_Register',
            ],
            true
        )
    ) {
        return;
    }

    try {
        $processors = \Civi\Api4\PaymentProcessor::get(false)
            ->addSelect('id')
            ->addWhere('class_name', '=', 'Payment_Sumup')
            ->addWhere('is_active', '=', true)
            ->addWhere('is_test', 'IN', [true, false])
            ->execute();
    } catch (\Throwable) {
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
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function sumup_payment_processor_civicrm_install(): void
{
    _sumup_payment_processor_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function sumup_payment_processor_civicrm_enable(): void
{
    _sumup_payment_processor_civix_civicrm_enable();
}
