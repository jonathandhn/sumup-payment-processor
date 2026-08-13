<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupPaymentMethod;

use Civi\Api4\ContributionRecur;
use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\System;
use CRM_Core_Payment_Sumup;
use CRM_Core_Session;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

abstract class ActionBase extends \Civi\Api4\Generic\AbstractAction
{
    protected ?int $contactId = null;

    protected string $checksum = '';

    protected function authorisedContactId(): int
    {
        $contactId = (int) CRM_Core_Session::getLoggedInContactID();
        if ($this->contactId !== null || $this->checksum !== '') {
            if (
                $this->contactId !== null
                && $this->contactId > 0
                && $this->checksum === ''
                && \CRM_Core_Permission::check('access CiviContribute')
                && \CRM_Core_Permission::check('edit contributions')
            ) {
                return $this->contactId;
            }
            if (
                $this->contactId === null
                || $this->contactId <= 0
                || $this->checksum === ''
                || strlen($this->checksum) > 255
                || !\CRM_Contact_BAO_Contact_Utils::validChecksum($this->contactId, $this->checksum)
            ) {
                throw new PaymentProcessorException(E::ts('The SumUp payment-method access link is invalid.'));
            }
            return $this->contactId;
        }
        if ($contactId <= 0) {
            throw new PaymentProcessorException(E::ts(
                'You must be logged in or use a valid CiviCRM checksum to manage a SumUp payment method.'
            ));
        }
        return $contactId;
    }

    protected function isAdministrativeRequest(): bool
    {
        return $this->contactId !== null
            && $this->contactId > 0
            && $this->checksum === ''
            && \CRM_Core_Permission::check('access CiviContribute')
            && \CRM_Core_Permission::check('edit contributions');
    }

    /** @return array<string, int|string> */
    protected function checksumQuery(): array
    {
        if ($this->contactId === null || $this->checksum === '') {
            return [];
        }
        return ['cid' => $this->contactId, 'cs' => $this->checksum];
    }

    /** @return array<string, mixed> */
    protected function ownedSchedule(int $recurId): array
    {
        if ($recurId <= 0) {
            throw new PaymentProcessorException(E::ts('Invalid recurring contribution identifier.'));
        }
        $schedule = ContributionRecur::get(false)
            ->addSelect('id', 'contact_id', 'payment_processor_id', 'contribution_status_id:name')
            ->addWhere('id', '=', $recurId)
            ->execute()
            ->single();
        if (
            (int) $schedule['contact_id'] !== $this->authorisedContactId()
            || (string) $schedule['contribution_status_id:name'] !== 'In Progress'
        ) {
            throw new PaymentProcessorException(E::ts('This recurring contribution cannot be managed.'));
        }
        return $schedule;
    }

    /** @param array<string, mixed> $schedule */
    protected function processor(array $schedule): CRM_Core_Payment_Sumup
    {
        $processor = System::singleton()->getById((int) $schedule['payment_processor_id']);
        if (!$processor instanceof CRM_Core_Payment_Sumup) {
            throw new PaymentProcessorException(E::ts('This recurring contribution does not use SumUp.'));
        }
        return $processor;
    }
}
