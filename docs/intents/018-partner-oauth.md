# SumUp partner OAuth

## User outcome

A CiviCRM administrator connects each SumUp payment processor to a SumUp
merchant account through the SumUp authorization screen in one click. The
processor no longer accepts or stores a personal API key, and CiviCRM
installations never store or handle the partner OAuth client secret. The same
delegated connection is used for online checkout, recurring cards, refunds and
Solo Cloud API calls.

## Product contract

- Live CiviCRM payment processors (`is_test = 0`) authenticate exclusively
  through the SumUp OAuth authorization code flow. Because the SumUp OAuth
  consent screen only authenticates live merchant profiles, test CiviCRM
  payment processors (`is_test = 1`) use the sandbox merchant code
  (`test_user_name`) and sandbox API key (`test_password`) fields so test mode
  never connects a live merchant account.
- Authorization and token exchange/refresh requests are brokered through the
  stateless partner OAuth relay (`https://sumup-auth.cou.re`), which holds the
  confidential SumUp OAuth client ID, client secret and registered redirect URI
  (`https://sumup-auth.cou.re/callback`) server-side.
- If a CiviCRM installation explicitly configures local `sumup_oauth_client_id`
  and `sumup_oauth_client_secret` settings, it may talk directly to SumUp's
  OAuth endpoints; otherwise it uses the partner OAuth relay by default with
  zero local credential setup.
- Payment checkouts, card tokenization, refunds, Solo Cloud API calls and
  webhooks go directly between CiviCRM and `https://api.sumup.com` using the
  merchant-scoped OAuth access token stored by CiviCRM.
- The SumUp affiliate identity remains part of the extension for Solo Cloud API
  reader checkouts.
- Card-present Solo Cloud API checkout requests include the embedded Affiliate
  application ID, Affiliate Key and one unique foreign transaction ID. Online
  checkout requests do not use the Affiliate Key.
- Access and refresh tokens are stored per CiviCRM payment processor.
- The callback validates a single-use state value in CiviCRM (in addition to
  the HMAC-SHA256 state verification performed by the partner relay) before
  exchanging the code.
- The authenticated merchant code is read from SumUp and stored on the payment
  processor. It is never entered manually or inferred locally.
- CiviCRM's test flag does not select a SumUp tenant. The OAuth connection uses
  the merchant returned by SumUp, so an administrator must verify the displayed
  merchant code before initiating any payment.
- Access tokens are refreshed before expiry under a processor-scoped lock.
- A rejected or expired refresh grant marks the processor as requiring a new
  connection; it never falls back to an API key.
- Disconnecting removes the delegated tokens. Existing CiviCRM accounting
  records, checkouts, readers and payment tokens remain untouched.
- The public merchant key remains an independent processor field because the
  browser wallet integration still requires it.

## Administration flow

1. Save the SumUp processor to obtain its CiviCRM processor ID.
2. Open the SumUp connection panel and click **Connect to SumUp**.
3. The administrator is redirected through `https://sumup-auth.cou.re/authorize`
   to SumUp, authorizes the merchant account, and returns through
   `https://sumup-auth.cou.re/callback` to the CiviCRM callback URL.
4. CiviCRM verifies the single-use OAuth state, exchanges the authorization
   code via `https://sumup-auth.cou.re/token`, retrieves the merchant identity
   from SumUp, persists the tokens and merchant code, and clears any former API
   key value.

The connection page displays the merchant identity, connection date,
access-token status and reconnect/disconnect actions. It never exposes client
secrets, affiliate keys or token values.

## Failure behaviour

- Missing, reused or expired state blocks the callback.
- Missing required scopes or an ambiguous merchant identity blocks activation.
- Token and provider errors are reported without provider payloads or secrets.
- Payment attempts fail locally when the processor is not connected or must be
  reconnected.
