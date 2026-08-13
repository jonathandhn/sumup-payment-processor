<?php

declare(strict_types=1);

namespace Civi\Api4;

use Civi\Api4\Action\SumupPaymentMethod\ContinueReplacement;
use Civi\Api4\Action\SumupPaymentMethod\Get;
use Civi\Api4\Action\SumupPaymentMethod\PayContribution;
use Civi\Api4\Action\SumupPaymentMethod\ListCards;
use Civi\Api4\Action\SumupPaymentMethod\StartReplacement;
use Civi\Api4\Action\SumupPaymentMethod\SendManagementLink;
use Civi\Api4\Generic\AbstractEntity;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Logged-in contact SumUp payment-method operations.
 *
 * @searchable none
 */
final class SumupPaymentMethod extends AbstractEntity
{
    /** @return array<string, list<string>> */
    public static function permissions(): array
    {
        return [
            'get' => ['make online contributions'],
            'listCards' => ['make online contributions'],
            'startReplacement' => ['make online contributions'],
            'continueReplacement' => ['make online contributions'],
            'payContribution' => ['make online contributions'],
            'sendManagementLink' => ['access CiviContribute'],
            'meta' => ['make online contributions'],
        ];
    }

    public static function get(bool $checkPermissions = true): Get
    {
        return (new Get(static::getEntityName(), __FUNCTION__))
            ->setCheckPermissions($checkPermissions);
    }

    public static function listCards(bool $checkPermissions = true): ListCards
    {
        return (new ListCards(static::getEntityName(), __FUNCTION__))
            ->setCheckPermissions($checkPermissions);
    }

    public static function startReplacement(bool $checkPermissions = true): StartReplacement
    {
        return (new StartReplacement(static::getEntityName(), __FUNCTION__))
            ->setCheckPermissions($checkPermissions);
    }

    public static function continueReplacement(bool $checkPermissions = true): ContinueReplacement
    {
        return (new ContinueReplacement(static::getEntityName(), __FUNCTION__))
            ->setCheckPermissions($checkPermissions);
    }

    public static function payContribution(bool $checkPermissions = true): PayContribution
    {
        return (new PayContribution(static::getEntityName(), __FUNCTION__))
            ->setCheckPermissions($checkPermissions);
    }

    public static function sendManagementLink(bool $checkPermissions = true): SendManagementLink
    {
        return (new SendManagementLink(static::getEntityName(), __FUNCTION__))
            ->setCheckPermissions($checkPermissions);
    }

    public static function getFields(bool $checkPermissions = true): BasicGetFieldsAction
    {
        return (new BasicGetFieldsAction(__CLASS__, __FUNCTION__, static fn(): array => []))
            ->setCheckPermissions($checkPermissions);
    }
}
