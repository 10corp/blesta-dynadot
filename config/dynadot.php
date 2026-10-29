<?php

/**
 * Dynadot Registrar Configuration (RESTful v2 API)
 *
 * Defines static metadata, order form field definitions, WHOIS contact mappings,
 * nameserver inputs, TLD-specific fields, and supported DNS record types used
 * throughout the Dynadot Blesta registrar module.
 *
 * Architecture Role (For Junior Developers):
 * - Loaded automatically in Dynadot::__construct() via Configure::load('dynadot', ...).
 * - Accessed via Configure::get('Dynadot.*') in dynadot.php.
 * - Provides field schemas for rendering registration, transfer, nameserver, WHOIS,
 *   and DNS forms in both Admin and Client interfaces.
 * - Maps Blesta WHOIS form inputs (RegistrantFirstName, AdminEmailAddress, etc.)
 *   to the contact payload sent by DynadotApi to POST/PUT /restful/v2/contacts.
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.config
 * @author 10Corp <cs@10corp.com>
 * @copyright Copyright (c) 2026, 10Corp
 * @link https://www.10corp.com
 */

// =========================================================================
// 1. Email Templates
// =========================================================================

// Used by Blesta when sending domain-related notification emails.
Configure::set('Dynadot.email_templates', [
    'en_us' => [
        'lang' => 'en_us',
        'text' => 'Your domain transfer authorization code is {auth_code}.',
        'html' => '<p>Your domain transfer authorization code is <strong>{auth_code}</strong>.</p>'
    ]
]);

// =========================================================================
// 2. Domain Registration & TLD-Specific Fields
// =========================================================================

// Base domain field required by Dynadot::getClientAddFields() during new orders.
Configure::set('Dynadot.domain_fields', [
    'domain' => [
        'label' => Language::_('Dynadot.domain.domain', true),
        'type' => 'text'
    ]
]);

/**
 * .US TLD Nexus Information
 * Required by the .US registry to establish US nexus eligibility:
 * - usnc: Nexus Category (Citizen, Permanent Resident, US Entity, Foreign Entity with US presence)
 * - usap: Application Purpose (Business, Personal, Non-profit, etc.)
 */
Configure::set('Dynadot.domain_fields.us', [
    'usnc' => [
        'label' => Language::_('Dynadot.domain.RegistrantNexus', true),
        'type' => 'select',
        'options' => [
            'C11' => Language::_('Dynadot.domain.RegistrantNexus.c11', true),
            'C12' => Language::_('Dynadot.domain.RegistrantNexus.c12', true),
            'C21' => Language::_('Dynadot.domain.RegistrantNexus.c21', true),
            'C31' => Language::_('Dynadot.domain.RegistrantNexus.c31', true),
            'C32' => Language::_('Dynadot.domain.RegistrantNexus.c32', true)
        ]
    ],
    'usap' => [
        'label' => Language::_('Dynadot.domain.RegistrantPurpose', true),
        'type' => 'select',
        'options' => [
            'P1' => Language::_('Dynadot.domain.RegistrantPurpose.p1', true),
            'P2' => Language::_('Dynadot.domain.RegistrantPurpose.p2', true),
            'P3' => Language::_('Dynadot.domain.RegistrantPurpose.p3', true),
            'P4' => Language::_('Dynadot.domain.RegistrantPurpose.p4', true),
            'P5' => Language::_('Dynadot.domain.RegistrantPurpose.p5', true)
        ]
    ]
]);

// =========================================================================
// 3. Nameserver Input Fields (ns1 - ns5)
// =========================================================================

// Rendered when adding/editing a domain service in Blesta.
Configure::set('Dynadot.nameserver_fields', [
    'ns1' => [
        'label' => Language::_('Dynadot.nameserver.ns1', true),
        'type' => 'text'
    ],
    'ns2' => [
        'label' => Language::_('Dynadot.nameserver.ns2', true),
        'type' => 'text'
    ],
    'ns3' => [
        'label' => Language::_('Dynadot.nameserver.ns3', true),
        'type' => 'text'
    ],
    'ns4' => [
        'label' => Language::_('Dynadot.nameserver.ns4', true),
        'type' => 'text'
    ],
    'ns5' => [
        'label' => Language::_('Dynadot.nameserver.ns5', true),
        'type' => 'text'
    ]
]);

// =========================================================================
// 4. Domain Transfer Fields
// =========================================================================

// Used by Dynadot::getAdminAddFields() and Dynadot::getClientAddFields() for transfers.
Configure::set('Dynadot.transfer_fields', [
    'domain' => [
        'label' => Language::_('Dynadot.transfer.DomainName', true)
            ?: Language::_('Dynadot.domain.domain', true),
        'type' => 'text'
    ],
    'auth' => [
        'label' => Language::_('Dynadot.transfer.EPPCode', true),
        'type' => 'text'
    ]
]);

// =========================================================================
// 5. WHOIS Contact Field Mappings
// =========================================================================
// Generates form field definitions for all 4 ICANN contact roles:
// Registrant, Admin, Technical, and Billing.
//
// Junior Dev Note:
// - Keys are formatted as "{Section}{Field}" (e.g., "RegistrantFirstName"), which
//   matches Dynadot::extractContactDataFromPost() and Dynadot::formatContactFieldsForDisplay().
// - 'lp' (local parameter) maps to Blesta's internal contact array keys.
// - 'rp' (remote parameter) is retained for backward compatibility with view templates.

$whois_sections = ['Registrant', 'Admin', 'Technical', 'Billing'];
$whois_field_map = [
    'FirstName'     => ['rp' => 'fn',  'lp' => 'first_name'],
    'LastName'      => ['rp' => 'ln',  'lp' => 'last_name'],
    'Organization'  => ['rp' => 'cp',  'lp' => 'company'],
    'Address1'      => ['rp' => 'ad',  'lp' => 'address1'],
    'Address2'      => ['rp' => 'ad2', 'lp' => 'address2'],
    'City'          => ['rp' => 'cy',  'lp' => 'city'],
    'StateProvince' => ['rp' => 'st',  'lp' => 'state'],
    'PostalCode'    => ['rp' => 'zp',  'lp' => 'zip'],
    'Country'       => ['rp' => 'ct',  'lp' => 'country'],
    'Phone'         => ['rp' => 'ph',  'lp' => 'phone'],
    'EmailAddress'  => ['rp' => 'em',  'lp' => 'email']
];

$whois_fields = [];
foreach ($whois_sections as $section) {
    foreach ($whois_field_map as $field_name => $mapping) {
        // Prefer role-specific translation (e.g., Dynadot.whois.RegistrantFirstName),
        // falling back to generic field translation (e.g., Dynadot.whois.FirstName).
        $label = Language::_('Dynadot.whois.' . $section . $field_name, true);
        if (empty($label)) {
            $label = Language::_('Dynadot.whois.' . $field_name, true);
        }

        $whois_fields[$section . $field_name] = [
            'label' => $label,
            'type'  => 'text',
            'rp'    => $mapping['rp'],
            'lp'    => $mapping['lp']
        ];
    }
}

Configure::set('Dynadot.whois_fields', $whois_fields);

// =========================================================================
// 6. Supported DNS Record Types
// =========================================================================

// Used by Dynadot::manageDnsRecords() to populate the DNS record type dropdown.
// Keys are uppercase for the UI form and normalized to lowercase in Dynadot::buildDnsArgs().
Configure::set('Dynadot.dns_records', [
    'A'       => 'A',
    'AAAA'    => 'AAAA',
    'CNAME'   => 'CNAME',
    'MX'      => 'MX',
    'TXT'     => 'TXT',
    'SRV'     => 'SRV',
    'FORWARD' => 'FORWARD',
    'STEALTH' => 'STEALTH',
    'EMAIL'   => 'EMAIL'
]);