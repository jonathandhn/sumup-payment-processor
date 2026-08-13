<?php

declare(strict_types=1);

namespace Civi\SumupPaymentProcessor\WorkflowMessage;

final class PlanChangeInvitation extends ManagementInvitation
{
    public const WORKFLOW = 'sumup_plan_change_invitation';
}
