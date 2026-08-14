# Recurring card reuse, bulk replacement and plan policy

## Outcome

Complete the SumUp recurring-card lifecycle without creating a second payment
engine:

- a payer may reuse an existing active SumUp card when creating a new recurring
  contribution;
- a replacement starts with the current recurring contribution selected and
  may also update other active recurring contributions explicitly selected by
  the payer;
- the member interface states how many active recurring payments use each
  saved authorisation;
- an optional strict policy prevents a contact from opening a second active
  SumUp recurring plan in the same test or live environment;
- the recurring-card collection job is installed active by default.

`ContributionRecur` remains the schedule and business source of truth. The
managed scheduled job is only an API3-compatible CiviCRM scheduler adapter;
all collection logic remains in `SumupRecurringCard.run` API4.

## Reusing a saved card for a new recurring contribution

The recurring Widget checkout lists the contact's active SumUp tokens in
addition to allowing a new card to be authorised. Choosing an existing card:

1. validates the browser action signature and the local checkout registry;
2. validates that the token belongs to the contribution contact, processor and
   SumUp customer;
3. retrieves the payment instrument from SumUp and requires it to remain
   active;
4. attaches the existing `PaymentToken` to `ContributionRecur`;
5. creates the same separate initial payment checkout used after a new-card
   setup and verifies it server-side before completing the contribution.

The unused provider setup checkout may expire at SumUp, but its local registry
is marked as using an existing token. Repeating the browser action recovers the
same deterministic initial charge and cannot create a second debit.

## Replacing one or several schedules

The replacement page always selects the schedule named by `recur_id`. Other
active SumUp schedules owned by the same contact and using the same processor
are offered as unchecked boxes. This preserves one-schedule replacement as the
default behaviour.

The selected schedule IDs are validated and persisted server-side through
their remediation records before the Widget is mounted. The browser cannot add
a schedule during completion. One verified replacement token is assigned to
all selected schedules inside one database transaction. Each former token is
deactivated only after no active schedule references it.

The member card list keeps provider tokens distinct and displays the exact
number of active recurring payments using each authorisation. Brand and last
four digits remain display metadata only.

## Optional strict single-plan policy

`sumup_single_active_recurring_plan` defaults to disabled. When enabled, a new
recurring checkout is refused if the same contact already has another active
SumUp `ContributionRecur` in the same test or live environment. The current
schedule is excluded from the lookup.

The error contains the CMS-agnostic CiviCRM recurring-payment management URL.
It does not generate a public contact checksum from an anonymous form and does
not send an unsolicited email. A logged-in member can manage the plan directly;
an administrator can continue using the existing workflow-message action to
send a checksum-protected card-change or plan-change link.

When an embedded QuickForm submission is rejected by this policy, the browser
must display CiviCRM's payment-processor error, including the management URL.
The AJAX bridge must not replace a known business error with a generic secure
payment form initialization error. It accepts both CiviCRM JSON messages and an
HTML error response returned after a core redirect.

## Scheduled job

The extension installs one active daily CiviCRM scheduled job. The Job action
is a compatibility adapter which invokes `SumupRecurringCard.run` with
permissions disabled and returns its structured result. The API4 action keeps
its existing global lock, bounded batch, stale-date guard and deterministic
occurrence logic.

## Acceptance

- Installing or upgrading the extension creates an active daily recurring-card
  job.
- A recurring checkout shows active saved cards and a new-card Widget.
- Selecting a saved card creates one initial payment and leaves the schedule
  operational with that token.
- Repeating that action does not create another payment.
- The replacement page starts with one schedule selected and offers other
  eligible schedules as unchecked boxes.
- Completing replacement switches exactly the stored selection and safely
  retains any old token still used elsewhere.
- Enabling the strict policy blocks a second active plan without exposing a
  public checksum or contact data.
- QuickForm displays the strict-policy explanation and management URL instead
  of a generic payment-form initialization error.
- PHPCS and PHPStan level 8 pass.
