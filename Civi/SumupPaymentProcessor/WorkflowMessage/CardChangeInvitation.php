<?php

declare(strict_types=1);

namespace Civi\SumupPaymentProcessor\WorkflowMessage;

final class CardChangeInvitation extends ManagementInvitation
{
    public const WORKFLOW = 'sumup_card_change_invitation';
}
