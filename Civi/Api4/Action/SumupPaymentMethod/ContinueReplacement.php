<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupPaymentMethod;

use Civi\Api4\Generic\Result;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

/**
 * @method $this setRecurId(int $recurId)
 * @method $this setCheckoutId(string $checkoutId)
 */
final class ContinueReplacement extends ActionBase
{
    protected int $recurId = 0;

    protected string $checkoutId = '';

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        if (!\CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($this->checkoutId)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp replacement checkout identifier.'));
        }
        $contactId = $this->authorisedContactId();
        $schedule = $this->ownedSchedule($this->recurId);
        $result[] = $this->processor($schedule)->completePaymentMethodReplacement(
            $this->recurId,
            $contactId,
            $this->checkoutId
        );
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore
}
