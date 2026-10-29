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
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot
 * @author 10Corp <cs@10corp.com>
 * @copyright Copyright (c) 2026, 10Corp
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link https://www.10corp.com
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
     *
     * @return array Empty array (no initial module row meta).
     */
    public function install()
    {
        $this->registerCronTasks($this->getScheduledCronTasks());
        return [];
    }

    /**
     * Handles the upgrade step in the Blesta-to-Dynadot registrar workflow.
     *
     * @param string $current_version Currently installed module version for upgrade comparison.
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
     *
     * @param int $module_id Blesta module ID.
     * @param bool $last_instance True when this is the last remaining module instance being uninstalled.
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
     *
     * @param string $key Cron task key or API key string.
     */
    public function cron($key)
    {
        if ($key == 'pull_contacts') {
            $this->synchronizeContacts();
        } elseif ($key == 'pull_nameservers') {
            $this->synchronizeNameservers();
        } elseif ($key == 'update_pricing') {
            $this->refreshPricingCache();
        } elseif ($key == 'sync_domains' || $key == 'pull_domains' || $key == 'domain_sync') {
            $this->synchronizeAllDomains();
        }
    }

    /**
     * Returns scheduled cron task definitions.
     *
     * @return array Cron task definitions for contact sync, NS sync, domain sync, and pricing update.
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
                'key' => 'pull_nameservers',
                'task_type' => 'module',
                'dir' => 'dynadot',
                'name' => Language::_('Dynadot.getScheduledCronTasks.pull_nameservers_name', true),
                'description' => Language::_('Dynadot.getScheduledCronTasks.pull_nameservers_desc', true),
                'type' => 'interval',
                'type_value' => 10,
                'enabled' => 1
            ],
            [
                'key' => 'sync_domains',
                'task_type' => 'module',
                'dir' => 'dynadot',
                'name' => Language::_('Dynadot.getScheduledCronTasks.sync_domains_name', true),
                'description' => Language::_('Dynadot.getScheduledCronTasks.sync_domains_desc', true),
                'type' => 'interval',
                'type_value' => 60,
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
     *
     * @param array $tasks Array of cron task definition arrays.
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
     * Updates or creates a service field record in Blesta.
     *
     * @param int $service_id Blesta service ID
     * @param string $key Service field key
     * @param string $value Service field value
     */
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

    /**
     * Fully synchronizes all domain data from Dynadot into Blesta:
     * - Expiration and registration dates
     * - Active Nameservers (ns1-ns5)
     * - WHOIS Contacts (Registrant, Admin, Tech, Billing IDs and details)
     * - DNS Zone Records and Email Forwarding
     * - Lock, Privacy (ID Protection), and Auto-Renewal settings
     *
     * @param stdClass $service Blesta service object with id, client_id, and domain
     * @param stdClass|null $package Blesta package object
     * @return array Array with success status and synced details
     */
    public function synchronizeFullDomain($service, $package = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? ($package->module_row ?? null));
        if (!$row || empty($row->meta->key)) {
            return ['success' => false, 'message' => 'No active module row found.'];
        }

        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);
        $domain = $this->getServiceDomain($service);
        if (empty($domain)) {
            return ['success' => false, 'message' => 'No domain found for service.'];
        }

        // Delegate domain synchronization to DynadotDomainSync command
        $sync_cmd = new DynadotDomainSync($api);
        $sync_data = $sync_cmd->getFullSyncData(['domain' => $domain]);

        if (empty($sync_data['success'])) {
            return [
                'success' => false,
                'message' => $sync_data['message'] ?? 'Failed to fetch domain details from Dynadot.'
            ];
        }

        Loader::loadModels($this, ['Services']);

        // 1. Synchronize Expiration & Registration Dates
        $edit_data = [];
        if (!empty($sync_data['dates']['expires'])) {
            $edit_data['date_renews'] = $sync_data['dates']['expires'];
        }
        if (!empty($sync_data['dates']['registered'])) {
            $edit_data['date_last_renewed'] = $sync_data['dates']['registered'];
        }
        if (!empty($edit_data)) {
            $this->Services->edit($service->id, $edit_data, true);
        }

        // 2. Synchronize Nameservers (ns1 - ns5)
        $raw_ns_urls = $sync_data['nameservers'] ?? [];
        $ns_urls = [];
        $seen_ns = [];
        foreach ((array) $raw_ns_urls as $u) {
            $u = strtolower(trim((string) $u));
            if ($u !== '' && !isset($seen_ns[$u])) {
                $seen_ns[$u] = true;
                $ns_urls[] = $u;
            }
        }
        for ($i = 1; $i <= 5; $i++) {
            $key = 'ns' . $i;
            $val = $ns_urls[$i - 1] ?? '';
            $this->updateServiceField($service->id, $key, $val);
        }

        // 3. Synchronize WHOIS Contacts
        $contact_ids = $sync_data['contacts'] ?? [];
        foreach ($contact_ids as $role => $cid) {
            if (!empty($cid)) {
                $this->updateServiceField($service->id, $role . '_contact_id', (string) $cid);
                $this->updateServiceField($service->id, $role . '_contact', (string) $cid);
            }
        }

        // Pull full contact profiles into client meta
        if (!empty($service->client_id)) {
            $this->synchronizeContactsForQueuedDomains([$service->client_id => [$domain]], $row);
        }

        // 4. Synchronize Security Settings (Lock, Privacy, Auto-Renew)
        $settings = $sync_data['settings'] ?? [];
        $this->updateServiceField($service->id, 'locked', !empty($settings['locked']) ? '1' : '0');
        $this->updateServiceField($service->id, 'id_protection', !empty($settings['privacy']) ? '1' : '0');
        $this->updateServiceField($service->id, 'auto_renew', !empty($settings['auto_renew']) ? '1' : '0');

        $this->clearDomainInfoCacheEntry($domain);

        return [
            'success' => true,
            'domain' => $domain,
            'dates' => $sync_data['dates'] ?? [],
            'nameservers' => $ns_urls,
            'contacts' => $contact_ids,
            'settings' => $settings,
            'dns' => !empty($sync_data['dns']['records']) || !empty($sync_data['dns']['email_forwarding']) || ($sync_data['dns']['glue_type'] ?? '') !== ''
        ];
    }

    /**
     * Fully synchronizes all active and suspended domain services with Dynadot:
     * Expiration dates, nameservers, WHOIS contacts, DNS records, lock and privacy status.
     */
    private function synchronizeAllDomains()
    {
        $module_rows = $this->Record->from('module_rows')->
            select(['module_rows.*'])->
            innerJoin('modules', 'modules.id', '=', 'module_rows.module_id', false)->
            where('modules.company_id', '=', Configure::get('Blesta.company_id'))->
            where('modules.class', '=', 'dynadot')->
            fetchAll();

        Loader::loadModels($this, ['ModuleManager']);

        foreach ($module_rows as $module_row) {
            $module_row = $this->ModuleManager->getConfiguredModuleRow($module_row->id);
            if (empty($module_row->meta->key)) {
                continue;
            }

            $services = $this->Record->select([
                    'services.id',
                    'services.client_id',
                    'services.module_row_id',
                    'service_fields.value' => 'domain'
                ])->
                from('services')->
                innerJoin('service_fields', 'service_fields.service_id', '=', 'services.id', false)->
                where('services.module_row_id', '=', $module_row->id)->
                where('services.status', 'IN', ['active', 'suspended'])->
                where('service_fields.key', '=', 'domain')->
                fetchAll();

            foreach ($services as $service) {
                try {
                    $this->synchronizeFullDomain($service);
                } catch (Throwable $e) {
                    // Continue with next domain
                }
            }
        }
    }

    /**
     * Keeps service nameserver fields synchronized with Dynadot.
     */
    private function synchronizeNameservers()
    {
        $this->synchronizeAllDomains();
    }

    /**
     * Keep Blesta contact data in sync with contacts stored at Dynadot.
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
     *
     * @param stdClass $module_row Blesta module row object with meta fields (key, secret, sandbox).
     * @param mixed $remaining_batch_slots Remaining API call budget for the current cron batch.
     * @param int $client_id Blesta client ID.
     * @param array $additional_domains Extra domains to sync per client, keyed by client_id.
     * @return int|null Remaining API call budget after processing this module row.
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
     *
     * @param mixed $queued_client_domains Map of client_id => array of domain names to sync.
     * @param stdClass $module_row Blesta module row object with meta fields (key, secret, sandbox).
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
        $sync_cmd = new DynadotDomainSync($api);
        $client_contacts = $sync_cmd->fetchClientContactsForDomains($queued_client_domains);

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
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return array Array of nameserver entries, each with "url" and "ips" keys.
     */
    public function getDomainNameServers($domain, $module_row_id = null)
    {
        $row = $this->getRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);

        $dns = new DynadotDomainsDns($api);
        $response = $dns->getNameservers(['domain' => $domain]);
        if (!$this->isDomainPendingResponse($api, $response)) {
            $this->processApiResponse($api, $response);
        }
        $result = $response->response();

        $raw_ns = $dns->parseNameserverList($response);
        $nameservers = [];
        foreach ($raw_ns as $ns_host) {
            $nameservers[] = ['url' => $ns_host, 'ips' => []];
        }

        if (empty($nameservers) && !empty($domain)) {
            // Check local service_fields for this domain if remote API returned no nameservers
            $service = $this->Record->select(['services.id', 'services.pricing_id'])
                ->from('services')
                ->innerJoin('service_fields', 'service_fields.service_id', '=', 'services.id', false)
                ->where('service_fields.key', '=', 'domain')
                ->where('service_fields.value', '=', $domain)
                ->where('services.status', '!=', 'canceled')
                ->order(['services.id' => 'DESC'])
                ->fetch();

            if ($service) {
                $fields = $this->Record->select(['key', 'value'])
                    ->from('service_fields')
                    ->where('service_id', '=', $service->id)
                    ->where('key', 'in', ['ns1', 'ns2', 'ns3', 'ns4', 'ns5'])
                    ->fetchAll();

                $local_ns = [];
                foreach ($fields as $field) {
                    $val = trim((string) ($field->value ?? ''));
                    if ($val !== '') {
                        $local_ns[$field->key] = $val;
                    }
                }
                ksort($local_ns);
                foreach ($local_ns as $ns_host) {
                    $nameservers[] = ['url' => $ns_host, 'ips' => []];
                }

                // If still empty, fall back to package default nameservers
                if (empty($nameservers) && !empty($service->pricing_id)) {
                    Loader::loadModels($this, ['Packages']);
                    $packages_model = new Packages();
                    $pkg = $packages_model->getByPricingId($service->pricing_id);
                    if ($pkg && !empty($pkg->meta->ns)) {
                        foreach ((array) $pkg->meta->ns as $pkg_ns) {
                            $pkg_ns = trim((string) $pkg_ns);
                            if ($pkg_ns !== '') {
                                $nameservers[] = ['url' => $pkg_ns, 'ips' => []];
                            }
                        }
                    }
                }
            }
        }

        // Deduplicate nameservers case-insensitively while preserving order
        $deduped = [];
        $seen = [];
        foreach ($nameservers as $ns) {
            $url = strtolower(trim((string) ($ns['url'] ?? '')));
            if ($url !== '' && !isset($seen[$url])) {
                $seen[$url] = true;
                $deduped[] = [
                    'url' => $url,
                    'ips' => $ns['ips'] ?? []
                ];
            }
        }

        return $deduped;
    }

    /**
     * Updates a domain's nameservers via RESTful v2 PUT /restful/v2/domains/{domain_name}/nameservers.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @param array $vars Form input data or service field overrides.
     * @return bool True on success, false on validation error or API failure.
     */
    public function setDomainNameservers($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);

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

        $dns = new DynadotDomainsDns($api);
        $response = $dns->setNameservers($args);
        $is_pending = $this->isDomainPendingResponse($api, $response);
        if (!$is_pending) {
            $this->processApiResponse($api, $response);
        }
        $this->clearDomainInfoCacheEntry($domain);

        return $response->status() == 'success' || $is_pending;
    }

    /**
     * Identifies the transient response returned while a newly registered
     * domain is not yet visible in the Dynadot account.
     *
     * @param DynadotApi $api Initialized DynadotApi client for the active Dynadot account.
     * @param DynadotResponse $response DynadotResponse object from the most recent API call.
     * @return bool True if the error indicates the domain is still initializing.
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
     *
     * @param DynadotApi $api Initialized DynadotApi client for the active Dynadot account.
     * @param array $nameservers Array of nameserver hostnames.
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
     *
     * @param DynadotResponse $response DynadotResponse object from the most recent API call.
     * @return array Array of server objects with ServerId, ServerName, and ServerIps.
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
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param array|null $vars Form input data or service field overrides.
     * @return bool True if validation passes, false on error.
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
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param array|null $vars Form input data or service field overrides.
     * @param mixed $parent_package Parent package when using package hierarchies, or null.
     * @param mixed $parent_service Parent service when using service hierarchies, or null.
     * @param string $status Initial service status ("pending", "active", etc.).
     * @return array|void Array of service meta key/value entries, or void on error.
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
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array $vars Form input data or service field overrides.
     * @param mixed $parent_package Parent package when using package hierarchies, or null.
     * @param mixed $parent_service Parent service when using service hierarchies, or null.
     * @return array|null Updated service meta array, or null when no changes were needed.
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

            $domains_privacy = new DynadotDomainsPrivacy($api);
            $response = $domains_privacy->set([
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
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param mixed $parent_package Parent package when using package hierarchies, or null.
     * @param mixed $parent_service Parent service when using service hierarchies, or null.
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        if ($package->meta->type == 'domain') {
            $domain = $this->getServiceDomain($service);
            $renew = new DynadotDomainsRenew($api);
            $response = $renew->setRenewOption(['domain' => $domain, 'renew_option' => 'donot']);
            $this->processApiResponse($api, $response);
        }
        return;
    }

    /**
     * Suspends a domain service by disabling auto-renewal.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param mixed $parent_package Parent package when using package hierarchies, or null.
     * @param mixed $parent_service Parent service when using service hierarchies, or null.
     * @return void 
     */
    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->cancelService($package, $service, $parent_package, $parent_service);
    }

    /**
     * Unsuspends a domain service by re-enabling auto-renewal.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param mixed $parent_package Parent package when using package hierarchies, or null.
     * @param mixed $parent_service Parent service when using service hierarchies, or null.
     */
    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        if ($package->meta->type == 'domain') {
            $domain = $this->getServiceDomain($service);
            $renew = new DynadotDomainsRenew($api);
            $response = $renew->setRenewOption(['domain' => $domain, 'renew_option' => 'auto']);
            $this->processApiResponse($api, $response);
        }
        return;
    }

    /**
     * Renews a domain via RESTful v2 POST /restful/v2/domains/{domain_name}/renew.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param mixed $parent_package Parent package when using package hierarchies, or null.
     * @param mixed $parent_service Parent service when using service hierarchies, or null.
     * @param int $years Registration or renewal term in years.
     * @return null Always null (renewal status is communicated via Input errors).
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
     *
     * @param array|null $vars Form input data or service field overrides.
     * @return array Array of package meta key/value entries.
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
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param array|null $vars Form input data or service field overrides.
     * @return array Array of package meta key/value entries.
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
     *
     * @param mixed $module Module.
     * @param array $vars Form input data or service field overrides.
     * @return string Rendered HTML for the module management view.
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
     * Resolves and sets the current module record from the database if not already loaded.
     * Follows the official NameSilo module pattern using ModuleManager::getByClass.
     *
     * @return stdClass The installed module record object.
     */
    private function resolveModule()
    {
        $module = $this->getModule();
        if (!$module || empty($module->id)) {
            if (!isset($this->ModuleManager)) {
                Loader::loadModels($this, ['ModuleManager']);
            }
            $modules = $this->ModuleManager->getByClass(
                \Illuminate\Support\Str::snake(get_class($this)),
                Configure::get('Blesta.company_id')
            );
            $module = (object) ($modules[0] ?? []);
            if (!empty($module->id)) {
                $this->setModule($module);
            }
        }

        return $module;
    }

    /**
     * Renders the add module row or domain audit/import screen.
     *
     * @param array $vars Form input data or service field overrides.
     */
    public function manageAddRow(array &$vars)
    {
        $this->resolveModule();
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
            $importer = new DynadotDomainImport($this);
            $module_row = $importer->getImportSourceModuleRow($_GET['module_row_id'] ?? null);
            if (!$module_row) {
                $this->view = new View('add_row', 'default');
                $this->view->base_uri = $this->base_uri;
                $this->view->setDefaultView(self::$defaultModuleView);
                Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
                $this->view->set('module', $this->getModule());
                $this->view->set('vars', (object) ['sandbox' => 'false']);
                return $this->view->fetch();
            }

            $this->view->set('vars', (object) $importer->buildImportOverview($module_row));
            $this->view->set('module_row', $module_row);
            $this->view->set('module_id', $this->getModule()->id ?? null);
            $this->view->set('import_token', $importer->getImportApiToken($module_row->id));

            return $this->view->fetch();
        } elseif ($action == 'import_domain') {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            $importer = new DynadotDomainImport($this);
            exit(json_encode($importer->importRegistrarDomain($_GET)));
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
     * Renders the edit module row screen.
     *
     * @param stdClass $module_row Blesta module row object with meta fields (key, secret, sandbox).
     * @param array $vars Form input data or service field overrides.
     * @return string Rendered HTML for the edit-row view.
     */
    public function manageEditRow($module_row, array &$vars)
    {
        $this->resolveModule();
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
     *
     * @param array $vars Form input data or service field overrides.
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
     *
     * @param stdClass $module_row Blesta module row object with meta fields (key, secret, sandbox).
     * @param array $vars Form input data or service field overrides.
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
     *
     * @param array|stdClass $vars Form input data or service field overrides.
     * @return array Blesta validation rule array for key and secret fields.
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
     *
     * @param string $key Cron task key or API key string.
     * @param string $user Account display label for the Blesta admin UI.
     * @param bool $sandbox True to target the Sandbox API, false for Production.
     * @param string $secret Dynadot API Secret for HMAC-SHA256 request signing.
     * @return bool True if the credentials produce a successful API response.
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
     *
     * @param mixed $portfolio Portfolio.
     * @param string $key Cron task key or API key string.
     * @param string $user Account display label for the Blesta admin UI.
     * @param bool $sandbox True to target the Sandbox API, false for Production.
     * @return bool Always true (placeholder).
     */
    public function validatePortfolio($portfolio, $key, $user, $sandbox)
    {
        return true;
    }

    /**
     * Initializes the DynadotApi RESTful v2 client for the selected Dynadot account.
     * Automatically resolves the API Secret from the matching module row when not explicitly provided.
     *
     * @param string $user Account display label for the Blesta admin UI.
     * @param string $key Cron task key or API key string.
     * @param bool $sandbox True to target the Sandbox API, false for Production.
     * @param string $username User/account label (not sent to Dynadot).
     * @param bool $batch Reserved for batch mode (unused in RESTful v2).
     * @param string $secret Dynadot API Secret for HMAC-SHA256 request signing.
     * @return DynadotApi Configured DynadotApi client for the selected Dynadot account.
     */
    public function getApi($user = null, $key = null, $sandbox = true, $username = null, $batch = false, $secret = null)
    {
        Loader::load(__DIR__ . DS . 'apis' . DS . 'dynadot_api.php');

        if (empty($key) || $secret === null) {
            if (!empty($key) && $secret === null) {
                foreach ((array) $this->getRows() as $r) {
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
     *
     * @param DynadotApi $api Initialized DynadotApi client for the active Dynadot account.
     * @param DynadotResponse $response DynadotResponse object from the most recent API call.
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
     *
     * @param DynadotApi $api Initialized DynadotApi client for the active Dynadot account.
     * @param DynadotResponse $response DynadotResponse object from the most recent API call.
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
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @return string TLD string including the leading dot (e.g., ".com").
     */
    private function extractTopLevelDomain($domain)
    {
        return strstr($domain, '.');
    }

    /**
     * Validates an ASCII or punycode domain name.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @return bool True if the domain matches the allowed ASCII/punycode pattern.
     */
    private function isDomainNameValid($domain)
    {
        $domain = strtolower(trim((string) $domain));

        return strlen($domain) <= 253
            && (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $domain);
    }

    /**
     * Validates a nameserver hostname.
     *
     * @param mixed $host Nameserver hostname to validate.
     * @return bool True if the hostname is a valid domain name.
     */
    private function isHostnameValid($host)
    {
        return $this->isDomainNameValid(rtrim((string) $host, '.'));
    }

    /**
     * Retrieves all Dynadot module rows across installed modules.
     *
     * @return array An array containing all Dynadot module rows.
     */
    private function getRows()
    {
        if (!isset($this->ModuleManager)) {
            Loader::loadModels($this, ['ModuleManager']);
        }

        $module_rows = [];
        $company_id = Configure::get('Blesta.company_id');
        $filters = !empty($company_id) ? ['company_id' => $company_id] : [];
        $modules = $this->ModuleManager->getInstalled($filters);

        foreach ($modules as $module) {
            $module_data = $this->ModuleManager->get($module->id);
            if ($module_data && strtolower($module_data->class) === 'dynadot') {
                foreach ($module_data->rows as $module_row) {
                    $module_rows[] = $module_row;
                }
            }
        }

        return $module_rows;
    }

    /**
     * Retrieves the specified, active, or first Dynadot module row.
     *
     * @param int|null $module_row_id Optional module row ID.
     * @return stdClass|null An stdClass object representing the module row if found, null otherwise.
     */
    private function getRow($module_row_id = null)
    {
        if ($module_row_id) {
            $row = $this->getModuleRow($module_row_id);
            if (!empty($row)) {
                return $row;
            }

            if (!isset($this->ModuleManager)) {
                Loader::loadModels($this, ['ModuleManager']);
            }
            $row = $this->ModuleManager->getRow($module_row_id);
            if (!empty($row)) {
                return $row;
            }
        }

        if (($row = $this->getModuleRow())) {
            return $row;
        }

        $module_rows = $this->getRows();
        return $module_rows[0] ?? null;
    }

    /**
     * Returns the active or first configured module row.
     *
     * @return stdClass|null Active module row, first available row, or null.
     */
    private function getConfiguredModuleRow()
    {
        if (!empty($this->row)) {
            return $this->row;
        }

        return $this->getRow();
    }

    /**
     * Checks if a domain is available for registration via RESTful v2 GET /restful/v2/domains/{domain_name}/search.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return bool True if the domain is available for registration.
     */
    public function checkAvailability($domain, $module_row_id = null)
    {
        $row = $this->getRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);

        $domains = new DynadotDomainsRegister($api);
        $result = $domains->check(['domain0' => $domain]);
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
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return bool True if the domain is registered (transferable) and not available for new registration.
     */
    public function checkTransferAvailability($domain, $module_row_id = null)
    {
        $row = $this->getRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);

        $transfer = new DynadotDomainsTransfer($api);
        $result = $transfer->check(['domain0' => $domain]);
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
     *
     * @param string $tld Top-level domain extension (e.g., ".com", ".net").
     * @param int $term Registration/renewal term length in years.
     * @param bool $transfer True when the term is for a transfer (max 1 year at Dynadot).
     * @return bool True if the term is within Dynadot limits (max 10 years, max 1 for transfer).
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
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @param array $vars Form input data or service field overrides.
     * @return bool True on successful registration, false on failure.
     */
    public function registerDomain($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);

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

        $domains = new DynadotDomainsRegister($api);
        $response = $domains->create($args);
        $this->processApiResponse($api, $response);

        if ($response->status() != 'success') {
            return false;
        }

        return true;
    }

    /**
     * Initiates a domain transfer via RESTful v2 POST /restful/v2/domains/{domain_name}/transfer_in.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @param array $vars Form input data or service field overrides.
     * @return bool True on successful transfer initiation, false on failure.
     */
    public function transferDomain($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);

        $args = [
            'domain' => $domain,
            'auth' => trim((string) ($vars['auth'] ?? ($vars['auth_code'] ?? ($vars['auth_info'] ?? ($vars['transfer_key'] ?? ($vars['epp_code'] ?? ''))))))
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

        $transfer = new DynadotDomainsTransfer($api);
        $response = $transfer->create($args);
        $this->processApiResponse($api, $response);

        return $response->status() == 'success';
    }

    /**
     * Extracts valid nameservers from form input arrays.
     *
     * @param array $vars Form input data or service field overrides.
     * @return array Deduplicated array of valid lowercase nameserver hostnames.
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
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @param array $vars Form input data or service field overrides.
     * @return bool True on successful renewal, false on failure.
     */
    public function renewDomain($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);

        $args = [
            'domain' => $domain,
            'duration' => $vars['years'] ?? ($vars['duration'] ?? 1)
        ];

        $renew = new DynadotDomainsRenew($api);
        $response = $renew->renew($args);
        $this->processApiResponse($api, $response);
        $this->clearDomainInfoCacheEntry($domain);

        return $response->status() == 'success';
    }

    /**
     * Restores an expired domain via RESTful v2 POST /restful/v2/domains/{domain_name}/restore.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @param array $vars Form input data or service field overrides.
     * @return bool True on successful restoration, false on failure.
     */
    public function restoreDomain($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true');

        $renew = new DynadotDomainsRenew($api);
        $response = $renew->restore(['domain' => $domain]);
        $this->processApiResponse($api, $response);

        return $response->status() == 'success';
    }

    /**
     * Locks a domain via RESTful v2 PUT /restful/v2/domains/{domain_name}/lock_status.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return bool True on successful lock, false on failure.
     */
    public function lockDomain($domain, $module_row_id = null)
    {
        $row = $this->getRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);

        $epp = new DynadotDomainsEpp($api);
        $response = $epp->lockDomain(['domain' => $domain]);
        $this->processApiResponse($api, $response);
        $this->clearDomainInfoCacheEntry($domain);

        return $response->status() == 'success';
    }

    /**
     * Unlocks a domain via RESTful v2.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return bool True on successful unlock, false on failure.
     */
    public function unlockDomain($domain, $module_row_id = null)
    {
        $row = $this->getRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);

        $epp = new DynadotDomainsEpp($api);
        $response = $epp->unlockDomain(['domain' => $domain]);
        $this->processApiResponse($api, $response);
        $this->clearDomainInfoCacheEntry($domain);

        return $response->status() == 'success';
    }

    /**
     * Fetches and caches domain details from RESTful v2 GET /restful/v2/domains/{domain_name}.
     *
     * @param DynadotApi $api Initialized DynadotApi client for the active Dynadot account.
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param bool $process True to process errors via processApiResponse(), false to just log.
     * @return stdClass|null Cached DomainInfo object, or null on failure.
     */
    private function fetchRegistrarDomainInfo(DynadotApi $api, $domain, $process = false)
    {
        $key = strtolower($domain);
        if (!array_key_exists($key, self::$domain_info_cache)) {
            $domains_register = new DynadotDomainsRegister($api);
            $response = $domains_register->getInfo(['domain' => $domain]);
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
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     */
    private function clearDomainInfoCacheEntry($domain)
    {
        unset(self::$domain_info_cache[strtolower($domain)]);
    }

    /**
     * Checks whether a domain is currently locked.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return bool True if the domain lock status is "yes" or true.
     */
    public function getDomainIsLocked($domain, $module_row_id = null)
    {
        $row = $this->getRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);

        $info = $this->fetchRegistrarDomainInfo($api, $domain);

        return isset($info->Locked) && ($info->Locked == 'yes' || $info->Locked === true);
    }

    /**
     * Returns normalized domain info for Blesta's Domain Manager.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return array Associative array with domain, dates, locked, and privacy fields.
     */
    public function getDomainInfo($domain, $module_row_id = null)
    {
        $row = $this->getRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);

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
                    strtolower(trim((string) ($d->Privacy ?? ($d->WhoisPrivacy ?? '')))),
                    ['', 'no', 'none', 'off', 'privacy off', 'privacy_off']
                ) && stripos((string) ($d->Privacy ?? ($d->WhoisPrivacy ?? '')), 'off') === false,
            ];
        }
        return [];
    }

    /**
     * Transfer email resend placeholder.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return bool Always false (placeholder, not supported by RESTful v2).
     */
    public function resendTransferEmail($domain, $module_row_id = null)
    {
        return false;
    }

    /**
     * Requests the domain's EPP/transfer auth code via RESTful v2 GET /restful/v2/domains/{domain_name}/transfer_auth_code.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return bool True on successful auth code retrieval.
     */
    public function sendEppEmail($domain, $module_row_id = null)
    {
        $row = $this->getRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);

        return $this->requestDomainEppCode($api, $domain);
    }

    /**
     * Updates WHOIS Privacy (ID Protection) via RESTful v2 PUT /restful/v2/domains/{domain_name}/privacy.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param bool $enabled True to enable full privacy, false to disable.
     * @param stdClass|int|null $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return bool True on success, false on failure.
     */
    public function setDomainPrivacy($domain, $enabled = true, $module_row_id = null)
    {
        $row = $this->getRow($module_row_id);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);

        $privacy = new DynadotDomainsPrivacy($api);
        $response = $privacy->set([
            'domain' => $domain,
            'option' => $enabled ? 'full' : 'off',
            'whois_privacy_option' => $enabled ? 'yes' : 'no'
        ]);
        $this->processApiResponse($api, $response);
        $this->clearDomainInfoCacheEntry($domain);

        return $response->status() == 'success';
    }

    /**
     * Custom EPP code update placeholder.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param string $epp_code EPP/transfer authorization code.
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @param array $vars Form input data or service field overrides.
     * @return bool Always false (placeholder, Dynadot manages EPP codes internally).
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
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return array Array of contact arrays with external_id, name, email, phone, address, and type.
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
        if (empty($contacts) && !empty($domain)) {
            $service = $this->Record->select(['services.id', 'services.client_id'])
                ->from('services')
                ->innerJoin('service_fields', 'service_fields.service_id', '=', 'services.id', false)
                ->where('service_fields.key', '=', 'domain')
                ->where('service_fields.value', '=', $domain)
                ->where('services.status', '!=', 'canceled')
                ->order(['services.id' => 'DESC'])
                ->fetch();

            if ($service && !empty($service->client_id)) {
                $vars = new stdClass();
                $this->populateWhoisContactDefaults($service, $vars);
                foreach (['Registrant', 'Admin', 'Technical', 'Billing'] as $section) {
                    $contacts[] = [
                        'external_id' => null,
                        'email' => $vars->{$section . 'EmailAddress'} ?? '',
                        'phone' => $vars->{$section . 'Phone'} ?? '',
                        'first_name' => $vars->{$section . 'FirstName'} ?? '',
                        'last_name' => $vars->{$section . 'LastName'} ?? '',
                        'address1' => $vars->{$section . 'Address1'} ?? '',
                        'address2' => $vars->{$section . 'Address2'} ?? '',
                        'city' => $vars->{$section . 'City'} ?? '',
                        'state' => $vars->{$section . 'StateProvince'} ?? '',
                        'zip' => $vars->{$section . 'PostalCode'} ?? '',
                        'country' => $vars->{$section . 'Country'} ?? '',
                        'type' => $section
                    ];
                }
            }
        }

        return $contacts;
    }

    /**
     * Assigns or creates WHOIS contacts for a domain via RESTful v2 PUT /restful/v2/domains/{domain_name}/contacts.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param array $vars Form input data or service field overrides.
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return bool True on successful WHOIS assignment, false if no contacts were provided.
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
            $is_pending = $this->isDomainPendingResponse($api, $response);
            if (!$is_pending) {
                $this->processApiResponse($api, $response);
            }
            $this->clearDomainInfoCacheEntry($domain);

            return $response->status() == 'success' || $is_pending;
        }

        return false;
    }

    /**
     * Normalizes Blesta contact fields for Dynadot contact creation/updating.
     * Delegates to DynadotDomainsRegister command class.
     *
     * @param array $data Contact field data (first_name, last_name, email, phone, etc.).
     * @return array Filtered associative array of contact fields for the Dynadot API.
     */
    private function buildContactArguments(array $data)
    {
        if (!isset($this->Contacts)) {
            Loader::loadModels($this, ['Contacts']);
        }
        $register = new DynadotDomainsRegister($this->getApi());
        return $register->buildContactArguments($data, $this->Contacts ?? null);
    }

    /**
     * Creates a new WHOIS contact at Dynadot via RESTful v2 POST /restful/v2/contacts.
     *
     * @param mixed $data Contact field data (first_name, last_name, email, phone, etc.).
     * @param mixed $api Initialized DynadotApi client for the active Dynadot account.
     * @return string|null New Dynadot contact ID string, or null on failure.
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
     *
     * @param string $country Country name or ISO-3166 alpha-2 code.
     * @param string $phone Phone number string (may include country code prefix).
     * @return string Calling code digits (e.g., "1", "880"), or empty string if unknown.
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
     *
     * @param string $country Country name or ISO-3166 alpha-2 code.
     * @return string ISO-3166 alpha-2 country code.
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
     *
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @return string Domain name from the service fields, or empty string.
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
     *
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param string $format PHP date format string (default "Y-m-d H:i:s").
     * @return string|false Formatted registration date string, or false if unavailable.
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
     *
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param string $format PHP date format string (default "Y-m-d H:i:s").
     * @return string|false Formatted expiration date string, or false if unavailable.
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
     *
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @return string Rendered HTML for the admin service info panel.
     */
    public function getAdminServiceInfo($service, $package)
    {
        return $this->renderServiceInformation('admin_service_info', $service, $package);
    }

    /**
     * Renders the client service information panel.
     *
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @return string Rendered HTML for the client service info panel.
     */
    public function getClientServiceInfo($service, $package)
    {
        return $this->renderServiceInformation('client_service_info', $service, $package);
    }

    /**
     * Populates and renders the service information view.
     *
     * @param string $view View template name (e.g., "tab_whois", "tab_settings").
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @return string Rendered HTML for the service info panel.
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
     *
     * @param array|stdClass $vars Form input data or service field overrides.
     * @return ModuleFields ModuleFields object with package type and nameserver inputs.
     */
    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);
        $fields = new ModuleFields();
        $types = ['domain' => Language::_('Dynadot.package_fields.type_domain', true)];

        $type = $fields->label(Language::_('Dynadot.package_fields.type', true), 'dynadot_type');
        $type->attach($fields->fieldSelect('meta[type]', $types, $vars->meta['type'] ?? null, ['id' => 'dynadot_type']));
        $fields->setField($type);

        // Set all TLD checkboxes
        $tld_options = $fields->label(Language::_('Dynadot.package_fields.tld_options', true));
        $tlds = $this->getTlds();
        sort($tlds);

        foreach ($tlds as $tld) {
            $tld_label = $fields->label($tld, 'tld_' . $tld);
            $tld_options->attach(
                $fields->fieldCheckbox(
                    'meta[tlds][]',
                    $tld,
                    (isset($vars->meta['tlds']) && in_array($tld, (array) $vars->meta['tlds'])),
                    ['id' => 'tld_' . $tld],
                    $tld_label
                )
            );
        }
        $fields->setField($tld_options);

        // Set EPP Code checkbox
        $epp_code_label = $fields->label(Language::_('Dynadot.package_fields.epp_code', true));
        $epp_code_label->attach(
            $fields->fieldCheckbox(
                'meta[epp_code]',
                '1',
                ($vars->meta['epp_code'] ?? '0') == '1',
                ['id' => 'epp_code'],
                $fields->label(Language::_('Dynadot.package_fields.enable_epp_code', true), 'epp_code')
            )
        );
        $fields->setField($epp_code_label);

        // Set nameservers 1 to 5
        for ($i = 1; $i <= 5; $i++) {
            $type = $fields->label(Language::_('Dynadot.package_fields.ns' . $i, true), 'dynadot_ns' . $i);
            $type->attach($fields->fieldText('meta[ns][]', $vars->meta['ns'][$i - 1] ?? null, ['id' => 'dynadot_ns' . $i]));
            $fields->setField($type);
        }

        $fields->setHtml(
            "
            <script type=\"text/javascript\">
                $(document).ready(function() {
                    toggleTldOptions($('#dynadot_type').val());

                    $('#dynadot_type').change(function() {
                        toggleTldOptions($(this).val());
                    });

                    function toggleTldOptions(type) {
                        if (type == 'ssl') {
                            $('.dynadot_tlds').hide();
                        } else {
                            $('.dynadot_tlds').show();
                        }
                    }
                });
            </script>
            "
        );

        return $fields;
    }

    /**
     * Returns admin service edit fields.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param array|stdClass $vars Form input data or service field overrides.
     * @return ModuleFields ModuleFields object with domain, NS, and auth inputs.
     */
    public function getAdminEditFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Form', 'Html']);
        $fields = new ModuleFields();

        if ($package->meta->type == 'domain') {
            $domain = trim((string) ($vars->domain ?? ''));
            $live_nameservers = [];
            if ($domain !== '' && !empty($vars->module_row_id)) {
                $live_nameservers = $this->getDomainNameServers($domain, $vars->module_row_id);
                foreach ($live_nameservers as $index => $nameserver) {
                    $key = 'ns' . ($index + 1);
                    if (empty($vars->{$key})) {
                        $vars->{$key} = $nameserver['url'] ?? '';
                    }
                }
            }

            $fields->setField($fields->label(Language::_('Dynadot.domain.domain', true), 'domain')->attach($fields->fieldText('domain', $domain, ['id' => 'domain'])));
            for ($i = 1; $i <= 5; $i++) {
                $fields->setField($fields->label(Language::_('Dynadot.nameserver.ns' . $i, true), 'ns' . $i)->attach($fields->fieldText('ns' . $i, $vars->{'ns' . $i} ?? null, ['id' => 'ns' . $i])));
            }
            $fields->setField($fields->label(Language::_('Dynadot.transfer.EPPCode', true), 'auth')->attach($fields->fieldText('auth', $vars->auth ?? null, ['id' => 'auth'])));
        }

        return $fields;
    }

    /**
     * Returns admin service add fields.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param array|stdClass $vars Form input data or service field overrides.
     * @return ModuleFields ModuleFields object with registration/transfer form inputs.
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
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param array|stdClass $vars Form input data or service field overrides.
     * @return ModuleFields ModuleFields object for the client-facing order form.
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
     *
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @return array Associative array mapping tab method names to display labels.
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
     *
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @return array Associative array mapping tab method names to label+icon arrays.
     */
    public function getClientServiceTabs($service)
    {
        return [
            'tabClientWhois' => ['name' => Language::_('Dynadot.tab_whois.title', true), 'icon' => 'fas fa-users'],
            'tabClientManageContacts' => ['name' => Language::_('Dynadot.tab_manage_contacts.title', true), 'icon' => 'fas fa-users'],
            'tabClientNameservers' => ['name' => Language::_('Dynadot.tab_nameservers.title', true), 'icon' => 'fas fa-server'],
            'tabClientSettings' => ['name' => Language::_('Dynadot.tab_settings.title', true), 'icon' => 'fas fa-cog'],
            'tabClientHosts' => ['name' => Language::_('Dynadot.tab_hosts.title', true), 'icon' => 'fas fa-network-wired'],
            'tabClientDnssec' => ['name' => Language::_('Dynadot.tab_dnssec.title', true), 'icon' => 'fas fa-lock'],
            'tabClientDnsRecords' => ['name' => Language::_('Dynadot.tab_dnsrecords.title', true), 'icon' => 'fas fa-sitemap'],
            'tabClientEmailForwarding' => ['name' => Language::_('Dynadot.tab_email_forwarding.title', true), 'icon' => 'fas fa-envelope'],
        ];
    }

    // =========================================================================
    // WHOIS Contacts Management Tabs
    // =========================================================================

    /**
     * Tab whois.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the admin WHOIS management tab.
     */
    public function tabWhois($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageWhois('tab_whois', $package, $service, $get, $post, $files);
    }

    /**
     * Tab client whois.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the client WHOIS management tab.
     */
    public function tabClientWhois($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageWhois('tab_client_whois', $package, $service, $get, $post, $files);
    }

    /**
     * Tab manage contacts.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the admin contact management tab.
     */
    public function tabManageContacts($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageManageContacts('tab_manage_contacts', $package, $service, $get, $post, $files);
    }

    /**
     * Tab client manage contacts.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the client contact management tab.
     */
    public function tabClientManageContacts($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageManageContacts('tab_client_manage_contacts', $package, $service, $get, $post, $files);
    }

    /**
     * Handles the Admin Actions tab (sync renewal date, request EPP code).
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the admin actions tab.
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

            if (isset($post['action']) && in_array($post['action'], ['sync_date', 'sync_domain'], true)) {
                $sync_result = $this->synchronizeFullDomain($service, $package);

                if (!empty($sync_result['success'])) {
                    $expires = $sync_result['dates']['expires'] ?? Language::_('Dynadot.tab_admin_actions.not_available', true);
                    $ns_str = !empty($sync_result['nameservers']) ? implode(', ', $sync_result['nameservers']) : 'none';
                    $locked_str = !empty($sync_result['settings']['locked']) ? 'Locked' : 'Unlocked';
                    $privacy_str = !empty($sync_result['settings']['privacy']) ? 'Full Privacy' : 'Off';

                    $this->setMessage(
                        'success',
                        Language::_('Dynadot.!success.domain_fully_synced', true, $expires, $ns_str, $locked_str, $privacy_str)
                    );
                } else {
                    $this->setMessage('error', $sync_result['message'] ?? 'Domain sync failed.');
                }
            }

            if (!empty($post['request_epp'])) {
                $epp_code = $this->requestDomainEppCode($api, $domain);
                if (!empty($epp_code) && is_string($epp_code)) {
                    $this->view->set('epp_code', $epp_code);
                }
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
     *
     * @param string $view View template name (e.g., "tab_whois", "tab_settings").
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the WHOIS management view.
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
                $is_pending = $this->isDomainPendingResponse($api, $response);
                if (!$is_pending) {
                    $this->processApiResponse($api, $response);
                }
                $this->clearDomainInfoCacheEntry($domain);

                if ($response->status() == 'success' || $is_pending) {
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

            // Fallback to client contact information in Blesta for any missing sections
            $this->populateWhoisContactDefaults($service, $vars);
        }

        $this->view->set('vars', $vars);
        $this->view->set('fields', $whois_fields);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    /**
     * Routes contact management requests (list, add, edit).
     *
     * @param string $view View template name (e.g., "tab_whois", "tab_settings").
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the contact management view.
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
     *
     * @param string $view View template name (e.g., "tab_whois", "tab_settings").
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array $post HTTP POST parameters from the submitted form.
     * @return string Rendered HTML for the contact list view.
     */
    private function handleContactList($view, $package, $service, ?array $get = null, array $post = [])
    {
        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }

        $module = $this->resolveModule();
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

        if (empty($contacts) && !empty($service->client_id)) {
            Loader::loadModels($this, ['Clients']);
            $client = $this->Clients->get($service->client_id);
            if ($client) {
                $client_name = trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? ''));
                if ($client_name !== '') {
                    $contacts['primary'] = $client_name . ' (Primary Client Profile)';
                }
            }
        }

        $this->view->set('contacts', $contacts);
        $this->view->set('service', $service);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    /**
     * Adds a new contact via RESTful v2 POST /restful/v2/contacts.
     *
     * @param string $view View template name (e.g., "tab_whois", "tab_settings").
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array $post HTTP POST parameters from the submitted form.
     * @return string Rendered HTML for the add-contact form.
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
                $module = $this->resolveModule();
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
     *
     * @param string $view View template name (e.g., "tab_whois", "tab_settings").
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array $post HTTP POST parameters from the submitted form.
     * @return string Rendered HTML for the edit-contact form.
     */
    private function handleContactEdit($view, $package, $service, ?array $get = null, array $post = [])
    {
        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }

        $module = $this->resolveModule();
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
     *
     * @param array $post HTTP POST parameters from the submitted form.
     * @param string $section WHOIS contact section name ("Registrant", "Admin", "Technical", or "Billing").
     * @return array Associative array of contact fields (first_name, last_name, email, etc.).
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
     *
     * @param mixed $contact Normalized Dynadot contact object from GetContactResponse.
     * @param string $section WHOIS contact section name ("Registrant", "Admin", "Technical", or "Billing").
     * @return array Associative array of section-prefixed field names to display values.
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
     * Populates empty WHOIS contact fields with the client profile contact from Blesta.
     *
     * @param stdClass $service Blesta service object.
     * @param stdClass $vars Form vars object (modified by reference).
     */
    private function populateWhoisContactDefaults($service, stdClass &$vars)
    {
        if (empty($service->client_id)) {
            return;
        }

        if (!isset($this->Clients)) {
            Loader::loadModels($this, ['Clients']);
        }
        if (!isset($this->Contacts)) {
            Loader::loadModels($this, ['Contacts']);
        }

        $client = $this->Clients->get($service->client_id);
        if (!$client) {
            return;
        }

        $contact = !empty($client->contact_id) ? $this->Contacts->get($client->contact_id) : null;

        $phone = '';
        if ($contact) {
            $numbers = $this->Contacts->getNumbers($contact->id, 'phone');
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
            $phone = trim((string) ($contact->phone ?? ($client->phone ?? '')));
        }

        $country = $contact->country ?? ($client->country ?? '');
        $intl_phone = $phone;
        if ($phone !== '' && $country !== '') {
            $intl_phone = $this->Contacts->intlNumber($phone, $country, '.');
        }

        $state = $contact->state ?? ($client->state ?? '');
        if (!empty($client->state_name)) {
            $state = $client->state_name;
        } elseif (is_numeric($state) && !empty($country)) {
            Loader::loadModels($this, ['States']);
            $state_obj = $this->States->get($country, $state);
            if ($state_obj && !empty($state_obj->name)) {
                $state = $state_obj->name;
            }
        }

        $first_name = $contact->first_name ?? ($client->first_name ?? '');
        $last_name = $contact->last_name ?? ($client->last_name ?? '');
        $company = $contact->company ?? ($client->company ?? '');
        $address1 = $contact->address1 ?? ($client->address1 ?? '');
        $address2 = $contact->address2 ?? ($client->address2 ?? '');
        $city = $contact->city ?? ($client->city ?? '');
        $zip = $contact->zip ?? ($client->zip ?? '');
        $email = $contact->email ?? ($client->email ?? '');

        foreach (['Registrant', 'Admin', 'Technical', 'Billing'] as $section) {
            if (empty($vars->{$section . 'FirstName'})) {
                $vars->{$section . 'FirstName'} = $first_name;
            }
            if (empty($vars->{$section . 'LastName'})) {
                $vars->{$section . 'LastName'} = $last_name;
            }
            if (!isset($vars->{$section . 'Organization'})) {
                $vars->{$section . 'Organization'} = $company;
            }
            if (empty($vars->{$section . 'Address1'})) {
                $vars->{$section . 'Address1'} = $address1;
            }
            if (!isset($vars->{$section . 'Address2'})) {
                $vars->{$section . 'Address2'} = $address2;
            }
            if (empty($vars->{$section . 'City'})) {
                $vars->{$section . 'City'} = $city;
            }
            if (empty($vars->{$section . 'StateProvince'})) {
                $vars->{$section . 'StateProvince'} = $state;
            }
            if (empty($vars->{$section . 'PostalCode'})) {
                $vars->{$section . 'PostalCode'} = $zip;
            }
            if (empty($vars->{$section . 'Country'})) {
                $vars->{$section . 'Country'} = $country;
            }
            if (empty($vars->{$section . 'Phone'})) {
                $vars->{$section . 'Phone'} = $intl_phone;
            }
            if (empty($vars->{$section . 'EmailAddress'})) {
                $vars->{$section . 'EmailAddress'} = $email;
            }
        }
    }

    /**
     * Domain status check hook.
     *
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @return string|null Rendered HTML string if domain is unavailable, null if OK.
     */
    private function isDomainStatusAvailable($service, $package)
    {
        return null;
    }

    /**
     * Requests the domain's transfer auth code and sets a UI status message.
     *
     * @param DynadotApi $api Initialized DynadotApi client for the active Dynadot account.
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @return bool True on success (auth code shown in UI message), false on API error.
     */
    private function requestDomainEppCode(DynadotApi $api, $domain)
    {
        $epp = new DynadotDomainsEpp($api);
        $response = $epp->getAuthCode(['domain' => $domain]);
        $this->processApiResponse($api, $response);

        if ($response->status() != 'success') {
            return false;
        }

        $res = $response->response();
        $auth_code = $res->GetTransferAuthCodeResponse->AuthCode
            ?? ($res->data->auth_code
            ?? ($res->auth_code ?? null));

        if (!empty($auth_code)) {
            $this->setMessage(
                'success',
                Language::_('Dynadot.!success.epp_code', true, (string) $auth_code)
            );
            return (string) $auth_code;
        } else {
            $this->setMessage('success', Language::_('Dynadot.!success.epp_code_sent', true));
            return true;
        }
    }

    /**
     * Displays a notice if the domain is not using Dynadot's default nameservers.
     *
     * @param string $domain Fully qualified domain name (e.g., "example.com").
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     */
    private function validateAndReportNameservers($domain, $module_row_id = null)
    {
        $nameservers = $this->getDomainNameServers($domain, $module_row_id);
        $current_nameservers = array_values(array_unique(array_filter(array_map(function ($nameserver) {
            $url = is_array($nameserver) ? ($nameserver['url'] ?? '') : (string) $nameserver;
            return strtolower(rtrim(trim($url), '.'));
        }, $nameservers))));

        $default_nameservers = array_map(function ($ns) {
            return strtolower(rtrim(trim($ns), '.'));
        }, $this->nameservers);

        $matching = array_intersect($current_nameservers, $default_nameservers);

        // Dynadot DNS and email forwarding require Dynadot default nameservers to function.
        // Show notice only if domain is not using at least 2 Dynadot nameservers.
        if (count($matching) < 2) {
            $this->setMessage('notice', Language::_('Dynadot.notice.default_nameservers', true));
        }
    }

    /**
     * Refreshes Dynadot TLD pricing caches for every configured module row.
     * Dynadot RESTful v2 operation: GET /restful/v2/domains/get_tld_price
     */
    private function refreshPricingCache()
    {
        foreach ($this->getRows() as $module_row) {
            if (empty($module_row->meta->key)) {
                continue;
            }

            $this->setModuleRow($module_row);
            $this->getUsdPrices(true);
        }
    }

    /**
     * Fetches and caches USD TLD prices via RESTful v2 GET /restful/v2/domains/get_tld_price.
     * Delegates directly to DynadotDomainsPricing command class.
     *
     * @param bool $force_refresh True to bypass the cache and re-fetch from Dynadot.
     * @return array Associative array of TLD => price data (registration, transfer, renew, min, max).
     */
    private function getUsdPrices($force_refresh = false)
    {
        $row = $this->getConfiguredModuleRow();
        if (!$row || empty($row->meta->key)) {
            return [];
        }

        $sandbox = (isset($row->meta->sandbox) && $row->meta->sandbox == 'true');
        $company_id = Configure::get('Blesta.company_id');
        if (empty($company_id)) {
            $module = $this->getModule() ?: $this->resolveModule();
            $company_id = $module->company_id ?? 1;
        }

        $api = $this->getApi(null, $row->meta->key, $sandbox, null, false, $row->meta->secret ?? null);
        $pricing = new DynadotDomainsPricing($api);
        return $pricing->fetchCachedUsdPrices($row, $company_id, $force_refresh);
    }

    /**
     * Parses a price value into a float.
     *
     * @param mixed $price Raw price value to parse.
     * @return float Numeric price as a float.
     */
    private function parsePriceValue($price)
    {
        return is_scalar($price) ? (float) str_replace(',', '', (string) $price) : 0.0;
    }

    /**
     * Converts cached USD TLD prices into each configured Blesta currency.
     *
     * @param array $filters Associative array of optional filters (tlds, currencies, terms).
     * @return array Multi-currency pricing array keyed by TLD then currency code.
     */
    protected function getPrices(array $filters = [])
    {
        $company_id = Configure::get('Blesta.company_id');
        if (empty($company_id)) {
            $module = $this->getModule() ?: $this->resolveModule();
            $company_id = $module->company_id ?? 1;
        }

        // Fetch the TLDs results from the cache, if they exist
        $cache = Cache::fetchCache(
            'tlds_prices',
            $company_id . DS . 'modules' . DS . 'dynadot' . DS
        );

        if ($cache) {
            $result = safe_unserialize(base64_decode($cache));
            if (!is_array($result)) {
                $result = json_decode($cache, true);
            }
        }

        Loader::loadModels($this, ['Currencies']);

        if (!isset($result) || !is_array($result) || empty($result)) {
            $result = $this->getUsdPrices();

            if (
                Configure::get('Caching.on') && is_writable(CACHEDIR)
                && !empty($result)
            ) {
                try {
                    Cache::writeCache(
                        'tlds_prices',
                        base64_encode(serialize($result)),
                        strtotime(Configure::get('Blesta.cache_length')) - time(),
                        $company_id . DS . 'modules' . DS . 'dynadot' . DS
                    );
                } catch (\Throwable $e) {
                    Configure::set('Caching.on', false);
                }
            }
        }

        $tlds = (array) ($result ?? []);

        // Get all currencies
        $currencies = $this->Currencies->getAll($company_id);
        if (empty($currencies)) {
            $currencies = [(object) ['code' => 'USD']];
        }

        $row = $this->getConfiguredModuleRow();
        $api = $this->getApi(null, $row->meta->key ?? '', (isset($row->meta->sandbox) && $row->meta->sandbox == 'true'), null, false, $row->meta->secret ?? null);
        $pricing = new DynadotDomainsPricing($api);

        return $pricing->getUnitPrices($tlds, $currencies, $filters, $this->Currencies, $company_id);
    }

    /**
     * Expands unit TLD prices across supported multi-year terms for Blesta's Domain Manager.
     * Delegates multi-currency calculation and term expansion to DynadotDomainsPricing.
     *
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @param array $filters Associative array of optional filters (tlds, currencies, terms).
     * @return array Pricing array keyed by TLD, currency, then year term.
     */
    public function getFilteredTldPricing($module_row_id = null, $filters = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $row = !empty($row) ? $row : $this->getRow();
        if ($row) {
            $this->setModuleRow($row);
        }

        $company_id = Configure::get('Blesta.company_id');
        if (empty($company_id)) {
            $module = $this->getModule() ?: $this->resolveModule();
            $company_id = $module->company_id ?? 1;
        }

        Loader::loadModels($this, ['Currencies']);
        $currencies = $this->Currencies->getAll($company_id);
        if (empty($currencies)) {
            $currencies = [(object) ['code' => 'USD']];
        }

        $tlds = $this->getUsdPrices();
        $api = $this->getApi(null, $row->meta->key ?? '', (isset($row->meta->sandbox) && $row->meta->sandbox == 'true'), null, false, $row->meta->secret ?? null);
        $pricing = new DynadotDomainsPricing($api);

        return $pricing->convertTldPrices($tlds, $currencies, $filters, $this->Currencies, $company_id);
    }

    /**
     * Public entry point for Blesta's Domain Manager TLD price sync.
     *
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return array Full TLD pricing array (delegates to getFilteredTldPricing).
     */
    public function getTldPricing($module_row_id = null)
    {
        return $this->getFilteredTldPricing($module_row_id);
    }

    /**
     * Returns the list of supported TLD extensions.
     *
     * @param stdClass $module_row_id Module row ID identifying the Dynadot account, or null for default.
     * @return array Array of supported TLD extension strings (e.g., [".com", ".net", ...]).
     */
    public function getTlds($module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $row = !empty($row) ? $row : $this->getRow();

        $company_id = Configure::get('Blesta.company_id');
        if (empty($company_id)) {
            $module = $this->getModule() ?: $this->resolveModule();
            $company_id = $module->company_id ?? 1;
        }

        $cache = Cache::fetchCache(
            'tlds',
            $company_id . DS . 'modules' . DS . 'dynadot' . DS
        );

        if ($cache) {
            $cached_tlds = safe_unserialize(base64_decode($cache));
            if (is_array($cached_tlds) && !empty($cached_tlds)) {
                return $cached_tlds;
            }
        }

        $tlds = [];
        if (empty($row)) {
            return $tlds;
        }

        $usd_prices = $this->getUsdPrices();
        $tlds = array_keys($usd_prices);

        if (count($tlds) > 0) {
            if (Configure::get('Caching.on') && is_writable(CACHEDIR)) {
                try {
                    Cache::writeCache(
                        'tlds',
                        base64_encode(serialize($tlds)),
                        strtotime(Configure::get('Blesta.cache_length')) - time(),
                        $company_id . DS . 'modules' . DS . 'dynadot' . DS
                    );
                } catch (\Throwable $e) {
                    Configure::set('Caching.on', false);
                }
            }
        }

        return $tlds;
    }

    /**
     * Finds or creates a Dynadot contact ID for a Blesta client.
     *
     * @param int $client_id Blesta client ID.
     * @param mixed $api Initialized DynadotApi client for the active Dynadot account.
     * @param stdClass $module_row Blesta module row object with meta fields (key, secret, sandbox).
     * @return string|null Existing or newly created Dynadot contact ID, or null on failure.
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

        $module = $this->resolveModule();
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

    /**
     * Tab nameservers.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the admin nameservers tab.
     */
    public function tabNameservers($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageNameservers('tab_nameservers', $package, $service, $get, $post, $files);
    }

    /**
     * Tab client nameservers.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the client nameservers tab.
     */
    public function tabClientNameservers($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageNameservers('tab_client_nameservers', $package, $service, $get, $post, $files);
    }

    /**
     * Manages domain nameservers tab.
     *
     * @param string $view View template name (e.g., "tab_whois", "tab_settings").
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the nameservers management view.
     */
    private function manageNameservers($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);
        $domain = $this->getServiceDomain($service);

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

                // Synchronize updated nameservers to service_fields so Basic Tab reflects changes immediately
                if (!empty($service->id)) {
                    for ($i = 1; $i <= 5; $i++) {
                        $key = 'ns' . $i;
                        $value = $ns[$i - 1] ?? '';
                        $field = $this->Record->select(['key'])->
                            from('service_fields')->
                            where('service_id', '=', $service->id)->
                            where('key', '=', $key)->
                            fetch();

                        if ($field) {
                            $this->Record->where('service_id', '=', $service->id)->
                                where('key', '=', $key)->
                                update('service_fields', ['value' => $value]);
                        } elseif ($value !== '') {
                            $this->Record->insert(
                                'service_fields',
                                ['service_id' => $service->id, 'key' => $key, 'value' => $value]
                            );
                        }
                    }
                }
            }
            $vars = (object)$post;
        } else {
            $nameservers = $this->getDomainNameServers($domain, $service->module_row_id);
            $vars->ns = [];
            $seen = [];
            foreach ($nameservers as $ns) {
                $url = strtolower(trim((string) ($ns['url'] ?? '')));
                if ($url !== '' && !isset($seen[$url])) {
                    $seen[$url] = true;
                    $vars->ns[] = $url;
                }
            }

            // Fallback to service fields (ns1, ns2, ns3...) if API returned no nameservers
            if (empty($vars->ns)) {
                $service_fields = $this->serviceFieldsToObject($service->fields ?? []);
                for ($i = 1; $i <= 5; $i++) {
                    $val = strtolower(trim((string) ($service_fields->{'ns' . $i} ?? '')));
                    if ($val !== '' && !isset($seen[$val])) {
                        $seen[$val] = true;
                        $vars->ns[] = $val;
                    }
                }
            }

            // Fallback to package default nameservers if still empty
            if (empty($vars->ns) && !empty($package->meta->ns)) {
                foreach ((array) $package->meta->ns as $pkg_ns) {
                    $val = strtolower(trim((string) $pkg_ns));
                    if ($val !== '' && !isset($seen[$val])) {
                        $seen[$val] = true;
                        $vars->ns[] = $val;
                    }
                }
            }
        }
        $this->view->set('vars', $vars);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    /**
     * Tab settings.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the admin settings tab.
     */
    public function tabSettings($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageSettings('tab_settings', $package, $service, $get, $post, $files);
    }

    /**
     * Tab client settings.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the client settings tab.
     */
    public function tabClientSettings($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageSettings('tab_client_settings', $package, $service, $get, $post, $files);
    }

    /**
     * Manages domain lock and EPP code settings tab.
     *
     * @param string $view View template name (e.g., "tab_whois", "tab_settings").
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the domain settings (lock/EPP) view.
     */
    private function manageSettings($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getRow($service->module_row_id ?? $package->module_row ?? null);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);
        $domain = $this->getServiceDomain($service);

        if (!empty($post)) {
            if (isset($post['registrar_lock'])) {
                $locked = $this->getDomainIsLocked($domain, $service->module_row_id ?? null);
                if ($post['registrar_lock'] == 'yes' && !$locked) {
                    $this->lockDomain($domain, $service->module_row_id ?? null);
                } elseif ($post['registrar_lock'] == 'no' && $locked) {
                    $this->unlockDomain($domain, $service->module_row_id ?? null);
                }
            }
            if (isset($post['privacy'])) {
                $this->setDomainPrivacy($domain, ($post['privacy'] == 'yes' || $post['privacy'] == 'full'), $service->module_row_id ?? null);
            }
            if (isset($post['request_epp']) && ($post['request_epp'] == 'true' || $post['request_epp'] == '1')) {
                $epp_code = $this->requestDomainEppCode($api, $domain);
                if (!empty($epp_code) && is_string($epp_code)) {
                    $this->view->set('epp_code', $epp_code);
                }
            }
            $vars = (object) $post;
        }

        $domain_info = $this->getDomainInfo($domain, $service->module_row_id ?? null);
        $vars->registrar_lock = !empty($domain_info['locked']) ? 'yes' : 'no';
        $vars->privacy = !empty($domain_info['private']) ? 'yes' : 'no';

        $this->view->set('vars', $vars);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    /**
     * Tab hosts.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the admin hosts (glue records) tab.
     */
    public function tabHosts($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageHosts('tab_hosts', $package, $service, $get, $post, $files);
    }

    /**
     * Tab client hosts.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the client hosts tab.
     */
    public function tabClientHosts($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageHosts('tab_client_hosts', $package, $service, $get, $post, $files);
    }

    /**
     * Manages child nameservers (glue records) via RESTful v2 /restful/v2/nameservers.
     *
     * @param string $view View template name (e.g., "tab_whois", "tab_settings").
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the hosts (glue records) management view.
     */
    private function manageHosts($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getRow($service->module_row_id ?? $package->module_row ?? null);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);
        $dns = new DynadotDomainsDns($api);
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
                    $response = $dns->deleteChildNameserver($server_id);
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
                    $response = $dns->registerChildNameserver($host . $suffix, $ip);
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

        $vars->hosts = $dns->getChildNameservers($domain);

        $this->view->set('vars', $vars);
        $this->view->set('domain', $domain);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    /**
     * Tab dnssec.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the admin DNSSEC tab.
     */
    public function tabDnssec($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageDnssec('tab_dnssec', $package, $service, $get, $post, $files);
    }

    /**
     * Tab client dnssec.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the client DNSSEC tab.
     */
    public function tabClientDnssec($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageDnssec('tab_client_dnssec', $package, $service, $get, $post, $files);
    }

    /**
     * Manages DNSSEC records via RESTful v2 PUT/DELETE /restful/v2/domains/{domain_name}/dnssec.
     *
     * @param string $view View template name (e.g., "tab_whois", "tab_settings").
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the DNSSEC management view.
     */
    private function manageDnssec($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getRow($service->module_row_id ?? $package->module_row ?? null);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);
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
        $this->view->set('domain', $domain);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    /**
     * Tab dns records.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the admin DNS records tab.
     */
    public function tabDnsRecords($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageDnsRecords('tab_dnsrecords', $package, $service, $get, $post, $files);
    }

    /**
     * Tab client dns records.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the client DNS records tab.
     */
    public function tabClientDnsRecords($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageDnsRecords('tab_client_dnsrecords', $package, $service, $get, $post, $files);
    }

    /**
     * Manages DNS zone records via RESTful v2 GET/POST /restful/v2/domains/{domain_name}/dns.
     *
     * @param string $view View template name (e.g., "tab_whois", "tab_settings").
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the DNS zone records management view.
     */
    private function manageDnsRecords($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getRow($service->module_row_id ?? $package->module_row ?? null);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);
        $dns = new DynadotDomainsDns($api);
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

            $errors = $dns->validateDnsRecords($records);
            if (!empty($errors)) {
                if (isset($errors['valid'])) {
                    $this->Input->setErrors(['dns' => ['valid' => Language::_('Dynadot.!error.dns.valid', true, $errors['valid']['type'], $errors['valid']['value'])]]);
                } elseif (isset($errors['empty'])) {
                    $this->Input->setErrors(['dns' => ['empty' => Language::_('Dynadot.!error.dns.empty', true)]]);
                } elseif (isset($errors['count'])) {
                    $this->Input->setErrors(['dns' => ['count' => Language::_('Dynadot.!error.dns.count', true)]]);
                }
            } else {
                $args = $dns->buildDnsArgs($domain, $records);
                $response = $dns->setRecords($args);
                $this->processApiResponse($api, $response);
                if ($response->status() == 'success') {
                    $this->setMessage('success', Language::_('Dynadot.!success.updated', true));
                }
            }

            $records = array_values(array_filter($records, function ($record) {
                return $record['value'] !== '';
            }));
        } else {
            $response = $dns->getRecords(['domain' => $domain]);
            if (!$this->isDomainPendingResponse($api, $response)) {
                $this->processApiResponse($api, $response);
            }
            $records = $dns->parseDnsRecords($response->response());
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
        $this->view->set('domain', $domain);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    /**
     * Validates and prepares DNS record arguments for DynadotApi::submit('set_dns2').
     * Delegates to DynadotDomainsDns.
     *
     * @param string $domain Fully qualified domain name.
     * @param array $records Array of DNS record arrays.
     * @return array|false Numbered DNS arguments array for DynadotApi.
     */
    private function buildDnsArgs($domain, array $records)
    {
        $dns = new DynadotDomainsDns($this->getApi());
        return $dns->buildDnsArgs($domain, $records);
    }

    /**
     * Parses DNS records from API response structures.
     * Delegates to DynadotDomainsDns.
     *
     * @param mixed $response DynadotResponse object from API call.
     * @return array Array of DNS record arrays with type, subdomain, value, and x keys.
     */
    private function parseDnsRecords($response)
    {
        $dns = new DynadotDomainsDns($this->getApi());
        return $dns->parseDnsRecords($response);
    }

    /**
     * Tab email forwarding.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the admin email forwarding tab.
     */
    public function tabEmailForwarding($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageEmailForwarding('tab_email_forwarding', $package, $service, $get, $post, $files);
    }

    /**
     * Tab client email forwarding.
     * This method is part of the module workflow and keeps the surrounding
     * integration logic in one place so it can be followed by developers.
     *
     * @param stdClass $package Blesta package object with meta fields (type, ns, module_row, etc.).
     * @param stdClass $service Blesta service object with domain, status, and module_row_id fields.
     * @param array|null $get HTTP GET parameters from the current request.
     * @param array|null $post HTTP POST parameters from the submitted form.
     * @param array|null $files Uploaded files array from the current request.
     * @return string Rendered HTML for the client email forwarding tab.
     */
    public function tabClientEmailForwarding($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageEmailForwarding('tab_client_email_forwarding', $package, $service, $get, $post, $files);
    }

    /**
     * Manages email forwarding rules via Dynadot RESTful v2.
     * Delegates parsing and formatting directly to DynadotEmailForwarding.
     *
     * @param string $view View template name.
     * @param stdClass $package Blesta package object.
     * @param stdClass $service Blesta service object.
     * @param array|null $get HTTP GET parameters.
     * @param array|null $post HTTP POST parameters.
     * @param array|null $files Uploaded files array.
     * @return string Rendered HTML for the email forwarding management view.
     */
    private function manageEmailForwarding($view, $package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getRow($service->module_row_id ?? $package->module_row ?? null);
        $api = $this->getApi(null, $row->meta->key, $row->meta->sandbox == 'true', null, false, $row->meta->secret ?? null);
        $domain = $this->getServiceDomain($service);
        $email_forwarding = new DynadotEmailForwarding($api);

        if (!empty($post)) {
            $parsed = $email_forwarding->buildEmailForwardArgs($domain, $post);
            $cnt = $parsed['count'];
            $valid = true;

            if (!empty($parsed['errors'])) {
                foreach ($parsed['errors'] as $err) {
                    $this->Input->setErrors([
                        'email_forward' => ['valid' => Language::_('Dynadot.!error.email_forward.valid', true, $err['username'], $err['email'])]
                    ]);
                }
                $valid = false;
            }

            if ($cnt > 10) {
                $this->Input->setErrors(['email_forward' => ['count' => Language::_('Dynadot.!error.email_forward.count', true)]]);
                $valid = false;
            }

            if ($valid) {
                if ($cnt > 0) {
                    $response = $email_forwarding->setForwards($parsed['args']);
                    $this->processApiResponse($api, $response);
                    if ($response->status() == 'success') {
                        $this->setMessage('success', Language::_('Dynadot.!success.updated', true));
                    }
                } else {
                    $response = $email_forwarding->clearForwards(['domain' => $domain]);
                    $res_data = is_object($response) && method_exists($response, 'response') ? $response->response() : null;
                    $err_desc = is_object($res_data) && isset($res_data->error->description)
                        ? (string) $res_data->error->description
                        : '';
                    if ($response->status() == 'success' || stripos($err_desc, 'Can not clear email_forwarding') !== false) {
                        $this->setMessage('success', Language::_('Dynadot.!success.updated', true));
                    } else {
                        $this->processApiResponse($api, $response);
                    }
                }
            }

            for ($i = 0; $i < $cnt; $i++) {
                $vars->{'username' . $i} = $parsed['args']['username' . $i] ?? '';
                $vars->{'exist_email' . $i} = $parsed['args']['exist_email' . $i] ?? '';
            }
        } else {
            $response = $email_forwarding->getList(['domain' => $domain]);
            if (!$this->isDomainPendingResponse($api, $response)) {
                $this->processApiResponse($api, $response);
            }
            $forwards = $email_forwarding->parseEmailForwarding($response);
            foreach ($forwards as $i => $alias) {
                $vars->{'username' . $i} = $alias['username'] ?? '';
                $vars->{'exist_email' . $i} = $alias['exist_email'] ?? '';
            }
        }

        $this->validateAndReportNameservers($domain, $service->module_row_id ?? null);

        $this->view->set('vars', $vars);
        $this->view->set('domain', $domain);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }
}