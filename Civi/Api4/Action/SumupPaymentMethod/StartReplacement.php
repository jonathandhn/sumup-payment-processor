<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupPaymentMethod;

use Civi\Api4\Generic\Result;

/**
 * @method $this setRecurId(int $recurId)
 */
final class StartReplacement extends ActionBase
{
    protected int $recurId = 0;

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        $contactId = $this->authorisedContactId();
        $schedule = $this->ownedSchedule($this->recurId);
        $result[] = $this->processor($schedule)->startPaymentMethodReplacement(
            $this->recurId,
            $contactId
        );
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore
}
