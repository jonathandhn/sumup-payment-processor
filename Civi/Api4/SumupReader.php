<?php

declare(strict_types=1);

namespace Civi\Api4;

use Civi\Api4\Action\SumupReader\Pair;
use Civi\Api4\Action\SumupReader\Synchronise;

class SumupReader extends Generic\DAOEntity
{
    public static function pair(bool $checkPermissions = true): Pair
    {
        return (new Pair(static::getEntityName(), __FUNCTION__))
            ->setCheckPermissions($checkPermissions);
    }

    public static function synchronise(bool $checkPermissions = true): Synchronise
    {
        return (new Synchronise(static::getEntityName(), __FUNCTION__))
            ->setCheckPermissions($checkPermissions);
    }
}
