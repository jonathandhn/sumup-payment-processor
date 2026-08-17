<?php

declare(strict_types=1);

namespace Civi\Api4;

/**
 * SumUp checkout-attempt registry.
 */
class SumupCheckout extends Generic\DAOEntity
{
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
