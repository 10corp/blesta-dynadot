<?php

/**
 * Dynadot RESTful v2 Full Domain Synchronization Management
 *
 * Encapsulates all domain attribute synchronization operations (expiration dates,
 * active nameservers, WHOIS contacts, DNS records, security lock, and privacy status)
 * directly from Dynadot's RESTful v2 API.
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.apis.commands
 * @copyright Copyright (c) 2026, 10Corp
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link https://www.10corp.com
 */
class DynadotDomainSync
{
    /**
     * @var DynadotApi
     */
    private $api;

    /**
     * Sets the API client instance.
     *
     * @param DynadotApi $api The API client
     */
    public function __construct(DynadotApi $api)
    {
        $this->api = $api;
    }

    /**
     * Retrieves domain information via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/domains/{domain}
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function getDomainInfo(array $vars)
    {
        return $this->api->submit('domain_info', $vars);
    }

    /**
     * Retrieves domain nameservers via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/domains/{domain}/nameservers
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function getNameservers(array $vars)
    {
        return $this->api->submit('get_ns', $vars);
    }

    /**
     * Retrieves WHOIS contact details via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/contacts/{contact_id}
     *
     * @param array $vars Array with 'contact_id' key
     * @return DynadotResponse
     */
    public function getContact(array $vars)
    {
        return $this->api->submit('get_contact', $vars);
    }

    /**
     * Retrieves DNS zone records and email forwarding via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/domains/{domain}/records
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function getDnsRecords(array $vars)
    {
        return $this->api->submit('get_dns', $vars);
    }

    /**
     * Fetches complete synchronized domain dataset from Dynadot RESTful v2:
     * - Dates: Expiration and Registration
     * - Nameservers: Active Name Servers list
     * - Contacts: Contact IDs and detailed profiles for Registrant, Admin, Tech, and Billing
     * - Security: Transfer Lock, WHOIS Privacy, and Auto-Renewal state
     * - DNS: Glue type, zone records, and email forwarder aliases
     *
     * @param array $vars Array with 'domain' key
     * @return array Associative array of fully normalized synchronization data
     */
    public function getFullSyncData(array $vars)
    {
        $domain = trim((string) ($vars['domain'] ?? ''));
        if ($domain === '') {
            return [
                'success' => false,
                'message' => 'Domain name is required for synchronization.'
            ];
        }

        // 1. Fetch primary domain details
        $info_response = $this->getDomainInfo(['domain' => $domain]);
        if ($info_response->status() !== 'success') {
            return [
                'success' => false,
                'domain' => $domain,
                'message' => 'Failed to fetch domain details from Dynadot: ' . $info_response->raw()
            ];
        }

        $resp_obj = $info_response->response();
        $raw_info = $resp_obj->data->domain_info ?? null;
        if (!is_object($raw_info)) {
            $raw_info = $resp_obj->DomainInfoResponse->DomainInfo ?? null;
        }

        // 2. Parse Expiration and Registration Dates
        $expiration_ms = $raw_info->expiration_date ?? ($raw_info->Expiration ?? null);
        $registration_ms = $raw_info->registration_date ?? ($raw_info->Registration ?? null);

        $dates = [
            'expires' => !empty($expiration_ms) ? date('Y-m-d H:i:s', (int) ((string) $expiration_ms / 1000)) : null,
            'registered' => !empty($registration_ms) ? date('Y-m-d H:i:s', (int) ((string) $registration_ms / 1000)) : null,
            'expiration_ms' => $expiration_ms,
            'registration_ms' => $registration_ms
        ];

        // 3. Parse Nameservers
        $ns_response = $this->getNameservers(['domain' => $domain]);
        $dns_cmd = new DynadotDomainsDns($this->api);
        $nameservers = $dns_cmd->parseNameserverList($ns_response);
        $glue_type = strtoupper((string) ($ns_response->response()->data->glue_type ?? ($raw_info->glue_info->glue_type ?? '')));
        if (empty($nameservers) && $glue_type === 'DNS') {
            $nameservers = ['ns1.dynadot.com', 'ns2.dynadot.com', 'ns1.dyna-ns.net', 'ns2.dyna-ns.net'];
        }
        $nameservers = array_values(array_unique($nameservers));

        // 4. Parse Contact IDs & Profiles
        $contact_ids = [
            'registrant' => (string) ($raw_info->registrant_contact_id ?? ($raw_info->Whois->Registrant->ContactId ?? '')),
            'admin' => (string) ($raw_info->admin_contact_id ?? ($raw_info->Whois->Admin->ContactId ?? '')),
            'technical' => (string) ($raw_info->technical_contact_id ?? ($raw_info->Whois->Technical->ContactId ?? '')),
            'billing' => (string) ($raw_info->billing_contact_id ?? ($raw_info->Whois->Billing->ContactId ?? ''))
        ];

        $contact_profiles = [];
        foreach (array_unique(array_filter($contact_ids)) as $cid) {
            $c_resp = $this->getContact(['contact_id' => $cid]);
            if ($c_resp->status() === 'success') {
                $c_obj = $c_resp->response();
                $contact = $c_obj->data->contact ?? ($c_obj->GetContactResponse->GetContact ?? null);
                if (is_object($contact)) {
                    $contact_profiles[$cid] = $this->normalizeContactProfile($contact, $cid);
                }
            }
        }

        // 5. Parse Security & Status Settings
        $locked_raw = strtolower((string) ($raw_info->locked ?? ($raw_info->Locked ?? '')));
        $locked = ($locked_raw === 'yes' || $locked_raw === '1' || $locked_raw === 'true');

        $privacy_raw = (string) ($raw_info->privacy ?? ($raw_info->Privacy ?? ''));
        $privacy = (stripos($privacy_raw, 'full') !== false || strtolower($privacy_raw) === 'yes');

        $renew_raw = (string) ($raw_info->renew_option ?? '');
        $auto_renew = (stripos($renew_raw, 'auto') !== false);

        $settings = [
            'locked' => $locked,
            'privacy' => $privacy,
            'auto_renew' => $auto_renew,
            'status' => (string) ($raw_info->status ?? ($raw_info->Status ?? 'active')),
            'transfer_lock_end_date' => $raw_info->transfer_lock_end_date ?? null
        ];

        // 6. Parse DNS Records & Forwarding
        $dns_response = $this->getDnsRecords(['domain' => $domain]);
        $dns_obj = $dns_response->response();
        $dns_main_list = $dns_obj->data->dns_main_list
            ?? ($raw_info->glue_info->dns_main_list ?? []);
        $email_alias_list = $dns_obj->data->email_alias_list
            ?? ($raw_info->glue_info->email_alias_list ?? []);

        return [
            'success' => true,
            'domain' => $domain,
            'dates' => $dates,
            'nameservers' => $nameservers,
            'contacts' => $contact_ids,
            'contact_profiles' => $contact_profiles,
            'settings' => $settings,
            'dns' => [
                'glue_type' => $glue_type,
                'records' => is_array($dns_main_list) ? $dns_main_list : [],
                'email_forwarding' => is_array($email_alias_list) ? $email_alias_list : []
            ]
        ];
    }

    /**
     * Normalizes contact fields into a standardized structure.
     *
     * @param stdClass $contact Contact object from API
     * @param string|int $cid Contact ID
     * @return array Standardized contact details array
     */
    private function normalizeContactProfile(stdClass $contact, $cid)
    {
        return [
            'contact_id' => (string) $cid,
            'name' => (string) ($contact->name ?? ($contact->Name ?? '')),
            'first_name' => (string) ($contact->first_name ?? ($contact->FirstName ?? '')),
            'last_name' => (string) ($contact->last_name ?? ($contact->LastName ?? '')),
            'organization' => (string) ($contact->organization ?? ($contact->Organization ?? '')),
            'email' => (string) ($contact->email ?? ($contact->Email ?? '')),
            'phone' => (string) ($contact->phone ?? ($contact->phone_num ?? ($contact->Phone ?? ''))),
            'address1' => (string) ($contact->address1 ?? ($contact->Address1 ?? '')),
            'address2' => (string) ($contact->address2 ?? ($contact->Address2 ?? '')),
            'city' => (string) ($contact->city ?? ($contact->City ?? '')),
            'state' => (string) ($contact->state ?? ($contact->State ?? '')),
            'zip' => (string) ($contact->zip ?? ($contact->zip_code ?? ($contact->Zip ?? ''))),
            'country' => (string) ($contact->country ?? ($contact->Country ?? ''))
        ];
    }

    /**
     * Fetches contact name mappings for queued client domains for contact sync.
     *
     * @param array $queued_client_domains Map of client_id => array of domain names
     * @return array Map of client_id => array of [contact_id => contact_name]
     */
    public function fetchClientContactsForDomains(array $queued_client_domains)
    {
        $client_contacts = [];

        foreach ($queued_client_domains as $client_id => $queued_domains) {
            foreach ($queued_domains as $queued_domain) {
                $domain_info = $this->getDomainInfo(['domain' => $queued_domain]);
                if ($domain_info->status() === 'error') {
                    continue;
                }

                $res = $domain_info->response();
                $contact_ids = [];

                $raw_info = $res->data->domain_info ?? ($res->DomainInfoResponse->DomainInfo ?? null);
                if (is_object($raw_info)) {
                    if (isset($raw_info->registrant_contact_id)) {
                        $contact_ids[] = (string) $raw_info->registrant_contact_id;
                    }
                    if (isset($raw_info->admin_contact_id)) {
                        $contact_ids[] = (string) $raw_info->admin_contact_id;
                    }
                    if (isset($raw_info->technical_contact_id)) {
                        $contact_ids[] = (string) $raw_info->technical_contact_id;
                    }
                    if (isset($raw_info->billing_contact_id)) {
                        $contact_ids[] = (string) $raw_info->billing_contact_id;
                    }

                    if (isset($raw_info->Whois)) {
                        foreach (['Registrant', 'Admin', 'Technical', 'Billing'] as $type) {
                            if (isset($raw_info->Whois->$type->ContactId)) {
                                $contact_ids[] = (string) $raw_info->Whois->$type->ContactId;
                            }
                        }
                    }
                }

                if (!isset($client_contacts[$client_id])) {
                    $client_contacts[$client_id] = [];
                }

                foreach (array_unique(array_filter($contact_ids)) as $cid) {
                    if ($cid === '0' || isset($client_contacts[$client_id][$cid])) {
                        continue;
                    }

                    $c_resp = $this->getContact(['contact_id' => $cid]);
                    if ($c_resp->status() === 'success') {
                        $c_obj = $c_resp->response();
                        $contact = $c_obj->data->contact ?? ($c_obj->GetContactResponse->GetContact ?? null);
                        if (is_object($contact)) {
                            $name = (string) ($contact->name ?? ($contact->Name ?? ''));
                            if ($name !== '') {
                                $client_contacts[$client_id][$cid] = $name;
                            }
                        }
                    }
                }
            }
        }

        return $client_contacts;
    }
}
