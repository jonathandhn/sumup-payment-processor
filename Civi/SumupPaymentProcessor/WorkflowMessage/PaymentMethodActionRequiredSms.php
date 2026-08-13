<?php

declare(strict_types=1);

namespace Civi\SumupPaymentProcessor\WorkflowMessage;

final class PaymentMethodActionRequiredSms extends \CRM_Contribute_WorkflowMessage_RecurringEdit
{
    public const WORKFLOW = 'sumup_payment_method_action_required_sms';
}
