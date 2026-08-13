<?php

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore

/**
 * Compatibility DAO for the SumUp checkout-attempt registry.
 *
 * @property int|string $id
 * @property string $checkout_id
 * @property string $checkout_reference
 * @property int|string $contribution_id
 * @property int|string $payment_processor_id
 * @property string $state
 * @property float|string $amount
 * @property string $currency
 * @property string $checkout_mode
 * @property string|null $transaction_id
 * @property string|null $reader_id
 * @property string $purpose
 * @property string|null $customer_id
 * @property int|string|null $payment_token_id
 * @property string|null $setup_checkout_id
 * @property string $created_date
 * @property string|null $modified_date
 * @property string|null $verified_date
 */
class CRM_SumupPaymentProcessor_DAO_SumupCheckout extends CRM_Core_DAO_Base
{
    /** @var string */
    public static $_tableName = 'civicrm_sumup_checkout';
}
