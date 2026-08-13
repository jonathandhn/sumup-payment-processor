# Security and payment-management risk model

This document records the security boundaries of the current SumUp integration
and the planned controls for higher-risk self-service and administration
actions. It is a product risk model, not a PCI DSS attestation.

## Assets and trust boundaries

- CiviCRM owns contacts, contributions, recurring schedules, permissions and
  the decision to create a future charge.
- SumUp owns card capture, cardholder authentication, the reusable card token,
  payment execution and the authoritative transaction state.
- CiviCRM stores only the SumUp customer, checkout, transaction and reusable
  payment-method identifiers plus non-sensitive display data such as the card
  brand, last four digits and expiry date. The extension must never receive,
  log or store the PAN or CVC.
- Browser callbacks and webhooks are signals. A payment or replacement is
  accepted only after a server-side SumUp API read confirms its state and
  ownership.
- A CiviCRM checksum URL is a bearer credential. Anyone possessing a valid
  management URL can exercise the actions that URL exposes for the associated
  contact and recurring schedule until the checksum expires.

## Current threat scenarios

### Compromised CiviCRM administrator

An attacker with the relevant CiviCRM contribution permissions may:

- stop a recurring schedule, preventing future CiviCRM-created charges;
- change the amount of future occurrences;
- send card-change or plan-change links to the contact address currently
  stored in CiviCRM;
- request an online refund through the CiviCRM refund workflow when refunds
  are enabled and the SumUp credential has the required scope;
- alter CiviCRM financial records or payment-processor configuration if the
  compromised account also has the corresponding wider permissions.

Stopping a schedule does not cancel or refund a payment already collected.
The current integration accepts only a full online refund and verifies the
SumUp merchant, transaction state, currency and remaining refundable balance
before sending it.

The extension does not expose an administrator action that deletes a stored
card from SumUp. Card replacement atomically assigns the new verified token to
the recurring schedule and only deactivates an old local CiviCRM token when it
is no longer used. Direct database, filesystem or SumUp-account compromise is
outside this UI boundary and has a substantially larger impact.

Current mitigating controls:

- CiviCRM permission checks for contribution administration;
- schedule ownership and active-status checks;
- processor and test/live isolation;
- locks around cancellation, recurring collection and refunds;
- provider-state verification and idempotent transaction references;
- full-refund amount enforcement;
- management-link sends recorded in the CiviCRM log.

Residual risk:

- a valid administrator session is currently sufficient for destructive
  actions allowed by CiviCRM; there is no extension-specific step-up
  authentication;
- an administrator able to change a contact email could then send a management
  link to the newly substituted address;
- logs provide investigation evidence but do not prevent the action.

### Compromised contact account, mailbox or management link

An attacker acting as the contact, or holding a valid CiviCRM checksum link,
may for an owned active SumUp recurring schedule:

- view masked card and schedule information;
- replace the card used for future charges through a new SumUp-hosted card
  capture and verification;
- change the amount of future occurrences;
- stop future payments.

The attacker cannot use these self-service actions to:

- retrieve the PAN or CVC;
- manage another contact's schedule;
- refund a collected payment;
- delete the remote SumUp card directly;
- change a payment already created or in flight.

Amount reduction and cancellation mainly create denial-of-service and revenue
loss. An amount increase can create an unauthorised future charge and therefore
has a higher customer-harm and dispute risk. Card replacement can disrupt the
legitimate schedule even though the replacement card must be entered and
verified through SumUp.

Current mitigating controls:

- an authenticated CiviCRM contact or a valid checksum is required;
- the recurring schedule must belong to that contact and remain active;
- replacement completion verifies the SumUp checkout, customer and reusable
  token before switching the schedule;
- an occurrence already created keeps its original amount;
- no sensitive card data is returned to CiviCRM.

Residual risk:

- possession of the CiviCRM account, mailbox or checksum link is currently the
  only customer authentication required by the extension;
- the current flow does not check whether the destination email or telephone
  number was changed shortly before the action;
- there is no risk-based distinction yet between cancellation, reduction,
  increase and card replacement.

### Compromised payment page or CMS

The Card Widget keeps raw card data out of CiviCRM, but the merchant page still
controls where the SumUp SDK is loaded and where the customer believes they are
paying. A compromised CMS, extension, theme or injected browser script could
replace the checkout UI, alter surrounding instructions or redirect the
customer to a fraudulent page.

Current mitigating controls:

- HTTPS is required for production Widget and return flows;
- checkouts are created server-side, so the API credential, amount and merchant
  are not supplied by browser input alone;
- successful browser callbacks are verified against SumUp server-side;
- the SumUp SDK and card capture remain provider-controlled.

Operational controls still expected from the site operator include timely
security updates, least-privilege administration, MFA where available, content
security policy management, dependency review, file-integrity monitoring,
backups and incident logging.

## PCI DSS boundary

The integration uses SumUp Hosted Checkout or the SumUp Payment Widget so that
raw account data is not stored or transmitted by CiviCRM. SumUp describes the
Widget as handling card-data PCI and PSD2 requirements, but this statement does
not by itself determine the merchant's complete PCI DSS validation scope or
SAQ eligibility.

For an embedded payment form, the merchant must still establish that the host
page is not susceptible to script attacks which could affect the e-commerce
system. The applicable SAQ and evidence must be confirmed with the merchant's
acquirer or PCI assessor for the actual deployment. A redirect to SumUp and an
embedded Widget do not necessarily have identical reporting obligations.

The extension must preserve these invariants:

- no PAN, CVC or raw card form is handled by CiviCRM code;
- secrets remain server-side and are never exported to JavaScript;
- only documented SumUp origins are permitted for SDK, API, image and frame
  resources when a CSP is deployed;
- logs and webhook records exclude sensitive authentication and card data;
- frontend dependency and script changes remain reviewable;
- payment success and token replacement always require a server-side provider
  verification.

### Operating an embedded Widget safely

SumUp's control of the card fields reduces the amount of cardholder data
handled by CiviCRM. It does not protect the surrounding merchant page from a
compromised CMS, administrator, theme, extension or third-party script. Sites
using the embedded Widget should therefore maintain the following operational
baseline:

1. serve the entire checkout and all return URLs over HTTPS;
2. keep CiviCRM, the CMS, themes, extensions and server dependencies on
   supported security releases;
3. use named administrator accounts, MFA where available and least-privilege
   permissions; shared administrator accounts are not acceptable;
4. inventory every script loaded on payment pages and remove advertising,
   tag-manager, chat and analytics scripts unless they are justified there;
5. deploy a Content Security Policy restricted to the SumUp origins required
   by the Widget and the site's reviewed assets. Introduce it in report-only
   mode, validate payment, wallet and 3DS flows, then enforce it. Broad `*` or
   unrestricted `unsafe-inline` policies do not provide the intended control;
6. detect unexpected changes to payment-page files, HTTP security headers and
   loaded scripts, and route the findings to a person who can respond;
7. retain auditable records of processor-configuration changes, refunds and
   recurring-plan administration, without recording cardholder data;
8. perform the external vulnerability scans and validation requested by the
   merchant's acquirer, SAQ or assessor.

#### CSP rules required by this extension

A CSP must be merged into the site's existing policy. Do not send a second CSP
header merely to add these hosts: multiple CSP headers are enforced together
and the additional header cannot relax a directive already present in another
policy.

The current extension loads these provider resources:

| Checkout feature | Resource |
| --- | --- |
| Card Widget | `https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js` |
| Wallet buttons | `https://js.sumup.com/swift-checkout/v1/sdk.js` |
| SumUp API calls made by the browser SDKs | `https://*.sumup.com` |
| Widget images and inline icons | `https://static.sumup.com`, `https://api.sumup.com`, `data:` |
| Widget frame | `https://gateway.sumup.com` |

For the **Card Widget without wallets**, the following is a deployable baseline
for a site which otherwise serves its resources from the same origin:

```http
Content-Security-Policy: default-src 'self'; script-src 'self' https://gateway.sumup.com; connect-src 'self' https://gateway.sumup.com https://api.sumup.com https://*.sumup.com; img-src 'self' data: https://static.sumup.com https://api.sumup.com; frame-src 'self' https://gateway.sumup.com; style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'
```

This is a starting policy, not a replacement for the CMS policy. Origins used
by the site's own fonts, images, APIs or CDN must be reviewed and merged into
the corresponding directives. The `style-src 'unsafe-inline'`
exception is needed because the SumUp Widget injects inline styles and CiviCRM
does not currently expose a portable, CMS-agnostic CSP nonce contract that the
extension and `SumUpCard.mount()` can share. It does not permit inline
JavaScript:
`script-src` deliberately has no `unsafe-inline`.

For **Wallet** or **Card Widget and wallets**, add
`https://js.sumup.com` to `script-src`:

```http
script-src 'self' https://gateway.sumup.com https://js.sumup.com;
```

For Apple Pay availability on third-party browsers, SumUp additionally
documents `https://applepay.cdn-apple.com` in both `default-src` and
`script-src`:

```http
default-src 'self' https://applepay.cdn-apple.com;
script-src 'self' https://gateway.sumup.com https://js.sumup.com https://applepay.cdn-apple.com;
```

SumUp does not currently publish an additional Google Pay CSP origin in its
Swift Checkout instructions. Do not add broad Google wildcards pre-emptively;
exercise Google Pay under `Content-Security-Policy-Report-Only`, inspect the
blocked origin and allow only a resource required by the documented flow.

A strict policy would normally replace the style exception with:

```http
style-src 'self' 'nonce-{RANDOM_PER_RESPONSE}';
```

The same unpredictable nonce must be generated for every HTTP response, added
to the CSP header and passed to the SumUp mount configuration. The extension
cannot implement this safely on its own while CiviCRM is not nonce-aware: a
nonce created independently by the extension would not match a policy emitted
earlier by the CMS, reverse proxy or web server. Copying the strict rule alone
will therefore break the Widget.

The supported choices are consequently explicit:

- embedded Widget: retain `style-src 'unsafe-inline'` while keeping
  `script-src` strict and restricted to reviewed origins;
- strict CSP which forbids all inline styles: use Hosted Checkout;
- nonce-based Widget: only after the particular CMS deployment provides a
  single end-to-end nonce mechanism covering the response header, CiviCRM
  resources and SumUp mount call. This is a site-specific integration, not a
  portable feature currently promised by the extension.

Start rollout with the identical policy in
`Content-Security-Policy-Report-Only`, then test at least:

- a successful card payment;
- a 3DS challenge and its return;
- a failed or abandoned payment;
- Apple Pay and Google Pay in every enabled browser;
- QuickForm, Afform and any Webform iframe deployment;
- a saved-card payment and card replacement.

Do not introduce a restrictive `form-action` or `frame-ancestors` directive by
copying an example blindly. A 3DS provider may require navigation or a POST to
a dynamic issuer URL, while `frame-ancestors 'self'` would prevent an intended
cross-origin Webform embedding. Those directives must be designed from the
actual deployment and tested separately.

CSP reporting, file checks and vulnerability scans are complementary signals;
none of them alone proves PCI DSS compliance. The exact validation path remains
a merchant/acquirer decision rather than a capability the extension can
certify.

If the organisation cannot operate this baseline, Hosted Checkout should be
the recommended mode. Redirecting the payer to SumUp reduces the exposure of
the merchant page to payment-form script compromise, although it does not
remove the need to secure CiviCRM accounts, return URLs and payment records.

### Proportionate profile for very small organisations

Small associations and businesses may have no dedicated security staff, SIEM
or continuous monitoring service. The product must not imply that these tools
are mandatory, but it must not silently weaken the payment-page baseline
either.

The minimum practical profile should be:

- Hosted Checkout selected by default when the site's maintenance or script
  inventory cannot be established;
- supported CMS and CiviCRM releases, preferably maintained by a managed host
  or a named service provider with a defined security-update process;
- one or two named administrators, MFA where the platform supports it, a
  password manager and no shared administrator credential;
- a documented list of the scripts permitted on checkout pages;
- backups with a tested restore procedure;
- a lightweight monthly review of administrator, processor, refund and
  recurring-payment events;
- a short incident procedure identifying who disables checkout, revokes API
  credentials, contacts SumUp and reconciles CiviCRM with the provider;
- PCI validation and external scanning at the scope and cadence confirmed by
  the acquirer or assessor.

The embedded Widget may be enabled when a named maintainer can demonstrate a
supported and patched site, a reviewed script inventory, a tested CSP or an
equivalent provider-approved protection, and an actionable alert path. When
this evidence is missing, the extension should warn and recommend Hosted
Checkout rather than claim that the site is compliant or block all payments.

The extension cannot, on behalf of a small organisation:

- issue a PCI DSS attestation or determine the applicable SAQ;
- guarantee that arbitrary CMS extensions and scripts are uncompromised;
- protect against a compromised hosting, root or SumUp administrator account;
- provide 24/7 security monitoring or incident response;
- reconstruct reliable historical email or telephone verification dates when
  the CMS did not record them;
- guarantee delivery of email or SMS challenges.

Future risk controls must retain usable fallbacks. A site without SMS should
be able to use a previously verified email channel or manual review. A recent
change to every available contact channel should disable high-risk
self-service actions temporarily, not make ordinary payments impossible.

PSD2 cardholder authentication and PCI DSS payment-page protection solve
different problems: a successful 3DS challenge does not secure a compromised
CiviCRM administrator session or a modified checkout page.

## Planned risk-based authorisation layer

This is a later product lot. It is not implemented by the current extension.

### Provider-neutral risk context

Introduce a provider-neutral `PaymentActionRiskContext` consumed by SumUp and,
where useful, by other payment extensions such as Monetico. It should describe
the requested action without disclosing unnecessary personal data to the PSP:

- actor: administrator, authenticated contact or checksum bearer;
- action: cancel, decrease amount, increase amount, replace card, refund or
  initiate a new payment;
- value and relative amount change;
- schedule and relationship age;
- whether an occurrence is already in flight;
- test or live mode;
- authentication strength and recent session signals;
- age and verification state of the notification channels;
- CMS integration capabilities and supported-security status;
- optional business context such as donation, membership, event ticket or
  lightweight sale.

The policy output should be explicit and deterministic:

- `allow`;
- `confirm_email`;
- `confirm_sms`;
- `confirm_existing_channel`;
- `manual_review`;
- `block`.

The CMS name alone must never decide the outcome. The adapter should report
capabilities and evidence: supported version, authentication and MFA support,
CSRF/session integration, authenticated-contact mapping, security update
status and audit availability.

Initial adapters to assess:

- CiviCRM Standalone;
- WordPress;
- Drupal 8 and newer;
- Drupal 7;
- Backdrop;
- Joomla.

### Cooling-off period for contact-channel changes

For a high-risk action, an email address or telephone number changed within a
configured period must not immediately become the sole confirmation channel.
The intended policy is:

1. determine the last verified change time for the selected channel;
2. if it is older than the configured cooling-off period, send the step-up
   challenge normally;
3. if it is recent, use a previously verified unchanged channel when one is
   available;
4. otherwise block the action or require manual review;
5. record the decision, challenge and result without recording the secret
   code.

CiviCRM does not provide a reliable modification timestamp for every contact
point in every supported deployment. This feature therefore requires either a
verified-channel ledger owned by the extension or a dependable audit-log
adapter. It must not infer channel age from the contact's general modified date.

Suggested first policy:

- cancellation or amount decrease: confirmation for suspicious sessions, with
  a lower threshold than financial expansion;
- amount increase: step-up through an unchanged verified channel;
- card replacement: step-up before starting the replacement, followed by
  SumUp's own cardholder authentication;
- refund: administrator step-up and explicit audit entry;
- recent channel change with no older verified channel: manual review or
  temporary block.

Thresholds such as hours or days must remain site-configurable and must be
tested against account-recovery and accessibility needs before activation.

## Validation backlog

- document the exact CiviCRM permissions needed for every administrator action;
- test checksum expiry and revocation behaviour on every supported CMS;
- verify that amount increase, decrease and cancellation generate distinct
  audit events;
- add an activity or immutable audit record for management-link sends and
  high-risk decisions;
- test compromised-email and recently-changed-phone scenarios;
- test CSP and injected-script detection for embedded Widget pages;
- reassess nonce support if CiviCRM later provides a portable per-response CSP
  nonce contract usable by extensions;
- confirm the merchant's PCI DSS validation path with SumUp/acquirer guidance;
- threat-model Monetico order/cart context before sharing additional business
  or customer risk information with the bank.

## References

- [SumUp Payment Widget: compliance, HTTPS and CSP](https://developer.sumup.com/online-payments/checkouts/card-widget/)
- [SumUp Swift Checkout SDK: wallet script and setup](https://developer.sumup.com/online-payments/checkouts/swift-checkout/)
- [PCI SSC FAQ 1588: SAQ A eligibility for embedded payment forms](https://www.pcisecuritystandards.org/faqs/1588/)
- [PCI SSC FAQ 1604: external vulnerability scans in SAQ A](https://www.pcisecuritystandards.org/faqs/1604/)
- [PCI SSC payment-page and e-skimming guidance](https://blog.pcisecuritystandards.org/new-information-supplement-payment-page-security-and-preventing-e-skimming)
