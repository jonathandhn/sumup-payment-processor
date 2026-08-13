<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupPaymentMethod;

use Civi\Api4\Generic\Result;
use Civi\Api4\PaymentToken;
use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\System;
use CRM_Core_Payment_Sumup;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

/**
 * @method $this setPaymentTokenId(int $paymentTokenId)
 */
final class DeactivateCard extends ActionBase
{
    protected int $paymentTokenId = 0;

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        $contactId = $this->authorisedContactId();
        $paymentToken = PaymentToken::get(false)
            ->addSelect('payment_processor_id')
            ->addWhere('id', '=', $this->paymentTokenId)
            ->addWhere('contact_id', '=', $contactId)
            ->execute()
            ->first();
        if (!$paymentToken) {
            throw new PaymentProcessorException(E::ts('This saved SumUp card does not exist.'));
        }
        $processor = System::singleton()->getById((int) $paymentToken['payment_processor_id']);
        if (!$processor instanceof CRM_Core_Payment_Sumup) {
            throw new PaymentProcessorException(E::ts('This saved card does not use SumUp.'));
        }
        $result[] = $processor->deactivateSavedCard($this->paymentTokenId, $contactId);
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore
}
