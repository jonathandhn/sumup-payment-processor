<?php

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore

/**
 * Compatibility DAO for SumUp payment-method remediation.
 */
class CRM_SumupPaymentProcessor_DAO_SumupRemediation extends CRM_Core_DAO_Base
{
    /** @var string */
    public static $_tableName = 'civicrm_sumup_remediation';
}
