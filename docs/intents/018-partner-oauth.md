# SumUp partner OAuth

## User outcome

A CiviCRM administrator connects each SumUp payment processor to a SumUp
merchant account through the SumUp authorization screen. The processor no
longer accepts or stores a personal API key. The same delegated connection is
used for online checkout, recurring cards, refunds and Solo Cloud API calls.

## Product contract

- SumUp OAuth authorization code flow is the only API authentication path.
- Each CiviCRM installation stores its own SumUp OAuth client ID and client
  secret. There is no embedded or community credential fallback.
- The extension displays the callback URL before credentials are configured.
  The administrator sends that exact URL to the partner who creates the OAuth
  client in SumUp.
- The callback URL may contain a CiviCRM language prefix. When credentials are
  first saved, or their client ID is replaced, the exact displayed URL is
  stored with them and reused for authorization and token exchange.
- The SumUp affiliate identity remains part of the extension and is independent
  from the installation-specific OAuth client credentials.
- Card-present Solo Cloud API checkout requests include the embedded Affiliate
  application ID, Affiliate Key and one unique foreign transaction ID. Online
  checkout requests do not use the Affiliate Key.
- Access and refresh tokens are stored per CiviCRM payment processor.
- The callback validates a single-use state value before exchanging the code.
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
2. Open the SumUp connection panel and copy the displayed callback URL.
3. Obtain a SumUp OAuth client created for that exact callback URL.
4. Save the client ID and client secret locally. A blank secret field keeps the
   currently stored secret.
5. Authorize the merchant account at SumUp.
6. Return to the exact registered callback URL.
7. Verify the OAuth state, exchange the code, retrieve the merchant identity,
   persist the tokens and merchant code, and clear any former API key value.

The connection page displays the client ID, whether a client secret is stored,
the exact callback URL, merchant identity, connection date, access-token status
and reconnect/disconnect actions. It never redisplays the client secret,
affiliate key or token values.

## Failure behaviour

- Missing or incomplete local client credentials block connection before
  redirecting.
- Missing, reused or expired state blocks the callback.
- Missing required scopes or an ambiguous merchant identity blocks activation.
- Token and provider errors are reported without provider payloads or secrets.
- Payment attempts fail locally when the processor is not connected or must be
  reconnected.
