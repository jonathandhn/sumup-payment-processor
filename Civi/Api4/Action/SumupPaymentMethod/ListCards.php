<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupPaymentMethod;

use Civi\Api4\Generic\Result;
use Civi\Api4\PaymentProcessor;
use Civi\Payment\System;
use CRM_Core_Payment_Sumup;

final class ListCards extends ActionBase
{
    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        $contactId = $this->authorisedContactId();
        $processors = PaymentProcessor::get(false)
            ->addSelect('id', 'title', 'is_test')
            ->addWhere('class_name', '=', 'Payment_Sumup')
            ->addWhere('is_active', '=', true)
            ->addWhere('is_test', 'IN', [true, false])
            ->execute();
        foreach ($processors as $processorRecord) {
            $processor = System::singleton()->getById((int) $processorRecord['id']);
            if (!$processor instanceof CRM_Core_Payment_Sumup) {
                continue;
            }
            foreach ($processor->getSavedCardsForContact($contactId) as $card) {
                $result[] = $card + [
                    'payment_processor_id' => (int) $processorRecord['id'],
                    'payment_processor_title' => (string) $processorRecord['title'],
                    'is_test' => (bool) $processorRecord['is_test'],
                ];
            }
        }
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore
}
