<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupTerminalCheckout;

use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\PaymentProcessor;
use Civi\Checkout\CheckoutSession;
use CRM_SumupPaymentProcessor_CheckoutMode;
use CRM_SumupPaymentProcessor_CheckoutService;
use CRM_SumupPaymentProcessor_CheckoutStore;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

/**
 * Start a new reader checkout for an authenticated pending CheckoutSession.
 *
 * @method $this setToken(string $token)
 */
final class Retry extends AbstractAction
{
    protected string $token = '';

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        if ($this->token === '') {
            throw new \CRM_Core_Exception(E::ts('The secure checkout token is missing.'));
        }

        $session = CheckoutSession::restoreFromToken($this->token);
        $contributionId = $session->getContributionId();
        $localReaderId = (int) $session->getCheckoutParam('sumup_reader_id');
        $previousCheckoutId = (string) $session->getCheckoutParam('sumup_reader_checkout_id');
        if (
            $localReaderId <= 0
            || !CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($previousCheckoutId)
        ) {
            throw new \CRM_Core_Exception(E::ts('This checkout is not linked to a SumUp card reader.'));
        }

        $checkout = CRM_SumupPaymentProcessor_CheckoutStore::getByCheckoutId($previousCheckoutId);
        if (
            (int) $checkout['contribution_id'] !== $contributionId
            || $checkout['checkout_mode'] !== CRM_SumupPaymentProcessor_CheckoutMode::SOLO
        ) {
            throw new \CRM_Core_Exception(E::ts('The SumUp terminal checkout does not match this contribution.'));
        }

        $contribution = Contribution::get(false)
            ->addSelect('contribution_status_id:name', 'is_test')
            ->addWhere('id', '=', $contributionId)
            ->execute()
            ->single();
        if (!in_array(($contribution['contribution_status_id:name'] ?? ''), ['Pending', 'Failed'], true)) {
            throw new \CRM_Core_Exception(E::ts('Only an unpaid contribution can be resent to a terminal.'));
        }

        $connection = PaymentProcessor::get(false)
            ->addSelect('id', 'name', 'is_test', 'class_name')
            ->addWhere('id', '=', (int) $checkout['payment_processor_id'])
            ->execute()
            ->single();
        if (
            ($connection['class_name'] ?? '') !== 'Payment_Sumup'
            || (bool) ($connection['is_test'] ?? false) !== (bool) ($contribution['is_test'] ?? false)
        ) {
            throw new \CRM_Core_Exception(E::ts('The SumUp payment processor does not match this checkout.'));
        }

        $processor = \Civi\Payment\System::singleton()->getByName(
            (string) $connection['name'],
            (bool) $connection['is_test']
        );
        if (!$processor instanceof \CRM_Core_Payment_Sumup) {
            throw new \CRM_Core_Exception(E::ts('Unable to load the SumUp payment processor.'));
        }

        $lock = \CRM_Core_Lock::createScopedLock('data.sumup.reader.retry.' . $contributionId);
        if (!$lock->acquire()) {
            throw new \CRM_Core_Exception(E::ts('This terminal payment is already being resent.'));
        }
        try {
            $checkoutId = $processor->startSoloCheckoutForContribution($contributionId, $localReaderId);
        } finally {
            $lock->release();
        }
        $session->pending();
        $session->setCheckoutParam('sumup_reader_checkout_id', $checkoutId);
        $session->setCheckoutParam('sumup_reader_attempt_status', 'PENDING');
        $session->setCheckoutParam('sumup_reader_status_error_logged', false);

        $result[] = [
            'token' => $session->tokenise(),
            'client_transaction_id' => $checkoutId,
        ];
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore
}
