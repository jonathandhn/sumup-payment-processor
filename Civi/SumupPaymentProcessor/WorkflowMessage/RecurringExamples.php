<?php

declare(strict_types=1);

namespace Civi\SumupPaymentProcessor\WorkflowMessage;

use Civi\Test;
use Civi\Test\ExampleDataInterface;
use Civi\WorkflowMessage\WorkflowMessage;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

final class RecurringExamples implements ExampleDataInterface
{
    /** @var array<string, string> */
    private const WORKFLOWS = [
        UpcomingRecurringPayment::WORKFLOW => 'Upcoming recurring payment',
        UpcomingRecurringPaymentSms::WORKFLOW => 'Upcoming recurring payment — SMS',
        PaymentMethodActionRequired::WORKFLOW => 'Payment method action required',
        PaymentMethodActionRequiredSms::WORKFLOW => 'Payment method action required — SMS',
        CardChangeInvitation::WORKFLOW => 'Change saved card invitation',
        PlanChangeInvitation::WORKFLOW => 'Adapt recurring plan invitation',
    ];

    /** @return iterable<int, array<string, mixed>> */
    public function getExamples(): iterable
    {
        foreach (self::WORKFLOWS as $workflow => $title) {
            yield [
                'name' => 'workflow/' . $workflow . '/sumup_recurring_contribution',
                'title' => E::ts('SumUp — %1', [1 => E::ts($title)]),
                'tags' => ['preview'],
                'workflow' => $workflow,
            ];
        }
    }

    /** @param array<string, mixed> $example */
    public function build(array &$example): void
    {
        $workflow = (string) $example['workflow'];
        $message = WorkflowMessage::create($workflow);
        $message
            ->setReceiptFromEmail('info@example.org')
            ->setContact(Test::example('entity/Contact/Barb'))
            ->setContributionRecur(Test::example('entity/ContributionRecur/Euro5990/pending'));
        if ($message instanceof ManagementInvitation) {
            $message->setManagementUrl('https://example.org/manage-recurring-payment');
        }

        $example['data'] = [
            'workflow' => $workflow,
            'modelProps' => $message->export('modelProps'),
        ];
    }
}
