<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupPaymentMethod;

use Civi\Api4\Generic\Result;
use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\System;
use CRM_Core_Payment_Sumup;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

/**
 * @method $this setContributionId(int $contributionId)
 * @method $this setCheckoutId(string $checkoutId)
 * @method $this setPaymentTokenId(int $paymentTokenId)
 * @method $this setProcessorId(int $processorId)
 * @method $this setExpires(int $expires)
 * @method $this setSignature(string $signature)
 */
final class PayContribution extends \Civi\Api4\Generic\AbstractAction
{
    protected int $contributionId = 0;

    protected string $checkoutId = '';

    protected int $paymentTokenId = 0;

    protected int $processorId = 0;

    protected int $expires = 0;

    protected string $signature = '';

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        $processor = System::singleton()->getById($this->processorId);
        if (!$processor instanceof CRM_Core_Payment_Sumup) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp payment processor.'));
        }
        $result[] = $processor->payContributionWithSavedCard(
            $this->contributionId,
            $this->checkoutId,
            $this->paymentTokenId,
            $this->expires,
            $this->signature
        );
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore
}
