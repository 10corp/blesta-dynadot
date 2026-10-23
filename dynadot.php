<?php

/**
 * Blesta registrar integration for Dynadot (RESTful v2 API).
 *
 * This is the main module class. It connects Blesta's billing and provisioning
 * lifecycle (registration, transfer, renewal, cancellation, and client/admin
 * management tabs) to Dynadot's RESTful v2 API.
 *
 * The API transport is implemented in apis/dynadot_api.php, which maps module
 * commands to RESTful v2 HTTP methods, resource URLs, JSON payloads, and
 * HMAC-SHA256 X-Signature headers for Live or Sandbox endpoints.
 * API responses are normalized by apis/dynadot_response.php. Configuration,
 * language strings, and templates are loaded from config/, language/, and views/.
 *
 * RESTful v2 uses https://api.dynadot.com/restful/v2/... for Live and
 * https://api-sandbox.dynadot.com/restful/v2/... for Sandbox, authenticated via
 * Bearer Token (API Key) and HMAC-SHA256 (API Secret).
 */

class Dynadot extends RegistrarModule
{
    /**
     * Reading guide for maintainers:
     * - Blesta calls the public service methods during provisioning and renewal.
     * - Those methods convert Blesta package/service data into command arguments.
     * - DynadotApi routes and signs the RESTful v2 request and DynadotResponse normalizes the result.
     * - Management-tab methods use the same helpers, so client and admin actions
     *   follow the same validation, logging, and error-handling path.
     */

    // =========================================================================
    // Module State and Dependencies
    // =========================================================================

    // Dynadot's default nameservers are used by DNS and forwarding features.
    private $nameservers = [
        'ns1.dynadot.com',
        'ns2.dynadot.com',
        'ns1.dyna-ns.net',
        'ns2.dyna-ns.net'
    ];

    // Blesta resolves module views relative to this directory.
    private static $defaultModuleView;

    // The API client is created lazily for the active module row.
    private $api;

    // Avoid requesting the same domain details more than once per request.
    private static $domain_info_cache = [];

    /**
     * Bootstrap dependencies and module configuration when Blesta creates the module.
     */
    public function __construct()
    {
        $this->loadConfig(__DIR__ . DS . 'config.json');

        Loader::loadModels($this, ['PluginManager', 'Clients']);

        Loader::loadComponents($this, ['Input', 'Record']);

        Language::loadLang('dynadot', null, __DIR__ . DS . 'language' . DS);

        Configure::load('dynadot', __DIR__ . DS . 'config' . DS);

        self::$defaultModuleView = 'components' . DS . 'modules' . DS . 'dynadot' . DS;
    }

    // =========================================================================
    // Module Installation and Cron
    // =========================================================================

    /**
     * Handles the install step in the Blesta-to-Dynadot registrar workflow.
     */
    public function install()
    {
        $this->registerCronTasks($this->getScheduledCronTasks());
        return [];
    }

    /**
     * Handles the upgrade step in the Blesta-to-Dynadot registrar workflow.
     */
    public function upgrade($current_version)
    {
        if (version_compare($this->getVersion(), $current_version, '>')) {
            if (!isset($this->Record)) {
                Loader::loadComponents($this, ['Record']);
            }

            $this->registerCronTasks($this->getScheduledCronTasks());
        }
    }

    /**
     * Handles the uninstall step in the Blesta-to-Dynadot registrar workflow.
     */
    public function uninstall($module_id, $last_instance)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }
        Loader::loadModels($this, ['CronTasks']);

        $cron_tasks = $this->getScheduledCronTasks();

        if ($last_instance) {
            foreach ($cron_tasks as $task) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $this->CronTasks->deleteTask($cron_task->id, $task['task_type'], $task['dir']);
                }
            }
        }

        foreach ($cron_tasks as $task) {
            $cron_task_run = $this->CronTasks
                ->getTaskRunByKey($task['key'], $task['dir'], false, $task['task_type']);
            if ($cron_task_run) {
                $this->CronTasks->deleteTaskRun($cron_task_run->task_run_id);
            }
        }
    }

    /**
     * Handles the cron step in the Blesta-to-Dynadot registrar workflow.
     */
    public function cron($key)
    {
        if ($key == 'pull_contacts') {
            $this->synchronizeContacts();
        } elseif ($key == 'update_pricing') {
            $this->refreshPricingCache();
        }
    }

    /**
     * Returns scheduled cron task definitions.
     */
    private function getScheduledCronTasks()
    {
        return [
            [
                'key' => 'pull_contacts',
                'task_type' => 'module',
                'dir' => 'dynadot',
                'name' => Language::_('Dynadot.getScheduledCronTasks.pull_contacts_name', true),
                'description' => Language::_('Dynadot.getScheduledCronTasks.pull_contacts_desc', true),
                'type' => 'interval',
                'type_value' => 10,
                'enabled' => 1
            ],
            [
                'key' => 'update_pricing',
                'task_type' => 'module',
                'dir' => 'dynadot',
                'name' => Language::_('Dynadot.getScheduledCronTasks.update_pricing_name', true),
                'description' => Language::_('Dynadot.getScheduledCronTasks.update_pricing_desc', true),
                'type' => 'interval',
                'type_value' => 1440,
                'enabled' => 1
            ]
        ];
    }

    /**
     * Registers cron tasks with Blesta.
     */
    private function registerCronTasks(array $tasks)
    {
        Loader::loadModels($this, ['CronTasks']);
        foreach ($tasks as $task) {
            $task_id = $this->CronTasks->add($task);

            if (!$task_id) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $task_id = $cron_task->id;
                }
            }

            if ($task_id) {
                $task_vars = ['enabled' => $task['enabled']];
                if ($task['type'] === 'time') {
                    $task_vars['time'] = $task['type_value'];
                } else {
                    $task_vars['interval'] = $task['type_value'];
                }

                $this->CronTasks->addTaskRun($task_id, $task_vars);
            }
        }
    }

    /**
     * Keep Blesta contact data in sync with contacts stored at Dynadot.
     *
     * Dynadot RESTful v2 operation: GET /restful/v2/domains/{domain_name} & GET /restful/v2/contacts/{contact_id}
     */
    private function synchronizeContacts()
    {
        $module_rows = $this->Record->from('module_rows')->
            select(['module_rows.*'])->
            innerJoin('modules', 'modules.id', '=', 'module_rows.module_id', false)->
            where('modules.company_id', '=', Configure::get('Blesta.company_id'))->
            where('modules.class', '=', 'dynadot')->
            fetchAll();

        Loader::loadModels($this, ['ModuleManager']);

        $remaining_batch_slots = 25;
        foreach ($module_rows as $module_row) {
            $module_row = $this->ModuleManager->getConfiguredModuleRow($module_row->id);
            if (empty($module_row->meta->key)) {
                continue;
            }

            $remaining_batch_slots = $this->synchronizeContactsForModuleRow($module_row, $remaining_batch_slots);
            if ($remaining_batch_slots <= 0) {
                break;
            }
        }
    }

    /**
     * Synchronizes contacts for a specific module row.
     */
    private function synchronizeContactsForModuleRow($module_row, $remaining_batch_slots = null, $client_id = null, $additional_domains = [])
    {
        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }

        $record = $this->Record->from('services')->
            select(['services.*', 'service_fields.value' => 'domain'])->
            on('service_fields.key', '=', 'domain')->
            innerJoin('service_fields', 'service_fields.service_id', '=', 'services.id', false)->
            on('module_client_meta.key', '=', 'contacts')->
            on('module_client_meta.client_id', '=', 'services.client_id', false)->
            leftJoin('module_client_meta', 'module_client_meta.module_row_id', '=', 'services.module_row_id', false)->
            where('module_client_meta.value', '=', null)->
            where('services.module_row_id', '=', $module_row->id ?? null)->
            where('services.status', '=', 'active');

        if ($client_id) {
            $record->where('services.client_id', '=', $client_id);
        }

        $services = $record->fetchAll();

        $client_domains = [];
        foreach ($services as $service) {
            if (empty($client_domains[$service->client_id])) {
                $client_domains[$service->client_id] = [];
            }

            $client_domains[$service->client_id][] = $service->domain;
        }

        $queued_client_domains = [];
        foreach ($client_domains as $cid => $domains) {
            if (!is_null($remaining_batch_slots)
                && !empty($queued_client_domains)
                && (count($queued_client_domains) + count($domains)) > $remaining_batch_slots
            ) {
                break;
            }
            if ($remaining_batch_slots !== null) {
                $remaining_batch_slots -= count($domains);
            }

            $queued_client_domains[$cid] = array_merge($domains, $additional_domains[$cid] ?? []);
        }

        foreach ($additional_domains as $cid => $additional_domain) {
            $queued_client_domains[$cid] = array_merge($queued_client_domains[$cid] ?? [], $additional_domain);
        }

        $this->synchronizeContactsForQueuedDomains($queued_client_domains, $module_row);

        return $remaining_batch_slots;
    }

    /**
     * Fetches domain WHOIS contact IDs and contact details from Dynadot RESTful v2.
     */
    private function synchronizeContactsForQueuedDomains($queued_client_domains, $module_row)
    {
        $api = $this->getApi(
            null,
            $module_row->meta->key,
            $module_row->meta->sandbox == 'true',
            null,
            false,
            $module_row->meta->secret ?? null
        );
        $client_contacts = [];

        foreach ($queued_client_domains as $client_id => $queued_domains) {
            foreach ($queued_domains as $queued_domain) {
                $domainInfo = $api->submit('domain_info', ['domain' => $queued_domain]);

                if ($domainInfo->status() == 'error') {
                    if (!$this->isDomainPendingResponse($api, $domainInfo)) {
                        $this->processApiResponse($api, $domainInfo);
                    } else {
                        $this->logApiRequest($api, $domainInfo);
                    }
                    continue;
                }

                $res = $domainInfo->response();
                $contact_ids = [];
                if (isset($res->DomainInfoResponse->DomainInfo->Whois)) {
                    $whois = $res->DomainInfoResponse->DomainInfo->Whois;
                    foreach (['Registrant', 'Admin', 'Technical', 'Billing'] as $type) {
                        if (isset($whois->$type->ContactId)) {
                            $contact_ids[] = (string)$whois->$type->ContactId;
                        }
                    }
                }

                if (!isset($client_contacts[$client_id])) {
                    $client_contacts[$client_id] = [];
                }

                foreach ($contact_ids as $contact_id) {
                    if ($contact_id == '0' || $contact_id === '') {
                        continue;
                    }
                    if (array_key_exists($contact_id, $client_contacts[$client_id])) {
                        continue;
                    }

                    $contactsInfo = $api->submit('get_contact', ['contact_id' => $contact_id]);
                    if ($contactsInfo->status() == 'error') {
                        continue;
                    }

                    $contact_response = $contactsInfo->response();
                    if (isset($contact_response->GetContactResponse->GetContact)) {
                        $c = $contact_response->GetContactResponse->GetContact;
                        $client_contacts[$client_id][$contact_id] = (string)$c->Name;
                    }
                }
            }
        }

        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }

        foreach ($client_contacts as $contact_client_id => $contacts) {
            $existing = $this->ModuleClientMeta->get($contact_client_id, 'contacts', $module_row->module_id, $module_row->id);
            if ($existing) {
                $contacts = $contacts + (array) json_decode($existing->value, true);
            }

            $this->Record->duplicate('module_id', '=', $module_row->module_id)->
                duplicate('module_row_id', '=', $module_row->id)->
                duplicate('client_id', '=', $contact_client_id)->
                duplicate('key', '=', 'contacts')->
                insert(
                    'module_client_meta',
                    [
                        'module_id' => $module_row->module_id,
                        'module_row_id' => $module_row->id,
                        'client_id' => $contact_client_id,
                        'key' => 'contacts',
                        'value' => json_encode($contacts)
                    ]
                );
        }
    }

    // =========================================================================
    // Nameservers and Domain Validation
    // =========================================================================

    /**
     * Retrieves the domain's nameservers via RESTful v2 GET /restful/v2/domains/{domain_name}/nameservers.
     */
    public function getDomainNameServers($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $response = $api->submit('get_ns', ['domain' => $domain]);
        if (!$this->isDomainPendingResponse($api, $response)) {
            $this->processApiResponse($api, $response);
        }
        $result = $response->response();

        $nameservers = [];
        if (isset($result->GetNsResponse->NsContent)) {
            $hosts = [];
            foreach ((array)$result->GetNsResponse->NsContent as $key => $value) {
                if (strpos($key, 'Host') === 0) {
                    $hosts[] = $value;
                }
            }

            foreach ($hosts as $ns) {
                $nameservers[] = [
                    'url' => trim($ns),
                    'ips' => []
                ];
            }
        }

        if (empty($nameservers)) {
            $raw = $result->nameservers
                ?? ($result->name_server_list
                ?? ($result->data->nameservers ?? ($result->data->name_server_list ?? [])));
            if (!is_array($raw)) {
                $raw = is_object($raw) ? (array) $raw : [];
            }
            foreach ($raw as $value) {
                $host = is_object($value)
                    ? ($value->server_name
                        ?? ($value->nameserver
                        ?? ($value->name_server
                        ?? ($value->host ?? ''))))
                    : (string) $value;
                $host = trim($host);
                if ($host !== '') {
                    $nameservers[] = ['url' => $host, 'ips' => []];
                }
            }
        }

        return $nameservers;
    }

    /**
     * Updates a domain's nameservers via RESTful v2 PUT /restful/v2/domains/{domain_name}/nameservers.
     */
    public function setDomainNameservers($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $nameservers = [];
        foreach ($vars as $ns) {
            if (is_array($ns) || is_object($ns)) {
                $ns = $ns['url'] ?? ($ns->url ?? ($ns['host'] ?? ($ns->host ?? '')));
            }
            $ns = strtolower(rtrim(trim((string) $ns), '.'));
            if ($ns === '') {
                continue;
            }

            if (!$this->isHostnameValid($ns)) {
                $this->Input->setErrors(
                    ['ns' => ['valid' => Language::_('Dynadot.!error.ns.valid', true, $ns)]]
                );
                return false;
            }
            $nameservers[] = $ns;
        }
        $nameservers = array_values(array_unique($nameservers));

        if (count($nameservers) < 2 || count($nameservers) > 13) {
            $this->Input->setErrors(['ns' => ['count' => Language::_('Dynadot.!error.ns.count', true)]]);
            return false;
        }

        $this->addMissingNameserversToAccount($api, $nameservers);

        $args = ['domain' => $domain];
        foreach ($nameservers as $i => $ns) {
            $args['ns' . $i] = $ns;
        }

        $response = $api->submit('set_ns', $args);
        if (!$this->isDomainPendingResponse($api, $response)) {
            $this->processApiResponse($api, $response);
        }
        $this->clearDomainInfoCacheEntry($domain);

        return $response->status() == 'success';
    }

    /**
     * Identifies the transient response returned while a newly registered
     * domain is not yet visible in the Dynadot account.
     */
    private function isDomainPendingResponse(DynadotApi $api, DynadotResponse $response)
    {
        if ($api->httpcode != 404 || $response->status() != 'error') {
            if ($api->httpcode != 409 || $response->status() != 'error') {
                return false;
            }
        }

        foreach ((array) $response->errors() as $error) {
            if (stripos((string) $error, 'in the account') !== false
                || stripos((string) $error, 'not found') !== false
                || stripos((string) $error, 'can not find') !== false
                || stripos((string) $error, 'initialization is still in progress') !== false
                || stripos((string) $error, 'try again later') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registers external nameservers in the Dynadot account if not already present.
     */
    private function addMissingNameserversToAccount(DynadotApi $api, array $nameservers)
    {
        $response = $api->submit('server_list');
        $this->logApiRequest($api, $response);

        $existing = [];
        foreach ($this->extractNameserverList($response) as $server) {
            if (isset($server->ServerName)) {
                $existing[strtolower((string) $server->ServerName)] = true;
            }
        }

        foreach ($nameservers as $ns) {
            if (isset($existing[$ns]) || in_array($ns, $this->nameservers)) {
                continue;
            }

            $add_response = $api->submit('add_ns', ['host' => $ns]);
            $this->logApiRequest($api, $add_response);
        }
    }

    /**
     * Extracts nameserver objects from the normalized ServerListResponse.
     */
    private function extractNameserverList(DynadotResponse $response)
    {
        $result = $response->response();
        if ($response->status() != 'success' || !isset($result->ServerListResponse->ServerList)) {
            return [];
        }

        $servers = $result->ServerListResponse->ServerList;

        return is_array($servers) ? $servers : [$servers];
    }

    // =========================================================================
    // Service Lifecycle
    // =========================================================================

    /**
     * Validates domain service parameters before provisioning.
     */
    public function validateService($package, ?array $vars = null)
    {
        $rules = [];

        if (isset($vars['transfer']) && ($vars['transfer'] == '1' || $vars['transfer'] == true)) {
            $rule = [
                'auth' => [
                    'empty' => [
                        'rule' => ['isEmpty'],
                        'negate' => true,
                        'message' => Language::_('Dynadot.!error.epp.empty', true),
                        'post_format' => 'trim'
                    ]
                ],
            ];
            $rules = array_merge($rules, $rule);
        }

        if (isset($vars['domain']) && !$this->isDomainNameValid($vars['domain'])) {
            $this->Input->setErrors(['domain' => ['valid' => Language::_('Dynadot.!error.domain.valid', true)]]);
            return false;
        }

        if (isset($rules) && count($rules) > 0) {
            $this->Input->setRules($rules);
            return $this->Input->validates($vars);
        }

        return true;
    }

    /**
     * Provisions a new domain registration or transfer via RESTful v2.
     */
    public function addService(
        $package,
        ?array $vars = null,
        $parent_package = null,
        $parent_service = null,
        $status = 'pending'
    ) {
        $row = $this->getModuleRow();

        $tld = null;
        if (isset($vars['domain'])) {
            $vars['domain'] = strtolower(trim($vars['domain']));
            $tld = $this->extractTopLevelDomain($vars['domain']);
        }

        if (isset($vars['use_module']) && $vars['use_module'] == 'true') {
            if ($package->meta->type == 'domain') {
                if (empty($vars['domain']) || empty($tld) || !$this->isDomainNameValid($vars['domain'])) {
                    $this->Input->setErrors(
                        ['domain' => ['valid' => Language::_('Dynadot.!error.domain.valid', true)]]
                    );
                    return;
                }

                $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

                $vars['years'] = 1;

                foreach ($package->pricing as $pricing) {
                    if ($pricing->id == $vars['pricing_id']) {
                        $vars['years'] = $pricing->term;
                        break;
                    }
                }

                $contact_id = $this->findContactIdForClient($vars['client_id'] ?? null, $api, $row);

                if ($this->Input->errors()) {
                    return;
                }

                $contact_id = $contact_id
                    ?: ($vars['registrant_contact_id'] ?? ($vars['contact_id'] ?? null));
                if (empty($contact_id)) {
                    $this->Input->setErrors(
                        ['registrant_contact' => ['valid' => Language::_('Dynadot.!error.registrant_contact.valid', true)]]
                    );
                    return;
                }

                if ($contact_id) {
                    $vars['registrant_contact'] = $contact_id;
                    $vars['admin_contact'] = $contact_id;
                    $vars['technical_contact'] = $contact_id;
                    $vars['billing_contact'] = $contact_id;
                }

                $is_transfer = !empty($vars['auth'])
                    && (!isset($vars['transfer']) || (string) $vars['transfer'] !== '0');

                if (!$this->isValidTerm($tld, $vars['years'] ?? 1, $is_transfer)) {
                    $this->Input->setErrors(
                        ['term' => ['invalid' => Language::_('Dynadot.!error.invalid_term', true)]]
                    );
                    return;
                }

                if ($is_transfer) {
                    if (!$this->checkTransferAvailability($vars['domain'], $row->id)) {
                        $this->Input->setErrors(
                            ['domain' => ['unavailable' => Language::_('Dynadot.!error.domain.unavailable', true)]]
                        );
                        return;
                    }

                    $this->transferDomain($vars['domain'], $row->id, $vars);
                } else {
                    if (!$this->checkAvailability($vars['domain'], $row->id)) {
                        $this->Input->setErrors(
                            ['domain' => ['unavailable' => Language::_('Dynadot.!error.domain.unavailable', true)]]
                        );
                        return;
                    }

                    $this->registerDomain($vars['domain'], $row->id, $vars);
                }

                if (!$this->Input->errors() && !empty($vars['client_id'])) {
                    $this->synchronizeContactsForModuleRow(
                        $row,
                        null,
                        $vars['client_id'],
                        [$vars['client_id'] => [$vars['domain']]]
                    );
                }
            }
        }

        $meta = [];
        $fields = ['domain', 'auth', 'ns1', 'ns2', 'ns3', 'ns4', 'ns5'];
        foreach ($vars as $key => $value) {
            if (in_array($key, $fields)) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => ($key == 'auth' ? 1 : 0)
                ];
            }
        }

        return $meta;
    }

    /**
     * Updates service settings (nameservers, renewal, WHOIS privacy) via RESTful v2.
     */
    public function editService($package, $service, array $vars = [], $parent_package = null, $parent_service = null)
    {
        if (isset($vars['use_module']) && $vars['use_module'] != 'true') {
            return null;
        }

        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        $renew = isset($vars['renew']) ? (int) $vars['renew'] : 0;
        if ($renew > 0) {
            $this->renewService($package, $service, $parent_package, $parent_service, $renew);
            unset($vars['renew']);
        }

        $stored = [];
        foreach ((array) ($service->fields ?? []) as $field) {
            $stored[$field->key] = $field->value;
        }

        $ns = [];
        $ns_changed = false;
        for ($i = 1; $i <= 5; $i++) {
            $value = strtolower(trim((string) ($vars['ns' . $i] ?? '')));
            if ($value !== '') {
                $ns[] = $value;
            }
            if (array_key_exists('ns' . $i, $vars) && $value !== strtolower(trim((string) ($stored['ns' . $i] ?? '')))) {
                $ns_changed = true;
            }
        }

        $meta = null;
        if ($ns_changed && count($ns) >= 2
            && $this->setDomainNameservers($domain, $service->module_row_id, $ns)
        ) {
            $meta = [];
            foreach (['domain', 'auth', 'ns1', 'ns2', 'ns3', 'ns4', 'ns5'] as $key) {
                $value = (strpos($key, 'ns') === 0)
                    ? ($ns[(int) substr($key, 2) - 1] ?? '')
                    : ($stored[$key] ?? null);

                if ($value !== null) {
                    $meta[] = ['key' => $key, 'value' => $value, 'encrypted' => ($key == 'auth' ? 1 : 0)];
                }
            }
        }

        if (isset($vars['configoptions']['id_protection'])) {
            $privacy = $vars['configoptions']['id_protection'] ? 'full' : 'off';
            $whois_privacy = $vars['configoptions']['id_protection'] ? 'yes' : 'no';

            $response = $api->submit('set_privacy', [
                'domain' => $domain,
                'option' => $privacy,
                'whois_privacy_option' => $whois_privacy
            ]);
            $this->processApiResponse($api, $response);
            $this->clearDomainInfoCacheEntry($domain);
        }

        return $meta;
    }

    /**
     * Disables auto-renew on domain cancellation via RESTful v2 PUT /restful/v2/domains/{domain_name}/renew_option.
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        if ($package->meta->type == 'domain') {
            $domain = $this->getServiceDomain($service);
            $response = $api->submit('set_renew_option', ['domain' => $domain, 'renew_option' => 'donot']);
            $this->processApiResponse($api, $response);
        }
        return;
    }

    /**
     * Suspends a domain service by disabling auto-renewal.
     */
    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->cancelService($package, $service, $parent_package, $parent_service);
    }

    /**
     * Unsuspends a domain service by re-enabling auto-renewal.
     */
    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        if ($package->meta->type == 'domain') {
            $domain = $this->getServiceDomain($service);
            $response = $api->submit('set_renew_option', ['domain' => $domain, 'renew_option' => 'auto']);
            $this->processApiResponse($api, $response);
        }
        return;
    }

    /**
     * Renews a domain via RESTful v2 POST /restful/v2/domains/{domain_name}/renew.
     */
    public function renewService($package, $service, $parent_package = null, $parent_service = null, $years = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        if ($package->meta->type == 'domain') {
            $domain = $this->getServiceDomain($service);

            if (!$years) {
                foreach ($package->pricing as $pricing) {
                    if ($pricing->id == $service->pricing_id) {
                        $years = $pricing->term;
                        break;
                    }
                }
            }

            $vars = [
                'domain' => $domain,
                'duration' => $years
            ];

            $this->renewDomain($domain, $row->id, $vars);
        }

        return null;
    }

    /**
     * Stores package meta fields when adding a package.
     */
    public function addPackage(?array $vars = null)
    {
        $meta = [];
        if (isset($vars['meta']) && is_array($vars['meta'])) {
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                ];
            }
        }

        return $meta;
    }

    /**
     * Stores package meta fields when editing a package.
     */
    public function editPackage($package, ?array $vars = null)
    {
        $meta = [];
        if (isset($vars['meta']) && is_array($vars['meta'])) {
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                ];
            }
        }

        return $meta;
    }

    // =========================================================================
    // Module Row and Import Management
    // =========================================================================

    /**
     * Renders the module management screen.
     */
    public function manageModule($module, array &$vars)
    {
        Loader::loadModels($this, ['Languages', 'Settings', 'Currencies', 'Packages']);

        $this->view = new View('manage', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        $link_buttons = [];
        foreach ($module->rows as $row) {
            if (isset($row->meta->key)) {
                $link_buttons = [
                    [
                        'name' => Language::_('Dynadot.manage.audit_domains', true),
                        'attributes' => [
                            'href' => $this->base_uri . 'settings/company/modules/addrow/' . $module->id .
                                '?action=audit_domains&module_row_id=' . $row->id
                        ]
                    ]
                ];
                break;
            }
        }

        $link_buttons[] = [
            'name' => Language::_('Dynadot.add_row.add_btn', true),
            'attributes' => ['href' => $this->base_uri . 'settings/company/modules/addrow/' . $module->id]
        ];

        $this->view->set('module', $module);
        $this->view->set('link_buttons', $link_buttons);

        return $this->view->fetch();
    }

    /**
     * Renders the add module row or domain audit/import screen.
     */
    public function manageAddRow(array &$vars)
    {
        $action = isset($_GET['action']) ? $_GET['action'] : null;
        if (!in_array($action, ['audit_domains', 'import_domain'], true)) {
            $action = null;
        }

        $this->view = new View(($action == 'audit_domains' ? 'audit_domains' : 'add_row'), 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget', 'Date']);
        Loader::loadModels($this, ['Services', 'ModuleManager', 'Clients', 'ClientGroups']);

        if ($action == 'audit_domains') {
            $module_row = $this->getImportSourceModuleRow($_GET['module_row_id'] ?? null);
            if (!$module_row) {
                $this->view = new View('add_row', 'default');
                $this->view->base_uri = $this->base_uri;
                $this->view->setDefaultView(self::$defaultModuleView);
                Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
                $this->view->set('module', $this->getModule());
                $this->view->set('vars', (object) ['sandbox' => 'false']);
                return $this->view->fetch();
            }

            $this->view->set('vars', (object) $this->buildImportOverview($module_row));
            $this->view->set('module_row', $module_row);
            $this->view->set('module_id', $this->getModule()->id ?? null);
            $this->view->set('import_token', $this->getImportApiToken($module_row->id));

            return $this->view->fetch();
        } elseif ($action == 'import_domain') {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            exit(json_encode($this->importRegistrarDomain($_GET)));
        } else {
            if (empty($vars['sandbox'])) {
                $vars['sandbox'] = 'false';
            }

            $this->view->set('module', $this->getModule());
            $this->view->set('vars', (object) $vars);
            return $this->view->fetch();
        }
    }

    /**
     * Resolves the module row used for domain auditing/importing.
     */
    private function getImportSourceModuleRow($module_row_id = null)
    {
        if (!empty($module_row_id)) {
            $module_row = $this->getModuleRow((int) $module_row_id);
            if ($module_row) {
                return $module_row;
            }
        }

        $module_rows = $this->getModuleRows();
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
     */
    private function getImportApiToken($module_row_id)
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
     */
    private function fetchRegistrarDomains(DynadotApi $api)
    {
        $domains = [];
        $per_page = 100;

        for ($page = 1; $page <= 500; $page++) {
            $response = $api->submit(
                'list_domain',
                ['count_per_page' => $per_page, 'page_index' => $page, 'sort' => 'NameAsc']
            );
            $this->logApiRequest($api, $response);

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
     */
    private function extractDomainListItems($response)
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
     */
    private function findDomainServices($domain = null)
    {
        $record = $this->Record->select([
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
     */
    private function getPackagesForImport()
    {
        Loader::loadModels($this, ['Packages']);

        $package_ids = $this->Record->select(['packages.id'])->
            from('packages')->
            where('packages.module_id', '=', $this->getModule()->id ?? null)->
            where('packages.company_id', '=', Configure::get('Blesta.company_id'))->
            fetchAll();

        $packages = [];
        foreach ($package_ids as $package_id) {
            $package = $this->Packages->get($package_id->id);
            if ($package && $this->isImportableDomainPackage($package)) {
                $packages[$package->id] = $package;
            }
        }

        return $packages;
    }

    /**
     * Determines whether a package can be used for a registrar domain import.
     *
     * Older Blesta domain packages do not always expose meta->type, so the
     * module association is the primary filter and the explicit type is only
     * rejected when it identifies a non-domain package.
     */
    private function isImportableDomainPackage($package)
    {
        $type = strtolower(trim((string) ($package->meta->type ?? '')));
        return $type === '' || $type === 'domain';
    }

    /**
     * Maps TLD extensions to Blesta package IDs.
     */
    private function buildImportTldMap(array $packages)
    {
        $tld_map = [];

        try {
            if ($this->Record->query("SHOW TABLES LIKE 'domains_tlds'")->fetch()) {
                $tlds = $this->Record->select(['domains_tlds.tld', 'domains_tlds.package_id'])->
                    from('domains_tlds')->
                    where('domains_tlds.company_id', '=', Configure::get('Blesta.company_id'))->
                    fetchAll();

                foreach ($tlds as $tld) {
                    if (isset($packages[$tld->package_id])) {
                        $tld_map['.' . ltrim(strtolower($tld->tld), '.')] = $tld->package_id;
                    }
                }
            }
        } catch (Throwable $e) {
            if (method_exists($this->Record, 'reset')) {
                $this->Record->reset();
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
     */
    private function findMatchingImportPackage($domain, array $tld_map)
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
     */
    private function getClientsForImport($client_id = null)
    {
        $record = $this->Record->select([
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
     */
    private function buildImportOverview($module_row)
    {
        $api = $this->getApi(
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
     */
    private function importRegistrarDomain(array $params)
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

        $api = $this->getApi(
            null,
            $module_row->meta->key,
            $module_row->meta->sandbox == 'true',
            null,
            false,
            $module_row->meta->secret ?? null
        );
        $response = $api->submit('domain_info', ['domain' => $domain]);
        $this->logApiRequest($api, $response);
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
                    Language::_('Dynadot.!error.import.package_no_match', true, $this->extractTopLevelDomain($domain))
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

        $currency_setting = $this->Clients->getSetting($client_id, 'default_currency');
        $currency = $currency_setting->value ?? null;
        $pricing = $this->getImportPackagePricing($package, $currency);
        if (!$pricing) {
            return $error(Language::_('Dynadot.!error.import.pricing', true, $currency));
        }

        $service_id = $this->Services->add(
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

        if (($errors = $this->Services->errors()) || !$service_id) {
            $messages = [];
            foreach ((array) $errors as $field_errors) {
                foreach ((array) $field_errors as $message) {
                    $messages[] = $message;
                }
            }

            return $error(implode(' ', $messages));
        }

        if (!empty($domain_info->Expiration)) {
            $this->Services->edit(
                $service_id,
                ['date_renews' => date('Y-m-d H:i:s', (int) ((string) $domain_info->Expiration / 1000))],
                true
            );
        }

        $client_name = trim($client->first_name . ' ' . $client->last_name);
        return [
            'success' => true,
            'service_id' => $service_id,
            'message' => Language::_('Dynadot.!success.import', true, $service_id)
                . ' ' . $client_name . (!empty($client->company) ? ' (' . $client->company . ')' : ''),
            'service_url' => $this->base_uri . 'clients/editservice/' . $client_id . '/' . $service_id . '/'
        ];
    }

    /**
     * Fetches a contact's email address from Dynadot RESTful v2 GET /restful/v2/contacts/{contact_id}.
     */
    private function fetchContactEmail(DynadotApi $api, $contact_id)
    {
        if (empty($contact_id)) {
            return null;
        }

        $response = $api->submit('get_contact', ['contact_id' => $contact_id]);
        $this->logApiRequest($api, $response);
        $result = $response->response();

        if ($response->status() != 'success' || empty($result->GetContactResponse->GetContact->Email)) {
            return null;
        }

        return strtolower(trim((string) $result->GetContactResponse->GetContact->Email));
    }

    /**
     * Finds a unique active Blesta client ID matching an email address.
     */
    private function findClientIdByEmailAddress($email)
    {
        $clients = $this->Record->select(['clients.id'])->
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
     */
    private function getImportPackagePricing($package, $currency)
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

    /**
     * Renders the edit module row screen.
     */
    public function manageEditRow($module_row, array &$vars)
    {
        $this->view = new View('edit_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars)) {
            $vars = (array) $module_row->meta;
        }

        $this->view->set('module', $this->getModule());
        $this->view->set('vars', (object) $vars);
        return $this->view->fetch();
    }

    /**
     * Saves a new module row with RESTful v2 credentials and a display label.
     */
    public function addModuleRow(array &$vars)
    {
        $meta_fields = ['user', 'key', 'secret', 'sandbox'];
        $encrypted_fields = ['key', 'secret'];

        if (empty($vars['sandbox'])) {
            $vars['sandbox'] = 'false';
        }

        $this->Input->setRules($this->buildModuleRowValidationRules($vars));

        if ($this->Input->validates($vars)) {
            $meta = [];
            foreach ($vars as $key => $value) {
                if (in_array($key, $meta_fields)) {
                    $meta[] = [
                        'key' => $key,
                        'value' => $value,
                        'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
                    ];
                }
            }

            return $meta;
        }
    }

    /**
     * Updates an existing module row with RESTful v2 credentials and a display label.
     */
    public function editModuleRow($module_row, array &$vars)
    {
        $meta_fields = ['user', 'key', 'secret', 'sandbox'];
        $encrypted_fields = ['key', 'secret'];

        if (empty($vars['sandbox'])) {
            $vars['sandbox'] = 'false';
        }

        $module_row_meta = array_merge((array) $module_row->meta, $vars);

        $this->Input->setRules($this->buildModuleRowValidationRules($vars));

        if ($this->Input->validates($vars)) {
            $meta = [];
            foreach ($module_row_meta as $key => $value) {
                if (in_array($key, $meta_fields) || array_key_exists($key, (array) $module_row->meta)) {
                    $meta[] = [
                        'key' => $key,
                        'value' => $value,
                        'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
                    ];
                }
            }

            return $meta;
        }
    }

    /**
     * Builds validation rules for RESTful v2 credentials. The user field is
     * only an account label for the Blesta UI and is not sent to Dynadot.
     */
    private function buildModuleRowValidationRules(&$vars)
    {
        return [
            'secret' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Dynadot.!error.secret.valid', true)
                ]
            ],
            'key' => [
                'valid' => [
                    'last' => true,
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Dynadot.!error.key.valid', true)
                ],
                'valid_connection' => [
                    'rule' => [
                        [$this, 'validateConnection'],
                        $vars['user'] ?? null,
                        isset($vars['sandbox']) ? $vars['sandbox'] : 'false',
                        $vars['secret'] ?? ''
                    ],
                    'message' => Language::_('Dynadot.!error.key.valid_connection', true)
                ]
            ]
        ];
    }

    // =========================================================================
    // API Access, Validation, and Domain Operations
    // =========================================================================

    /**
     * Validates API Key and API Secret against RESTful v2 GET /restful/v2/accounts/info.
     */
    public function validateConnection($key, $user, $sandbox, $secret = '')
    {
        $api = $this->getApi(null, $key, $sandbox == 'true', null, false, $secret);
        $response = $api->submit('get_account_balance');
        $this->processApiResponse($api, $response);

        return $response->status() == 'success';
    }

    /**
     * Portfolio validation placeholder.
     */
    public function validatePortfolio($portfolio, $key, $user, $sandbox)
    {
        return true;
    }

    /**
     * Initializes the DynadotApi RESTful v2 client for the selected Dynadot account.
     * Automatically resolves the API Secret from the matching module row when not explicitly provided.
     */
    public function getApi($user = null, $key = null, $sandbox = true, $username = null, $batch = false, $secret = null)
    {
        Loader::load(__DIR__ . DS . 'apis' . DS . 'dynadot_api.php');

        if (empty($key) || $secret === null) {
            if (!empty($key) && $secret === null) {
                foreach ((array) $this->getModuleRows() as $r) {
                    if (isset($r->meta->key) && $r->meta->key === $key) {
                        $secret = $r->meta->secret ?? '';
                        break;
                    }
                }
            }

            if (($row = $this->getConfiguredModuleRow())) {
                if (empty($key)) {
                    $key = $row->meta->key ?? '';
                    $sandbox = (isset($row->meta->sandbox) && $row->meta->sandbox == 'true');
                }
                if ($secret === null) {
                    $secret = $row->meta->secret ?? '';
                }
            }
        }

        $this->api = new DynadotApi((string) $key, (bool) $sandbox, (string) ($secret ?? ''));
        return $this->api;
    }

    /**
     * Logs the API request/response and records any errors on Blesta's Input component.
     */
    private function processApiResponse(DynadotApi $api, DynadotResponse $response)
    {
        $this->logApiRequest($api, $response);

        if ($api->httpcode != 200 && $api->httpcode != 0 && $response->status() != 'error') {
            $this->Input->setErrors(
                ['errors' => ['http' => Language::_('Dynadot.!error.api_http', true, $api->httpcode)]]
            );
            return;
        }

        if ($response->status() == 'error') {
            $errors = $response->errors();
            $this->Input->setErrors(['errors' => $errors]);
        }
    }

    /**
     * Writes masked request/response entries to Blesta's Module Log (Tools -> Logs -> Module).
     */
    private function logApiRequest(DynadotApi $api, DynadotResponse $response)
    {
        $last_request = $api->lastRequest();
        $url = $last_request['url'] ?? '';
        $args = $last_request['args'] ?? [];
        if (isset($args['key'])) {
            $args['key'] = '***';
        }
        if (isset($args['secret'])) {
            $args['secret'] = '***';
        }

        $this->log($url, serialize($args), 'input', true);
        $this->log(
            $url,
            $response->raw(),
            'output',
            $response->status() == 'success'
        );
    }

    /**
     * Extracts the top-level domain extension (including leading dot).
     */
    private function extractTopLevelDomain($domain)
    {
        return strstr($domain, '.');
    }

    /**
     * Validates an ASCII or punycode domain name.
     */
    private function isDomainNameValid($domain)
    {
        $domain = strtolower(trim((string) $domain));

        return strlen($domain) <= 253
            && (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $domain);
    }

    /**
     * Validates a nameserver hostname.
     */
    private function isHostnameValid($host)
    {
        return $this->isDomainNameValid(rtrim((string) $host, '.'));
    }

    /**
     * Returns the active or first configured module row.
     */
    private function getConfiguredModuleRow()
    {
        if (!empty($this->row)) {
            return $this->row;
        }

        if (($row = $this->getModuleRow())) {
            return $row;
        }

        $module_rows = $this->getModuleRows();
        return $module_rows[0] ?? null;
    }

    /**
     * Checks if a domain is available for registration via RESTful v2 GET /restful/v2/domains/{domain_name}/search.
     */
    public function checkAvailability($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $result = $api->submit('search', ['domain0' => $domain]);
        $this->processApiResponse($api, $result);

        if ($result->status() === 'error') {
            return false;
        }

        $response = $result->response();

        if (!isset($response->SearchResponse->SearchResults)) {
            return false;
        }

        $results = $response->SearchResponse->SearchResults;

        if (!is_array($results)) {
            $results = [$results];
        }

        foreach ($results as $res) {
            if (
                isset($res->DomainName) &&
                strtolower($res->DomainName) === strtolower($domain)
            ) {
                return (isset($res->Available) && $res->Available === 'yes');
            }
        }

        return false;
    }

    /**
     * Checks if a domain is registered and eligible for transfer via RESTful v2 search.
     */
    public function checkTransferAvailability($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $result = $api->submit('search', ['domain0' => $domain]);
        $this->processApiResponse($api, $result);

        if ($result->status() == 'error') {
            return false;
        }

        $response = $result->response();

        if (isset($response->SearchResponse->SearchResults)) {
            $results = $response->SearchResponse->SearchResults;
            if (!is_array($results)) {
                $results = [$results];
            }
            foreach ($results as $res) {
                if (isset($res->DomainName) && strtolower($res->DomainName) == strtolower($domain)) {
                    if (isset($res->Available) && $res->Available == 'yes') {
                        return false;
                    }
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Checks whether the requested registration/transfer term is valid.
     */
    public function isValidTerm($tld, $term, $transfer = false)
    {
        if ($term > 10 || ($transfer && $term > 1)) {
            return false;
        }

        return true;
    }

    /**
     * Registers a domain via RESTful v2 POST /restful/v2/domains/{domain_name}/register.
     */
    public function registerDomain($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $args = [
            'domain' => $domain,
            'duration' => $vars['years'] ?? 1
        ];

        foreach (['registrant_contact', 'admin_contact', 'technical_contact', 'billing_contact'] as $contact) {
            if (!empty($vars[$contact])) {
                $args[$contact] = $vars[$contact];
            }
        }

        if (empty($args['registrant_contact'])) {
            $args['registrant_contact'] = $vars['registrant_contact_id']
                ?? ($vars['contact_id'] ?? null);
        }

        foreach ($this->extractNameserversFromInput($vars) as $index => $nameserver) {
            $args['ns' . $index] = $nameserver;
        }

        $response = $api->submit('register', $args);
        $this->processApiResponse($api, $response);

        if ($response->status() != 'success') {
            return false;
        }

        return true;
    }

    /**
     * Initiates a domain transfer via RESTful v2 POST /restful/v2/domains/{domain_name}/transfer_in.
     */
    public function transferDomain($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $args = [
            'domain' => $domain,
            'auth' => trim((string) ($vars['auth'] ?? ($vars['auth_code'] ?? ($vars['epp_code'] ?? ''))))
        ];

        foreach (['registrant_contact', 'admin_contact', 'technical_contact', 'billing_contact'] as $contact) {
            if (!empty($vars[$contact])) {
                $args[$contact] = $vars[$contact];
            }
        }

        $nameservers = $this->extractNameserversFromInput($vars);
        if (!empty($nameservers)) {
            $args['name_servers'] = implode(',', $nameservers);
        }

        $response = $api->submit('transfer', $args);
        $this->processApiResponse($api, $response);

        return $response->status() == 'success';
    }

    /**
     * Extracts valid nameservers from form input arrays.
     */
    private function extractNameserversFromInput(array $vars)
    {
        $nameservers = [];
        for ($i = 1; $i <= 5; $i++) {
            if (!empty($vars['ns' . $i]) && is_scalar($vars['ns' . $i])) {
                $nameservers[] = strtolower(trim($vars['ns' . $i]));
            }
        }

        if (empty($nameservers) && !empty($vars['ns']) && is_array($vars['ns'])) {
            foreach ($vars['ns'] as $ns) {
                if (!empty($ns) && is_scalar($ns)) {
                    $nameservers[] = strtolower(trim($ns));
                }
            }
        }

        return array_values(array_unique(array_filter($nameservers, function ($ns) {
            return $this->isHostnameValid($ns);
        })));
    }

    /**
     * Renews a domain via RESTful v2 POST /restful/v2/domains/{domain_name}/renew.
     */
    public function renewDomain($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $args = [
            'domain' => $domain,
            'duration' => $vars['years'] ?? ($vars['duration'] ?? 1)
        ];

        $response = $api->submit('renew', $args);
        $this->processApiResponse($api, $response);

        return $response->status() == 'success';
    }

    /**
     * Restores an expired domain via RESTful v2 POST /restful/v2/domains/{domain_name}/restore.
     */
    public function restoreDomain($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $response = $api->submit('restore', ['domain' => $domain]);
        $this->processApiResponse($api, $response);

        return $response->status() == 'success';
    }

    /**
     * Locks a domain via RESTful v2 PUT /restful/v2/domains/{domain_name}/lock_status.
     */
    public function lockDomain($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $response = $api->submit('lock_domain', ['domain' => $domain]);
        $this->processApiResponse($api, $response);
        $this->clearDomainInfoCacheEntry($domain);

        return $response->status() == 'success';
    }

    /**
     * Unlocks a domain via RESTful v2.
     */
    public function unlockDomain($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $response = $api->submit('get_transfer_auth_code', ['domain' => $domain, 'unlock_domain_for_transfer' => '1']);
        $this->processApiResponse($api, $response);
        $this->clearDomainInfoCacheEntry($domain);

        return $response->status() == 'success';
    }

    /**
     * Fetches and caches domain details from RESTful v2 GET /restful/v2/domains/{domain_name}.
     */
    private function fetchRegistrarDomainInfo(DynadotApi $api, $domain, $process = false)
    {
        $key = strtolower($domain);
        if (!array_key_exists($key, self::$domain_info_cache)) {
            $response = $api->submit('domain_info', ['domain' => $domain]);
            if ($process) {
                if (!$this->isDomainPendingResponse($api, $response)) {
                    $this->processApiResponse($api, $response);
                } else {
                    $this->logApiRequest($api, $response);
                }
            } else {
                $this->logApiRequest($api, $response);
            }

            $result = $response->response();
            if ($response->status() != 'success' || !isset($result->DomainInfoResponse->DomainInfo)) {
                return null;
            }

            self::$domain_info_cache[$key] = $result->DomainInfoResponse->DomainInfo;
        }

        return self::$domain_info_cache[$key];
    }

    /**
     * Clears the cached domain_info entry for a domain.
     */
    private function clearDomainInfoCacheEntry($domain)
    {
        unset(self::$domain_info_cache[strtolower($domain)]);
    }

    /**
     * Checks whether a domain is currently locked.
     */
    public function getDomainIsLocked($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $info = $this->fetchRegistrarDomainInfo($api, $domain);

        return isset($info->Locked) && ($info->Locked == 'yes' || $info->Locked === true);
    }

    /**
     * Returns normalized domain info for Blesta's Domain Manager.
     */
    public function getDomainInfo($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $d = $this->fetchRegistrarDomainInfo($api, $domain);

        if ($d) {
            return [
                'domain' => (string)$d->Name,
                'created' => isset($d->Registration) ? (string)$d->Registration / 1000 : null,
                'expires' => isset($d->Expiration) ? (string)$d->Expiration / 1000 : null,
                'registration_date' => isset($d->Registration) ? date('Y-m-d H:i:s', (int) ((string)$d->Registration / 1000)) : null,
                'expiration' => isset($d->Expiration) ? date('Y-m-d H:i:s', (int) ((string)$d->Expiration / 1000)) : null,
                'locked' => isset($d->Locked) && ($d->Locked == 'yes' || $d->Locked === true),
                'private' => !in_array(
                    strtolower((string) ($d->Privacy ?? ($d->WhoisPrivacy ?? ''))),
                    ['', 'no', 'none', 'off']
                ),
            ];
        }
        return [];
    }

    /**
     * Transfer email resend placeholder.
     */
    public function resendTransferEmail($domain, $module_row_id = null)
    {
        return false;
    }

    /**
     * Requests the domain's EPP/transfer auth code via RESTful v2 GET /restful/v2/domains/{domain_name}/transfer_auth_code.
     */
    public function sendEppEmail($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $response = $api->submit('get_transfer_auth_code', ['domain' => $domain]);
        $this->processApiResponse($api, $response);

        return $response->status() == 'success';
    }

    /**
     * Custom EPP code update placeholder.
     */
    public function updateEppCode($domain, $epp_code, $module_row_id = null, array $vars = [])
    {
        return false;
    }

    // =========================================================================
    // WHOIS Contacts
    // =========================================================================

    /**
     * Retrieves the domain's WHOIS contacts via RESTful v2.
     */
    public function getDomainContacts($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $info = $this->fetchRegistrarDomainInfo($api, $domain);

        $contacts = [];
        if (isset($info->Whois)) {
            $whois = $info->Whois;
            $sections = ['Registrant', 'Admin', 'Technical', 'Billing'];

            foreach ($sections as $section) {
                if (isset($whois->$section->ContactId)) {
                    $cid = (string)$whois->$section->ContactId;
                    if ($cid) {
                        $c_resp = $api->submit('get_contact', ['contact_id' => $cid]);
                        $c_res = $c_resp->response();
                        if (isset($c_res->GetContactResponse->GetContact)) {
                            $c = $c_res->GetContactResponse->GetContact;
                            $name_parts = explode(' ', (string)$c->Name, 2);
                            $contacts[] = [
                                'external_id' => $cid,
                                'email' => (string)$c->Email,
                                'phone' => '+' . (string)$c->PhoneCc . '.' . (string)$c->PhoneNum,
                                'first_name' => $name_parts[0] ?? '',
                                'last_name' => $name_parts[1] ?? '',
                                'address1' => (string)$c->Address1,
                                'address2' => (string)$c->Address2,
                                'city' => (string)$c->City,
                                'state' => (string)$c->State,
                                'zip' => (string)$c->ZipCode,
                                'country' => (string)$c->Country,
                                'type' => $section
                            ];
                        }
                    }
                }
            }
        }
        return $contacts;
    }

    /**
     * Assigns or creates WHOIS contacts for a domain via RESTful v2 PUT /restful/v2/domains/{domain_name}/contacts.
     */
    public function setDomainContacts($domain, array $vars = [], $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $args = ['domain' => $domain];
        $types = ['Registrant', 'Admin', 'Technical', 'Billing'];
        foreach ($types as $type) {
            $key_id = strtolower($type) . '_contact';

            if (isset($vars[$key_id])) {
                $args[$key_id] = $vars[$key_id];
                continue;
            }

            if (isset($vars[$type]) && is_array($vars[$type])) {
                $contact_id = $this->createRegistrarContact($vars[$type], $api);
                if ($contact_id) {
                    $args[$key_id] = $contact_id;
                }
            } elseif (isset($vars[$type . 'FirstName'])) {
                $contact_data = [
                    'first_name' => $vars[$type . 'FirstName'] ?? '',
                    'last_name' => $vars[$type . 'LastName'] ?? '',
                    'email' => $vars[$type . 'EmailAddress'] ?? '',
                    'phone' => $vars[$type . 'Phone'] ?? '',
                    'address1' => $vars[$type . 'Address1'] ?? '',
                    'address2' => $vars[$type . 'Address2'] ?? '',
                    'city' => $vars[$type . 'City'] ?? '',
                    'state' => $vars[$type . 'StateProvince'] ?? '',
                    'zip' => $vars[$type . 'PostalCode'] ?? '',
                    'country' => $vars[$type . 'Country'] ?? '',
                    'company' => $vars[$type . 'Organization'] ?? '',
                ];
                $contact_id = $this->createRegistrarContact($contact_data, $api);
                if ($contact_id) {
                    $args[$key_id] = $contact_id;
                }
            }
        }

        if (count($args) > 1) {
            $response = $api->submit('set_whois', $args);
            $this->processApiResponse($api, $response);
            $this->clearDomainInfoCacheEntry($domain);

            return $response->status() == 'success';
        }

        return false;
    }

    /**
     * Normalizes Blesta contact fields for Dynadot contact creation/updating.
     */
    private function buildContactArguments(array $data)
    {
        $country = $this->normalizeContactCountry(
            $data['country'] ?? ($data['Country'] ?? ($data['country_code'] ?? ''))
        );
        $phone = trim((string) ($data['phone'] ?? $data['Phone'] ?? $data['phone_number'] ?? ''));
        $phone_cc = trim((string) ($data['phone_cc'] ?? $data['PhoneCc'] ?? ''));

        if ($phone !== '' && !preg_match('/^\+\d+\./', $phone) && $country !== '') {
            if (!isset($this->Contacts)) {
                Loader::loadModels($this, ['Contacts']);
            }
            $phone = $this->Contacts->intlNumber($phone, $country, '.');
        }

        $phone_number = preg_replace('/[^0-9]/', '', $phone);
        if (preg_match('/^\+(\d+)\.(.+)$/', $phone, $matches)) {
            $phone_cc = $matches[1];
            $phone_number = preg_replace('/[^0-9]/', '', $matches[2]);
        } elseif (strpos($phone, '+') === 0) {
            $phone_cc = $this->getCountryCallingCode($country, $phone);
            if ($phone_cc !== '') {
                $phone_number = preg_replace('/^' . preg_quote($phone_cc, '/') . '/', '', $phone_number);
            }
        } elseif (strpos($phone, '00') === 0) {
            $international_phone = '+' . substr($phone, 2);
            $phone_cc = $this->getCountryCallingCode($country, $international_phone);
            if ($phone_cc !== '') {
                $phone_number = preg_replace('/^' . preg_quote($phone_cc, '/') . '/', '', substr($phone, 2));
            }
        } else {
            $phone_cc = $phone_cc ?: $this->getCountryCallingCode($country);
        }

        $args = [
            'name' => trim(($data['first_name'] ?? $data['FirstName'] ?? '') . ' ' . ($data['last_name'] ?? $data['LastName'] ?? '')),
            'email' => $data['email'] ?? $data['EmailAddress'] ?? '',
            'phone_cc' => preg_replace('/[^0-9]/', '', $phone_cc),
            'phone_number' => $phone_number,
            'organization' => $data['company'] ?? $data['Organization'] ?? '',
            'address1' => $data['address1'] ?? $data['Address1'] ?? '',
            'address2' => $data['address2'] ?? $data['Address2'] ?? '',
            'city' => $data['city'] ?? $data['City'] ?? '',
            'state' => $data['state'] ?? $data['StateProvince'] ?? '',
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
     * Creates a new WHOIS contact at Dynadot via RESTful v2 POST /restful/v2/contacts.
     */
    private function createRegistrarContact($data, $api)
    {
        $args = $this->buildContactArguments((array) $data);
        if (empty($args['phone_cc']) || empty($args['phone_number'])) {
            $this->Input->setErrors(
                ['registrant_contact' => ['phone' => Language::_('Dynadot.!error.registrant_contact.phone', true)]]
            );
            return null;
        }

        $response = $api->submit('create_contact', $args);
        $this->processApiResponse($api, $response);
        $res = $response->response();

        if ($response->status() == 'success' && isset($res->CreateContactResponse->CreateContactContent->ContactId)) {
            return (string)$res->CreateContactResponse->CreateContactContent->ContactId;
        }
        return null;
    }

    /**
     * Returns a calling code when Blesta did not format the phone as +CC.number.
     */
    private function getCountryCallingCode($country, $phone = '')
    {
        $codes = [
            'BD' => '880', 'BANGLADESH' => '880',
            'IN' => '91', 'INDIA' => '91',
            'PK' => '92', 'PAKISTAN' => '92',
            'NP' => '977', 'NEPAL' => '977',
            'US' => '1', 'CA' => '1', 'GB' => '44', 'AU' => '61',
            'AE' => '971', 'SA' => '966', 'MY' => '60', 'SG' => '65',
            'DE' => '49', 'FR' => '33', 'IT' => '39', 'NL' => '31',
            'JP' => '81', 'CN' => '86', 'HK' => '852'
        ];
        $country = strtoupper(trim((string) $country));
        if (isset($codes[$country])) {
            return $codes[$country];
        }

        if (preg_match('/^\+(\d{1,3})/', trim((string) $phone), $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Converts Blesta country names or codes to the ISO-3166 alpha-2 value
     * required by Dynadot contact requests.
     */
    private function normalizeContactCountry($country)
    {
        $country = strtoupper(trim((string) $country));
        $aliases = [
            'BANGLADESH' => 'BD',
            'INDIA' => 'IN',
            'PAKISTAN' => 'PK',
            'NEPAL' => 'NP',
            'UNITED STATES' => 'US',
            'UNITED STATES OF AMERICA' => 'US',
            'CANADA' => 'CA',
            'UNITED KINGDOM' => 'GB',
            'AUSTRALIA' => 'AU',
            'UNITED ARAB EMIRATES' => 'AE',
            'SAUDI ARABIA' => 'SA',
            'MALAYSIA' => 'MY',
            'SINGAPORE' => 'SG',
            'GERMANY' => 'DE',
            'FRANCE' => 'FR',
            'ITALY' => 'IT',
            'NETHERLANDS' => 'NL',
            'JAPAN' => 'JP',
            'CHINA' => 'CN',
            'HONG KONG' => 'HK'
        ];

        return $aliases[$country] ?? $country;
    }

    // =========================================================================
    // Service Information and Management Views
    // =========================================================================

    /**
     * Extracts the domain name from a Blesta service record.
     */
    public function getServiceDomain($service)
    {
        if (isset($service->fields)) {
            foreach ($service->fields as $service_field) {
                if ($service_field->key == 'domain') {
                    return $service_field->value;
                }
            }
        }
        return $service->name ?? '';
    }

    /**
     * Returns the domain's registration date formatted for Blesta.
     */
    public function getRegistrationDate($service, $format = 'Y-m-d H:i:s')
    {
        Loader::loadHelpers($this, ['Date']);
        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($service->module_row_id ?? null);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $info = $this->fetchRegistrarDomainInfo($api, $domain, true);

        return isset($info->Registration)
            ? $this->Date->format($format, (int) ((string)$info->Registration / 1000))
            : false;
    }

    /**
     * Returns the domain's expiration date formatted for Blesta.
     */
    public function getExpirationDate($service, $format = 'Y-m-d H:i:s')
    {
        Loader::loadHelpers($this, ['Date']);
        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($service->module_row_id ?? null);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $info = $this->fetchRegistrarDomainInfo($api, $domain, true);

        return isset($info->Expiration)
            ? $this->Date->format($format, (int) ((string)$info->Expiration / 1000))
            : false;
    }

    /**
     * Renders the admin service information panel.
     */
    public function getAdminServiceInfo($service, $package)
    {
        return $this->renderServiceInformation('admin_service_info', $service, $package);
    }

    /**
     * Renders the client service information panel.
     */
    public function getClientServiceInfo($service, $package)
    {
        return $this->renderServiceInformation('client_service_info', $service, $package);
    }

    /**
     * Populates and renders the service information view.
     */
    private function renderServiceInformation($view, $service, $package)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        $vars = [
            'domain' => $domain,
            'status' => 'Unknown',
            'expiration' => '',
            'locked' => 'Unknown'
        ];

        $d = $this->fetchRegistrarDomainInfo($api, $domain);
        if ($d) {
            $vars['status'] = isset($d->Status) ? (string)$d->Status : 'Unknown';
            if (isset($d->Expiration)) {
                $vars['expiration'] = date('Y-m-d H:i:s', (int) ((string)$d->Expiration / 1000));
            }
            $vars['locked'] = (isset($d->Locked) && ($d->Locked == 'yes' || $d->Locked === true)) ? 'Yes' : 'No';
        }

        Loader::loadHelpers($this, ['Html', 'Date']);
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);

        $this->view->set('domain', $vars['domain']);
        $this->view->set('status', $vars['status']);
        $this->view->set('expiration', $vars['expiration']);
        $this->view->set('locked', $vars['locked']);

        return $this->view->fetch();
    }

    /**
     * Returns package configuration fields.
     */
    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);
        $fields = new ModuleFields();
        $types = ['domain' => Language::_('Dynadot.package_fields.type_domain', true)];

        $type = $fields->label(Language::_('Dynadot.package_fields.type', true), 'dynadot_type');
        $type->attach($fields->fieldSelect('meta[type]', $types, $vars->meta['type'] ?? null, ['id' => 'dynadot_type']));
        $fields->setField($type);

        for ($i = 1; $i <= 5; $i++) {
            $type = $fields->label(Language::_('Dynadot.package_fields.ns' . $i, true), 'dynadot_ns' . $i);
            $type->attach($fields->fieldText('meta[ns][]', $vars->meta['ns'][$i - 1] ?? null, ['id' => 'dynadot_ns' . $i]));
            $fields->setField($type);
        }

        return $fields;
    }

    /**
     * Returns admin service edit fields.
     */
    public function getAdminEditFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Form', 'Html']);
        $fields = new ModuleFields();

        if ($package->meta->type == 'domain') {
            $fields->setField($fields->label(Language::_('Dynadot.domain.domain', true), 'domain')->attach($fields->fieldText('domain', $vars->domain ?? null, ['id' => 'domain'])));
            for ($i = 1; $i <= 5; $i++) {
                $fields->setField($fields->label(Language::_('Dynadot.nameserver.ns' . $i, true), 'ns' . $i)->attach($fields->fieldText('ns' . $i, $vars->{'ns' . $i} ?? null, ['id' => 'ns' . $i])));
            }
            $fields->setField($fields->label(Language::_('Dynadot.transfer.EPPCode', true), 'auth')->attach($fields->fieldText('auth', $vars->auth ?? null, ['id' => 'auth'])));
        }

        return $fields;
    }

    /**
     * Returns admin service add fields.
     */
    public function getAdminAddFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Form', 'Html']);

        if ($package->meta->type == 'domain') {
            if (!isset($vars->ns1) && isset($package->meta->ns)) {
                $i = 1;
                foreach ($package->meta->ns as $ns) {
                    $vars->{'ns' . $i++} = $ns;
                }
            }

            if ((isset($vars->transfer) && $vars->transfer) || (isset($vars->auth) && $vars->auth)) {
                return $this->arrayToInputFields(Configure::get('Dynadot.transfer_fields'), null, $vars);
            } else {
                $fields = Configure::get('Dynadot.transfer_fields');
                $fields['transfer'] = [
                    'label' => Language::_('Dynadot.domain.DomainAction', true),
                    'type' => 'radio',
                    'value' => '1',
                    'options' => ['0' => 'Register', '1' => 'Transfer'],
                ];
                $fields['auth'] = ['label' => Language::_('Dynadot.transfer.EPPCode', true), 'type' => 'text'];

                // Nameservers are managed exclusively in the Nameservers tab.
                // Keeping them out of Basic prevents two unsynchronized editors
                // from showing different values for the same domain.
                $module_fields = $this->arrayToInputFields($fields, null, $vars);
                $module_fields->setHtml(<<<'HTML'
                    <script type="text/javascript">
                        (function () {
                            function fieldRow(el) {
                                if (!el) {
                                    return null;
                                }
                                return el.closest('li') || el.closest('.mb-3') || el.closest('.form-group') || el.parentElement;
                            }

                            function toggle(el, show) {
                                var row = fieldRow(el);
                                if (row) {
                                    row.style.display = show ? '' : 'none';
                                }
                            }

                            function update() {
                                var checked = document.querySelector('input[name="transfer"]:checked');
                                var is_transfer = checked && checked.value == '1';

                                toggle(document.getElementById('auth_id'), is_transfer);
                            }

                            function init() {
                                var radios = document.querySelectorAll('input[name="transfer"]');
                                if (!radios.length) {
                                    return;
                                }

                                var register = document.getElementById('transfer_id_0');
                                if (register && !document.querySelector('input[name="transfer"]:checked')) {
                                    register.checked = true;
                                }

                                radios.forEach(function (radio) {
                                    if (!radio.dataset.dynadotBound) {
                                        radio.dataset.dynadotBound = '1';
                                        radio.addEventListener('change', update);
                                    }
                                });
                                update();
                            }

                            if (document.readyState === 'loading') {
                                document.addEventListener('DOMContentLoaded', init);
                            } else {
                                init();
                            }
                            document.addEventListener('blestaAjaxComplete', init);
                        })();
                    </script>
                    HTML);
                return $module_fields;
            }
        }
        return new ModuleFields();
    }

    /**
     * Returns client order form fields.
     */
    public function getClientAddFields($package, $vars = null)
    {
        if (isset($vars->domain)) {
            $vars->domain = $vars->domain;
        }

        if ($package->meta->type == 'domain') {
            if (!isset($vars->ns) && isset($package->meta->ns)) {
                $i = 1;
                foreach ($package->meta->ns as $ns) {
                    $vars->{'ns' . $i++} = $ns;
                }
            }

            if ((isset($vars->transfer) && $vars->transfer) || isset($vars->auth)) {
                $fields = Configure::get('Dynadot.transfer_fields');
                $fields['domain']['type'] = 'hidden';
                $fields['domain']['label'] = null;
                return $this->arrayToInputFields($fields, null, $vars);
            } else {
                $fields = array_merge(Configure::get('Dynadot.nameserver_fields'), Configure::get('Dynadot.domain_fields'));
                $fields['domain']['type'] = 'hidden';
                $fields['domain']['label'] = null;
                return $this->arrayToInputFields($fields, null, $vars);
            }
        }
        return new ModuleFields();
    }

    /**
     * Returns admin service management tabs.
     */
    public function getAdminServiceTabs($service)
    {
        return [
            'tabWhois' => Language::_('Dynadot.tab_whois.title', true),
            'tabManageContacts' => Language::_('Dynadot.tab_manage_contacts.title', true),
            'tabNameservers' => Language::_('Dynadot.tab_nameservers.title', true),
            'tabSettings' => Language::_('Dynadot.tab_settings.title', true),
            'tabHosts' => Language::_('Dynadot.tab_hosts.title', true),
            'tabDnssec' => Language::_('Dynadot.tab_dnssec.title', true),
            'tabDnsRecords' => Language::_('Dynadot.tab_dnsrecords.title', true),
            'tabEmailForwarding' => Language::_('Dynadot.tab_email_forwarding.title', true),
            'tabAdminActions' => Language::_('Dynadot.tab_adminactions.title', true),
        ];
    }

    /**
     * Returns client service management tabs.
     */
    public function getClientServiceTabs($service)
    {
        return [
            'tabClientWhois' => ['name' => Language::_('Dynadot.tab_whois.title', true), 'icon' => 'fas fa-users'],
            'tabClientManageContacts' => ['name' => Language::_('Dynadot.tab_manage_contacts.title', true), 'icon' => 'fas fa-users'],
            'tabClientNameservers' => ['name' => Language::_('Dynadot.tab_nameservers.title', true), 'icon' => 'fas fa-server'],
            'tabClientSettings' => ['name' => Language::_('Dynadot.tab_settings.title', true), 'icon' => 'fas fa-cog'],
            'tabClientHosts' => ['name' => Language::_('Dynadot.tab_hosts.title', true), 'icon' => 'fas fa-hdd'],
            'tabClientDnssec' => ['name' => Language::_('Dynadot.tab_dnssec.title', true), 'icon' => 'fas fa-lock'],
            'tabClientDnsRecords' => ['name' => Language::_('Dynadot.tab_dnsrecords.title', true), 'icon' => 'fas fa-sitemap'],
            'tabClientEmailForwarding' => ['name' => Language::_('Dynadot.tab_email_forwarding.title', true), 'icon' => 'fas fa-envelope'],
        ];
    }

    // =========================================================================
    // WHOIS Contacts Management Tabs
    // =========================================================================

    public function tabWhois($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageWhois('tab_whois', $package, $service, $get, $post, $files);
    }

    public function tabClientWhois($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageWhois('tab_client_whois', $package, $service, $get, $post, $files);
    }

    public function tabManageContacts($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageManageContacts('tab_manage_contacts', $package, $service, $get, $post, $files);
    }

    public function tabClientManageContacts($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageManageContacts('tab_client_manage_contacts', $package, $service, $get, $post, $files);
    }

    /**
     * Handles the Admin Actions tab (sync renewal date, request EPP code).
     */
    public function tabAdminActions($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View('tab_admin_actions', 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView(self::$defaultModuleView);

        if (!empty($post)) {
            $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
            $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');
            $domain = $this->getServiceDomain($service);

            if (isset($post['action']) && $post['action'] == 'sync_date') {
                Loader::loadModels($this, ['Services']);
                $info = $this->fetchRegistrarDomainInfo($api, $domain, true);

                if (!$this->Input->errors() && isset($info->Expiration)) {
                    $expires = date('Y-m-d H:i:s', (int) ((string)$info->Expiration / 1000));
                    $this->Services->edit($service->id, ['date_renews' => $expires], true);

                    if (($errors = $this->Services->errors())) {
                        $this->Input->setErrors($errors);
                    } else {
                        $this->setMessage('success', Language::_('Dynadot.!success.renew_date_synced', true));
                    }
                }
            }

            if (!empty($post['request_epp'])) {
                $this->requestDomainEppCode($api, $domain);
            }

            if (!empty($post['eppCode'])) {
                $this->Input->setErrors(
                    ['eppCode' => ['unsupported' => Language::_('Dynadot.!error.epp.unsupported', true)]]
                );
            }
        }
        return $this->view->fetch();
    }

    /**
     * Manages domain WHOIS contact assignments.
     */
    private function manageWhois($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);

        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);
        $whois_fields = Configure::get('Dynadot.whois_fields');

        $isDomainStatusAvailable = $this->isDomainStatusAvailable($service, $package);
        if (isset($isDomainStatusAvailable)) {
            return $isDomainStatusAvailable;
        }

        if (!empty($post)) {
            $contact_ids = [];
            $created_contacts = [];
            foreach (['Registrant', 'Admin', 'Technical', 'Billing'] as $section) {
                $contact_data = $this->extractContactDataFromPost($post, $section);

                if (implode('', $contact_data) === '') {
                    continue;
                }

                $hash = md5(json_encode($contact_data));
                if (!array_key_exists($hash, $created_contacts)) {
                    $created_contacts[$hash] = $this->createRegistrarContact($contact_data, $api);
                }

                if ($created_contacts[$hash]) {
                    $contact_ids[strtolower($section) . '_contact'] = $created_contacts[$hash];
                }
            }

            if (!empty($contact_ids) && !$this->Input->errors()) {
                $args = array_merge(['domain' => $domain], $contact_ids);
                $response = $api->submit('set_whois', $args);
                $this->processApiResponse($api, $response);
                $this->clearDomainInfoCacheEntry($domain);

                if ($response->status() == 'success') {
                    $this->setMessage('success', Language::_('Dynadot.!success.updated', true));
                }
            }
            $vars = (object)$post;
        } else {
            $info = $this->fetchRegistrarDomainInfo($api, $domain, true);
            if (isset($info->Whois)) {
                $whois_ids = $info->Whois;
                $fetched_contacts = [];
                foreach (['Registrant', 'Admin', 'Technical', 'Billing'] as $section) {
                    if (isset($whois_ids->$section->ContactId)) {
                        $cid = (string)$whois_ids->$section->ContactId;
                        if ($cid > 0) {
                            if (!array_key_exists($cid, $fetched_contacts)) {
                                $c_resp = $api->submit('get_contact', ['contact_id' => $cid]);
                                $this->logApiRequest($api, $c_resp);
                                $fetched_contacts[$cid] = $c_resp->response();
                            }
                            $c_res = $fetched_contacts[$cid];
                            if (isset($c_res->GetContactResponse->GetContact)) {
                                $fields = $this->formatContactFieldsForDisplay($c_res->GetContactResponse->GetContact, $section);
                                foreach ($fields as $key => $value) {
                                    $vars->$key = $value;
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->view->set('vars', $vars);
        $this->view->set('fields', $whois_fields);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    /**
     * Routes contact management requests (list, add, edit).
     */
    private function manageManageContacts($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $isDomainStatusAvailable = $this->isDomainStatusAvailable($service, $package);
        if (isset($isDomainStatusAvailable)) {
            return $isDomainStatusAvailable;
        }

        if (($get['action'] ?? '') == 'manage') {
            if (array_key_exists('contact_id', $get)) {
                return $this->handleContactEdit($view, $package, $service, $get, $post ?? []);
            } else {
                return $this->handleContactAdd($view, $package, $service, $get, $post ?? []);
            }
        }
        return $this->handleContactList($view, $package, $service, $get, $post ?? []);
    }

    /**
     * Displays and syncs the client's saved Dynadot contacts.
     */
    private function handleContactList($view, $package, $service, ?array $get = null, array $post = [])
    {
        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }

        $module = $this->getModule();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);

        if (!empty($post) && isset($post['pull_contacts'])) {
            $module_row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
            $domains = [];
            foreach ($this->findDomainServices() as $client_service) {
                if ($client_service->client_id == $service->client_id
                    && $client_service->module_row_id == $module_row->id
                    && $client_service->status == 'active'
                ) {
                    $domains[] = $client_service->domain;
                }
            }

            $domains = array_slice(array_values(array_unique($domains)), 0, 25);
            $this->synchronizeContactsForQueuedDomains([$service->client_id => $domains], $module_row);
            $this->setMessage('success', Language::_('Dynadot.!success.contacts_synced', true));
        }

        $contacts = [];
        $contact_meta = $this->ModuleClientMeta->get($service->client_id, 'contacts', $module->id, $service->module_row_id);
        if ($contact_meta) {
            $contacts = json_decode($contact_meta->value, true);
        }

        $this->view->set('contacts', $contacts);
        $this->view->set('service', $service);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    /**
     * Adds a new contact via RESTful v2 POST /restful/v2/contacts.
     */
    private function handleContactAdd($view, $package, $service, ?array $get = null, array $post = [])
    {
        $vars = new stdClass();
        $this->view = new View($view == 'tab_manage_contacts' ? 'tab_edit_contact' : 'tab_client_edit_contact', 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);

        $fields = [];
        foreach (Configure::get('Dynadot.whois_fields') as $key => $field) {
            if (strpos($key, 'Registrant') === 0) {
                $fields[$key] = $field;
            }
        }

        if (!empty($post)) {
            $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
            $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

            $contact_data = $this->extractContactDataFromPost($post, 'Registrant');
            $vars = (object) $post;

            $cid = $this->createRegistrarContact($contact_data, $api);
            if ($cid) {
                if (!isset($this->ModuleClientMeta)) {
                    Loader::loadModels($this, ['ModuleClientMeta']);
                }
                $module = $this->getModule();
                $contact_meta = $this->ModuleClientMeta->get(
                    $service->client_id,
                    'contacts',
                    $module->id,
                    $service->module_row_id
                );
                $contacts = $contact_meta ? (array) json_decode($contact_meta->value, true) : [];
                $contacts[$cid] = trim($contact_data['first_name'] . ' ' . $contact_data['last_name']);
                $this->ModuleClientMeta->set(
                    $service->client_id,
                    $module->id,
                    $service->module_row_id,
                    [['key' => 'contacts', 'value' => json_encode($contacts)]]
                );
                $this->setMessage('success', Language::_('Dynadot.!success.contact_added', true));

                return $this->handleContactList($view, $package, $service, $get);
            }
        }

        $this->view->set('vars', $vars);
        $this->view->set('fields', $fields);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    /**
     * Edits an existing contact via RESTful v2 PUT /restful/v2/contacts/{contact_id}.
     */
    private function handleContactEdit($view, $package, $service, ?array $get = null, array $post = [])
    {
        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }

        $module = $this->getModule();
        $contact_meta = $this->ModuleClientMeta->get(
            $service->client_id,
            'contacts',
            $module->id,
            $service->module_row_id
        );
        $contacts = $contact_meta ? (array) json_decode($contact_meta->value, true) : [];
        $contact_id = (string) ($get['contact_id'] ?? '');

        if ($contact_id === '' || !ctype_digit($contact_id) || !array_key_exists($contact_id, $contacts)) {
            return $this->handleContactList($view, $package, $service, $get);
        }

        $this->view = new View($view == 'tab_manage_contacts' ? 'tab_edit_contact' : 'tab_client_edit_contact', 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);

        $fields = [];
        foreach (Configure::get('Dynadot.whois_fields') as $key => $field) {
            if (strpos($key, 'Registrant') === 0) {
                $fields[$key] = $field;
            }
        }

        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');
        $vars = new stdClass();

        if (!empty($post)) {
            $contact_data = $this->extractContactDataFromPost($post, 'Registrant');
            $args = $this->buildContactArguments($contact_data);
            $args['contact_id'] = $contact_id;

            $response = $api->submit('edit_contact', $args);
            $this->processApiResponse($api, $response);

            if ($response->status() == 'success') {
                $contacts[$contact_id] = trim($contact_data['first_name'] . ' ' . $contact_data['last_name']);
                $this->ModuleClientMeta->set(
                    $service->client_id,
                    $module->id,
                    $service->module_row_id,
                    [['key' => 'contacts', 'value' => json_encode($contacts)]]
                );
                $this->setMessage('success', Language::_('Dynadot.!success.updated', true));

                return $this->handleContactList($view, $package, $service, $get);
            }

            $vars = (object) $post;
        } else {
            $response = $api->submit('get_contact', ['contact_id' => $contact_id]);
            $this->processApiResponse($api, $response);
            $result = $response->response();

            if (isset($result->GetContactResponse->GetContact)) {
                foreach ($this->formatContactFieldsForDisplay($result->GetContactResponse->GetContact, 'Registrant') as $key => $value) {
                    $vars->$key = $value;
                }
            }
        }

        $this->view->set('vars', $vars);
        $this->view->set('fields', $fields);
        $this->view->set('contact_id', $contact_id);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    /**
     * Extracts contact fields for a given WHOIS section from POST data.
     */
    private function extractContactDataFromPost(array $post, $section)
    {
        $map = [
            'first_name' => 'FirstName',
            'last_name' => 'LastName',
            'email' => 'EmailAddress',
            'phone' => 'Phone',
            'company' => 'Organization',
            'address1' => 'Address1',
            'address2' => 'Address2',
            'city' => 'City',
            'state' => 'StateProvince',
            'zip' => 'PostalCode',
            'country' => 'Country'
        ];

        $data = [];
        foreach ($map as $key => $field) {
            $value = $post[$section . $field] ?? '';
            $data[$key] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $data;
    }

    /**
     * Formats normalized Dynadot contact fields for Blesta form display.
     */
    private function formatContactFieldsForDisplay($contact, $section)
    {
        $name_parts = explode(' ', trim((string) ($contact->Name ?? '')), 2);
        $phone = preg_replace('/[^0-9]/', '', (string) ($contact->PhoneNum ?? ''));

        return [
            $section . 'FirstName' => $name_parts[0] ?? '',
            $section . 'LastName' => $name_parts[1] ?? '',
            $section . 'EmailAddress' => (string) ($contact->Email ?? ''),
            $section . 'Phone' => $phone !== '' ? '+' . (string) ($contact->PhoneCc ?? '') . '.' . $phone : '',
            $section . 'Organization' => (string) ($contact->Organization ?? ''),
            $section . 'Address1' => (string) ($contact->Address1 ?? ''),
            $section . 'Address2' => (string) ($contact->Address2 ?? ''),
            $section . 'City' => (string) ($contact->City ?? ''),
            $section . 'StateProvince' => (string) ($contact->State ?? ''),
            $section . 'PostalCode' => (string) ($contact->ZipCode ?? ''),
            $section . 'Country' => (string) ($contact->Country ?? '')
        ];
    }

    /**
     * Domain status check hook.
     */
    private function isDomainStatusAvailable($service, $package)
    {
        return null;
    }

    /**
     * Requests the domain's transfer auth code and sets a UI status message.
     */
    private function requestDomainEppCode(DynadotApi $api, $domain)
    {
        $response = $api->submit('get_transfer_auth_code', ['domain' => $domain]);
        $this->processApiResponse($api, $response);

        if ($response->status() != 'success') {
            return false;
        }

        $res = $response->response();
        if (isset($res->GetTransferAuthCodeResponse->AuthCode)) {
            $this->setMessage(
                'success',
                Language::_('Dynadot.!success.epp_code', true, (string) $res->GetTransferAuthCodeResponse->AuthCode)
            );
        } else {
            $this->setMessage('success', Language::_('Dynadot.!success.epp_code_sent', true));
        }

        return true;
    }

    /**
     * Displays a notice if the domain is not using Dynadot's default nameservers.
     */
    private function validateAndReportNameservers($domain, $module_row_id = null)
    {
        $nameservers = $this->getDomainNameServers($domain, $module_row_id);
        $current_nameservers = array_filter(array_map(function ($nameserver) {
            return $nameserver['url'] ?? null;
        }, $nameservers));

        $required_nameservers_count = count(array_intersect($this->nameservers, $current_nameservers));

        if ($required_nameservers_count !== count($current_nameservers) || $required_nameservers_count < 2) {
            $this->setMessage('notice', Language::_('Dynadot.notice.default_nameservers', true));
        }
    }

    /**
     * Refreshes Dynadot TLD pricing caches for every configured module row.
     *
     * Dynadot RESTful v2 operation: GET /restful/v2/domains/get_tld_price
     */
    private function refreshPricingCache()
    {
        foreach ($this->getModuleRows() as $module_row) {
            if (empty($module_row->meta->key)) {
                continue;
            }

            $this->setModuleRow($module_row);
            $this->getUsdPrices(true);
        }
    }

    /**
     * Fetches and caches USD TLD prices via RESTful v2 GET /restful/v2/domains/get_tld_price.
     */
    private function getUsdPrices($force_refresh = false)
    {
        $row = $this->getConfiguredModuleRow();
        if (!$row || empty($row->meta->key)) {
            return [];
        }

        $sandbox = (isset($row->meta->sandbox) && $row->meta->sandbox == 'true');
        $cache_key = 'tld_prices_' . $row->id . '_' . ($sandbox ? 'sandbox' : 'live');
        $cache_dir = Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'dynadot' . DS;

        if (!$force_refresh) {
            $cache = Cache::fetchCache($cache_key, $cache_dir);
            if ($cache) {
                $cached = json_decode($cache, true);
                if (is_array($cached)) {
                    return $cached;
                }
            }
        }

        $api = $this->getApi(null, $row->meta->key, $sandbox, null, false, $row->meta->secret ?? null);
        $prices = [];
        $page_size = 100;

        for ($page = 1; $page <= 100; $page++) {
            $response = $api->submit(
                'tld_price',
                ['currency' => 'usd', 'page_index' => $page, 'count_per_page' => $page_size]
            );
            $result = $response->response();

            if ($response->status() != 'success') {
                $this->logApiRequest($api, $response);
                break;
            }

            $items = $result->TldPriceResponse->TldPrice ?? [];
            if (!is_array($items)) {
                $items = [$items];
            }

            $new_tlds = 0;
            foreach ($items as $item) {
                if (!isset($item->Tld) || !isset($item->Price)) {
                    continue;
                }

                $tld = '.' . ltrim(strtolower(trim((string) $item->Tld)), '.');
                if (isset($prices[$tld])) {
                    continue;
                }

                $new_tlds++;
                $prices[$tld] = [
                    'registration' => $this->parsePriceValue($item->Price->Register ?? 0),
                    'transfer' => $this->parsePriceValue($item->Price->Transfer ?? 0),
                    'renew' => $this->parsePriceValue($item->Price->Renew ?? 0),
                    'min' => max(1, (int) ($item->minDuration ?? 1)),
                    'max' => min(10, max(1, (int) ($item->maxDuration ?? 10)))
                ];
            }

            if ($new_tlds == 0 || count($items) < $page_size) {
                break;
            }
        }

        if (!empty($prices) && Configure::get('Caching.on') && is_writable(CACHEDIR)) {
            try {
                Cache::writeCache(
                    $cache_key,
                    json_encode($prices),
                    max(60, strtotime(Configure::get('Blesta.cache_length')) - time()),
                    $cache_dir
                );
            } catch (Throwable $e) {
            }
        }

        return $prices;
    }

    /**
     * Parses a price value into a float.
     */
    private function parsePriceValue($price)
    {
        return is_scalar($price) ? (float) str_replace(',', '', (string) $price) : 0.0;
    }

    /**
     * Converts cached USD TLD prices into each configured Blesta currency.
     */
    protected function getPrices(array $filters = [])
    {
        $usd_prices = $this->getUsdPrices();
        if (empty($usd_prices)) {
            return [];
        }

        Loader::loadModels($this, ['Currencies']);
        $company_id = Configure::get('Blesta.company_id');
        $currencies = $this->Currencies->getAll($company_id);

        $pricing = [];
        foreach ($usd_prices as $tld => $price) {
            if (isset($filters['tlds']) && !in_array($tld, (array) $filters['tlds'])) {
                continue;
            }

            foreach ($currencies as $currency) {
                if (isset($filters['currencies']) && !in_array($currency->code, (array) $filters['currencies'])) {
                    continue;
                }

                $pricing[$tld][$currency->code] = (object) [
                    'registration' => $this->Currencies->convert($price['registration'], 'USD', $currency->code, $company_id),
                    'transfer' => $this->Currencies->convert($price['transfer'], 'USD', $currency->code, $company_id),
                    'renew' => $this->Currencies->convert($price['renew'], 'USD', $currency->code, $company_id),
                    'min' => $price['min'],
                    'max' => $price['max']
                ];
            }
        }

        return $pricing;
    }

    /**
     * Expands unit TLD prices across supported multi-year terms for Blesta's Domain Manager.
     */
    public function getFilteredTldPricing($module_row_id = null, $filters = [])
    {
        if ($module_row_id) {
            $this->setModuleRow($this->getModuleRow($module_row_id));
        }

        $pricing = [];
        foreach ($this->getPrices($filters) as $tld => $currency_prices) {
            foreach ($currency_prices as $currency => $prices) {
                $pricing[$tld][$currency] = [];

                foreach (range($prices->min, $prices->max) as $years) {
                    if (isset($filters['terms']) && !in_array($years, (array) $filters['terms'])) {
                        continue;
                    }

                    $pricing[$tld][$currency][$years] = [
                        'register' => round($prices->registration * $years, 2),
                        'transfer' => round($prices->transfer * $years, 2),
                        'renew' => round($prices->renew * $years, 2)
                    ];
                }
            }
        }

        return $pricing;
    }

    /**
     * Public entry point for Blesta's Domain Manager TLD price sync.
     */
    public function getTldPricing($module_row_id = null)
    {
        return $this->getFilteredTldPricing($module_row_id);
    }

    /**
     * Returns the list of supported TLD extensions.
     */
    public function getTlds($module_row_id = null)
    {
        if ($module_row_id) {
            $this->setModuleRow($this->getModuleRow($module_row_id));
        }

        return array_keys($this->getUsdPrices());
    }

    /**
     * Finds or creates a Dynadot contact ID for a Blesta client.
     */
    private function findContactIdForClient($client_id, $api, $module_row = null)
    {
        if (!$client_id) {
            return null;
        }
        $client = $this->Clients->get($client_id);
        if (!$client) {
            return null;
        }

        $phone = '';
        if (!empty($client->contact_id)) {
            Loader::loadModels($this, ['Contacts']);
            $numbers = $this->Contacts->getNumbers($client->contact_id, 'phone');
            foreach ((array) $numbers as $number) {
                if (is_object($number)) {
                    $number = (array) $number;
                }
                if (is_array($number)) {
                    $phone = trim((string) (
                        $number['number']
                        ?? ($number['value']
                        ?? ($number['phone_number'] ?? ''))
                    ));
                } elseif (is_scalar($number)) {
                    $phone = trim((string) $number);
                }
                if ($phone !== '') {
                    break;
                }
            }
        }
        if ($phone === '') {
            $phone = trim((string) ($client->phone ?? ($client->phone_number ?? '')));
        }

        $data = [
            'first_name' => $client->first_name ?? '',
            'last_name' => $client->last_name ?? '',
            'email' => $client->email ?? '',
            'phone' => $phone,
            'company' => $client->company ?? '',
            'address1' => $client->address1 ?? '',
            'address2' => $client->address2 ?? '',
            'city' => $client->city ?? '',
            'state' => $client->state ?? '',
            'zip' => $client->zip ?? '',
            'country' => $client->country ?? ''
        ];

        $module = $this->getModule();
        if (!$module_row || empty($module->id)) {
            return $this->createRegistrarContact($data, $api);
        }

        Loader::loadModels($this, ['ModuleClientMeta']);
        $hash = md5(json_encode($data));
        $meta = $this->ModuleClientMeta->get($client_id, 'registrant_contact', $module->id, $module_row->id);
        $saved = $meta ? json_decode($meta->value, true) : null;
        if (!empty($saved['id']) && ($saved['hash'] ?? null) === $hash) {
            $response = $api->submit('get_contact', ['contact_id' => $saved['id']]);
            $this->logApiRequest($api, $response);
            if ($response->status() == 'success') {
                return $saved['id'];
            }
        }

        $contact_id = $this->createRegistrarContact($data, $api);
        if ($contact_id) {
            $this->ModuleClientMeta->set(
                $client_id,
                $module->id,
                $module_row->id,
                [['key' => 'registrant_contact', 'value' => json_encode(['id' => $contact_id, 'hash' => $hash])]]
            );
        }

        return $contact_id;
    }

    // =========================================================================
    // Nameservers, Settings, Hosts, DNSSEC, DNS Records, and Email Forwarding
    // =========================================================================

    public function tabNameservers($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageNameservers('tab_nameservers', $package, $service, $get, $post, $files);
    }

    public function tabClientNameservers($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageNameservers('tab_client_nameservers', $package, $service, $get, $post, $files);
    }

    /**
     * Manages domain nameservers tab.
     */
    private function manageNameservers($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);
        $domain = $this->getServiceDomain($service);
        $this->validateAndReportNameservers($domain, $service->module_row_id ?? null);

        if (!empty($post)) {
            $ns = [];
            foreach ((array) ($post['ns'] ?? []) as $n) {
                $n = trim($n);
                if ($n !== '') {
                    $ns[] = $n;
                }
            }

            if (!empty($ns) && $this->setDomainNameservers($domain, $service->module_row_id, $ns)) {
                $this->setMessage('success', Language::_('Dynadot.!success.updated', true));
            }
            $vars = (object)$post;
        } else {
            $nameservers = $this->getDomainNameServers($domain, $service->module_row_id);
            $vars->ns = [];
            foreach ($nameservers as $ns) {
                $vars->ns[] = $ns['url'];
            }
        }
        $this->view->set('vars', $vars);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    public function tabSettings($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageSettings('tab_settings', $package, $service, $get, $post, $files);
    }

    public function tabClientSettings($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageSettings('tab_client_settings', $package, $service, $get, $post, $files);
    }

    /**
     * Manages domain lock and EPP code settings tab.
     */
    private function manageSettings($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        if (!empty($post)) {
            if (isset($post['registrar_lock'])) {
                $locked = $this->getDomainIsLocked($domain, $service->module_row_id);
                if ($post['registrar_lock'] == 'yes' && !$locked) {
                    $this->lockDomain($domain, $service->module_row_id);
                } elseif ($post['registrar_lock'] == 'no' && $locked) {
                    $this->unlockDomain($domain, $service->module_row_id);
                }
            }
            if (isset($post['request_epp']) && $post['request_epp'] == 'true') {
                $this->requestDomainEppCode($api, $domain);
            }
            $vars = (object) $post;
        }

        $vars->registrar_lock = $this->getDomainIsLocked($domain, $service->module_row_id) ? 'yes' : 'no';

        $this->view->set('vars', $vars);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    public function tabHosts($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageHosts('tab_hosts', $package, $service, $get, $post, $files);
    }

    public function tabClientHosts($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageHosts('tab_client_hosts', $package, $service, $get, $post, $files);
    }

    /**
     * Manages child nameservers (glue records) via RESTful v2 /restful/v2/nameservers.
     */
    private function manageHosts($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        $suffix = '.' . strtolower($domain);

        if (!empty($post)) {
            $host = strtolower(trim((string) ($post['host'] ?? '')));

            if (($post['action'] ?? '') == 'delete') {
                $server_id = null;
                if ($host !== '' && strpos($host, ',') === false) {
                    $response = $api->submit('server_list');
                    $this->logApiRequest($api, $response);
                    foreach ($this->extractNameserverList($response) as $server) {
                        if (isset($server->ServerName, $server->ServerId)
                            && strtolower((string) $server->ServerName) === $host . $suffix
                        ) {
                            $server_id = (string) $server->ServerId;
                            break;
                        }
                    }
                }

                if ($server_id !== null) {
                    $response = $api->submit('delete_ns', ['server_id' => $server_id]);
                    $this->processApiResponse($api, $response);
                    if ($response->status() == 'success') {
                        $this->setMessage('success', Language::_('Dynadot.!success.updated', true));
                    }
                }
            } elseif ($host !== '' || !empty($post['ip'])) {
                $ip = trim((string) ($post['ip'] ?? ''));

                if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)) {
                    $this->Input->setErrors(['host' => ['valid' => Language::_('Dynadot.!error.host.valid', true)]]);
                } elseif (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    $this->Input->setErrors(['ip' => ['valid' => Language::_('Dynadot.!error.ip.valid', true)]]);
                } else {
                    $response = $api->submit('register_ns', ['host' => $host . $suffix, 'ip' => $ip]);
                    $this->processApiResponse($api, $response);
                    if ($response->status() == 'success') {
                        $this->setMessage('success', Language::_('Dynadot.!success.updated', true));
                    }
                }

                if ($this->Input->errors()) {
                    $vars->new_host = $host;
                    $vars->new_ip = $ip;
                }
            }
        }

        $response = $api->submit('server_list');
        $this->logApiRequest($api, $response);

        $hosts = [];
        foreach ($this->extractNameserverList($response) as $server) {
            $name = strtolower((string) ($server->ServerName ?? ''));

            if (strlen($name) > strlen($suffix) && substr($name, -strlen($suffix)) === $suffix) {
                $ips = [];
                $server_ips = $server->ServerIps ?? [];
                foreach ((is_array($server_ips) ? $server_ips : [$server_ips]) as $server_ip) {
                    if (!empty($server_ip->Ip)) {
                        $ips[] = (string) $server_ip->Ip;
                    }
                }

                $hosts[] = (object) ['host' => substr($name, 0, -strlen($suffix)), 'ip' => implode(', ', $ips)];
            }
        }
        $vars->hosts = $hosts;

        $this->view->set('vars', $vars);
        $this->view->set('domain', $domain);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    public function tabDnssec($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageDnssec('tab_dnssec', $package, $service, $get, $post, $files);
    }

    public function tabClientDnssec($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageDnssec('tab_client_dnssec', $package, $service, $get, $post, $files);
    }

    /**
     * Manages DNSSEC records via RESTful v2 PUT/DELETE /restful/v2/domains/{domain_name}/dnssec.
     */
    private function manageDnssec($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        $algorithms = [
            1 => 'RSA/MD5', 2 => 'Diffie-Hellman', 3 => 'DSA/SHA-1', 5 => 'RSA/SHA-1',
            6 => 'DSA-NSEC3-SHA1', 7 => 'RSASHA1-NSEC3-SHA1', 8 => 'RSA/SHA-256',
            10 => 'RSA/SHA-512', 13 => 'ECDSA Curve P-256 with SHA-256', 14 => 'ECDSA Curve P-384 with SHA-384',
            15 => 'ED25519', 16 => 'ED448'
        ];
        $digest_types = [1 => 'SHA-1', 2 => 'SHA-256', 3 => 'GOSTR 34.11-94', 4 => 'SHA-384'];

        if (!empty($post['action'])) {
            $response = null;
            if ($post['action'] == 'add') {
                $key_tag = trim((string) ($post['key_tag'] ?? ''));
                $digest = preg_replace('/\s+/', '', (string) ($post['digest'] ?? ''));

                if (!ctype_digit($key_tag) || (int) $key_tag > 65535
                    || !array_key_exists((int) ($post['algorithm'] ?? 0), $algorithms)
                    || !array_key_exists((int) ($post['digest_type'] ?? 0), $digest_types)
                    || !ctype_xdigit($digest)
                ) {
                    $this->Input->setErrors(['dnssec' => ['valid' => Language::_('Dynadot.!error.dnssec.valid', true)]]);
                    $vars = (object) $post;
                    $get['action'] = 'add';
                } else {
                    $response = $api->submit('set_dnssec', [
                        'domain_name' => $domain,
                        'key_tag' => $key_tag,
                        'algorithm' => (int) $post['algorithm'],
                        'digest_type' => (int) $post['digest_type'],
                        'digest' => $digest
                    ]);
                }
            } elseif ($post['action'] == 'clear') {
                $response = $api->submit('clear_dnssec', ['domain_name' => $domain]);
            }

            if ($response) {
                $this->processApiResponse($api, $response);
                if ($response->status() == 'success') {
                    $this->setMessage('success', Language::_('Dynadot.!success.updated', true));
                } elseif ($post['action'] == 'add') {
                    $vars = (object) $post;
                    $get['action'] = 'add';
                }
            }
        }

        if (isset($get['action']) && $get['action'] == 'add') {
            $vars->add_view = true;
            $vars->algorithms = $algorithms;
            $vars->digest_types = $digest_types;
        }

        $this->view->set('vars', $vars);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    public function tabDnsRecords($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageDnsRecords('tab_dnsrecords', $package, $service, $get, $post, $files);
    }

    public function tabClientDnsRecords($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageDnsRecords('tab_client_dnsrecords', $package, $service, $get, $post, $files);
    }

    /**
     * Manages DNS zone records via RESTful v2 GET/POST /restful/v2/domains/{domain_name}/dns.
     */
    private function manageDnsRecords($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        if (!empty($post)) {
            $records = [];
            foreach ($post as $key => $value) {
                if (preg_match('/^main_record_type(\d+)$/', $key, $matches)) {
                    $i = $matches[1];
                    $records[] = [
                        'type' => strtolower(trim((string) $value)),
                        'subdomain' => strtolower(trim((string) ($post['subdomain' . $i] ?? ''))),
                        'value' => trim((string) ($post['main_record' . $i] ?? '')),
                        'x' => trim((string) ($post['record_x' . $i] ?? ''))
                    ];
                }
            }

            $args = $this->buildDnsArgs($domain, $records);
            if ($args !== false) {
                $response = $api->submit('set_dns2', $args);
                $this->processApiResponse($api, $response);
                if ($response->status() == 'success') {
                    $this->setMessage('success', Language::_('Dynadot.!success.updated', true));
                }
            }

            $records = array_values(array_filter($records, function ($record) {
                return $record['value'] !== '';
            }));
        } else {
            $response = $api->submit('get_dns', ['domain' => $domain]);
            $this->processApiResponse($api, $response);
            $records = $this->parseDnsRecords($response->response());
        }

        $this->validateAndReportNameservers($domain, $service->module_row_id ?? null);

        foreach ($records as $i => $record) {
            $vars->{'main_record_type' . $i} = strtoupper($record['type']);
            $vars->{'subdomain' . $i} = $record['subdomain'];
            $vars->{'main_record' . $i} = $record['value'];
            $vars->{'record_x' . $i} = $record['x'];
        }
        $vars->record_types = Configure::get('Dynadot.dns_records');

        $this->view->set('vars', $vars);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    /**
     * Validates and prepares DNS record arguments for DynadotApi::submit('set_dns2').
     */
    private function buildDnsArgs($domain, array $records)
    {
        $main_types = ['a', 'aaaa', 'cname', 'forward', 'txt', 'mx', 'stealth', 'email'];
        $sub_types = array_merge($main_types, ['srv']);
        $x_types = ['mx', 'forward', 'stealth', 'email'];

        $args = ['domain' => $domain];
        $main = 0;
        $sub = 0;
        foreach ($records as $record) {
            if ($record['value'] === '') {
                continue;
            }

            $is_main = in_array($record['subdomain'], ['', '@']);
            if (!in_array($record['type'], $is_main ? $main_types : $sub_types)
                || (!$is_main && !preg_match('/^(?:\*|[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?)(?:\.[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?)*$/', $record['subdomain']))
                || (in_array($record['type'], $x_types) && $record['x'] === '')
            ) {
                $this->Input->setErrors([
                    'dns' => ['valid' => Language::_('Dynadot.!error.dns.valid', true, strtoupper($record['type']), $record['value'])]
                ]);
                return false;
            }

            if ($is_main) {
                $args['main_record_type' . $main] = $record['type'];
                $args['main_record' . $main] = $record['value'];
                if ($record['x'] !== '') {
                    $args['main_recordx' . $main] = $record['x'];
                }
                $main++;
            } else {
                $args['subdomain' . $sub] = $record['subdomain'];
                $args['sub_record_type' . $sub] = $record['type'];
                $args['sub_record' . $sub] = $record['value'];
                if ($record['x'] !== '') {
                    $args['sub_recordx' . $sub] = $record['x'];
                }
                $sub++;
            }
        }

        if ($main == 0 && $sub == 0) {
            $this->Input->setErrors(['dns' => ['empty' => Language::_('Dynadot.!error.dns.empty', true)]]);
            return false;
        }

        if ($main > 20 || $sub > 100) {
            $this->Input->setErrors(['dns' => ['count' => Language::_('Dynadot.!error.dns.count', true)]]);
            return false;
        }

        return $args;
    }

    /**
     * Parses DNS records from both RESTful v2 (dns.main_records / dns.sub_records) and legacy structures.
     */
    private function parseDnsRecords($response)
    {
        if (!is_object($response)) {
            return [];
        }

        $records = [];
        $read = function ($item, $subdomain) {
            $x = $item->value2
                ?? ($item->distance
                ?? ($item->priority
                ?? ($item->Distance
                ?? ($item->Value2
                ?? ($item->RecordX
                ?? ($item->Priority ?? ''))))));

            return [
                'type' => strtolower((string) ($item->record_type ?? ($item->type ?? ($item->RecordType ?? ($item->Type ?? ''))))),
                'subdomain' => strtolower((string) $subdomain),
                'value' => (string) ($item->value ?? ($item->record ?? ($item->Value ?? ($item->Record ?? '')))),
                'x' => is_scalar($x) ? (string) $x : ''
            ];
        };

        // Support RESTful v2 DNS response objects (dns->main_records / dns->sub_records)
        $v2Dns = $response->dns ?? $response->name_server_settings ?? $response;
        if (is_object($v2Dns)) {
            foreach (['main_records' => false, 'main_domains' => false, 'sub_records' => true, 'sub_domains' => true] as $key => $is_sub) {
                if (!isset($v2Dns->$key)) {
                    continue;
                }
                $items = is_array($v2Dns->$key) ? $v2Dns->$key : [$v2Dns->$key];
                foreach ($items as $item) {
                    if (is_object($item)) {
                        $subhost = $is_sub ? ($item->subdomain ?? ($item->subhost ?? ($item->Subhost ?? ($item->Subdomain ?? '')))) : '';
                        $records[] = $read($item, $subhost);
                    }
                }
            }
        }

        // Also support normalized GetDnsResponse structures
        $settings = $response->GetDnsResponse->GetDns->NameServerSettings
            ?? ($response->GetDnsResponse->GetDnsContent->NameServerSettings ?? null);
        if (is_object($settings)) {
            foreach (['MainDomains' => false, 'SubDomains' => true] as $key => $is_sub) {
                if (!isset($settings->$key)) {
                    continue;
                }

                $items = is_array($settings->$key) ? $settings->$key : [$settings->$key];
                foreach ($items as $item) {
                    if (is_object($item)) {
                        $records[] = $read($item, $is_sub ? ($item->Subhost ?? ($item->Subdomain ?? '')) : '');
                    }
                }
            }

            for ($i = 0; isset($settings->{'MainRecordType' . $i}); $i++) {
                $records[] = [
                    'type' => strtolower((string) $settings->{'MainRecordType' . $i}),
                    'subdomain' => '',
                    'value' => (string) ($settings->{'MainRecord' . $i} ?? ''),
                    'x' => (string) ($settings->{'MainRecordX' . $i} ?? ($settings->{'MainRecordx' . $i} ?? ''))
                ];
            }
            for ($i = 0; isset($settings->{'SubRecordType' . $i}); $i++) {
                $records[] = [
                    'type' => strtolower((string) $settings->{'SubRecordType' . $i}),
                    'subdomain' => strtolower((string) ($settings->{'Subdomain' . $i} ?? '')),
                    'value' => (string) ($settings->{'SubRecord' . $i} ?? ''),
                    'x' => (string) ($settings->{'SubRecordX' . $i} ?? ($settings->{'SubRecordx' . $i} ?? ''))
                ];
            }
        }

        return array_values(array_filter($records, function ($record) {
            return $record['type'] !== '' && $record['value'] !== '';
        }));
    }

    public function tabEmailForwarding($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageEmailForwarding('tab_email_forwarding', $package, $service, $get, $post, $files);
    }

    public function tabClientEmailForwarding($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageEmailForwarding('tab_client_email_forwarding', $package, $service, $get, $post, $files);
    }

    /**
     * Manages email forwarding rules via RESTful v2 PUT /restful/v2/domains/{domain_name}/email_forwarding.
     */
    private function manageEmailForwarding($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        if (!empty($post)) {
            $args = ['domain' => $domain, 'forward_type' => 'forward'];
            $cnt = 0;
            $valid = true;
            foreach ($post as $k => $v) {
                if (preg_match('/^username(\d+)$/', $k, $matches)) {
                    $i = $matches[1];
                    $username = strtolower(trim((string) ($post['username' . $i] ?? '')));
                    $email = trim((string) ($post['exist_email' . $i] ?? ''));
                    if ($username === '' && $email === '') {
                        continue;
                    }

                    if (!preg_match('/^[a-z0-9!#$%&\'*+\/=?^_`{|}~.-]{1,64}$/', $username)
                        || !filter_var($email, FILTER_VALIDATE_EMAIL)
                    ) {
                        $this->Input->setErrors([
                            'email_forward' => ['valid' => Language::_('Dynadot.!error.email_forward.valid', true, $username, $email)]
                        ]);
                        $valid = false;
                    }

                    $args['username' . $cnt] = $username;
                    $args['exist_email' . $cnt] = $email;
                    $cnt++;
                }
            }

            if ($cnt > 10) {
                $this->Input->setErrors(['email_forward' => ['count' => Language::_('Dynadot.!error.email_forward.count', true)]]);
                $valid = false;
            }

            if ($cnt > 0 && $valid) {
                $response = $api->submit('set_email_forward', $args);
                $this->processApiResponse($api, $response);
                if ($response->status() == 'success') {
                    $this->setMessage('success', Language::_('Dynadot.!success.updated', true));
                }
            }

            for ($i = 0; $i < $cnt; $i++) {
                $vars->{'username' . $i} = $args['username' . $i];
                $vars->{'exist_email' . $i} = $args['exist_email' . $i];
            }
        }

        $this->view->set('vars', $vars);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }
}