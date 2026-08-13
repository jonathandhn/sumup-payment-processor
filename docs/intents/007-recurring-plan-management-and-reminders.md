# Intent 007: recurring plan management and reminders

## Outcome

A contributor or an authorised administrator can stop a SumUp recurring
contribution or change its amount through CiviCRM's native recurring-payment
forms. The new amount applies to the next occurrence which has not already
been created. Changing frequency remains outside this first iteration.

CiviCRM's scheduled-reminder engine can target SumUp recurring contributions,
including schedules which require customer action. The extension installs
editable email and SMS templates plus two disabled reminder workflows. An
administrator explicitly enables the useful workflows instead of rebuilding
their SumUp criteria from scratch.

## Ownership

- `ContributionRecur` is the schedule and business source of truth.
- SumUp stores the reusable payment instrument but has no remote subscription
  object to edit or cancel.
- Stopping a plan therefore prevents future CiviCRM-created charges and does
  not call a SumUp cancellation endpoint.
- Payments already collected are neither cancelled nor refunded.
- A Pending contribution or SumUp checkout already created for an occurrence
  keeps its original amount. A plan edit never mutates an in-flight payment.

## Native CiviCRM contracts

- The processor declares `cancelRecurring` and implements
  `doCancelRecurring()` as a local schedule cancellation guard.
- The processor exposes only `amount` through
  `getEditableRecurringScheduleFields()`.
- `changeSubscriptionAmount()` validates processor ownership, active status
  and amount, then lets CiviCRM persist the schedule and template contribution.
- CiviCRM's native cancellation and recurring-edit forms remain responsible
  for status changes, activities and the existing
  `contribution_recurring_cancelled` and `contribution_recurring_edit`
  workflow messages.
- The SumUp payment-method Afform links to those native forms. It does not
  introduce private plan-management endpoints.
- CiviCRM's native "change billing details" action links to the SumUp card
  replacement Afform, so the feature is discoverable from the user dashboard.
- The public view shows direct, checksum-authorised card, amount-change and
  cancellation actions. The contact-summary administration view instead shows
  a direct stop action plus explicit buttons to email a temporary card-change
  or plan-change link to the contact.

## Scheduled reminders

The extension registers a SumUp recurring-contribution action mapping with:

- SumUp payment processor as the administrator-selectable scope;
- native recurring-contribution statuses;
- start, creation, next scheduled contribution, retry, cancellation and end
  dates;
- the creation date of an unresolved SumUp remediation as the
  "customer action required since" trigger.

The mapping uses CiviCRM's existing action log and delivery engine. Email and
SMS are therefore handled by CiviCRM, including contact communication flags,
configured SMS providers and replay protection. The extension does not send
SMS directly.

Two native reminders are installed in the disabled state:

- an upcoming-payment notice three days before the next scheduled payment;
- an action-required notice on the remediation date, repeated every three
  days for at most nine days while the remediation remains unresolved.

Both reminders include editable email and SMS workflow templates, paired with
reserved extension defaults. They appear under CiviCRM's workflow-message
templates and can be restored with the native "Revert to Default" action.
Their native `is_active` toggle is the administrator's on/off control.
CiviCRM's existing workflow messages remain responsible for immediate
amount-change and cancellation notifications.

## Safety and concurrency

- Cancellation refuses to run while the recurring-card job is processing.
- The recurring job remains authoritative for one deterministic occurrence at
  a time and reuses its existing checkout registry and locks.
- Amount changes require an active SumUp schedule and a positive amount using
  the schedule currency precision.
- Test and live processors remain isolated.
- Self-service URLs use CiviCRM's native contact authorisation and checksum
  handling.

## Acceptance

- The contact payment-method page offers native actions to change the amount,
  stop future payments and replace the saved card.
- The schedule summary shows its start date, next payment, and its end date or
  installment count when either limit exists.
- Changing the amount updates `ContributionRecur` and its template line items,
  while an already-created occurrence remains unchanged.
- Cancelling changes the schedule to Cancelled and prevents a later job run
  from creating another charge.
- Native workflow email can be sent for an amount change or cancellation.
- Scheduled Reminders offers "SumUp recurring contribution" with email and SMS
  channels and can target the next payment or an unresolved remediation date.
- The two supplied reminder workflows are present but disabled after
  installation or upgrade, and can be independently enabled.
- Each supplied message can be edited and restored to the extension default
  from CiviCRM's workflow-message template screen.
- Each supplied workflow has preview example data for a representative SumUp
  recurring contribution.
- PHPCS and PHPStan level 8 remain green.
