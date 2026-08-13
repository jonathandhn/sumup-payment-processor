<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

$manageUrl = <<<'SMARTY'
{crmURL p='civicrm/sumup/payment-methods' q="cid={contact.id}&{contact.checksum}" a=true h=0 fe=1}
SMARTY;

$upcomingSubject = '{ts}Your next recurring payment is approaching{/ts}';
$upcomingText = <<<'SMARTY'
{contact.email_greeting_display},

{ts}Your next recurring payment is scheduled soon.{/ts}
{ts}Amount:{/ts} {contribution_recur.amount|crmMoney}
{ts}Scheduled date:{/ts} {contribution_recur.next_sched_contribution_date|crmDate}

{ts}Manage this recurring payment or its saved card:{/ts}
SMARTY;
$upcomingText .= "\n" . $manageUrl;
$upcomingHtml = <<<'SMARTY'
<p>{contact.email_greeting_display},</p>
<p>{ts}Your next recurring payment is scheduled soon.{/ts}</p>
<ul>
  <li><strong>{ts}Amount:{/ts}</strong> {contribution_recur.amount|crmMoney}</li>
  <li><strong>{ts}Scheduled date:{/ts}</strong> {contribution_recur.next_sched_contribution_date|crmDate}</li>
</ul>
<p><a href="
SMARTY;
$upcomingHtml .= $manageUrl . '">{ts}Manage this recurring payment{/ts}</a></p>';
$upcomingSms = '{ts}Upcoming recurring payment:{/ts} '
    . '{contribution_recur.amount|crmMoney}, '
    . '{contribution_recur.next_sched_contribution_date|crmDate}. ' . $manageUrl;

$actionSubject = '{ts}Action required for your recurring payment{/ts}';
$actionText = <<<'SMARTY'
{contact.email_greeting_display},

{ts}Your saved payment card needs attention before future recurring payments can continue.{/ts}

{ts}Review or replace the card:{/ts}
SMARTY;
$actionText .= "\n" . $manageUrl;
$actionHtml = <<<'SMARTY'
<p>{contact.email_greeting_display},</p>
<p>{ts}Your saved payment card needs attention before future recurring payments can continue.{/ts}</p>
<p><a href="
SMARTY;
$actionHtml .= $manageUrl . '">{ts}Review or replace the card{/ts}</a></p>';
$actionSms = '{ts}Action required for your recurring payment. Review or replace the saved card:{/ts} '
    . $manageUrl;

$cardInvitationSubject = '{ts}Update the card used for your recurring payment{/ts}';
$cardInvitationText = <<<'SMARTY'
{contact.email_greeting_display},

{ts}An administrator has invited you to securely replace the card used for your recurring payment.{/ts}

{ts}Replace the saved card:{/ts}
{$management_url}
SMARTY;
$cardInvitationHtml = <<<'SMARTY'
<p>{contact.email_greeting_display},</p>
<p>{ts}An administrator has invited you to securely replace the card used for your recurring payment.{/ts}</p>
<p><a href="{$management_url}">{ts}Replace the saved card{/ts}</a></p>
SMARTY;

$planInvitationSubject = '{ts}Review the amount of your recurring payment{/ts}';
$planInvitationText = <<<'SMARTY'
{contact.email_greeting_display},

{ts}An administrator has invited you to review the amount of your recurring payment.{/ts}

{ts}Adapt the recurring payment:{/ts}
{$management_url}
SMARTY;
$planInvitationHtml = <<<'SMARTY'
<p>{contact.email_greeting_display},</p>
<p>{ts}An administrator has invited you to review the amount of your recurring payment.{/ts}</p>
<p><a href="{$management_url}">{ts}Adapt the recurring payment{/ts}</a></p>
SMARTY;

$inProgressStatus = CRM_Core_PseudoConstant::getKey(
    'CRM_Contribute_BAO_ContributionRecur',
    'contribution_status_id',
    'In Progress'
);

$templates = [
    'upcoming_email' => [
        'workflow_name' => 'sumup_upcoming_recurring_payment',
        'title' => E::ts('SumUp — Upcoming recurring payment'),
        'subject' => $upcomingSubject,
        'text' => $upcomingText,
        'html' => $upcomingHtml,
        'is_sms' => false,
    ],
    'upcoming_sms' => [
        'workflow_name' => 'sumup_upcoming_recurring_payment_sms',
        'title' => E::ts('SumUp — Upcoming recurring payment — SMS'),
        'subject' => '',
        'text' => $upcomingSms,
        'html' => '',
        'is_sms' => true,
    ],
    'action_email' => [
        'workflow_name' => 'sumup_payment_method_action_required',
        'title' => E::ts('SumUp — Payment method action required'),
        'subject' => $actionSubject,
        'text' => $actionText,
        'html' => $actionHtml,
        'is_sms' => false,
    ],
    'action_sms' => [
        'workflow_name' => 'sumup_payment_method_action_required_sms',
        'title' => E::ts('SumUp — Payment method action required — SMS'),
        'subject' => '',
        'text' => $actionSms,
        'html' => '',
        'is_sms' => true,
    ],
    'card_invitation' => [
        'workflow_name' => 'sumup_card_change_invitation',
        'title' => E::ts('SumUp — Change saved card invitation'),
        'subject' => $cardInvitationSubject,
        'text' => $cardInvitationText,
        'html' => $cardInvitationHtml,
        'is_sms' => false,
    ],
    'plan_invitation' => [
        'workflow_name' => 'sumup_plan_change_invitation',
        'title' => E::ts('SumUp — Adapt recurring plan invitation'),
        'subject' => $planInvitationSubject,
        'text' => $planInvitationText,
        'html' => $planInvitationHtml,
        'is_sms' => false,
    ],
];

$managed = [];
foreach ($templates as $name => $template) {
    $managed[] = [
        'name' => 'SumupRecurringTemplate_' . $name,
        'entity' => 'MessageTemplate',
        'cleanup' => 'unused',
        'update' => 'unmodified',
        'params' => [
            'version' => 4,
            'checkPermissions' => false,
            'match' => ['workflow_name', 'is_reserved'],
            'values' => [
                'msg_title' => $template['title'],
                'msg_subject' => $template['subject'],
                'msg_text' => $template['text'],
                'msg_html' => $template['html'],
                'is_default' => true,
                'is_active' => true,
                'is_reserved' => false,
                'is_sms' => $template['is_sms'],
                'workflow_name' => $template['workflow_name'],
            ],
        ],
    ];

    $managed[] = [
        'name' => 'SumupRecurringTemplate_' . $name . '_reserved',
        'entity' => 'MessageTemplate',
        'cleanup' => 'unused',
        'update' => 'always',
        'params' => [
            'version' => 4,
            'checkPermissions' => false,
            'match' => ['workflow_name', 'is_reserved'],
            'values' => [
                'msg_title' => E::ts('%1 (system default)', [1 => $template['title']]),
                'msg_subject' => $template['subject'],
                'msg_text' => $template['text'],
                'msg_html' => $template['html'],
                'is_default' => false,
                'is_active' => true,
                'is_reserved' => true,
                'is_sms' => $template['is_sms'],
                'workflow_name' => $template['workflow_name'],
            ],
        ],
    ];
}

$managed[] = [
    'name' => 'SumupRecurringReminder_upcoming',
    'entity' => 'ActionSchedule',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
        'version' => 4,
        'checkPermissions' => false,
        'match' => ['name'],
        'values' => [
            'name' => 'sumup_upcoming_recurring_payment',
            'title' => E::ts('SumUp — Upcoming recurring payment'),
            'mapping_id' => 'sumup_contribution_recur',
            'entity_value' => [],
            'entity_status' => [$inProgressStatus],
            'start_action_offset' => 3,
            'start_action_unit' => 'day',
            'start_action_condition' => 'before',
            'start_action_date' => 'next_sched_contribution_date',
            'is_repeat' => false,
            'is_active' => false,
            'record_activity' => true,
            'mode' => 'Email',
            'msg_template_id.msg_title' => $templates['upcoming_email']['title'],
            'sms_template_id.msg_title' => $templates['upcoming_sms']['title'],
            'subject' => $upcomingSubject,
            'body_text' => $upcomingText,
            'body_html' => $upcomingHtml,
            'sms_body_text' => $upcomingSms,
        ],
    ],
];

$managed[] = [
    'name' => 'SumupRecurringReminder_action_required',
    'entity' => 'ActionSchedule',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
        'version' => 4,
        'checkPermissions' => false,
        'match' => ['name'],
        'values' => [
            'name' => 'sumup_payment_method_action_required',
            'title' => E::ts('SumUp — Payment method action required'),
            'mapping_id' => 'sumup_contribution_recur',
            'entity_value' => [],
            'entity_status' => [$inProgressStatus],
            'start_action_offset' => 0,
            'start_action_unit' => 'day',
            'start_action_condition' => 'after',
            'start_action_date' => 'sumup_remediation_created_date',
            'is_repeat' => true,
            'repetition_frequency_interval' => 3,
            'repetition_frequency_unit' => 'day',
            'end_frequency_interval' => 9,
            'end_frequency_unit' => 'day',
            'end_action' => 'after',
            'end_date' => 'sumup_remediation_created_date',
            'is_active' => false,
            'record_activity' => true,
            'mode' => 'Email',
            'msg_template_id.msg_title' => $templates['action_email']['title'],
            'sms_template_id.msg_title' => $templates['action_sms']['title'],
            'subject' => $actionSubject,
            'body_text' => $actionText,
            'body_html' => $actionHtml,
            'sms_body_text' => $actionSms,
        ],
    ],
];

return $managed;
