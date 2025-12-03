<?php

declare(strict_types=1);

require_once __DIR__ . '/AWSSigner.php';
require_once __DIR__ . '/EntityDefinitions.php';
require_once __DIR__ . '/SensorDefinitions.php';
require_once __DIR__ . '/XSenseCloudClient.php';
use XSense\Gateway\EntityDefinitions;
use XSense\Gateway\SensorDefinitions;
use XSense\Gateway\XSenseCloudClient;



class XSenseGateway extends IPSModule
{
    private const TIMER_IDENT = 'XSenseUpdate';
    private const RECONNECT_TIMER_IDENT = 'XSenseReconnect';
    
    // Data flow GUIDs for child communication
    private const CHILD_DATA_TX_GUID = '{XSENSE-DEVICE-TX-GUID-F6E5D4C3B2A1}';
    private const CHILD_DATA_RX_GUID = '{XSENSE-DEVICE-RX-GUID-A1B2C3D4E5F6}';

    private ?XSenseCloudClient $client = null;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('UpdateInterval', 300);
        $this->RegisterPropertyBoolean('EnableDiagnostics', true);
        $this->RegisterPropertyBoolean('EnableBinarySensors', true);
        $this->RegisterPropertyBoolean('EnableEnvironmentSensors', true);
        $this->RegisterPropertyBoolean('EnableDeviceSensors', true);
        $this->RegisterPropertyBoolean('EnableActions', true);
        $this->RegisterPropertyString('ShadowPreference', 'auto');
        $this->RegisterPropertyString('StationFilter', '[]');
        
        // Webhook properties
        $this->RegisterPropertyString('WebhookURL', '');
        $this->RegisterPropertyBoolean('WebhookEnabled', false);
        $this->RegisterPropertyString('WebhookEvents', '["alarm"]');

        $this->RegisterTimer(self::TIMER_IDENT, 0, 'XSENSE_Update($_IPS["TARGET"]);');
        $this->RegisterTimer(self::RECONNECT_TIMER_IDENT, 0, 'XSENSE_AttemptReconnect($_IPS["TARGET"]);');

        $this->RegisterAttributeString('SessionCache', '{}');
        $this->RegisterAttributeString('InventoryCache', '{}');
        $this->RegisterAttributeString('StationIndex', '{}');
        $this->RegisterAttributeString('SubscribedTopics', '[]');
        $this->RegisterAttributeString('MqttState', '{}');
        $this->RegisterAttributeString('Diagnostics', '{}');
        $this->RegisterAttributeString('SensorRequestCache', '{}');


        $this->ConnectParent('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();
        
        $interval = max(60, $this->ReadPropertyInteger('UpdateInterval')) * 1000;
        $this->SetTimerInterval(self::TIMER_IDENT, $interval);

        // Validate configuration
        $email = $this->ReadPropertyString('Email');
        $password = $this->ReadPropertyString('Password');
        
        if ($email === '' || $password === '') {
            $this->SetStatus(104); // IS_INACTIVE - not configured
            return;
        }
        
        $this->SetStatus(IS_ACTIVE);
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function GetConfigurationForm(): string
    {
        $session = json_decode($this->ReadAttributeString('SessionCache'), true);
        $sessionInfo = $this->Translate('No active session');
        if (is_array($session) && ($session['username'] ?? '') !== '') {
            $expiry = $session['accessTokenExpiry'] ?? null;
            $sessionInfo = sprintf(
                $this->Translate('Logged in as %s%s'),
                $session['username'],
                $expiry ? sprintf($this->Translate(' (valid until %s)'), $expiry) : ''
            );
        }

        $mqttState = json_decode($this->ReadAttributeString('MqttState'), true);
        $mqttCaption = $this->Translate('MQTT: no status data');
        if (is_array($mqttState) && isset($mqttState['connected'])) {
            $mqttCaption = sprintf(
                'MQTT: %s%s',
                $this->Translate($mqttState['connected'] ? 'connected' : 'disconnected'),
                isset($mqttState['message']) ? ' – ' . $mqttState['message'] : ''
            );
        }

        $inventory = json_decode($this->ReadAttributeString('InventoryCache'), true);
        $filterSetting = json_decode($this->ReadPropertyString('StationFilter'), true);
        if (!is_array($filterSetting)) {
            $filterSetting = [];
        }
        $filterMap = [];
        foreach ($filterSetting as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $stationSn = $entry['stationSn'] ?? null;
            if (!is_string($stationSn) || $stationSn === '') {
                continue;
            }
            $filterMap[$stationSn] = $entry;
        }
        $stationValues = [];
        if (is_array($inventory)) {
            foreach ($inventory as $houseId => $house) {
                $houseName = $house['definition']['houseName'] ?? (string) $houseId;
                foreach (($house['stations'] ?? []) as $stationSn => $station) {
                    if (!is_array($station)) {
                        continue;
                    }
                    $stationName = $station['definition']['stationName'] ?? ($station['definition']['sn'] ?? (string) $stationSn);
                    $stationValues[] = [
                        'stationSn' => (string) $stationSn,
                        'label' => sprintf('%s / %s', $houseName, $stationName),
                        'enabled' => isset($filterMap[$stationSn]) ? (bool) ($filterMap[$stationSn]['enabled'] ?? false) : true,
                    ];
                    unset($filterMap[$stationSn]);
                }
            }
        }
        foreach ($filterMap as $stationSn => $entry) {
            $stationValues[] = [
                'stationSn' => $stationSn,
                'label' => $entry['label'] ?? $stationSn,
                'enabled' => (bool) ($entry['enabled'] ?? false),
            ];
        }
        usort($stationValues, static fn($a, $b) => strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));

        $form = [
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'Email', 'caption' => $this->Translate('E-Mail')],
                ['type' => 'PasswordTextBox', 'name' => 'Password', 'caption' => $this->Translate('Password')],
                ['type' => 'NumberSpinner', 'name' => 'UpdateInterval', 'caption' => $this->Translate('Polling interval (s)'), 'minimum' => 60],
                ['type' => 'Select', 'name' => 'ShadowPreference', 'caption' => $this->Translate('Shadow page preference'), 'options' => [
                    ['caption' => $this->Translate('Automatic (recommended)'), 'value' => 'auto'],
                    ['caption' => $this->Translate('Prefer primary shadow pages'), 'value' => 'primary'],
                    ['caption' => $this->Translate('Prefer secondary shadow pages'), 'value' => 'secondary'],
                ]],
                ['type' => 'CheckBox', 'name' => 'EnableDiagnostics', 'caption' => $this->Translate('Create diagnostic variables (Wi-Fi, Firmware, RF)')],
                ['type' => 'CheckBox', 'name' => 'EnableEnvironmentSensors', 'caption' => $this->Translate('Environment sensors (temperature, humidity, CO)')],
                ['type' => 'CheckBox', 'name' => 'EnableBinarySensors', 'caption' => $this->Translate('Create alarm and status variables')],
                ['type' => 'CheckBox', 'name' => 'EnableDeviceSensors', 'caption' => $this->Translate('Synchronize connected devices (e.g., room sensors)')],
                ['type' => 'CheckBox', 'name' => 'EnableActions', 'caption' => $this->Translate('Provide actions (test, mute)')],
                [
                    'type' => 'List',
                    'name' => 'StationFilter',
                    'caption' => $this->Translate('Station selection'),
                    'rowCount' => max(3, count($stationValues)),
                    'add' => false,
                    'delete' => false,
                    'columns' => [
                        ['caption' => $this->Translate('Station'), 'name' => 'label', 'width' => 'auto', 'edit' => ['type' => 'ValidationTextBox', 'enabled' => false]],
                        ['caption' => $this->Translate('Serial number'), 'name' => 'stationSn', 'width' => '150px', 'edit' => ['type' => 'ValidationTextBox', 'enabled' => false]],
                        ['caption' => $this->Translate('Include'), 'name' => 'enabled', 'width' => '100px', 'edit' => ['type' => 'CheckBox']],
                    ],
                    'values' => $stationValues,
                ],
                ['type' => 'ExpansionPanel', 'caption' => $this->Translate('Webhook settings'), 'items' => [
                    ['type' => 'CheckBox', 'name' => 'WebhookEnabled', 'caption' => $this->Translate('Enable webhook')],
                    ['type' => 'ValidationTextBox', 'name' => 'WebhookURL', 'caption' => $this->Translate('Webhook URL')],
                    ['type' => 'Label', 'caption' => $this->Translate('Events: alarm, battery_low, offline')],
                ]],
            ],
            'actions' => [
                ['type' => 'Label', 'caption' => $sessionInfo],
                ['type' => 'Label', 'caption' => $mqttCaption],
                ['type' => 'Button', 'label' => $this->Translate('Manual sync'), 'onClick' => 'XSENSE_Update($id);'],
                ['type' => 'Button', 'label' => $this->Translate('Reconnect MQTT'), 'onClick' => 'XSENSE_AttemptReconnect($id);'],
                ['type' => 'Button', 'label' => $this->Translate('Test webhook'), 'onClick' => 'XSENSE_TestWebhook($id);'],
            ],
        ];
        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    public function RequestAction($ident, $value): void
    {
        if (!is_string($ident)) {
            throw new InvalidArgumentException($this->Translate('Ident must be a string'));
        }
        if (strpos($ident, 'action_') === 0) {
            $this->ExecuteDeviceAction(substr($ident, 7));
            return;
        }
        throw new InvalidArgumentException(sprintf($this->Translate('Unknown action: %s'), (string) $ident));
    }

    public function Update(): void
    {
        try {
            $client = $this->EnsureClient();
            $inventory = $client->syncInventory();
            $inventory = $this->HydrateInventory($inventory);
            $this->WriteAttributeString('SessionCache', json_encode($client->exportSession(), JSON_THROW_ON_ERROR));
            $this->WriteAttributeString('InventoryCache', json_encode($inventory, JSON_THROW_ON_ERROR));
            $this->SyncVariables($inventory);
            $this->EnsureMqttSubscriptions($inventory);
            $this->RequestLongtermSensors($client, $inventory);
            $this->ReportApiSuccess();
            $this->SetStatus(IS_ACTIVE);
        } catch (\Throwable $exception) {
            $this->SendDebug('XSenseGateway', $exception->getMessage(), 0);
            $this->ReportApiError($exception->getMessage());
            $this->SetStatus(IS_ACTIVE);
        }
    }

    public function AttemptReconnect(): void
    {
        $parentId = $this->GetParentID();
        if ($parentId === 0) {
            return;
        }
        $this->SendDebug('XSenseGateway', 'Attempting MQTT reconnect/subscription refresh', 0);
        $this->MaintainMqttLink($parentId);
        $inventory = json_decode($this->ReadAttributeString('InventoryCache'), true);
        if (is_array($inventory)) {
            $this->EnsureMqttSubscriptions($inventory, true);
        }
        $this->SetTimerInterval(self::RECONNECT_TIMER_IDENT, 0);
    }

    public function MessageSink($timestamp, $senderID, $message, $data): void
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);
        $parentId = $this->GetParentID();
        if ($senderID !== $parentId) {
            return;
        }
        if ($message === IM_CHANGESTATUS) {
            $status = is_array($data) && isset($data[0]) ? (int) $data[0] : null;
            $this->HandleMqttStatusChange($status);
        }
    }

    public function ReceiveData($json): void
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return;
        }
        if (($data['DataID'] ?? '') !== '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}') {
            return;
        }
        $this->HandleMqttPayload($data['Buffer'] ?? '');
    }

    private function EnsureClient(): XSenseCloudClient
    {
        if ($this->client instanceof XSenseCloudClient) {
            return $this->client;
        }
        $this->client = new XSenseCloudClient($this);
        $session = json_decode($this->ReadAttributeString('SessionCache'), true);
        if (is_array($session)) {
            $this->client->restoreSession($session);
        }
        $email = $this->ReadPropertyString('Email');
        $password = $this->ReadPropertyString('Password');
        if ($email !== '' && $password !== '' && ($session['username'] ?? '') === '') {
            $this->client->login($email, $password);
            $this->WriteAttributeString('SessionCache', json_encode($this->client->exportSession(), JSON_THROW_ON_ERROR));
        }
        return $this->client;
    }

    /**
     * @param array<string,mixed> $inventory
     * @return array<string,mixed>
     */
    private function HydrateInventory(array $inventory): array
    {
        $stationIndex = [];
        foreach ($inventory as $houseId => &$house) {
            if (!isset($house['stations']) || !is_array($house['stations'])) {
                $house['stations'] = [];
                continue;
            }
            foreach ($house['stations'] as $stationSn => &$station) {
                if (!is_array($station)) {
                    $station = [];
                    continue;
                }
                $station['stationSn'] = $stationSn;
                $station['devices'] = $this->ExtractDeviceSnapshots($station);
                $station['latest'] = $station['latest'] ?? [];
                $stationIndex[$stationSn] = $houseId;
            }
        }
        unset($house, $station);
        $this->WriteAttributeString('StationIndex', json_encode($stationIndex, JSON_THROW_ON_ERROR));
        return $inventory;
    }

    private function SyncVariables(array $inventory): void
    {
        $mqttState = json_decode($this->ReadAttributeString('MqttState'), true);
        $this->CreateOrUpdateVariable('mqtt_connected', $this->Translate('MQTT connected'), $this->InstanceID, \VARIABLETYPE_BOOLEAN, '~Switch', (bool) ($mqttState['connected'] ?? false));

        foreach ($inventory as $houseId => $house) {
            $houseName = $house['definition']['houseName'] ?? $houseId;
            $houseCategory = $this->EnsureCategory('house_' . $houseId, $houseName, $this->InstanceID);
            $this->CreateOrUpdateVariable('house_' . $houseId . '_online', $this->Translate('Online'), $houseCategory, \VARIABLETYPE_BOOLEAN, '~Switch', $house['definition']['online'] ?? true);
            if (!isset($house['stations']) || !is_array($house['stations'])) {
                continue;
            }
            foreach ($house['stations'] as $stationSn => $station) {
                if (!$this->StationIsEnabled((string) $stationSn)) {
                    continue;
                }
                $stationIdent = 'station_' . $stationSn;
                $stationName = $station['definition']['stationName'] ?? ($station['definition']['sn'] ?? $stationSn);
                $stationCategory = $this->EnsureCategory($stationIdent, $stationName, $houseCategory);
                $this->SyncStation($stationIdent, $stationCategory, $station, true);
            }
        }
    }

    private function SyncStation(string $stationIdent, int $category, array $station, bool $create): void
    {
        $this->SyncStationSensors($stationIdent, $category, $station, $create);
        if ($this->ReadPropertyBoolean('EnableActions')) {
            $this->EnsureActionButtons($stationIdent, $station, $category, $create);
        }
        if ($this->ReadPropertyBoolean('EnableDeviceSensors')) {
            $this->SyncDevices($stationIdent, $category, $station, $create);
        }
    }

    private function SyncStationSensors(string $stationIdent, int $category, array $station, bool $create): void
    {
        foreach (SensorDefinitions::stationSensors() as $sensor) {
            if (!$this->ReadPropertyBoolean('EnableDiagnostics') && $sensor['group'] === 'diagnostic') {
                continue;
            }
            if (!$this->ReadPropertyBoolean('EnableEnvironmentSensors') && $sensor['group'] === 'environment') {
                continue;
            }
            if (!$this->ReadPropertyBoolean('EnableBinarySensors') && $sensor['key'] === 'alarm') {
                continue;
            }
            $value = $this->ResolveStationValue($station, $sensor['key']);
            if ($value === null) {
                continue;
            }
            if (is_callable($sensor['formatter'])) {
                $value = $sensor['formatter']($value, $station);
            }
            $this->CreateOrUpdateVariable(
                $stationIdent . '_' . $sensor['key'],
                $this->Translate($sensor['label']),
                $category,
                $sensor['type'],
                $sensor['profile'],
                $value,
                $create
            );
        }
    }

    private function SyncDevices(string $stationIdent, int $category, array $station, bool $create): void
    {
        if (!isset($station['devices']) || !is_array($station['devices'])) {
            return;
        }
        foreach ($station['devices'] as $deviceSn => $device) {
            if (!is_array($device)) {
                continue;
            }
            $deviceIdent = $stationIdent . '_device_' . $deviceSn;
            $deviceName = $device['name'] ?? $device['deviceName'] ?? $deviceSn;
            $deviceCategory = $this->EnsureCategory($deviceIdent, $deviceName, $category);
            $this->CreateOrUpdateVariable($deviceIdent . '_type', $this->Translate('Device type'), $deviceCategory, \VARIABLETYPE_STRING, '', $device['type'] ?? '', $create);
            $this->CreateOrUpdateVariable($deviceIdent . '_online', $this->Translate('Online'), $deviceCategory, \VARIABLETYPE_BOOLEAN, '~Switch', $device['online'] ?? true, $create);
            foreach (SensorDefinitions::deviceSensors() as $sensor) {
                if (!$this->ReadPropertyBoolean('EnableDiagnostics') && $sensor['group'] === 'diagnostic') {
                    continue;
                }
                if (!$this->ReadPropertyBoolean('EnableEnvironmentSensors') && $sensor['group'] === 'environment') {
                    continue;
                }
                $value = $this->ResolveDeviceValue($device, $sensor['key']);
                if ($value === null) {
                    continue;
                }
                if (is_callable($sensor['formatter'])) {
                    $value = $sensor['formatter']($value, $device);
                }
                $this->CreateOrUpdateVariable(
                    $deviceIdent . '_' . $sensor['key'],
                    $this->Translate($sensor['label']),
                    $deviceCategory,
                    $sensor['type'],
                    $sensor['profile'],
                    $value,
                    $create
                );
            }
        }
    }

    private function EnsureMqttSubscriptions(array $inventory, bool $force = false): void
    {
        $parentId = $this->GetParentID();
        if ($parentId === 0) {
            return;
        }
        $topics = [];
        foreach ($inventory as $houseId => $house) {
            $topics[] = sprintf('@xsense/events/+/%s', $houseId);
            $topics[] = sprintf('$aws/things/%s/shadow/name/+/update', $houseId);
            if (!isset($house['stations']) || !is_array($house['stations'])) {
                continue;
            }
            foreach ($house['stations'] as $station) {
                $stationSn = (string) ($station['stationSn'] ?? $station['definition']['stationSN'] ?? $station['definition']['sn'] ?? '');
                if ($stationSn !== '' && !$this->StationIsEnabled($stationSn)) {
                    continue;
                }
                $shadowName = $this->BuildStationShadowName($station['definition'] ?? []);
                if ($shadowName === null) {
                    continue;
                }
                $topics[] = sprintf('$aws/things/%s/shadow/name/+/update', $shadowName);
                $topics[] = sprintf('$aws/events/presence/+/%s', $shadowName);
            }
        }
        $topics = array_values(array_unique($topics));
        $current = json_decode($this->ReadAttributeString('SubscribedTopics'), true);
        if (!is_array($current)) {
            $current = [];
        }
        $diff = $force ? $topics : array_diff($topics, $current);
        if ($diff !== [] || $force) {
            foreach ($topics as $topic) {
                $this->SendDataToParent(json_encode([
                    'DataID' => '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}',
                    'Buffer' => json_encode(['Command' => 'Subscribe', 'Topic' => $topic], JSON_THROW_ON_ERROR),
                ], JSON_THROW_ON_ERROR));
            }
            $this->WriteAttributeString('SubscribedTopics', json_encode($topics, JSON_THROW_ON_ERROR));
        }
        $this->SendDebug('XSenseGateway', 'MQTT Subscriptions: ' . json_encode($topics), 0);
    }

    private function RequestLongtermSensors(XSenseCloudClient $client, array $inventory): void
    {
        if (!$this->ReadPropertyBoolean('EnableEnvironmentSensors')) {
            return;
        }
        $cache = $this->LoadSensorRequestCache();
        $cooldown = max(300, $this->ReadPropertyInteger('UpdateInterval'));
        $now = time();
        $updated = false;
        foreach ($inventory as $houseId => $house) {
            if (!isset($house['stations']) || !is_array($house['stations'])) {
                continue;
            }
            foreach ($house['stations'] as $stationSn => $station) {
                $stationSn = (string) $stationSn;
                if ($stationSn === '' || !$this->StationIsEnabled($stationSn)) {
                    continue;
                }
                $devices = [];
                foreach (($station['devices'] ?? []) as $deviceSn => $device) {
                    if (!is_array($device)) {
                        continue;
                    }
                    $type = (string) ($device['type'] ?? $device['deviceType'] ?? '');
                    if (in_array($type, ['STH51', 'STH0A'], true)) {
                        $devices[] = (string) $deviceSn;
                    }
                }
                if ($devices === []) {
                    continue;
                }
                $lastRequest = (int) ($cache[$stationSn]['timestamp'] ?? 0);
                if ($now - $lastRequest < $cooldown) {
                    continue;
                }
                try {
                    $client->requestSensorReport($house, $station, $devices, [
                        'timeoutM' => (string) max(1, (int) ceil($cooldown / 60)),
                    ]);
                    $cache[$stationSn] = [
                        'timestamp' => $now,
                        'devices' => $devices,
                    ];
                    $updated = true;
                } catch (\Throwable $exception) {
                    $this->SendDebug('XSenseGateway', sprintf('Sensor report request failed for %s: %s', $stationSn, $exception->getMessage()), 0);
                    $this->ReportApiError('Sensor request ' . $stationSn . ': ' . $exception->getMessage());
                }
            }
        }
        if ($updated) {
            $this->SaveSensorRequestCache($cache);
        }
    }

    private function MaintainMqttLink(int $instanceID): void
    {
        if ($instanceID > 0 && IPS_InstanceExists($instanceID)) {
            $this->SendDebug('XSenseGateway', 'Linked to MQTT instance ' . $instanceID, 0);
            $this->SetMqttState(true, $this->Translate('Broker reports connection'));
            $this->SetStatus(IS_ACTIVE);
        }
    }

    private function HandleMqttPayload($payload): void
    {
        $packet = $this->NormalizeMqttPacket($payload);
        if ($packet === null) {
            return;
        }
        $topic = $packet['Topic'] ?? '';
        $payloadRaw = $packet['Payload'] ?? '';
        if (!is_string($payloadRaw) || $payloadRaw === '') {
            return;
        }
        $message = json_decode($payloadRaw, true);
        if (!is_array($message)) {
            return;
        }
        $reported = $message['state']['reported'] ?? [];
        if (!is_array($reported)) {
            return;
        }
        $stationSn = $reported['stationSN'] ?? null;
        if (!is_string($stationSn) || $stationSn === '') {
            return;
        }
        $devicePayload = [];
        if (isset($reported['devs']) && is_array($reported['devs'])) {
            $devicePayload = $reported['devs'];
            unset($reported['devs']);
        }
        $this->SendDebug('XSenseGateway', sprintf('MQTT update for %s via %s', $stationSn, $topic), 0);
        
        // Update local cache
        $this->ApplyRealtimeUpdate($stationSn, $reported, $devicePayload);
        
        // Forward to child devices
        $this->ForwardToChildren($stationSn, $reported, $devicePayload);
    }

    private function EnsureCategory(string $ident, string $name, int $parent): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($id === false) {
            $id = IPS_CreateCategory();
            IPS_SetIdent($id, $ident);
        }
        IPS_SetParent($id, $parent);
        IPS_SetName($id, $name);
        return $id;
    }

    private function CreateOrUpdateVariable(string $ident, string $name, int $parent, int $type, string $profile, $value, bool $createMissing = true): void
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($vid === false) {
            if (!$createMissing) {
                return;
            }
            $vid = IPS_CreateVariable($type);
            IPS_SetIdent($vid, $ident);
        }
        IPS_SetParent($vid, $parent);
        IPS_SetName($vid, $name);
        if ($profile !== '') {
            IPS_SetVariableCustomProfile($vid, $profile);
        } else {
            IPS_SetVariableCustomProfile($vid, '');
        }
        $this->SetVariableValue($vid, $type, $value);
    }

    private function EnsureActionButtons(string $stationIdent, array $station, int $parent, bool $create): void
    {
        $definitions = EntityDefinitions::definitions();
        $type = $station['definition']['stationType'] ?? $station['definition']['type'] ?? '';
        if (!isset($definitions[$type])) {
            return;
        }
        foreach ($definitions[$type]['actions'] as $index => $action) {
            $ident = sprintf('action_%s_%d', $stationIdent, $index);
            $name = strtoupper($action['action']);
            $this->MaintainActionVariable($ident, $name, $parent, $create);
            $this->MaintainActionStatusVariable($stationIdent, $name, $parent, $index, $create);
        }
    }

    private function MaintainActionVariable(string $ident, string $name, int $parent, bool $create): void
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($vid === false) {
            if (!$create) {
                return;
            }
            $vid = IPS_CreateVariable(\VARIABLETYPE_BOOLEAN);
            IPS_SetIdent($vid, $ident);
            IPS_SetVariableCustomAction($vid, $this->InstanceID);
        }
        IPS_SetParent($vid, $parent);
        IPS_SetName($vid, $name);
        SetValueBoolean($vid, false);
    }

    private function MaintainActionStatusVariable(string $stationIdent, string $name, int $parent, int $index, bool $create): void
    {
        $statusIdent = sprintf('action_%s_%d_status', $stationIdent, $index);
        $vid = @IPS_GetObjectIDByIdent($statusIdent, $parent);
        if ($vid === false) {
            if (!$create) {
                return;
            }
            $vid = IPS_CreateVariable(\VARIABLETYPE_STRING);
            IPS_SetIdent($vid, $statusIdent);
            IPS_SetParent($vid, $parent);
            IPS_SetName($vid, $name . ' ' . $this->Translate('Status'));
            SetValueString($vid, $this->Translate('Ready'));
            return;
        }
        IPS_SetParent($vid, $parent);
        IPS_SetName($vid, $name . ' ' . $this->Translate('Status'));
    }

    private function ExecuteDeviceAction(string $token): void
    {
        $pos = strrpos($token, '_');
        if ($pos === false) {
            throw new RuntimeException($this->Translate('Invalid action identifier'));
        }
        $stationIdent = substr($token, 0, $pos);
        $index = (int) substr($token, $pos + 1);
        $inventory = json_decode($this->ReadAttributeString('InventoryCache'), true);
        if (!is_array($inventory)) {
            throw new RuntimeException($this->Translate('No inventory available'));
        }
        $stationSn = substr($stationIdent, strlen('station_'));
        $stationIndex = json_decode($this->ReadAttributeString('StationIndex'), true);
        $houseId = is_array($stationIndex) ? ($stationIndex[$stationSn] ?? null) : null;
        if (!is_string($houseId) || !isset($inventory[$houseId]['stations'][$stationSn])) {
            throw new RuntimeException($this->Translate('Station not found'));
        }
        $station = $inventory[$houseId]['stations'][$stationSn];
        $definitions = EntityDefinitions::definitions();
        $type = $station['definition']['stationType'] ?? $station['definition']['type'] ?? '';
        if (!isset($definitions[$type]['actions'][$index])) {
            throw new RuntimeException($this->Translate('Action not available'));
        }
        $action = $definitions[$type]['actions'][$index];
        try {
            $response = $this->EnsureClient()->triggerAction($inventory[$houseId], $station, $action);
            $this->SendDebug('XSenseGateway', 'Trigger action ' . json_encode($action), 0);
            $this->SetActionStatus($stationIdent, $index, true, $response['state']['desired'] ?? []);
        } catch (\Throwable $exception) {
            $this->SendDebug('XSenseGateway', $this->Translate('Action failed') . ': ' . $exception->getMessage(), 0);
            $this->SetActionStatus($stationIdent, $index, false, $exception->getMessage());
            throw $exception;
        } finally {
            $this->ResetActionVariable($stationIdent, $index);
        }
    }

    private function SetActionStatus(string $stationIdent, int $index, bool $success, $details): void
    {
        $stationCategory = @IPS_GetObjectIDByIdent($stationIdent, $this->InstanceID);
        if ($stationCategory === false) {
            return;
        }
        $statusIdent = sprintf('action_%s_%d_status', $stationIdent, $index);
        $vid = @IPS_GetObjectIDByIdent($statusIdent, $stationCategory);
        if ($vid === false) {
            return;
        }
        $label = $success ? $this->Translate('Success') : $this->Translate('Error');
        $timestamp = date('c');
        $detailMessage = '';
        if (is_string($details)) {
            $detailMessage = $details;
        } elseif (is_array($details) && $details !== []) {
            $encoded = json_encode($details);
            $detailMessage = $encoded === false ? '' : $encoded;
        }
        $message = $label . ' (' . $timestamp . ')';
        if ($detailMessage !== '') {
            $message .= ' – ' . $detailMessage;
        }
        SetValueString($vid, $message);
    }

    private function ResetActionVariable(string $stationIdent, int $index): void
    {
        $stationCategory = @IPS_GetObjectIDByIdent($stationIdent, $this->InstanceID);
        if ($stationCategory === false) {
            return;
        }
        $actionIdent = sprintf('action_%s_%d', $stationIdent, $index);
        $vid = @IPS_GetObjectIDByIdent($actionIdent, $stationCategory);
        if ($vid !== false) {
            SetValueBoolean($vid, false);
        }
    }

    private function HandleMqttStatusChange(?int $status): void
    {
        if ($status === IS_ACTIVE) {
            $this->SetMqttState(true, $this->Translate('Broker reports connection'));
            $this->SetTimerInterval(self::RECONNECT_TIMER_IDENT, 0);
            $this->ReportMqttSuccess($this->Translate('Broker reports connection'));
            return;
        }
        $this->SetMqttState(false, $this->Translate('Status: ') . ($status ?? $this->Translate('unknown')));
        $this->SetTimerInterval(self::RECONNECT_TIMER_IDENT, 30000);
        $this->ReportMqttError($this->Translate('Status: ') . ($status ?? $this->Translate('unknown')));
    }

    private function SetVariableValue(int $vid, int $type, $value): void
    {
        switch ($type) {
            case \VARIABLETYPE_BOOLEAN:
                SetValueBoolean($vid, (bool) $value);
                break;
            case \VARIABLETYPE_INTEGER:
                SetValueInteger($vid, (int) $value);
                break;
            case \VARIABLETYPE_FLOAT:
                SetValueFloat($vid, (float) $value);
                break;
            case \VARIABLETYPE_STRING:
            default:
                SetValueString($vid, (string) $value);
                break;
        }
    }

    private function ResolveStationValue(array $station, string $key)
    {
        $sources = $this->PreferredShadowOrder();
        foreach ($sources as $source) {
            if ($source === 'definition' && isset($station['definition'][$key])) {
                return $station['definition'][$key];
            }
            if ($source === 'latest' && isset($station['latest'][$key])) {
                return $station['latest'][$key];
            }
            if (!isset($station['shadows']) || !is_array($station['shadows'])) {
                continue;
            }
            if (!isset($station['shadows'][$source])) {
                continue;
            }
            $reported = $station['shadows'][$source]['state']['reported'] ?? [];
            if (is_array($reported) && array_key_exists($key, $reported)) {
                return $reported[$key];
            }
        }
        foreach ($station['shadows'] ?? [] as $shadow) {
            $reported = $shadow['state']['reported'] ?? [];
            if (is_array($reported) && array_key_exists($key, $reported)) {
                return $reported[$key];
            }
        }
        return null;
    }

    private function ResolveDeviceValue(array $device, string $key)
    {
        if (array_key_exists($key, $device)) {
            return $device[$key];
        }
        if (isset($device['data']) && is_array($device['data']) && array_key_exists($key, $device['data'])) {
            return $device['data'][$key];
        }
        return null;
    }

    private function PreferredShadowOrder(): array
    {
        $preference = $this->ReadPropertyString('ShadowPreference');
        return match ($preference) {
            'primary' => ['latest', 'mainpage', 'info', '2nd_mainpage', '2nd_info', 'definition'],
            'secondary' => ['latest', '2nd_mainpage', '2nd_info', 'mainpage', 'info', 'definition'],
            default => ['latest', 'mainpage', '2nd_mainpage', 'info', '2nd_info', 'definition'],
        };
    }

    private function StationIsEnabled(string $stationSn): bool
    {
        $raw = json_decode($this->ReadPropertyString('StationFilter'), true);
        if (!is_array($raw) || $raw === []) {
            return true;
        }
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ((string) ($entry['stationSn'] ?? '') === $stationSn) {
                return (bool) ($entry['enabled'] ?? false);
            }
        }
        return false;
    }

    private function LoadSensorRequestCache(): array
    {
        $cache = json_decode($this->ReadAttributeString('SensorRequestCache'), true);
        return is_array($cache) ? $cache : [];
    }

    private function SaveSensorRequestCache(array $cache): void
    {
        $this->WriteAttributeString('SensorRequestCache', json_encode($cache, JSON_THROW_ON_ERROR));
    }

    private function ExtractDeviceSnapshots(array $station): array
    {
        $devices = [];
        foreach ($station['shadows'] ?? [] as $shadow) {
            $reported = $shadow['state']['reported'] ?? [];
            if (!is_array($reported) || !isset($reported['devs']) || !is_array($reported['devs'])) {
                continue;
            }
            foreach ($reported['devs'] as $sn => $payload) {
                if (!is_string($sn) || $sn === '') {
                    continue;
                }
                $devices[$sn] = $this->NormalizeDevicePayload($sn, $payload);
            }
        }
        return $devices;
    }

    private function NormalizeDevicePayload(string $sn, $payload): array
    {
        $device = is_array($payload) ? $payload : ['value' => $payload];
        $device['sn'] = $sn;
        if (isset($device['deviceName']) && !isset($device['name'])) {
            $device['name'] = $device['deviceName'];
        }
        if (!isset($device['online']) && isset($device['state'])) {
            $device['online'] = ($device['state'] ?? '') !== 'offline';
        }
        return $device;
    }

    private function ApplyRealtimeUpdate(string $stationSn, array $reported, array $devicePayload): void
    {
        $inventory = json_decode($this->ReadAttributeString('InventoryCache'), true);
        if (!is_array($inventory)) {
            return;
        }
        $stationIndex = json_decode($this->ReadAttributeString('StationIndex'), true);
        $houseId = is_array($stationIndex) ? ($stationIndex[$stationSn] ?? null) : null;
        if (!is_string($houseId) || !isset($inventory[$houseId]['stations'][$stationSn])) {
            return;
        }
        if (!$this->StationIsEnabled($stationSn)) {
            return;
        }
        $station = &$inventory[$houseId]['stations'][$stationSn];
        if (!isset($station['shadows']) || !is_array($station['shadows'])) {
            $station['shadows'] = [];
        }
        $station['shadows']['mqtt'] = ['state' => ['reported' => $reported]];
        $station['latest'] = array_merge($station['latest'] ?? [], $reported);
        if ($devicePayload !== []) {
            foreach ($devicePayload as $sn => $payload) {
                if (!is_string($sn) || $sn === '') {
                    continue;
                }
                $station['devices'][$sn] = $this->NormalizeDevicePayload($sn, $payload);
            }
        }
        $this->WriteAttributeString('InventoryCache', json_encode($inventory, JSON_THROW_ON_ERROR));
        $houseCategory = @IPS_GetObjectIDByIdent('house_' . $houseId, $this->InstanceID);
        if ($houseCategory === false) {
            return;
        }
        $stationCategory = @IPS_GetObjectIDByIdent('station_' . $stationSn, $houseCategory);
        if ($stationCategory === false) {
            return;
        }
        $this->SyncStation('station_' . $stationSn, $stationCategory, $station, true);
    }

    private function BuildStationShadowName(array $station): ?string
    {
        $type = $station['stationType'] ?? $station['type'] ?? '';
        $sn = $station['sn'] ?? $station['stationSN'] ?? $station['stationId'] ?? null;
        if (!is_string($type) || !is_string($sn) || $sn === '') {
            return null;
        }
        if ($type === 'SBS10') {
            return $sn;
        }
        if (in_array($type, ['XC04-WX', 'SC07-WX'], true)) {
            return $type . '-' . $sn;
        }
        return $type . $sn;
    }

    private function NormalizeMqttPacket($payload): ?array
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['Topic']) && isset($payload['Payload'])) {
            return $payload;
        }
        if (isset($payload['topic']) && isset($payload['payload'])) {
            return ['Topic' => $payload['topic'], 'Payload' => $payload['payload']];
        }
        return null;
    }

    private function SetMqttState(bool $connected, string $message): void
    {
        $state = ['connected' => $connected, 'message' => $message, 'timestamp' => time()];
        $this->WriteAttributeString('MqttState', json_encode($state, JSON_THROW_ON_ERROR));
        $this->CreateOrUpdateVariable('mqtt_connected', $this->Translate('MQTT connected'), $this->InstanceID, \VARIABLETYPE_BOOLEAN, '~Switch', $connected);
    }

    private function EnsureProfiles(): void
    {
        if (!IPS_VariableProfileExists('XSense.RFLevel')) {
            IPS_CreateVariableProfile('XSense.RFLevel', \VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('XSense.RFLevel', 0, $this->Translate('no signal'), '', 0x999999);
            IPS_SetVariableProfileAssociation('XSense.RFLevel', 1, $this->Translate('weak'), '', 0x00AEEF);
            IPS_SetVariableProfileAssociation('XSense.RFLevel', 2, $this->Translate('medium'), '', 0x00A000);
            IPS_SetVariableProfileAssociation('XSense.RFLevel', 3, $this->Translate('good'), '', 0x007700);
        }
    }

    private function LoadDiagnostics(): array
    {
        $diagnostics = json_decode($this->ReadAttributeString('Diagnostics'), true);
        if (!is_array($diagnostics)) {
            $diagnostics = [];
        }
        $diagnostics += [
            'apiErrors' => 0,
            'mqttErrors' => 0,
            'lastApiError' => '',
            'lastApiErrorTime' => null,
            'lastMqttError' => '',
            'lastMqttErrorTime' => null,
            'lastApiSuccess' => null,
            'lastMqttSuccess' => null,
            'lastMqttStatus' => '',
            'lastMqttStatusTime' => null,
        ];
        return $diagnostics;
    }

    private function SaveDiagnostics(array $diagnostics): void
    {
        $this->WriteAttributeString('Diagnostics', json_encode($diagnostics, JSON_THROW_ON_ERROR));
        $this->EnsureDiagnosticVariables($diagnostics);
    }

    private function ReportApiSuccess(): void
    {
        $diagnostics = $this->LoadDiagnostics();
        $diagnostics['lastApiSuccess'] = time();
        $this->SaveDiagnostics($diagnostics);
    }

    private function ReportApiError(string $message): void
    {
        $diagnostics = $this->LoadDiagnostics();
        $diagnostics['apiErrors'] = (int) $diagnostics['apiErrors'] + 1;
        $diagnostics['lastApiError'] = $message;
        $diagnostics['lastApiErrorTime'] = time();
        $this->SaveDiagnostics($diagnostics);
    }

    private function ReportMqttSuccess(string $message): void
    {
        $diagnostics = $this->LoadDiagnostics();
        $diagnostics['lastMqttSuccess'] = time();
        $diagnostics['lastMqttStatus'] = $message;
        $diagnostics['lastMqttStatusTime'] = time();
        $this->SaveDiagnostics($diagnostics);
    }

    private function ReportMqttError(string $message): void
    {
        $diagnostics = $this->LoadDiagnostics();
        $diagnostics['mqttErrors'] = (int) $diagnostics['mqttErrors'] + 1;
        $diagnostics['lastMqttError'] = $message;
        $diagnostics['lastMqttErrorTime'] = time();
        $diagnostics['lastMqttStatus'] = $message;
        $diagnostics['lastMqttStatusTime'] = time();
        $this->SaveDiagnostics($diagnostics);
    }

    private function EnsureDiagnosticVariables(array $diagnostics): void
    {
        if (!$this->ReadPropertyBoolean('EnableDiagnostics')) {
            return;
        }
        $this->CreateOrUpdateVariable('diag_api_errors', $this->Translate('API errors'), $this->InstanceID, \VARIABLETYPE_INTEGER, '', (int) ($diagnostics['apiErrors'] ?? 0));
        $this->CreateOrUpdateVariable('diag_mqtt_errors', $this->Translate('MQTT errors'), $this->InstanceID, \VARIABLETYPE_INTEGER, '', (int) ($diagnostics['mqttErrors'] ?? 0));
        $this->CreateOrUpdateVariable('diag_last_api_error', $this->Translate('Last API error'), $this->InstanceID, \VARIABLETYPE_STRING, '', $this->formatDiagnosticMessage($diagnostics['lastApiErrorTime'] ?? null, $diagnostics['lastApiError'] ?? ''));
        $this->CreateOrUpdateVariable('diag_last_mqtt_error', $this->Translate('Last MQTT error'), $this->InstanceID, \VARIABLETYPE_STRING, '', $this->formatDiagnosticMessage($diagnostics['lastMqttErrorTime'] ?? null, $diagnostics['lastMqttError'] ?? ''));
        $this->CreateOrUpdateVariable('diag_last_mqtt_status', $this->Translate('Last MQTT status'), $this->InstanceID, \VARIABLETYPE_STRING, '', $this->formatDiagnosticMessage($diagnostics['lastMqttStatusTime'] ?? null, $diagnostics['lastMqttStatus'] ?? ''));
        $this->CreateOrUpdateVariable('diag_last_api_success', $this->Translate('Last successful API request'), $this->InstanceID, \VARIABLETYPE_STRING, '', $this->formatDiagnosticMessage($diagnostics['lastApiSuccess'] ?? null));
        $this->CreateOrUpdateVariable('diag_last_mqtt_success', $this->Translate('Last successful MQTT connection'), $this->InstanceID, \VARIABLETYPE_STRING, '', $this->formatDiagnosticMessage($diagnostics['lastMqttSuccess'] ?? null));
    }

    private function formatDiagnosticMessage($timestamp, string $message = ''): string
    {
        if (!is_int($timestamp) || $timestamp <= 0) {
            return $message;
        }
        $timeString = date('c', $timestamp);
        if ($message === '') {
            return $timeString;
        }
        return $timeString . ' – ' . $message;
    }

    /**
     * Gets the parent instance ID (MQTT Client)
     */
    private function GetParentID(): int
    {
        $instance = @IPS_GetInstance($this->InstanceID);
        return (int) ($instance['ConnectionID'] ?? 0);
    }

    // ==================== CHILD DATA FLOW ====================

    /**
     * Forwards data to all child device instances
     * Each child filters by its own StationSN
     */
    private function ForwardToChildren(string $stationSn, array $state, array $devicePayload): void
    {
        // Send station update
        $this->SendDataToChildren(json_encode([
            'DataID' => self::CHILD_DATA_RX_GUID,
            'stationSn' => $stationSn,
            'type' => 'station',
            'state' => $state,
            'timestamp' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Send individual device updates
        foreach ($devicePayload as $deviceSn => $deviceData) {
            if (!is_string($deviceSn) || $deviceSn === '') {
                continue;
            }
            $this->SendDataToChildren(json_encode([
                'DataID' => self::CHILD_DATA_RX_GUID,
                'stationSn' => $stationSn,
                'deviceSn' => $deviceSn,
                'type' => 'device',
                'state' => is_array($deviceData) ? $deviceData : ['value' => $deviceData],
                'timestamp' => time(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $this->SendDebug('XSenseGateway', sprintf('Forwarded data to children: station=%s, devices=%d', $stationSn, count($devicePayload)), 0);
    }

    /**
     * Receives requests from child devices (ForwardData interface)
     */
    public function ForwardData($JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return json_encode(['success' => false, 'error' => 'Invalid JSON']);
        }

        $buffer = $data['Buffer'] ?? [];
        if (is_string($buffer)) {
            $buffer = json_decode($buffer, true) ?? [];
        }

        $action = (string) ($buffer['Action'] ?? '');
        $this->SendDebug('XSenseGateway', 'ForwardData action: ' . $action, 0);

        try {
            switch ($action) {
                case 'GetInventory':
                    return json_encode([
                        'success' => true,
                        'inventory' => json_decode($this->ReadAttributeString('InventoryCache'), true) ?? [],
                    ]);

                case 'GetStationData':
                    $stationSn = (string) ($buffer['StationSN'] ?? '');
                    return json_encode([
                        'success' => true,
                        'data' => $this->GetStationDataFromCache($stationSn),
                    ]);

                case 'TriggerAction':
                    $stationSn = (string) ($buffer['StationSN'] ?? '');
                    $actionName = (string) ($buffer['ActionName'] ?? '');
                    $result = $this->TriggerStationAction($stationSn, $actionName);
                    return json_encode(['success' => $result]);

                case 'RequestUpdate':
                    $this->Update();
                    return json_encode(['success' => true]);

                default:
                    return json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
            }
        } catch (\Throwable $e) {
            $this->SendDebug('XSenseGateway', 'ForwardData error: ' . $e->getMessage(), 0);
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Gets station data from cache
     */
    private function GetStationDataFromCache(string $stationSn): array
    {
        $inventory = json_decode($this->ReadAttributeString('InventoryCache'), true);
        if (!is_array($inventory)) {
            return [];
        }

        foreach ($inventory as $house) {
            if (isset($house['stations'][$stationSn])) {
                return $house['stations'][$stationSn];
            }
        }

        return [];
    }

    // ==================== PUBLIC API METHODS ====================

    /**
     * Sets the webhook URL
     */
    public function SetWebhookURL(string $url): void
    {
        IPS_SetProperty($this->InstanceID, 'WebhookURL', $url);
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Enables or disables the webhook
     */
    public function EnableWebhook(bool $enable): void
    {
        IPS_SetProperty($this->InstanceID, 'WebhookEnabled', $enable);
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Tests the webhook by sending a test event
     */
    public function TestWebhook(): bool
    {
        $url = $this->ReadPropertyString('WebhookURL');
        if ($url === '') {
            $this->SendDebug('XSenseGateway', 'Webhook URL not configured', 0);
            return false;
        }
        
        $payload = [
            'event' => 'test',
            'station' => 'TEST-STATION',
            'device' => 'XS01-M',
            'type' => 'test',
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            'data' => [
                'message' => $this->Translate('This is a test webhook event'),
            ],
        ];
        
        return $this->SendWebhook($payload);
    }

    /**
     * Triggers a self-test for a station
     */
    public function TriggerTest(string $stationSn): bool
    {
        return $this->TriggerStationAction($stationSn, 'test');
    }

    /**
     * Mutes an active alarm on a station
     */
    public function MuteAlarm(string $stationSn): bool
    {
        return $this->TriggerStationAction($stationSn, 'mute');
    }

    /**
     * Gets the current inventory as JSON
     */
    public function GetInventory(): string
    {
        return $this->ReadAttributeString('InventoryCache');
    }

    /**
     * Gets the list of stations
     */
    public function GetStations(): array
    {
        $inventory = json_decode($this->ReadAttributeString('InventoryCache'), true);
        if (!is_array($inventory)) {
            return [];
        }
        
        $stations = [];
        foreach ($inventory as $houseId => $house) {
            $houseName = $house['definition']['houseName'] ?? $houseId;
            foreach (($house['stations'] ?? []) as $stationSn => $station) {
                $stations[] = [
                    'stationSn' => $stationSn,
                    'name' => $station['definition']['stationName'] ?? $stationSn,
                    'type' => $station['definition']['stationType'] ?? '',
                    'house' => $houseName,
                    'houseId' => $houseId,
                ];
            }
        }
        return $stations;
    }

    // ==================== WEBHOOK METHODS ====================

    /**
     * Sends a webhook notification
     */
    private function SendWebhook(array $payload): bool
    {
        if (!$this->ReadPropertyBoolean('WebhookEnabled')) {
            return false;
        }
        
        $url = $this->ReadPropertyString('WebhookURL');
        if ($url === '') {
            return false;
        }
        
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->SendDebug('XSenseGateway', 'Failed to encode webhook payload', 0);
            return false;
        }
        
        $this->SendDebug('XSenseGateway', 'Sending webhook to ' . $url, 0);
        
        try {
            $result = Sys_GetURLContentEx($url, [
                'Timeout' => 5000,
                'Headers' => [
                    'Content-Type: application/json',
                    'User-Agent: XSense-Symcon/1.0',
                ],
                'Content' => $json,
            ]);
            
            if ($result === false) {
                $this->SendDebug('XSenseGateway', 'Webhook request failed', 0);
                return false;
            }
            
            $this->SendDebug('XSenseGateway', 'Webhook sent successfully', 0);
            return true;
        } catch (\Throwable $e) {
            $this->SendDebug('XSenseGateway', 'Webhook error: ' . $e->getMessage(), 0);
            return false;
        }
    }

    /**
     * Sends an alarm webhook event
     */
    private function SendAlarmWebhook(string $stationSn, string $deviceType, array $data): void
    {
        $events = json_decode($this->ReadPropertyString('WebhookEvents'), true);
        if (!is_array($events) || !in_array('alarm', $events, true)) {
            return;
        }
        
        $payload = [
            'event' => 'alarm',
            'station' => $stationSn,
            'device' => $deviceType,
            'type' => $this->DetermineAlarmType($data),
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            'data' => $data,
        ];
        
        $this->SendWebhook($payload);
    }

    /**
     * Determines the alarm type from data
     */
    private function DetermineAlarmType(array $data): string
    {
        if (isset($data['coPpm']) && (float) $data['coPpm'] > 0) {
            return 'co';
        }
        if (isset($data['alarm']) && $data['alarm']) {
            return 'smoke';
        }
        if (isset($data['water']) && $data['water']) {
            return 'water';
        }
        return 'unknown';
    }

    /**
     * Triggers a station action by name
     */
    private function TriggerStationAction(string $stationSn, string $actionName): bool
    {
        $inventory = json_decode($this->ReadAttributeString('InventoryCache'), true);
        if (!is_array($inventory)) {
            $this->SendDebug('XSenseGateway', 'No inventory available', 0);
            return false;
        }
        
        $stationIndex = json_decode($this->ReadAttributeString('StationIndex'), true);
        $houseId = is_array($stationIndex) ? ($stationIndex[$stationSn] ?? null) : null;
        if (!is_string($houseId) || !isset($inventory[$houseId]['stations'][$stationSn])) {
            $this->SendDebug('XSenseGateway', 'Station not found: ' . $stationSn, 0);
            return false;
        }
        
        $station = $inventory[$houseId]['stations'][$stationSn];
        $definitions = EntityDefinitions::definitions();
        $type = $station['definition']['stationType'] ?? $station['definition']['type'] ?? '';
        
        if (!isset($definitions[$type]['actions'])) {
            $this->SendDebug('XSenseGateway', 'No actions defined for type: ' . $type, 0);
            return false;
        }
        
        // Find the action by name
        $action = null;
        foreach ($definitions[$type]['actions'] as $a) {
            if (($a['action'] ?? '') === $actionName) {
                $action = $a;
                break;
            }
        }
        
        if ($action === null) {
            $this->SendDebug('XSenseGateway', 'Action not found: ' . $actionName, 0);
            return false;
        }
        
        try {
            $this->EnsureClient()->triggerAction($inventory[$houseId], $station, $action);
            $this->SendDebug('XSenseGateway', 'Action triggered: ' . $actionName . ' on ' . $stationSn, 0);
            return true;
        } catch (\Throwable $e) {
            $this->SendDebug('XSenseGateway', 'Action failed: ' . $e->getMessage(), 0);
            return false;
        }
    }
}
