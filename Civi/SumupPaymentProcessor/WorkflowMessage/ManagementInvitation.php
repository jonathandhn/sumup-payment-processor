<?php

declare(strict_types=1);

namespace Civi\SumupPaymentProcessor\WorkflowMessage;

abstract class ManagementInvitation extends \CRM_Contribute_WorkflowMessage_RecurringEdit
{
    /**
     * Secure self-service URL supplied for this message only.
     *
     * @var string
     * @scope tplParams as management_url
     * @required
     */
    public string $managementUrl = '';

    public function setManagementUrl(string $managementUrl): self
    {
        $this->managementUrl = $managementUrl;
        return $this;
    }
}
