<?php

declare(strict_types=1);

namespace Civi\Api4;

use Civi\Api4\Action\SumupTerminalCheckout\Retry;
use Civi\Api4\Generic\AbstractEntity;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Public, token-authenticated SumUp Solo checkout operations.
 *
 * @searchable none
 */
final class SumupTerminalCheckout extends AbstractEntity
{
    /** @return array<string, list<string>> */
    public static function permissions(): array
    {
        return [
            'retry' => ['make online contributions'],
            'meta' => ['make online contributions'],
        ];
    }

    public static function retry(bool $checkPermissions = true): Retry
    {
        return (new Retry(static::getEntityName(), __FUNCTION__))
            ->setCheckPermissions($checkPermissions);
    }

    public static function getFields(bool $checkPermissions = true): BasicGetFieldsAction
    {
        return (new BasicGetFieldsAction(__CLASS__, __FUNCTION__, static fn(): array => []))
            ->setCheckPermissions($checkPermissions);
    }
}
