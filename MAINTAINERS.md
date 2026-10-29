# Code Map & Maintainer Guide

> **Module:** Blesta Dynadot Registrar Module  
> **API Version:** Dynadot RESTful v2 (Pure RESTful v2 — Zero API3 legacy calls)  
> **Target Framework:** Blesta 6.x+ (Paradigm Theme, Bootstrap 5, No jQuery)  
> **PHP Runtime:** PHP 8.2, 8.3, 8.4+ (Strict typing, Modern cURL, OpenSSL, JSON)  
> **Maintainer:** 10Corp (<cs@10corp.com>)  
> **License:** GNU General Public License v3.0 ([GPL-3.0-or-later](LICENSE))  
> **Repository:** [https://github.com/10corp/blesta-dynadot](https://github.com/10corp/blesta-dynadot)

---

## 1. Architectural Overview

The module follows a decoupled, 4-tier separation of concerns:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        Blesta Core & Web UI                            │
│           (Controllers, Cron Workers, Admin & Client Portal)           │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                   Tier 1: Blesta Module Interface                      │
│                           (dynadot.php)                                │
│   - Implements Blesta Registrar Module Specification                   │
│   - Lifecycle Hooks (registerDomain, renewDomain, transferDomain)      │
│   - UI Tab Handlers (Manage Nameservers, DNS, Contacts, Security)      │
│   - Scheduled Cron Tasks (sync_domains, update_pricing, pull_contacts) │
│   - Multi-tier Fallback & Service Field Storage Engine                 │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                   Tier 2: Specialized Command Layer                    │
│                           (apis/commands/)                             │
│   - DynadotDomainsRegister     - DynadotEmailForwarding                │
│   - DynadotDomainsRenew        - DynadotDomainsPrivacy                 │
│   - DynadotDomainsTransfer     - DynadotDomainsEpp                     │
│   - DynadotDomainsDns          - DynadotDomainsPricing                 │
│   - DynadotDomainSync (Full Domain, WHOIS, DNS & NS Synchronization)   │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                Tier 3: RESTful v2 Transport & Security                 │
│                 (apis/dynadot_api.php & dynadot_response.php)          │
│   - Base URLs: Live vs. Sandbox Routing                                │
│   - HMAC-SHA256 Cryptographic Request Signing                          │
│   - UUID v4 Generation (X-Request-ID)                                  │
│   - Strict JSON Payload Sanitization & Response Normalization          │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                      Dynadot RESTful v2 API Gateway                    │
│           (https://api.dynadot.com / api-sandbox.dynadot.com)          │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Directory Structure & File Map

```
/components/modules/dynadot/
├── apis/
│   ├── commands/                     # Dedicated domain service command classes
│   │   ├── domain_sync.php           # DynadotDomainSync (Full domain & WHOIS sync)
│   │   ├── domains_dns.php           # DynadotDomainsDns (Records, subdomains, nameservers)
│   │   ├── domains_epp.php           # DynadotDomainsEpp (EPP transfer code & lock)
│   │   ├── domains_pricing.php       # DynadotDomainsPricing (TLD catalog & term matrices)
│   │   ├── domains_privacy.php       # DynadotDomainsPrivacy (WHOIS ID Protection toggle)
│   │   ├── domains_register.php      # DynadotDomainsRegister (Availability & registration)
│   │   ├── domains_renew.php         # DynadotDomainsRenew (Renewal & restore operations)
│   │   ├── domains_transfer.php      # DynadotDomainsTransfer (Inbound transfer ingestion)
│   │   └── email_forwarding.php      # DynadotEmailForwarding (Mail aliases & forwarders)
│   ├── dynadot_api.php               # Core RESTful v2 transport & HMAC request signer
│   └── dynadot_response.php          # Response parser, envelope unwrapper & error extractor
├── config/
│   └── dynadot.php                   # TLD definitions, whois tags, and package settings
├── config.json                       # Module metadata, author tags, and feature flags
├── dynadot.php                       # Primary Blesta module class (Lifecycle & UI Controller)
├── language/
│   └── en_us/
│       └── dynadot.php               # English language definitions & UI labels
├── MAINTAINERS.md                    # This architecture and maintainer specification
├── README.md                         # Production documentation & deployment guide
└── views/
    └── default/                      # Bootstrap 5 responsive PDT views
        ├── admin_manage_plugin.pdt   # Module account configuration & credential setup
        ├── domain_import.pdt         # Existing Dynadot domains migration wizard
        ├── tab_admin_actions.pdt     # Admin quick sync & status action buttons
        ├── tab_client_contacts.pdt   # Client WHOIS contact editor
        ├── tab_client_dns_records.pdt# Client DNS records manager
        ├── tab_client_email_forwarding.pdt # Client email forwarder manager
        ├── tab_client_nameservers.pdt# Client nameserver management tab
        ├── tab_client_settings.pdt   # Client registrar lock & EPP retrieval tab
        ├── tab_contacts.pdt          # Admin WHOIS contact manager
        ├── tab_dns_records.pdt       # Admin DNS zone records manager
        ├── tab_email_forwarding.pdt  # Admin email forwarder manager
        ├── tab_nameservers.pdt       # Admin nameservers editor
        └── tab_settings.pdt          # Admin registrar lock & EPP retrieval tab
```

---

## 3. RESTful v2 Security & Authentication Pipeline

Dynadot RESTful v2 requires every request to be cryptographically signed using HMAC-SHA256. API3 legacy query parameters (`key=...`, `command=...`) are **completely removed**.

### Request Signing Specifications
Every HTTP call generated by `DynadotApi::call()` signs the request payload using the following structure:

```
stringToSign = apiKey + "\n" + fullPathAndQuery + "\n" + xRequestId + "\n" + requestBody
```

1. **`apiKey`**: Plaintext Dynadot API Key.
2. **`fullPathAndQuery`**: Resource path including `/restful/v2` and URL query parameters (e.g., `/restful/v2/domains/example.com/search?currency=USD`).
3. **`xRequestId`**: RFC 4122 compliant UUID v4 uniquely generated for each request.
4. **`requestBody`**: Raw JSON-encoded string for `POST`/`PUT` requests, or empty string `""` for `GET`/`DELETE`.
5. **`X-Signature`**: `base64_encode(hash_hmac('sha256', stringToSign, apiSecret, true))`.

### HTTP Request Headers
```http
Accept: application/json
Authorization: Bearer <API_KEY>
X-Request-ID: <UUID-v4>
X-Signature: <BASE64-HMAC-SHA256>
Content-Type: application/json
```

---

## 4. Specialized Command Layer (`apis/commands/`)

To prevent `dynadot.php` from becoming a monolithic file and ensure clear boundaries, service operations are grouped into dedicated command classes:

### 1. `DynadotDomainsRegister` (`apis/commands/domains_register.php`)
- `check(array $vars)`: Performs domain availability check via `GET /restful/v2/domains/{domain}/search`.
- `create(array $vars)`: Submits new registration via `POST /restful/v2/domains/{domain}/register`. Supports duration (1-10 years), registrant/admin/tech/billing contact IDs, nameservers, and privacy settings.
- `getInfo(array $vars)`: Fetches raw domain status and configuration via `GET /restful/v2/domains/{domain}`.
- `getList(array $vars)`: Lists all active account domains via `GET /restful/v2/domains`.

### 2. `DynadotDomainsRenew` (`apis/commands/domains_renew.php`)
- `renew(array $vars)`: Renews domain for 1-10 years via `POST /restful/v2/domains/{domain}/renew`.
- `restore(array $vars)`: Restores expired domain in redemption period via `POST /restful/v2/domains/{domain}/restore`.

### 3. `DynadotDomainsTransfer` (`apis/commands/domains_transfer.php`)
- `create(array $vars)`: Ingests inbound domain transfer via `POST /restful/v2/domains/{domain}/transfer_in` with authorization/EPP code and contact assignment.
- `check(array $vars)`: Checks transfer eligibility via `GET /restful/v2/domains/{domain}/transfer_check`.

### 4. `DynadotDomainsDns` (`apis/commands/domains_dns.php`)
- `getRecords(array $vars)`: Retrieves DNS zone records via `GET /restful/v2/domains/{domain}/records`.
- `setRecords(array $vars)`: Updates apex (`dns_main_list`) and subdomain (`dns_sub_list`) records via `POST /restful/v2/domains/{domain}/records`.
- `getNameservers(array $vars)`: Fetches active nameservers via `GET /restful/v2/domains/{domain}/nameservers`.
- `setNameservers(array $vars)`: Updates active authoritative nameservers via `PUT /restful/v2/domains/{domain}/nameservers`.
- `clearSetting(array $vars)`: Resets DNS configuration via `PUT /restful/v2/domains/{domain}/clear_domain_setting`.

### 5. `DynadotEmailForwarding` (`apis/commands/email_forwarding.php`)
- `setForwards(array $vars)`: Configures up to 10 email aliases via `PUT /restful/v2/domains/{domain}/email_forwarding`.
- `getList(array $vars)`: Retrieves existing forwarders via `GET /restful/v2/domains/{domain}/records`.
- `clear(array $vars)`: Deletes forwarders via `clear_domain_setting`.

### 6. `DynadotDomainsPrivacy` (`apis/commands/domains_privacy.php`)
- `set(array $vars)`: Toggles WHOIS ID Protection (`full` or `off`) via `PUT /restful/v2/domains/{domain}/privacy`.
- `get(array $vars)`: Retrieves privacy status via `GET /restful/v2/domains/{domain}`.

### 7. `DynadotDomainsEpp` (`apis/commands/domains_epp.php`)
- `getAuthCode(array $vars)`: Retrieves transfer authorization code via `GET /restful/v2/domains/{domain}/transfer_auth_code`.
- `lockDomain(array $vars)`: Locks domain against transfers via `PUT /restful/v2/domains/{domain}/lock_status`.
- `unlockDomain(array $vars)`: Unlocks domain via `PUT /restful/v2/domains/{domain}/lock_status`.

### 8. `DynadotDomainsPricing` (`apis/commands/domains_pricing.php`)
- `getTldPrice(array $vars)`: Paginated fetch of Dynadot TLD catalog via `GET /restful/v2/domains/get_tld_price`.
- `getAllTldPrices(string $currency)`: Automatically paginates through all pages, assembling the complete TLD pricing catalog.

### 9. `DynadotDomainSync` (`apis/commands/domain_sync.php`)
- `getFullSyncData(array $vars)`: Consolidated synchronization orchestrator. Gathers expiration dates, registration dates, authoritative nameservers, full WHOIS contact profiles, transfer lock state, WHOIS privacy status, auto-renewal flag, and DNS records into a normalized dataset.

---

## 5. Blesta Integration Patterns & Rules

### Database Schema Constraint: `service_fields`
The Blesta `service_fields` table uses a composite primary key:
```sql
PRIMARY KEY (`service_id`, `key`)
```
> [!CAUTION]
> The table has **no `id` column**. Any query attempting `->select(['id'])` or `->where('id', '=', ...)` will fail with SQL error `1054: Unknown column 'id' in 'field list'`.
> Maintainers must always use the encapsulated helper `dynadot::updateServiceField($service_id, $key, $value)`:

```php
private function updateServiceField($service_id, $key, $value)
{
    $field = $this->Record->select(['key'])->
        from('service_fields')->
        where('service_id', '=', $service_id)->
        where('key', '=', $key)->
        fetch();

    if ($field) {
        $this->Record->where('service_id', '=', $service_id)->
            where('key', '=', $key)->
            update('service_fields', ['value' => (string) $value]);
    } elseif ($value !== '' && $value !== null) {
        $this->Record->insert(
            'service_fields',
            ['service_id' => $service_id, 'key' => $key, 'value' => (string) $value]
        );
    }
}
```

### Nameserver Display & Multi-tier Fallback Engine
When rendering nameservers in `manageNameservers()`:
1. **Tier 1 (Authoritative API):** Query Dynadot RESTful v2 `GET /domains/{domain}/nameservers`. If `glue_type == 'DNS'`, return Dynadot defaults `ns1.dynadot.com` / `ns2.dynadot.com`.
2. **Tier 2 (Service Fields):** Read stored `ns1`–`ns5` from Blesta's `service_fields` table.
3. **Tier 3 (Package Defaults):** Read default nameservers configured on the Blesta package (`$package->meta->ns`).

### Bootstrap 5 UI & Zero jQuery Rule
Blesta 6.x removed jQuery from the administrative interface. Maintainers must:
- Never use `$` or jQuery selectors in PDT views.
- Use vanilla JavaScript (`document.querySelector`, `addEventListener`).
- Use Bootstrap 5 markup and attributes (`data-bs-toggle="tooltip"`, `data-bs-target`, etc.).

---

## 6. Scheduled Cron Automation

The module registers three scheduled cron tasks with Blesta:

| Task Key | Method | Schedule | Purpose |
| :--- | :--- | :--- | :--- |
| `sync_domains` | `cron('sync_domains')` | Daily | Runs `synchronizeAllDomains()` across all active and suspended services, updating expiration dates, nameservers, WHOIS contacts, and security flags. |
| `update_pricing` | `cron('update_pricing')` | Daily | Fetches complete TLD catalog via `DynadotDomainsPricing`, caches prices per module row, and converts prices into company currencies. |
| `pull_contacts` | `cron('pull_contacts')` | Hourly | Synchronizes queued client contact details from Dynadot into Blesta client meta records. |

---

## 7. Testing & Quality Assurance Playbook

Maintainers can verify the entire 9-command RESTful v2 suite without starting a web session:

### Direct CLI Verification Script
```bash
php -d display_errors=1 -r '
require_once "/home/tuhin/Documents/www/components/modules/dynadot/apis/dynadot_api.php";

$key = "sandbox_7e7C7k748r6x18h8JC73T9F8K7T6B6M8G7nww8uC9U6V6M7kI757N6he7T";
$secret = "sandbox_a3526bb3062a0989bdec7063ab3df92055bf0c5277c0cf8f58c4330ac0ca7bda";
$api = new DynadotApi($key, true, $secret);

// 1. Verify Register
$reg = new DynadotDomainsRegister($api);
$domain = "test" . time() . ".com";
$r1 = $reg->create(["domain" => $domain, "duration" => 1, "registrant_contact" => 3508]);
echo "Register: " . $r1->status() . "\n";

// 2. Verify Full Sync
$sync = new DynadotDomainSync($api);
$r2 = $sync->getFullSyncData(["domain" => $domain]);
echo "Full Sync: " . ($r2["success"] ? "PASS" : "FAIL") . " (Expires: " . $r2["dates"]["expires"] . ")\n";
'
```

### Full 9-Service Test Suite
Run the automated test runner in the workspace to test:
1. `DynadotDomainsRegister::create()`
2. `DynadotDomainsRenew::renew()`
3. `DynadotDomainsTransfer::create()`
4. `DynadotDomainsDns::setRecords()` & `getRecords()`
5. `DynadotEmailForwarding::setForwards()` & `getList()`
6. `DynadotDomainsPrivacy::set()`
7. `DynadotDomainsEpp::getAuthCode()`
8. `DynadotDomainsPricing::getTldPrice()`
9. `DynadotDomainSync::getFullSyncData()`

---

## 8. Maintainer Contact & Contributions

- **Organization:** 10Corp
- **Website:** [10corp.com](https://www.10corp.com)
- **Support:** [cs@10corp.com](mailto:cs@10corp.com)
- **Contribution Guidelines:** Ensure all new features use the `apis/commands/` architecture, use pure RESTful v2 endpoints, sign requests via `DynadotApi`, and contain zero jQuery.
