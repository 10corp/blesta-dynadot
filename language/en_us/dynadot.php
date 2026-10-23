<?php

/**
 * Dynadot Registrar Language Definitions (en_us)
 *
 * Contains all English (US) UI text, validation errors, success messages,
 * tab titles, and form labels used by the Dynadot module in Blesta.
 *
 * Architecture Role (For Junior Developers):
 * - Loaded automatically in Dynadot::__construct() via Language::loadLang('dynadot', ...).
 * - Accessed via Language::_('Dynadot.key', true) in dynadot.php and config/dynadot.php,
 *   or $this->_('Dynadot.key') inside .pdt view templates.
 * - Supports printf-style numbered placeholders (%1$s, %2$s, %3$s) for dynamic values
 *   passed as additional arguments to Language::_().
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.language.en_us
 * @author 10Corp <cs@10corp.com>
 * @copyright Copyright (c) 2026, 10Corp
 * @link https://www.10corp.com
 */

// =========================================================================
// 1. Validation & API Error Messages
// =========================================================================

// Module Row Credential Validation (dynadot.php -> buildModuleRowValidationRules)
$lang['Dynadot.!error.user.valid'] = 'Please enter an account name.';
$lang['Dynadot.!error.key.valid'] = 'Please enter your Dynadot API Key.';
$lang['Dynadot.!error.secret.valid'] = 'Please enter your Dynadot API Secret.';
$lang['Dynadot.!error.key.valid_connection'] = 'The API Key or API Secret appears to be invalid, the API is not enabled for your Dynadot account, or the IP address of this server is not allowed under Tools > API in your Dynadot account. Also verify that the Sandbox setting matches the type of API credentials used.';
$lang['Dynadot.!error.registrant_contact.valid'] = 'A Dynadot registrant contact could not be created or found for this client. Registration was stopped.';
$lang['Dynadot.!error.registrant_contact.phone'] = 'The client must have a phone number before a Dynadot registrant contact can be created.';
$lang['Dynadot.!error.portfolio.valid_portfolio'] = 'The portfolio entered does not appear to be valid.';
$lang['Dynadot.!error.payment_id.valid_format'] = 'Payment ID must be numeric.';

// Domain Provisioning & Lifecycle Validation
$lang['Dynadot.!error.domain.valid'] = 'Invalid domain name.';
$lang['Dynadot.!error.domain.unavailable'] = 'Domain is not available.';
$lang['Dynadot.!error.epp.empty'] = 'EPP code is required for transfers.';
$lang['Dynadot.!error.invalid_term'] = 'Invalid number of years, or no years provided.';
$lang['Dynadot.!error.api_http'] = 'The Dynadot API returned an unexpected HTTP response code: %1$s';
$lang['Dynadot.!error.epp.unsupported'] = 'Setting a custom EPP code is not supported by the Dynadot API.';

// Nameserver, Host, DNSSEC, DNS Records & Email Forwarding Validation
$lang['Dynadot.!error.ns.valid'] = 'The name server "%1$s" is not a valid host name.';
$lang['Dynadot.!error.ns.count'] = 'Enter between 2 and 13 name servers.';
$lang['Dynadot.!error.host.valid'] = 'Enter a valid host name label, such as "ns1".';
$lang['Dynadot.!error.ip.valid'] = 'Enter a valid IPv4 or IPv6 address.';
$lang['Dynadot.!error.dnssec.valid'] = 'Enter a numeric key tag (0-65535), a supported algorithm and digest type, and a hexadecimal digest.';
$lang['Dynadot.!error.dns.valid'] = 'The %1$s record "%2$s" is invalid. MX, FORWARD, STEALTH and EMAIL records need the extra value, SRV records need a subdomain, and subdomains may only contain letters, numbers, hyphens and underscores.';
$lang['Dynadot.!error.dns.empty'] = 'Enter at least one DNS record.';
$lang['Dynadot.!error.dns.count'] = 'Dynadot allows at most 20 records for the domain itself and 100 records for subdomains.';
$lang['Dynadot.!error.email_forward.valid'] = 'The email forward "%1$s" to "%2$s" is invalid.';
$lang['Dynadot.!error.email_forward.count'] = 'Dynadot allows at most 10 email forwards.';

// Domain Import & Audit Validation Errors
$lang['Dynadot.!error.import.token'] = 'The request could not be verified. Reload the page and try again.';
$lang['Dynadot.!error.import.domain'] = 'Invalid domain name.';
$lang['Dynadot.!error.import.exists'] = 'This domain already exists in Blesta as service #%1$s.';
$lang['Dynadot.!error.import.not_found'] = 'This domain was not found in the Dynadot account.';
$lang['Dynadot.!error.import.package'] = 'Select a valid package that uses this Dynadot module.';
$lang['Dynadot.!error.import.package_no_match'] = 'No package was found for the TLD %1$s. Select a package manually.';
$lang['Dynadot.!error.import.pricing'] = 'The selected package has no yearly pricing for the client\'s currency (%1$s).';
$lang['Dynadot.!error.import.client'] = 'Select a valid client.';
$lang['Dynadot.!error.import.client_no_match'] = 'No Blesta client matches the registrant email (%1$s). Select a client manually.';
$lang['Dynadot.!error.import.client_no_email'] = 'The registrant email could not be fetched. Select a client manually.';

// =========================================================================
// 2. Success & Notice Messages
// =========================================================================

$lang['Dynadot.!success.contact_deleted'] = 'The contact has been successfully deleted.';
$lang['Dynadot.!success.epp_code_sent'] = 'The EPP code was requested from Dynadot.';
$lang['Dynadot.!success.epp_code'] = 'The EPP code for this domain is: %1$s';
$lang['Dynadot.!success.updated'] = 'The changes have been saved.';
$lang['Dynadot.!success.renew_date_synced'] = 'The renew date has been synchronized with the registrar.';
$lang['Dynadot.!success.contacts_synced'] = 'The contacts have been pulled from Dynadot.';
$lang['Dynadot.!success.contact_added'] = 'The contact has been created.';
$lang['Dynadot.!success.import'] = 'Imported as service #%1$s.';

$lang['Dynadot.notice.default_nameservers'] = 'This domain is not using the Dynadot name servers, so DNS records and email forwarding managed here will not take effect.';
$lang['Dynadot.whois_disabled.message'] = 'Whois management is disabled.';

// =========================================================================
// 3. Module Metadata Labels (Referenced in config.json)
// =========================================================================

$lang['Dynadot.name'] = 'Dynadot';
$lang['Dynadot.description'] = 'Dynadot is an ICANN accredited domain registrar and web host.';
$lang['Dynadot.module_row'] = 'Account';
$lang['Dynadot.module_row_plural'] = 'Accounts';
$lang['Dynadot.module_group'] = 'Account Group';

// =========================================================================
// 4. Module Management & Account Row Forms (manage.pdt, add_row.pdt, edit_row.pdt)
// =========================================================================

$lang['Dynadot.manage.box_title'] = 'Manage Dynadot Accounts';
$lang['Dynadot.manage.title'] = 'Dynadot Accounts';
$lang['Dynadot.manage.module_row_title'] = 'Account';
$lang['Dynadot.manage.module_groups_title'] = 'Groups';
$lang['Dynadot.manage.module_rows_options'] = 'Options';
$lang['Dynadot.manage.module_rows.edit'] = 'Edit';
$lang['Dynadot.manage.module_rows.delete'] = 'Delete';
$lang['Dynadot.manage.module_rows.confirm_delete'] = 'Are you sure you want to delete this account?';
$lang['Dynadot.manage.module_rows_no_results'] = 'There are no accounts.';
$lang['Dynadot.back_to_manage'] = 'Back to Manage';
$lang['Dynadot.manage.audit_domains'] = 'Import Domains';
$lang['Dynadot.manage.module_rows.import'] = 'Import Domains';

// Shared Row Meta Labels & Tooltips (Used in views/default/add_row.pdt & edit_row.pdt)
$lang['Dynadot.row_meta.user'] = 'Account Name (for your reference only)';
$lang['Dynadot.row_meta.key'] = 'API Key';
$lang['Dynadot.row_meta.key_tooltip'] = 'Enter the API Key generated under Tools > API in your Dynadot account (used as the RESTful v2 Bearer token).';
$lang['Dynadot.row_meta.secret'] = 'API Secret';
$lang['Dynadot.row_meta.secret_tooltip'] = 'Enter the API Secret generated under Tools > API in your Dynadot account (used to sign RESTful v2 requests with HMAC-SHA256).';
$lang['Dynadot.row_meta.sandbox'] = 'Sandbox (use a Dynadot sandbox API key and secret)';

// Add Account Form Labels
$lang['Dynadot.add_row.box_title'] = 'Add Dynadot Account';
$lang['Dynadot.add_row.basic_title'] = 'Basic Settings';
$lang['Dynadot.add_row.field_user'] = 'Account Name (for your reference only)';
$lang['Dynadot.add_row.field_key'] = 'API Key';
$lang['Dynadot.add_row.field_secret'] = 'API Secret';
$lang['Dynadot.add_row.field_sandbox'] = 'Sandbox (use a Dynadot sandbox API key)';
$lang['Dynadot.add_row.add_btn'] = 'Add Account';

// Edit Account Form Labels
$lang['Dynadot.edit_row.box_title'] = 'Edit Dynadot Account';
$lang['Dynadot.edit_row.basic_title'] = 'Basic Settings';
$lang['Dynadot.edit_row.field_user'] = 'Account Name (for your reference only)';
$lang['Dynadot.edit_row.field_key'] = 'API Key';
$lang['Dynadot.edit_row.field_secret'] = 'API Secret';
$lang['Dynadot.edit_row.field_sandbox'] = 'Sandbox (use a Dynadot sandbox API key)';
$lang['Dynadot.edit_row.add_btn'] = 'Update Account';

// =========================================================================
// 5. Audit & Import Domains Screen (audit_domains.pdt)
// =========================================================================

$lang['Dynadot.audit_domains.box_title'] = 'Audit & Import Dynadot Domains';
$lang['Dynadot.add_row.audit_domains.box_title'] = 'Import Domains from Dynadot (%1$s)';
$lang['Dynadot.add_row.audit_domains.box_description'] = 'These domains are in your Dynadot account but are not yet a service in Blesta. Choose a client and a package for each domain you want to import. Imported services are created as active, without contacting the registrar, without invoices and without emails. The renew date is set to the registrar expiration date.';
$lang['Dynadot.add_row.audit_domains.summary'] = 'Domains in Dynadot: %1$s. Already in Blesta: %2$s. Not yet in Blesta: %3$s.';
$lang['Dynadot.add_row.audit_domains.domain_name'] = 'Domain Name';
$lang['Dynadot.add_row.audit_domains.expiration'] = 'Expires';
$lang['Dynadot.add_row.audit_domains.status'] = 'Dynadot Status';
$lang['Dynadot.add_row.audit_domains.client'] = 'Client';
$lang['Dynadot.add_row.audit_domains.package'] = 'Package';
$lang['Dynadot.add_row.audit_domains.result'] = 'Result';
$lang['Dynadot.add_row.audit_domains.client_auto'] = 'Auto: match registrant email';
$lang['Dynadot.add_row.audit_domains.package_auto'] = 'Auto: match TLD';
$lang['Dynadot.add_row.audit_domains.apply_all'] = 'Apply to all selected';
$lang['Dynadot.add_row.audit_domains.import_btn'] = 'Import Selected Domains';
$lang['Dynadot.add_row.audit_domains.importing'] = 'Importing...';
$lang['Dynadot.add_row.audit_domains.done'] = 'Finished. Imported: %1$s, failed: %2$s.';
$lang['Dynadot.add_row.audit_domains.no_packages'] = 'No active domain packages use this Dynadot module. Create a package for the module before you import domains.';
$lang['Dynadot.add_row.audit_domains.no_results'] = 'All domains in this Dynadot account already exist in Blesta.';
$lang['Dynadot.add_row.audit_domains.list_error'] = 'The domain list could not be fetched from Dynadot. Check the module log for details.';
$lang['Dynadot.add_row.audit_domains.missing_title'] = 'In Blesta but not in this Dynadot account';
$lang['Dynadot.add_row.audit_domains.missing_description'] = 'These active or suspended Blesta services use this Dynadot account, but the domain was not found in it. The domain may have been transferred away or may have expired.';
$lang['Dynadot.add_row.audit_domains.service'] = 'Service';

// =========================================================================
// 6. Package, Domain, Transfer & Nameserver Fields
// =========================================================================

// Package Configuration Fields
$lang['Dynadot.package_fields.type'] = 'Type';
$lang['Dynadot.package_fields.type_domain'] = 'Domain Registration';
$lang['Dynadot.package_fields.tld_options'] = 'TLDs';
$lang['Dynadot.package_fields.ns1'] = 'Name Server 1';
$lang['Dynadot.package_fields.ns2'] = 'Name Server 2';
$lang['Dynadot.package_fields.ns3'] = 'Name Server 3';
$lang['Dynadot.package_fields.ns4'] = 'Name Server 4';
$lang['Dynadot.package_fields.ns5'] = 'Name Server 5';
$lang['Dynadot.package_fields.epp_code'] = 'EPP Code';
$lang['Dynadot.package_fields.enable_epp_code'] = 'Allow User to Fetch';

// Domain Transfer Fields
$lang['Dynadot.transfer.domain'] = 'Domain Name';
$lang['Dynadot.transfer.DomainName'] = 'Domain Name';
$lang['Dynadot.transfer.EPPCode'] = 'EPP Code';

// Domain Registration & .US Nexus Fields
$lang['Dynadot.domain.domain'] = 'Domain';
$lang['Dynadot.domain.DomainAction'] = 'Domain Action';
$lang['Dynadot.domain.RegistrantNexus'] = 'Registrant Type';
$lang['Dynadot.domain.RegistrantNexus.c11'] = 'US citizen';
$lang['Dynadot.domain.RegistrantNexus.c12'] = 'Permanent resident of the US';
$lang['Dynadot.domain.RegistrantNexus.c21'] = 'US entity or organization';
$lang['Dynadot.domain.RegistrantNexus.c31'] = 'Foreign organization';
$lang['Dynadot.domain.RegistrantNexus.c32'] = 'Foreign organization with an office in the US';

$lang['Dynadot.domain.RegistrantPurpose'] = 'Purpose';
$lang['Dynadot.domain.RegistrantPurpose.p1'] = 'Business use for profit';
$lang['Dynadot.domain.RegistrantPurpose.p2'] = 'Non-profit business';
$lang['Dynadot.domain.RegistrantPurpose.p3'] = 'Personal use';
$lang['Dynadot.domain.RegistrantPurpose.p4'] = 'Education';
$lang['Dynadot.domain.RegistrantPurpose.p5'] = 'Government';

// Nameserver Input Fields
$lang['Dynadot.nameserver.ns1'] = 'Name Server 1';
$lang['Dynadot.nameserver.ns2'] = 'Name Server 2';
$lang['Dynadot.nameserver.ns3'] = 'Name Server 3';
$lang['Dynadot.nameserver.ns4'] = 'Name Server 4';
$lang['Dynadot.nameserver.ns5'] = 'Name Server 5';

// =========================================================================
// 7. WHOIS Contact Field Labels
// =========================================================================

// Generic WHOIS Labels
$lang['Dynadot.whois.FirstName'] = 'First Name';
$lang['Dynadot.whois.LastName'] = 'Last Name';
$lang['Dynadot.whois.Organization'] = 'Organization';
$lang['Dynadot.whois.Address1'] = 'Address 1';
$lang['Dynadot.whois.Address2'] = 'Address 2';
$lang['Dynadot.whois.City'] = 'City';
$lang['Dynadot.whois.StateProvince'] = 'State/Province';
$lang['Dynadot.whois.PostalCode'] = 'Postal Code';
$lang['Dynadot.whois.Country'] = 'Country';
$lang['Dynadot.whois.Phone'] = 'Phone';
$lang['Dynadot.whois.EmailAddress'] = 'Email';

// Registrant WHOIS Labels
$lang['Dynadot.whois.RegistrantFirstName'] = 'First Name';
$lang['Dynadot.whois.RegistrantLastName'] = 'Last Name';
$lang['Dynadot.whois.RegistrantOrganization'] = 'Organization';
$lang['Dynadot.whois.RegistrantAddress1'] = 'Address 1';
$lang['Dynadot.whois.RegistrantAddress2'] = 'Address 2';
$lang['Dynadot.whois.RegistrantCity'] = 'City';
$lang['Dynadot.whois.RegistrantStateProvince'] = 'State/Province';
$lang['Dynadot.whois.RegistrantPostalCode'] = 'Postal Code';
$lang['Dynadot.whois.RegistrantCountry'] = 'Country';
$lang['Dynadot.whois.RegistrantPhone'] = 'Phone';
$lang['Dynadot.whois.RegistrantEmailAddress'] = 'Email';

// Copy Registrant WHOIS labels onto Admin, Technical, and Billing sections
foreach (['Admin', 'Technical', 'Billing'] as $whois_section) {
    foreach ([
        'FirstName', 'LastName', 'Organization', 'Address1', 'Address2',
        'City', 'StateProvince', 'PostalCode', 'Country', 'Phone', 'EmailAddress'
    ] as $whois_field) {
        $lang['Dynadot.whois.' . $whois_section . $whois_field] = $lang['Dynadot.whois.Registrant' . $whois_field];
    }
}
unset($whois_section, $whois_field);

// =========================================================================
// 8. Admin & Client Service Management Tabs
// =========================================================================

// WHOIS Tab
$lang['Dynadot.tab_whois.title'] = 'Whois';
$lang['Dynadot.tab_whois.section_registrant'] = 'Registrant';
$lang['Dynadot.tab_whois.section_admin'] = 'Administrative';
$lang['Dynadot.tab_whois.section_technical'] = 'Technical';
$lang['Dynadot.tab_whois.section_billing'] = 'Billing';
$lang['Dynadot.tab_whois.field_submit'] = 'Update Whois';

// Manage Contacts Tab
$lang['Dynadot.tab_manage_contacts.title'] = 'Manage Contacts';
$lang['Dynadot.tab_manage_contacts.heading.name'] = 'Name';
$lang['Dynadot.tab_manage_contacts.heading.options'] = 'Options';
$lang['Dynadot.tab_manage_contacts.heading.add_contact'] = 'Add Contact';
$lang['Dynadot.tab_manage_contacts.option.edit'] = 'Edit';
$lang['Dynadot.tab_manage_contacts.option.delete'] = 'Delete';
$lang['Dynadot.tab_manage_contacts.confirm_delete'] = 'Are you sure you want to delete this contact?';
$lang['Dynadot.tab_manage_contacts.no_results'] = 'No contacts found.';
$lang['Dynadot.tab_manage_contacts.field_sync'] = 'Pull Contacts from Registrar';
$lang['Dynadot.tab_client_manage_contacts.title'] = 'Manage Contacts';

$lang['Dynadot.tab_edit_contact.title'] = 'Edit Contact';
$lang['Dynadot.tab_edit_contact.field_submit'] = 'Save Contact';

// Nameservers Tab
$lang['Dynadot.tab_nameservers.title'] = 'Name Servers';
$lang['Dynadot.tab_nameservers.field_ns'] = 'Name Server %1$s';
$lang['Dynadot.tab_nameservers.field_submit'] = 'Update Name Servers';

// Settings Tab (Registrar Lock & EPP Code)
$lang['Dynadot.tab_settings.title'] = 'Settings';
$lang['Dynadot.tab_settings.field_registrar_lock'] = 'Registrar Lock';
$lang['Dynadot.tab_settings.field_registrar_lock_yes'] = 'Set Registrar Lock. Recommended to prevent unauthorized transfer.';
$lang['Dynadot.tab_settings.field_registrar_lock_no'] = 'Release Registrar Lock so the domain can be transferred.';
$lang['Dynadot.tab_settings.field_request_epp'] = 'Request EPP Code/Auth Key';
$lang['Dynadot.tab_settings.field_submit'] = 'Update Settings';

// Child Nameservers (Host Names / Glue Records) Tab
$lang['Dynadot.tab_hosts.title'] = 'Host Names';
$lang['Dynadot.tab_hosts.title_add'] = 'Register a Child Name Server';
$lang['Dynadot.tab_hosts.field_hostname'] = 'Host Name';
$lang['Dynadot.tab_hosts.field_ip'] = 'IP Address';
$lang['Dynadot.tab_hosts.field_submit'] = 'Update Host Names';
$lang['Dynadot.tab_hosts.field_add'] = 'Add Host';
$lang['Dynadot.tab_hosts.field_delete'] = 'Delete';
$lang['Dynadot.tab_hosts.no_results'] = 'No child name servers are registered for this domain.';

// DNSSEC Tab
$lang['Dynadot.tab_dnssec.title'] = 'DNSSEC';
$lang['Dynadot.tab_dnssec.title_add'] = 'Add DNSSEC Record';
$lang['Dynadot.tab_dnssec.description'] = 'Add a DS record to the domain. Dynadot does not report the existing DS records through its API.';
$lang['Dynadot.tab_dnssec.title_clear'] = 'Remove DNSSEC';
$lang['Dynadot.tab_dnssec.clear_description'] = 'Removes every DNSSEC record from this domain.';
$lang['Dynadot.tab_dnssec.field_delete'] = 'Delete';
$lang['Dynadot.tab_dnssec.field_add'] = 'Add Record';
$lang['Dynadot.tab_dnssec.field_clear'] = 'Remove All DNSSEC Records';
$lang['Dynadot.dnssec.key_tag'] = 'Key Tag';
$lang['Dynadot.dnssec.algorithm'] = 'Algorithm';
$lang['Dynadot.dnssec.digest_type'] = 'Digest Type';
$lang['Dynadot.dnssec.digest'] = 'Digest';

// DNS Zone Records Tab
$lang['Dynadot.tab_dnsrecords.title'] = 'DNS Records';
$lang['Dynadot.tab_dnsrecords.title_list'] = 'DNS Records';
$lang['Dynadot.tab_dnsrecords.help_text'] = 'Saving replaces all DNS records of this domain. Leave the subdomain empty (or "@") for the domain itself. The extra value is the priority for MX records, 1 (301) or 2 (302) for FORWARD records, the page title for STEALTH records and the alias for EMAIL records.';
$lang['Dynadot.tab_dnsrecords.add_record'] = 'Add Record';
$lang['Dynadot.tab_dnsrecords.record_type'] = 'Record Type';
$lang['Dynadot.tab_dnsrecords.host'] = 'Subdomain';
$lang['Dynadot.tab_dnsrecords.value'] = 'Value';
$lang['Dynadot.tab_dnsrecords.extra'] = 'Priority / Extra';
$lang['Dynadot.tab_dnsrecords.extra_placeholder'] = 'MX priority, etc.';
$lang['Dynadot.tab_dnsrecords.options'] = 'Options';
$lang['Dynadot.tab_dnsrecords.warning_overwrite'] = 'Warning: Saving this form will overwrite all existing DNS records for this domain.';
$lang['Dynadot.tab_dnsrecords.field_submit'] = 'Update DNS Records';
$lang['Dynadot.dnsrecord.record_type'] = 'Record Type';
$lang['Dynadot.dnsrecord.host'] = 'Host';
$lang['Dynadot.dnsrecord.value'] = 'Value';

// Email Forwarding Tab
$lang['Dynadot.tab_email_forwarding.title'] = 'Email Forwarding';
$lang['Dynadot.tab_email_forwarding.title_list'] = 'Email Forwards';
$lang['Dynadot.tab_email_forwarding.help_text_no_fetch'] = 'The Dynadot API does not return existing email forwards, so they are not shown here. Saving this form replaces all existing email forwarding rules for this domain.';
$lang['Dynadot.tab_email_forwarding.add_record'] = 'Add Forward';
$lang['Dynadot.tab_email_forwarding.username'] = 'User (e.g. "admin" for admin@domain.com)';
$lang['Dynadot.tab_email_forwarding.destination'] = 'Destination Email';
$lang['Dynadot.tab_email_forwarding.options'] = 'Options';
$lang['Dynadot.tab_email_forwarding.warning_overwrite'] = 'Warning: Saving this form will overwrite all existing email forwarding rules for this domain.';
$lang['Dynadot.tab_email_forwarding.field_submit'] = 'Update Email Forwarding';
$lang['Dynadot.email_forwarding.username'] = 'User (e.g. "admin" for admin@domain.com)';
$lang['Dynadot.email_forwarding.destination'] = 'Destination Email';

// Admin Actions Tab
$lang['Dynadot.tab_adminactions.title'] = 'Admin Actions';
$lang['Dynadot.tab_admin_actions.title'] = 'Admin Actions';
$lang['Dynadot.tab_admin_actions.field_submit'] = 'Sync Renew Date';
$lang['Dynadot.tab_admin_actions.sync_date_tooltip'] = 'This will synchronize the renewal date with the registrar.';
$lang['Dynadot.tab_admin_actions.epp_title'] = 'EPP Code';
$lang['Dynadot.tab_admin_actions.epp_desc'] = 'Fetch the EPP code of this domain from Dynadot. The domain must be unlocked first.';
$lang['Dynadot.tab_admin_actions.field_epp_submit'] = 'Request EPP Code';

// Service Info & Transfer Status Panels
$lang['Dynadot.service_info.domain'] = 'Domain';
$lang['Dynadot.service_info.status'] = 'Status';
$lang['Dynadot.service_info.expiration'] = 'Expiration Date';
$lang['Dynadot.service_info.lock_status'] = 'Registrar Lock';

$lang['Dynadot.transferstatus.title'] = 'Transfer Status';
$lang['Dynadot.transferstatus.heading_status'] = 'Status';

// =========================================================================
// 9. Scheduled Cron Task Labels
// =========================================================================

$lang['Dynadot.getScheduledCronTasks.pull_contacts_name'] = 'Pull Contacts';
$lang['Dynadot.getScheduledCronTasks.pull_contacts_desc'] = 'Pulls contacts from Dynadot.';
$lang['Dynadot.getScheduledCronTasks.update_pricing_name'] = 'Update TLD Pricing';
$lang['Dynadot.getScheduledCronTasks.update_pricing_desc'] = 'Refreshes Dynadot TLD registration, transfer, and renewal prices daily.';