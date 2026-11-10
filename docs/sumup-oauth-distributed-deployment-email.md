Subject: SumUp OAuth partner model for distributed CiviCRM installations

Hi Salman,

I believe the remaining issue is an internal understanding gap about the deployment model. The teams consulted so far appear to be treating the CiviCRM integration as a centrally hosted multi-tenant service. It is instead one open-source PHP extension deployed across multiple independently operated backends.

Each organisation runs its own CiviCRM installation, PHP extension and database on its own domain. Its server performs the authorization-code exchange and token refresh directly with SumUp. Its callback is hosted on that organisation's domain, and its OAuth tokens remain in that organisation's local database. There is no central CiviCRM backend or OAuth token broker operated by me.

There are also two separate credential-provisioning models, as illustrated by the existing HelloAsso integration:

1. Non-partner mode: the organisation obtains and configures its own API or OAuth credentials.
2. Partner mode: the partner provisions the credentials and supplies them to the organisation, but the OAuth runtime, callback and tokens still remain on the organisation's self-hosted CiviCRM backend.

For the SumUp extension release, I am prepared to use the second model. I would keep one partner-owned SumUp OAuth application and create a separate client credential set for each CiviCRM installation and its exact callback URL. I would then provide those credentials to the relevant organisation for local server-side configuration.

This means that credential provisioning is partner-managed, while authorization and API execution remain decentralised. Providing the credentials does not make my infrastructure the runtime provider.

The current SumUp OAuth documentation appears to support this model. It states that an OAuth application may create as many client credentials as needed, that exact redirect URIs must be registered, and that multiple redirect URIs may be configured.

Reference: https://developer.sumup.com/tools/authorization/oauth

The blocking point is therefore not the OAuth sequence implemented in the extension. The blocking point is that the SumUp teams consulted so far do not appear to recognise or know how to qualify this combination of partner-managed credential provisioning and independently managed customer backends.

Could you please forward this to the team responsible for OAuth application and partner credential provisioning, and obtain confirmation of the following operational points?

1. May the CiviCRM partner application create a distinct client credential set for every independently hosted customer installation without a practical client-count limit?
2. Do the restricted scopes approved for the partner application, particularly `payments` and `payment_instruments`, automatically apply to every client credential set created under that application?
3. Is it an accepted partner deployment model for me to provide each customer with its own client ID and client secret for storage in its self-hosted PHP backend?
4. Will transactions initiated by those installations remain attributed to my partner integration when the Affiliate Key is sent on the eligible card-present requests?

The attached diagrams show the local OAuth sequence and the difference between the centrally hosted architecture currently being assumed and the actual distributed CiviCRM topology. The key provisioning boundary is the only centrally managed part; customer authorization, token storage, refresh and payment API calls are direct between each CiviCRM server and SumUp.

Once you confirm these points, I will document the provisioning procedure and publish the integration using this per-installation credential model.

Best regards,

Jonathan Dahan
