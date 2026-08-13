<?php

declare(strict_types=1);

namespace Civi\Api4;

use Civi\Api4\Action\SumupRecurringCard\Run;
use Civi\Api4\Generic\AbstractEntity;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * SumUp recurring-card operations.
 *
 * @searchable none
 */
final class SumupRecurringCard extends AbstractEntity
{
    public static function run(bool $checkPermissions = true): Run
    {
        return (new Run(static::getEntityName(), __FUNCTION__))
            ->setCheckPermissions($checkPermissions);
    }

    public static function getFields(bool $checkPermissions = true): BasicGetFieldsAction
    {
        return (new BasicGetFieldsAction(__CLASS__, __FUNCTION__, static fn(): array => []))
            ->setCheckPermissions($checkPermissions);
    }
}
