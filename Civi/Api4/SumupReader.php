<?php

declare(strict_types = 1);

namespace Civi\Api4;

use Civi\Api4\Action\SumupReader\Adopt;
use Civi\Api4\Action\SumupReader\ListDiscovered;
use Civi\Api4\Action\SumupReader\Pair;
use Civi\Api4\Action\SumupReader\Synchronise;
use Civi\Api4\Action\SumupReader\Unpair;

class SumupReader extends Generic\DAOEntity {

  public static function adopt(bool $checkPermissions = TRUE): Adopt {
    return (new Adopt(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function listDiscovered(bool $checkPermissions = TRUE): ListDiscovered {
    return (new ListDiscovered(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function pair(bool $checkPermissions = TRUE): Pair {
    return (new Pair(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function synchronise(bool $checkPermissions = TRUE): Synchronise {
    return (new Synchronise(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function reassignSite(bool $checkPermissions = TRUE): Action\SumupReader\ReassignSite {
    return (new Action\SumupReader\ReassignSite(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function unpair(bool $checkPermissions = TRUE): Unpair {
    return (new Unpair(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

}
