# Blesta/Dynadot

Blesta registrar module for [Dynadot](https://www.dynadot.com/).

## Code Map for Maintainers

The module is intentionally split into four layers:

1. **`dynadot.php`** is the Blesta-facing module class. Blesta calls its
   lifecycle methods when a domain is added, renewed, suspended, cancelled, or
   edited. Its tab methods power the admin and client management screens.
2. **`apis/dynadot_api.php`** is the RESTful v2 transport layer. It selects the
   Live or Sandbox endpoint, builds the HTTP method/path/JSON request, signs it
   with HMAC-SHA256, sends the cURL request, and records safe request metadata
   for Blesta module logging.
3. **`apis/dynadot_response.php`** is the RESTful v2 response layer. It checks
   the HTTP status and JSON API status, normalizes response data for the
   module, and extracts error messages.
4. **`config/`, `language/`, and `views/`** contain reusable field definitions,
   translated UI strings, and the HTML/PHP templates used by management tabs.

When tracing a feature, start in `dynadot.php` at the public method named by
Blesta, follow its `DynadotApi::submit()` command, then inspect the matching
response envelope in `dynadot_response.php` and the related view under
`views/default/`.

### RESTful API v2

This module uses Dynadot's RESTful v2 API. Requests use resource-oriented HTTP
paths below `/restful/v2`, JSON request bodies where required, a Bearer API key,
an `X-Request-ID`, and an HMAC-SHA256 `X-Signature` generated with the API
secret. Live requests use `https://api.dynadot.com/restful/v2`; Sandbox requests
use `https://api-sandbox.dynadot.com/restful/v2`.

## Description

This module connects Blesta to Dynadot RESTful v2 for domain registrations,
transfers and renewals, and lets admins and clients manage name servers, DNS
records, child name servers, DNSSEC, email forwarding, contacts, the registrar
lock and EPP codes.

## Requirements

- Blesta 6.0 or later (built and reviewed against the 6.0.3 documentation)
- PHP 8.2 or later (Blesta 6 supports PHP 8.2, 8.3 and 8.4) with the cURL and JSON extensions

Version 1.0.1 targets Blesta 6 only. Its admin screens use Bootstrap 5 markup and no jQuery.
- A Dynadot account with API access enabled

## Install the Module

1. Upload the module files to `/components/modules/dynadot/` in your Blesta installation.
2. Log in to your Blesta admin interface.
3. Go to **Settings > Modules**.
4. Click the **Available** tab.
5. Click **Install** for the Dynadot module.

When upgrading from an earlier version, upload the new files and click **Upgrade** for the module under **Settings > Modules > Installed**.

## Dynadot Account Setup

1. In your Dynadot account, go to **Tools > API** and generate an API key.
2. Add the public IP address of your Blesta server to the API IP allow list on that page. Requests from any other IP address are rejected.
3. Keep enough account balance for registrations, transfers and renewals. Dynadot charges your account balance, not your clients.
4. Set the account default renewal option to **Do not renew**. Blesta renews domains when invoices are paid, so Dynadot auto-renewal would renew a domain twice or renew domains clients did not pay for.
5. Optional: accept the **API skip lock agreement** under Tools > API. Dynadot needs it to unlock domains through the API. Without it, the "unlock" option and EPP code requests for locked domains return an error.

## Module Setup

1. Go to **Settings > Modules > Dynadot > Manage** and click **Add Account**.
2. Enter an account name (for your reference only) and the API key.
3. Tick **Sandbox** only when using a key from the Dynadot API sandbox. Sandbox keys do not work on the live API, and live keys do not work on the sandbox.
4. Save. The module checks the API key and secret with Dynadot before saving
   them. Both credentials are stored encrypted. The account name is only a
   local display label and is not sent to Dynadot.
5. Create a package that uses the Dynadot module with the type **Domain Registration**, and optionally default name servers. If you use the Domain Manager plugin, TLDs and prices can be imported from Dynadot. Prices are converted from USD to each currency of the company.

## Features

- Domain registration (1-10 years, limited by each TLD's terms), transfer (with EPP code) and renewal
- Default or custom name servers on registration and transfer. Name servers that are not yet in your Dynadot account are added to it automatically.
- Registrar lock and unlock, EPP code retrieval
- Whois contact management, plus a per-client contact list with add and edit
- DNS records (A, AAAA, CNAME, MX, TXT, SRV, FORWARD, STEALTH, EMAIL) for the domain and its subdomains
- Email forwarding (up to 10 addresses)
- Child name server (glue record) registration and removal
- DNSSEC DS records
- ID protection through the `id_protection` configurable option
- Renew date synchronisation with the registrar
- TLD and price import for the Domain Manager plugin
- A cron task that pulls Dynadot contacts for the client contact list
- Import of domains that are already in your Dynadot account (see below)

## Importing existing Dynadot domains

Domains that are already in your Dynadot account can be added to Blesta without registering, transferring or renewing them again.

1. Create at least one package that uses the Dynadot module with the type **Domain Registration**, with yearly pricing in your clients' currencies. If you use the Domain Manager plugin, the TLDs configured there are used to pick the package automatically.
2. Go to **Settings > Modules > Dynadot > Manage** and click **Import Domains** next to the account.
3. The page lists every domain in the Dynadot account that is not yet a service in Blesta, and every active Blesta service on that account whose domain is no longer in Dynadot.
4. For each domain, choose a client (or leave **auto** to match the Dynadot registrant email to a client's primary email) and a package (or leave **auto** to match the TLD). Select the domains and click **Import Selected Domains**.

Imported services are created as active, with the renew date set to the Dynadot expiration date. No invoice or email is sent, and nothing is changed at Dynadot. Domains are imported one at a time to stay within the Dynadot API rate limit, and a domain that already exists in Blesta is never imported twice.

## Importing Dynadot prices

The Domain Manager plugin imports prices through the registrar module's
`getTldPricing()` method. The Dynadot implementation:

1. Calls the RESTful v2 TLD pricing endpoint in USD, one page at a time.
2. Caches normalized USD prices per Dynadot module row and environment.
3. Converts registration, transfer, and renewal unit prices into each Blesta
   company currency.
4. Expands unit prices into yearly terms using each TLD's `minDuration` and
   `maxDuration` values.

The daily **Update TLD Pricing** module cron refreshes the cache. If the cache
is empty, the next Domain Manager pricing import fetches current RESTful v2
prices automatically.

## Known Limitations

- **RESTful v2 availability:** Follow Dynadot's current API documentation for
  endpoint and payload changes. The module intentionally fails closed when the
  API secret is missing because RESTful v2 requests require HMAC signing.
- **Rate limit:** regular Dynadot accounts allow 60 API requests per minute on one thread. The contact sync cron processes at most 25 domains per run to stay within it.
- **DNSSEC:** The DNSSEC tab can add records and remove all of them, but does
  not currently list existing DS records.
- **Email forwarding:** Saving the form replaces all forwards.
- **DNS records:** saving the form replaces all DNS records of the domain, and switches it to Dynadot DNS.
- **Internationalised domain names** must be entered in punycode (`xn--...`).
- **Premium domains** are not registered automatically. Dynadot rejects them without the premium flag, and the error is shown on the order.

## Security Notes

- The API key and API secret are stored encrypted in Blesta and masked in the
  module log. The account name is a local display label only.
- EPP codes entered on orders are stored encrypted.
- Clients can only edit contacts listed for their own account, and only child name servers of their own domain.
- Service changes are made through POST forms protected by Blesta's CSRF token. Domain import requests are signed with a token bound to the admin session.
- Domain names are validated before any API call, because Dynadot treats commas in a domain as a list of domains.
- `apis/dynadot_api.php` can write a debug log file with full API responses (including contact details and EPP codes) when `$enable_log` is set to `true`. Leave it `false` in production.

## Changelog

### 1.0.1

- Added the daily `update_pricing` module cron task for automatic Dynadot TLD
  price refresh.
- The version bump causes existing 1.3.0 installations to run the module
  upgrade hook and register the pricing task. Fresh installations register it
  during installation.

## Support

Developed and maintained by **10Corp**.

- Website: [10corp.com](https://www.10corp.com)
- Email: [cs@10corp.com](mailto:cs@10corp.com)
