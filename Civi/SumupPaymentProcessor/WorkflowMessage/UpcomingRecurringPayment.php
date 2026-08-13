<?php

declare(strict_types=1);

namespace Civi\SumupPaymentProcessor\WorkflowMessage;

final class UpcomingRecurringPayment extends \CRM_Contribute_WorkflowMessage_RecurringEdit
{
    public const WORKFLOW = 'sumup_upcoming_recurring_payment';
}
