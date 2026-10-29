# Blesta Dynadot Registrar Module

[![Blesta](https://img.shields.io/badge/Blesta-6.x%20Ready-007acc.svg)](https://www.blesta.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%20|%208.3%20|%208.4-777bb4.svg)](https://www.php.net/)
[![Dynadot API](https://img.shields.io/badge/Dynadot-RESTful%20v2-green.svg)](https://www.dynadot.com/domain/api-document)
[![Protocol](https://img.shields.io/badge/Protocol-Pure%20RESTful%20v2%20(Zero%20API3)-blue.svg)](https://www.dynadot.com/)
[![UI](https://img.shields.io/badge/UI-Bootstrap%205%20+%20FontAwesome%20(No%20jQuery)-purple.svg)](https://getbootstrap.com/)
[![License](https://img.shields.io/badge/License-GPL--3.0--or--later-blue.svg)](LICENSE)

An enterprise-grade domain registrar module connecting **[Blesta](https://www.blesta.com/)** to **[Dynadot's RESTful v2 API](https://www.dynadot.com/domain/api-document)**. Built with a clean 4-tier architecture designed specifically for domain resellers, web hosts, and cloud infrastructure providers.

---

## 4-Tier Architecture Overview

The module decomposes business logic, transport security, and presentation into 4 distinct layers:

```text
┌────────────────────────────────────────────────────────────────────────┐
│                   Tier 1: Blesta Registrar Module                      │
│                           (dynadot.php)                                │
│   - Lifecycle Hooks (registerDomain, renewDomain, transferDomain)      │
│   - UI Tab Handlers (Nameservers, DNS, Contacts, Security, Forwarding) │
│   - Scheduled Cron Tasks (sync_domains, update_pricing, pull_contacts) │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ (Delegates)
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│              Tier 2: Specialized Command Classes Layer                 │
│                          (apis/commands/)                              │
│   ├── domains_register.php  -> Registration & WHOIS contact formatting │
│   ├── domains_renew.php     -> Term duration validation & renew args   │
│   ├── domains_transfer.php  -> Inbound transfer intake & auth codes    │
│   ├── domains_dns.php       -> DNS zone records & child glue servers   │
│   ├── domain_sync.php       -> Full domain, contacts & nameserver sync │
│   ├── domain_import.php     -> Bulk registrar audit & service import   │
│   ├── email_forwarding.php  -> Forwarder rules parsing & validation    │
│   ├── domains_pricing.php   -> Caching, pagination & currency convert  │
│   ├── domains_privacy.php   -> WHOIS privacy enable/disable (full/off) │
│   └── domains_epp.php       -> Auth code retrieval & generation        │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                   Tier 3: RESTful v2 Transport Layer                   │
│             (apis/dynadot_api.php & apis/dynadot_response.php)         │
│   - Live & Sandbox Endpoint Routing (api.dynadot.com / api-sandbox)    │
│   - HMAC-SHA256 Request Signing with RFC 4122 UUID v4 (X-Request-ID)   │
│   - Response Normalization & Error Parsing                             │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                      Tier 4: Views & Presentation                      │
│                  (views/default/, language/, config/)                  │
│   - Bootstrap 5 Responsive Templates (Zero jQuery, FontAwesome Icons)  │
│   - Internationalization Strings (language/en_us/dynadot.php)          │
│   - Registry and Order Configurations (config/dynadot.php)             │
└────────────────────────────────────────────────────────────────────────┘
```

---

## Selling Domains

| Feature | Description |
| :--- | :--- |
| **Registrations** | New domain registrations offered only for the terms each registry allows (1–10 years, constrained per TLD). |
| **Transfers** | Inbound domain transfers with authorization (EPP) code validation. Handles registries with strict auth code enforcement (such as `.nz` and `.fi`). |
| **Renewals** | Manual renewals via admin service management and automatic renewals triggered on invoice payment. |
| **TLD Import** | Pull the entire Dynadot TLD catalogue directly into Blesta's Domain Manager. |
| **TLD Pricing** | Paginated import and multi-currency synchronization of registration, renewal, and transfer prices across 1–10 year terms. |
| **Additional Fields** | Collect registry-specific requirements at order time (e.g. `.us` Nexus Application Purpose and Category). |

---

## Managing Domains

| Feature | Description |
| :--- | :--- |
| **Default Nameservers** | Out-of-the-box fallback to Dynadot's authoritative nameservers: `ns1.dynadot.com`, `ns2.dynadot.com`, `ns1.dyna-ns.net`, `ns2.dyna-ns.net`. |
| **Nameservers Delegation** | Set custom DNS delegation or switch back to the managed default nameservers. Unregistered nameservers are automatically added to the account. |
| **Nameserver Hosts (Glue)** | Register, list, and delete host child nameservers (glue records) with IPv4 and IPv6 support. |
| **WHOIS Contacts** | Manage Registrant, Administrative, Technical, and Billing contact profiles with standardized international phone formatting (`+CC.NUMBER`). |
| **Registrar Lock** | Prevent unauthorized transfers away by toggling the domain transfer lock directly from admin and client tabs. |
| **EPP Code Retrieval** | Instantly retrieve or email domain authorization codes for outgoing domain transfers. |
| **DNS Management** | Full zone file editing for `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `SRV`, `FORWARD`, `STEALTH`, and `EMAIL` records. |
| **Email Forwarding** | Forward domain email addresses to existing mailboxes with input validation and automated DNS record configuration. |
| **ID Protection** | Real-time enabling and disabling of WHOIS Privacy (`full` vs `off`) linked to the `id_protection` configurable option. |
| **DNSSEC** | Add, view, and clear DS and Key records directly from domain management tabs. |
| **Full Domain Sync** | Scheduled and manual sync of Expiration Dates, Registration Dates, Nameservers, WHOIS Contacts, Transfer Lock, and Privacy status. |

---

## Requirements

- **Blesta:** 6.x or later (compatible with Paradigm and responsive themes).
- **PHP:** 8.2, 8.3, or 8.4 with `curl`, `openssl`, and `json` extensions.
- **Dynadot Account:** Active Dynadot account with API access enabled and your server IP allowlisted.
- **UI:** Native Bootstrap 5 markup with FontAwesome icons (zero jQuery dependencies).

---

## Dynadot Account Setup

1. Log in to your [Dynadot Account](https://www.dynadot.com/).
2. Navigate to **Tools > API**.
3. Generate your **API Key** and copy your **API Secret**.
4. **IP Allowlist:** Add the public IPv4 address of your Blesta server to the **API IP Allowlist**. Requests from unlisted IPs will be rejected by Dynadot with HTTP 403.
5. **Account Balance:** Ensure your Dynadot prepaid balance has sufficient funds for registrations, renewals, and transfers.
6. **Default Renewal Setting:** Set your Dynadot account default renewal option to **"Do not renew"**. Blesta automatically manages renewal scheduling when invoices are paid.
7. **API Skip Lock Agreement (Recommended):** Review and accept the *API Skip Lock Agreement* under **Tools > API** to allow programmatic unlocking and EPP code retrieval.

---

## Installation & Upgrade

### Fresh Installation

1. Copy the module files into your Blesta installation:
   ```bash
   cp -r dynadot /path/to/blesta/components/modules/
   ```
2. In Blesta Admin, navigate to **Settings > Modules > Available**.
3. Locate **Dynadot** and click **Install**.

### Upgrading an Existing Installation

1. Upload the updated files, overwriting `/components/modules/dynadot/`.
2. In Blesta Admin, navigate to **Settings > Modules > Installed**.
3. Click **Upgrade** next to Dynadot. Database tables, service fields, and scheduled cron tasks will migrate automatically.

---

## Module Configuration in Blesta

1. In Blesta Admin, navigate to **Settings > Modules > Dynadot > Manage** and click **Add Account**.
2. Fill in the account credentials:
   - **Account Name:** A friendly label (e.g., `Dynadot Production` or `Dynadot Sandbox`).
   - **API Key:** Your Dynadot API Key.
   - **API Secret:** Your Dynadot API Secret (used for cryptographic HMAC-SHA256 request signing).
   - **Sandbox:** Leave unchecked for production. Check only if using credentials from `api-sandbox.dynadot.com`.
3. Click **Save**. The module tests the connection before encrypting and storing credentials.
4. **Package Configuration:** Navigate to **Packages > Add Package**:
   - Set **Module** to `Dynadot`.
   - Set **Type** to `Domain Registration`.
   - Configure pricing terms (1–10 years) and default nameservers (`ns1.dynadot.com`, `ns2.dynadot.com`, `ns1.dyna-ns.net`, `ns2.dyna-ns.net`).

---

## Scheduled Cron Tasks

The module registers four automated background tasks:

| Cron Key | Interval | Purpose |
| :--- | :--- | :--- |
| `sync_domains` | Every 60 min | Full domain synchronization via `domain_sync.php` (Expiration, Nameservers, Status, Lock, Privacy). |
| `update_pricing` | Daily | Refreshes and caches TLD prices across all configured currencies via `domains_pricing.php`. |
| `pull_contacts` | Every 10 min | Synchronizes client contact details with Dynadot WHOIS contacts. |
| `pull_nameservers` | Every 10 min | Updates local service nameservers with remote registry delegation. |

---

## Testing & Quality Assurance

The module includes an automated test suite located in `tests/`:

```bash
# Run the end-to-end verification suite
php components/modules/dynadot/tests/run_tuhin_co_tests.php
```

To run live calls against the Dynadot Sandbox API:
```bash
export DYNADOT_SANDBOX_KEY="your_sandbox_api_key"
export DYNADOT_SANDBOX_SECRET="your_sandbox_api_secret"
php components/modules/dynadot/tests/run_tuhin_co_tests.php
```

---

## Security & Architecture Standards

- **HMAC-SHA256 Signing:** Every RESTful v2 request is signed with a SHA256 digest of `apiKey + "\n" + path + "\n" + xRequestId + "\n" + body`.
- **RFC 4122 UUID v4:** Every request includes an idempotency header `X-Request-ID`.
- **AES-256 Encryption:** API keys, secrets, and EPP authorization codes are stored encrypted using Blesta's core database encryption.
- **Fail-Closed Security:** Requests intentionally abort if the API secret is missing, preventing unsigned requests from being transmitted.
- **Zero jQuery:** Client and admin tabs run exclusively on modern Bootstrap 5 markup and FontAwesome vector icons.

---

## Changelog

### Version 1.0.2
- **4-Tier Modular Decomposition:** Extracted business logic from `dynadot.php` into 10 specialized command classes under `apis/commands/`.
- **Full Domain Synchronization:** Added `DynadotDomainSync` for complete synchronization of expiration dates, nameservers, lock status, privacy, and contacts.
- **Import Wizard Integration:** Added `DynadotDomainImport` extracting bulk domain auditing, matching, and provisioning.
- **Bootstrap 5 UI Refinement:** Upgraded all management views and icons to FontAwesome (`fas fa-*`) and Bootstrap 5 responsive cards.
- **PHP 8.4 Verification:** Full compatibility with PHP 8.2, 8.3, and 8.4; clean strict typing and zero deprecated library warnings.

---

## Support & Maintainer

Developed and maintained by **[10Corp](https://www.10corp.com)**.

- **Website:** [https://www.10corp.com](https://www.10corp.com)
- **Technical Support:** [cs@10corp.com](mailto:cs@10corp.com)
- **GitHub Repository:** [https://github.com/10corp/blesta-dynadot](https://github.com/10corp/blesta-dynadot)
- **Blesta Marketplace:** [https://marketplace.blesta.com/](https://marketplace.blesta.com/)
