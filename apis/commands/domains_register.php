<?php

/**
 * Dynadot RESTful v2 Domain Registration & Contact Management
 *
 * Encapsulates domain availability search, domain creation, WHOIS contacts lifecycle,
 * and contact payload parsing directly via Dynadot RESTful v2.
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.apis.commands
 * @copyright Copyright (c) 2026, 10Corp
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link https://www.10corp.com
 */
class DynadotDomainsRegister
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
     * Checks availability of a domain name via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/domains/{domain}/search
     *
     * @param array $vars Array of parameters:
     *  - domain (string) The domain name to search
     *  - show_price (string, optional) Set '1' to return pricing. Default '1'.
     *  - currency (string, optional) Currency code. Default 'USD'.
     * @return DynadotResponse
     */
    public function check(array $vars)
    {
        return $this->api->submit('search', $vars);
    }

    /**
     * Registers a new domain name via Dynadot RESTful v2.
     *
     * Endpoint: POST /restful/v2/domains/{domain}/register
     *
     * @param array $vars Array of registration parameters:
     *  - domain (string) Domain name to register
     *  - duration (int, optional) Registration years (default 1)
     *  - privacy (string, optional) Privacy level ('full' or 'off')
     *  - registrant_contact (int) Contact ID for registrant
     *  - admin_contact (int) Contact ID for admin
     *  - technical_contact (int) Contact ID for technical
     *  - billing_contact (int) Contact ID for billing
     *  - ns0, ns1... (string, optional) Nameservers
     *  - currency (string, optional) Currency code (default 'USD')
     * @return DynadotResponse
     */
    public function create(array $vars)
    {
        return $this->api->submit('register', $vars);
    }

    /**
     * Retrieves detailed domain information via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/domains/{domain}
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function getInfo(array $vars)
    {
        return $this->api->submit('domain_info', $vars);
    }

    /**
     * Lists registered domains in the Dynadot account via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/domains
     *
     * @param array $vars Array of pagination parameters:
     *  - page (int, optional) Page number
     *  - page_size (int, optional) Page size
     * @return DynadotResponse
     */
    public function getList(array $vars = [])
    {
        return $this->api->submit('list_domain', $vars);
    }

    /**
     * Normalizes Blesta contact fields for Dynadot RESTful v2 contact creation/updating.
     *
     * @param array $data Contact field data
     * @param object|null $contacts_model Blesta Contacts model
     * @return array Standardized contact field arguments
     */
    public function buildContactArguments(array $data, $contacts_model = null)
    {
        $country = strtoupper(trim((string) ($data['country'] ?? ($data['Country'] ?? ($data['country_code'] ?? '')))));
        $phone = trim((string) ($data['phone'] ?? ($data['Phone'] ?? ($data['phone_number'] ?? ''))));
        $phone_cc = trim((string) ($data['phone_cc'] ?? ($data['PhoneCc'] ?? '')));

        if ($phone !== '' && !preg_match('/^\+\d+\./', $phone) && $country !== '' && $contacts_model) {
            $phone = $contacts_model->intlNumber($phone, $country, '.');
        }

        $phone_number = preg_replace('/[^0-9]/', '', $phone);
        if (preg_match('/^\+(\d+)\.(.+)$/', $phone, $matches)) {
            $phone_cc = $matches[1];
            $phone_number = preg_replace('/[^0-9]/', '', $matches[2]);
        } elseif (strpos($phone, '+') === 0) {
            $phone_number = preg_replace('/^' . preg_quote($phone_cc, '/') . '/', '', $phone_number);
        } elseif (strpos($phone, '00') === 0) {
            $phone_number = preg_replace('/^' . preg_quote($phone_cc, '/') . '/', '', substr($phone, 2));
        }

        $args = [
            'name' => trim(($data['first_name'] ?? ($data['FirstName'] ?? '')) . ' ' . ($data['last_name'] ?? ($data['LastName'] ?? ''))),
            'email' => $data['email'] ?? ($data['EmailAddress'] ?? ''),
            'phone_cc' => preg_replace('/[^0-9]/', '', $phone_cc),
            'phone_number' => $phone_number,
            'organization' => $data['company'] ?? ($data['Organization'] ?? ''),
            'address1' => $data['address1'] ?? ($data['Address1'] ?? ''),
            'address2' => $data['address2'] ?? ($data['Address2'] ?? ''),
            'city' => $data['city'] ?? ($data['City'] ?? ''),
            'state' => $data['state'] ?? ($data['StateProvince'] ?? ''),
            'zip' => $data['zip']
                ?? ($data['zip_code']
                ?? ($data['postal_code']
                ?? ($data['PostalCode'] ?? ''))),
            'country' => $country
        ];

        return array_filter($args, function ($value) {
            return $value !== '' && $value !== null;
        });
    }

    /**
     * Creates a new WHOIS contact profile via Dynadot RESTful v2.
     *
     * Endpoint: POST /restful/v2/contacts
     *
     * @param array $contact_data Contact fields array
     * @return DynadotResponse
     */
    public function createContact(array $contact_data)
    {
        return $this->api->submit('create_contact', $contact_data);
    }

    /**
     * Updates an existing WHOIS contact profile via Dynadot RESTful v2.
     *
     * Endpoint: PUT /restful/v2/contacts/{contact_id}
     *
     * @param int|string $contact_id Dynadot contact ID
     * @param array $contact_data Contact fields array
     * @return DynadotResponse
     */
    public function editContact($contact_id, array $contact_data)
    {
        return $this->api->submit('edit_contact', array_merge(['contact_id' => $contact_id], $contact_data));
    }

    /**
     * Retrieves a contact profile by ID via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/contacts/{contact_id}
     *
     * @param int|string $contact_id Dynadot contact ID
     * @return DynadotResponse
     */
    public function getContact($contact_id)
    {
        return $this->api->submit('get_contact', ['contact_id' => $contact_id]);
    }

    /**
     * Updates domain WHOIS contact assignments via Dynadot RESTful v2.
     *
     * Endpoint: PUT /restful/v2/domains/{domain}/contacts
     *
     * @param string $domain Domain name
     * @param array $contacts Associative array with registrant_contact_id, admin_contact_id, etc.
     * @return DynadotResponse
     */
    public function setWhois($domain, array $contacts)
    {
        return $this->api->submit('set_whois', array_merge(['domain' => $domain], $contacts));
    }

    /**
     * Extracts WHOIS contact field values for a specific section from submitted form data.
     *
     * @param array $post Form post array
     * @param string $section WHOIS section name ('Registrant', 'Admin', 'Technical', 'Billing')
     * @return array Standardized contact field values
     */
    public function extractContactDataFromPost(array $post, $section)
    {
        $first_name = trim((string) ($post[$section . 'FirstName'] ?? ''));
        $last_name = trim((string) ($post[$section . 'LastName'] ?? ''));
        $name = trim($first_name . ' ' . $last_name);

        $phone = trim((string) ($post[$section . 'Phone'] ?? ''));
        $phone_cc = '1';
        $phone_num = '';
        if (preg_match('/^\+?([0-9]{1,4})[\s.-]?([0-9]{5,15})$/', $phone, $m)) {
            $phone_cc = $m[1];
            $phone_num = $m[2];
        } else {
            $digits = preg_replace('/[^0-9]/', '', $phone);
            $phone_num = substr($digits, -10);
            if (strlen($digits) > 10) {
                $phone_cc = substr($digits, 0, strlen($digits) - 10);
            }
        }

        return [
            'name' => $name,
            'email' => trim((string) ($post[$section . 'EmailAddress'] ?? '')),
            'organization' => trim((string) ($post[$section . 'Organization'] ?? '')),
            'address1' => trim((string) ($post[$section . 'Address1'] ?? '')),
            'address2' => trim((string) ($post[$section . 'Address2'] ?? '')),
            'city' => trim((string) ($post[$section . 'City'] ?? '')),
            'state' => trim((string) ($post[$section . 'StateProvince'] ?? '')),
            'zip' => trim((string) ($post[$section . 'PostalCode'] ?? '')),
            'country' => strtoupper(trim((string) ($post[$section . 'Country'] ?? ''))),
            'phone_cc' => $phone_cc,
            'phone_num' => $phone_num
        ];
    }

    /**
     * Formats a Dynadot contact API object into template form field values for display.
     *
     * @param object $contact Contact object from API response
     * @param string $section Section prefix ('Registrant', 'Admin', 'Technical', 'Billing')
     * @return array Form variables array
     */
    public function formatContactFieldsForDisplay($contact, $section)
    {
        $name_parts = explode(' ', trim((string) ($contact->Name ?? ($contact->name ?? ''))), 2);
        $phone = preg_replace('/[^0-9]/', '', (string) ($contact->PhoneNum ?? ($contact->phone_num ?? ($contact->phone_number ?? ''))));
        $cc = (string) ($contact->PhoneCc ?? ($contact->phone_cc ?? '1'));

        return [
            $section . 'FirstName' => $name_parts[0] ?? '',
            $section . 'LastName' => $name_parts[1] ?? '',
            $section . 'EmailAddress' => (string) ($contact->Email ?? ($contact->email ?? '')),
            $section . 'Phone' => $phone !== '' ? '+' . $cc . '.' . $phone : '',
            $section . 'Organization' => (string) ($contact->Organization ?? ($contact->organization ?? '')),
            $section . 'Address1' => (string) ($contact->Address1 ?? ($contact->address1 ?? '')),
            $section . 'Address2' => (string) ($contact->Address2 ?? ($contact->address2 ?? '')),
            $section . 'City' => (string) ($contact->City ?? ($contact->city ?? '')),
            $section . 'StateProvince' => (string) ($contact->State ?? ($contact->state ?? '')),
            $section . 'PostalCode' => (string) ($contact->ZipCode ?? ($contact->zip ?? ($contact->zip_code ?? ''))),
            $section . 'Country' => (string) ($contact->Country ?? ($contact->country ?? ''))
        ];
    }
}
