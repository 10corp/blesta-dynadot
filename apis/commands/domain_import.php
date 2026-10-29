<?php

/**
 * Dynadot RESTful v2 Domain Import & Registrar Audit Management
 *
 * Encapsulates scanning all domains in a Dynadot account, comparing them against active
 * Blesta services, mapping TLD packages, resolving client accounts, and provisioning
 * imported domains directly into Blesta.
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.apis.commands
 * @copyright Copyright (c) 2026, 10Corp
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link https://www.10corp.com
 */
class DynadotDomainImport
{
    /**
     * @var Dynadot Parent module instance providing Blesta database models and API client factory
     */
    private $module;

    /**
     * Initializes the domain import command handler.
     *
     * @param Dynadot $module Parent module instance
     */
    public function __construct($module)
    {
        $this->module = $module;
    }

    /**
     * Resolves the module row to use as the import source.
     *
     * @param int|null $module_row_id Optional module row ID
     * @return stdClass|null
     */
    public function getImportSourceModuleRow($module_row_id = null)
    {
        if (!empty($module_row_id)) {
            $module_row = $this->module->getModuleRow((int) $module_row_id);
            if ($module_row) {
                return $module_row;
            }
        }

        $module_rows = $this->module->getModuleRows();
        if (empty($module_row_id)) {
            return $module_rows[0] ?? null;
        }

        foreach ($module_rows as $module_row) {
            if ($module_row->id == $module_row_id) {
                return $module_row;
            }
        }

        return null;
    }

    /**
     * Generates a CSRF/HMAC token for domain import AJAX calls.
     *
     * @param int $module_row_id Module row ID identifying the Dynadot account
     * @return string HMAC-SHA256 hex token string
     */
    public function getImportApiToken($module_row_id)
    {
        $secret = Configure::get('Blesta.system_key');
        if (empty($secret)) {
            if (empty($_SESSION['dynadot_import_secret'])) {
                $_SESSION['dynadot_import_secret'] = bin2hex(random_bytes(32));
            }
            $secret = $_SESSION['dynadot_import_secret'];
        }

        return hash_hmac('sha256', 'dynadot_import|' . session_id() . '|' . $module_row_id, $secret);
    }

    /**
     * Fetches domains in the Dynadot account via RESTful v2 GET /restful/v2/domains.
     *
     * @param DynadotApi $api Initialized DynadotApi client for the active Dynadot account
     * @return array|false Associative array of domain data keyed by lowercase name, or false on failure
     */
    public function fetchRegistrarDomains(DynadotApi $api)
    {
        $domains = [];
        $per_page = 100;
        $domains_register = new DynadotDomainsRegister($api);

        for ($page = 1; $page <= 500; $page++) {
            $response = $domains_register->getList(
                ['count_per_page' => $per_page, 'page_index' => $page, 'sort' => 'NameAsc']
            );

            if ($response->status() != 'success') {
                return $page == 1 ? false : $domains;
            }

            $items = $this->extractDomainListItems($response->response());
            $new_domains = 0;
            foreach ($items as $item) {
                if (empty($item->Name)) {
                    continue;
                }

                $name = strtolower(trim((string) $item->Name));
                if (isset($domains[$name])) {
                    continue;
                }

                $new_domains++;
                $domains[$name] = [
                    'domain' => $name,
                    'expiration' => !empty($item->Expiration)
                        ? date('Y-m-d H:i:s', (int) ((string) $item->Expiration / 1000))
                        : null,
                    'status' => isset($item->Status) ? (string) $item->Status : '',
                    'registrant_contact' => isset($item->Whois->Registrant->ContactId)
                        ? (string) $item->Whois->Registrant->ContactId
                        : null
                ];
            }

            if ($new_domains == 0 || count($items) < $per_page) {
                break;
            }
        }

        return $domains;
    }

    /**
     * Extracts domain objects from ListDomainInfoResponse.
     *
     * @param mixed $response Response object from API call
     * @return array Array of normalized domain objects
     */
    public function extractDomainListItems($response)
    {
        $list = $response->ListDomainInfoResponse ?? null;
        if (!$list) {
            return [];
        }

        if (isset($list->MainDomains)) {
            $items = $list->MainDomains;
        } elseif (isset($list->ListDomainInfoContent->DomainInfoList)) {
            $items = $list->ListDomainInfoContent->DomainInfoList;
        } elseif (isset($list->DomainInfoList)) {
            $items = $list->DomainInfoList;
        } else {
            return [];
        }

        if (!is_array($items)) {
            $items = [$items];
        }

        $domains = [];
        foreach ($items as $item) {
            if (isset($item->DomainInfo->Domain)) {
                $item = $item->DomainInfo->Domain;
            } elseif (isset($item->DomainInfo)) {
                $item = $item->DomainInfo;
            } elseif (isset($item->Domain)) {
                $item = $item->Domain;
            }

            if (is_object($item)) {
                $domains[] = $item;
            }
        }

        return $domains;
    }

    /**
     * Queries active/non-canceled domain services in Blesta.
     *
     * @param string|null $domain Optional fully qualified domain name filter
     * @return array Array of service records
     */
    public function findDomainServices($domain = null)
    {
        $record = $this->module->Record->select([
                'services.id',
                'services.status',
                'services.module_row_id',
                'services.client_id',
                'service_fields.value' => 'domain'
            ])->
            from('services')->
            on('service_fields.key', '=', 'domain')->
            innerJoin('service_fields', 'service_fields.service_id', '=', 'services.id', false)->
            innerJoin('clients', 'clients.id', '=', 'services.client_id', false)->
            innerJoin('client_groups', 'client_groups.id', '=', 'clients.client_group_id', false)->
            where('client_groups.company_id', '=', Configure::get('Blesta.company_id'))->
            where('services.status', '!=', 'canceled');

        if ($domain !== null) {
            $record->where('service_fields.value', '=', $domain);
        }

        return $record->fetchAll();
    }

    /**
     * Retrieves domain packages configured for this module.
     *
     * @return array Array of Blesta package objects keyed by package ID
     */
    public function getPackagesForImport()
    {
        Loader::loadModels($this->module, ['Packages']);

        $module_id = $this->module->getModule()->id ?? null;
        $package_ids = $this->module->Record->select(['packages.id'])->
            from('packages')->
            where('packages.module_id', '=', $module_id)->
            where('packages.company_id', '=', Configure::get('Blesta.company_id'))->
            fetchAll();

        $packages = [];
        foreach ($package_ids as $package_id) {
            $package = $this->module->Packages->get($package_id->id);
            if ($package && $this->isImportableDomainPackage($package)) {
                $packages[$package->id] = $package;
            }
        }

        return $packages;
    }

    /**
     * Determines whether a package can be used for a registrar domain import.
     *
     * @param stdClass $package Blesta package object
     * @return bool True if importable
     */
    public function isImportableDomainPackage($package)
    {
        $type = strtolower(trim((string) ($package->meta->type ?? '')));
        return $type === '' || $type === 'domain';
    }

    /**
     * Maps TLD extensions to Blesta package IDs.
     *
     * @param array $packages Array of package objects
     * @return array Associative array mapping ".tld" strings to package IDs
     */
    public function buildImportTldMap(array $packages)
    {
        $tld_map = [];

        try {
            if ($this->module->Record->query("SHOW TABLES LIKE 'domains_tlds'")->fetch()) {
                $tlds = $this->module->Record->select(['domains_tlds.tld', 'domains_tlds.package_id'])->
                    from('domains_tlds')->
                    where('domains_tlds.company_id', '=', Configure::get('Blesta.company_id'))->
                    fetchAll();

                foreach ($tlds as $tld) {
                    if (isset($packages[$tld->package_id])) {
                        $tld_map['.' . ltrim(strtolower($tld->tld), '.')] = $tld->package_id;
                    }
                }
            }
        } catch (\Throwable $e) {
            if (method_exists($this->module->Record, 'reset')) {
                $this->module->Record->reset();
            }
        }

        foreach ($packages as $package) {
            foreach ((array) ($package->meta->tlds ?? []) as $tld) {
                $tld = '.' . ltrim(strtolower($tld), '.');
                if (!isset($tld_map[$tld])) {
                    $tld_map[$tld] = $package->id;
                }
            }
        }

        if (empty($tld_map) && count($packages) == 1) {
            $tld_map['*'] = key($packages);
        }

        return $tld_map;
    }

    /**
     * Finds the matching Blesta package ID for a domain's TLD.
     *
     * @param string $domain Domain name
     * @param array $tld_map Map of TLDs to package IDs
     * @return int|null Matching package ID
     */
    public function findMatchingImportPackage($domain, array $tld_map)
    {
        $parts = explode('.', $domain);
        for ($i = 1; $i < count($parts); $i++) {
            $tld = '.' . implode('.', array_slice($parts, $i));
            if (isset($tld_map[$tld])) {
                return $tld_map[$tld];
            }
        }

        return $tld_map['*'] ?? null;
    }

    /**
     * Retrieves active Blesta clients for domain import assignment.
     *
     * @param int|null $client_id Optional client ID filter
     * @return array Array of client records
     */
    public function getClientsForImport($client_id = null)
    {
        $record = $this->module->Record->select([
                'clients.id',
                'contacts.first_name',
                'contacts.last_name',
                'contacts.company',
                'contacts.email'
            ])->
            from('clients')->
            on('contacts.contact_type', '=', 'primary')->
            innerJoin('contacts', 'contacts.client_id', '=', 'clients.id', false)->
            innerJoin('client_groups', 'client_groups.id', '=', 'clients.client_group_id', false)->
            where('client_groups.company_id', '=', Configure::get('Blesta.company_id'))->
            where('clients.status', '=', 'active');

        if ($client_id !== null) {
            $record->where('clients.id', '=', $client_id);
        }

        return $record->order(['clients.id' => 'ASC'])->fetchAll();
    }

    /**
     * Builds the overview data for the domain audit & import view.
     *
     * @param stdClass $module_row Module row object
     * @return array Overview data array
     */
    public function buildImportOverview($module_row)
    {
        $api = $this->module->getApi(
            null,
            $module_row->meta->key,
            $module_row->meta->sandbox == 'true',
            null,
            false,
            $module_row->meta->secret ?? null
        );
        $dynadot_domains = $this->fetchRegistrarDomains($api);

        $vars = [
            'list_error' => ($dynadot_domains === false),
            'total' => 0,
            'existing' => 0,
            'domains' => [],
            'missing' => [],
            'packages' => [],
            'clients' => []
        ];

        if ($dynadot_domains === false) {
            return $vars;
        }

        $blesta_domains = [];
        foreach ($this->findDomainServices() as $service) {
            $name = strtolower(trim($service->domain));
            $blesta_domains[$name] = true;

            if ($service->module_row_id == $module_row->id
                && in_array($service->status, ['active', 'suspended'])
                && !isset($dynadot_domains[$name])
            ) {
                $vars['missing'][] = $service;
            }
        }

        $packages = $this->getPackagesForImport();
        $tld_map = $this->buildImportTldMap($packages);

        foreach ($dynadot_domains as $name => $domain) {
            if (isset($blesta_domains[$name])) {
                $vars['existing']++;
                continue;
            }

            $domain['package_id'] = $this->findMatchingImportPackage($name, $tld_map);
            $vars['domains'][] = (object) $domain;
        }

        $vars['total'] = count($dynadot_domains);
        foreach ($packages as $package) {
            $vars['packages'][$package->id] = $package->name
                . ($package->status != 'active' ? ' (' . $package->status . ')' : '');
        }
        $vars['clients'] = $this->getClientsForImport();

        return $vars;
    }

    /**
     * Imports a single Dynadot domain into Blesta as an active service.
     *
     * @param array $params HTTP request parameters from the import AJAX call
     * @return array Result array with success flag, message, and optional service_id/service_url
     */
    public function importRegistrarDomain(array $params)
    {
        $error = function ($message) {
            return ['success' => false, 'message' => $message];
        };

        $module_row = $this->getImportSourceModuleRow($params['module_row_id'] ?? null);
        if (!$module_row
            || empty($params['token'])
            || !is_string($params['token'])
            || !hash_equals($this->getImportApiToken($module_row->id), $params['token'])
        ) {
            return $error(Language::_('Dynadot.!error.import.token', true));
        }

        $domain = strtolower(trim((string) ($params['domain'] ?? '')));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $domain)) {
            return $error(Language::_('Dynadot.!error.import.domain', true));
        }

        foreach ($this->findDomainServices($domain) as $service) {
            if (strtolower(trim($service->domain)) == $domain) {
                return $error(Language::_('Dynadot.!error.import.exists', true, $service->id));
            }
        }

        $api = $this->module->getApi(
            null,
            $module_row->meta->key,
            $module_row->meta->sandbox == 'true',
            null,
            false,
            $module_row->meta->secret ?? null
        );
        $response = $api->submit('domain_info', ['domain' => $domain]);
        $result = $response->response();
        if ($response->status() != 'success' || !isset($result->DomainInfoResponse->DomainInfo)) {
            return $error(Language::_('Dynadot.!error.import.not_found', true));
        }
        $domain_info = $result->DomainInfoResponse->DomainInfo;

        $packages = $this->getPackagesForImport();
        $package_id = $params['package_id'] ?? 'auto';
        if ($package_id == 'auto') {
            $package_id = $this->findMatchingImportPackage($domain, $this->buildImportTldMap($packages));
            if (!$package_id) {
                return $error(
                    Language::_('Dynadot.!error.import.package_no_match', true, $this->module->extractTopLevelDomain($domain))
                );
            }
        }
        if (!isset($packages[$package_id])) {
            return $error(Language::_('Dynadot.!error.import.package', true));
        }
        $package = $packages[$package_id];

        $client_param = trim((string) ($params['client_id'] ?? 'auto'));
        if ($client_param === '' || strtolower($client_param) == 'auto') {
            $email = $this->fetchContactEmail(
                $api,
                isset($domain_info->Whois->Registrant->ContactId)
                    ? (string) $domain_info->Whois->Registrant->ContactId
                    : null
            );
            if (empty($email)) {
                return $error(Language::_('Dynadot.!error.import.client_no_email', true));
            }

            $client_id = $this->findClientIdByEmailAddress($email);
            if (!$client_id) {
                return $error(Language::_('Dynadot.!error.import.client_no_match', true, $email));
            }
        } elseif (preg_match('/^#?(\d+)/', $client_param, $matches)) {
            $client_id = (int) $matches[1];
        } else {
            return $error(Language::_('Dynadot.!error.import.client', true));
        }

        $clients = $this->getClientsForImport($client_id);
        if (empty($clients)) {
            return $error(Language::_('Dynadot.!error.import.client', true));
        }
        $client = $clients[0];

        Loader::loadModels($this->module, ['Clients', 'Services']);
        $currency_setting = $this->module->Clients->getSetting($client_id, 'default_currency');
        $currency = $currency_setting->value ?? null;
        $pricing = $this->getImportPackagePricing($package, $currency);
        if (!$pricing) {
            return $error(Language::_('Dynadot.!error.import.pricing', true, $currency));
        }

        $service_id = $this->module->Services->add(
            [
                'client_id' => $client_id,
                'pricing_id' => $pricing->id,
                'module_row_id' => $module_row->id,
                'status' => 'active',
                'use_module' => 'false',
                'domain' => $domain
            ],
            null,
            false
        );

        if (($errors = $this->module->Services->errors()) || !$service_id) {
            $messages = [];
            foreach ((array) $errors as $field_errors) {
                foreach ((array) $field_errors as $message) {
                    $messages[] = $message;
                }
            }

            return $error(implode(' ', $messages));
        }

        $service_obj = (object) [
            'id' => $service_id,
            'client_id' => $client_id,
            'module_row_id' => $module_row->id,
            'domain' => $domain
        ];
        $this->module->synchronizeFullDomain($service_obj, $package);

        $client_name = trim($client->first_name . ' ' . $client->last_name);
        return [
            'success' => true,
            'service_id' => $service_id,
            'message' => Language::_('Dynadot.!success.import', true, $service_id)
                . ' ' . $client_name . (!empty($client->company) ? ' (' . $client->company . ')' : ''),
            'service_url' => $this->module->base_uri . 'clients/editservice/' . $client_id . '/' . $service_id . '/'
        ];
    }

    /**
     * Fetches a contact's email address from Dynadot RESTful v2 GET /restful/v2/contacts/{contact_id}.
     *
     * @param DynadotApi $api Initialized DynadotApi client
     * @param string|null $contact_id Contact ID
     * @return string|null
     */
    public function fetchContactEmail(DynadotApi $api, $contact_id)
    {
        if (empty($contact_id)) {
            return null;
        }

        $response = $api->submit('get_contact', ['contact_id' => $contact_id]);
        $result = $response->response();

        if ($response->status() != 'success' || empty($result->GetContactResponse->GetContact->Email)) {
            return null;
        }

        return strtolower(trim((string) $result->GetContactResponse->GetContact->Email));
    }

    /**
     * Finds a unique active Blesta client ID matching an email address.
     *
     * @param string $email Email address
     * @return int|null Client ID
     */
    public function findClientIdByEmailAddress($email)
    {
        $clients = $this->module->Record->select(['clients.id'])->
            from('contacts')->
            innerJoin('clients', 'clients.id', '=', 'contacts.client_id', false)->
            innerJoin('client_groups', 'client_groups.id', '=', 'clients.client_group_id', false)->
            where('contacts.contact_type', '=', 'primary')->
            where('contacts.email', '=', $email)->
            where('clients.status', '=', 'active')->
            where('client_groups.company_id', '=', Configure::get('Blesta.company_id'))->
            group(['clients.id'])->
            fetchAll();

        return count($clients) == 1 ? (int) $clients[0]->id : null;
    }

    /**
     * Selects the shortest yearly pricing term for a package in the client's currency.
     *
     * @param stdClass $package Package object
     * @param string $currency Currency code
     * @return stdClass|null
     */
    public function getImportPackagePricing($package, $currency)
    {
        $selected = null;
        foreach ((array) ($package->pricing ?? []) as $pricing) {
            if ($pricing->period != 'year' || $pricing->currency != $currency) {
                continue;
            }

            if (!$selected || $pricing->term < $selected->term) {
                $selected = $pricing;
            }
        }

        return $selected;
    }
}
