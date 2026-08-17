<?php

declare(strict_types=1);

namespace Civi\Api4;

/**
 * SumUp checkout-attempt registry.
 */
class SumupCheckout extends Generic\DAOEntity
{
    /** @return array<string, list<string>> */
    public static function permissions(): array
    {
        return [
            'sendPaymentLink' => ['make online contributions'],
            'get' => ['access CiviContribute'],
            'default' => ['administer CiviCRM'],
        ];
    }

    /**
     * @param bool $checkPermissions
     * @return Action\SumupCheckout\SendPaymentLink
     */
    public static function sendPaymentLink(bool $checkPermissions = true): Action\SumupCheckout\SendPaymentLink
    {
        return (new Action\SumupCheckout\SendPaymentLink(self::getEntityName(), __FUNCTION__))
            ->setCheckPermissions($checkPermissions);
    }
}
