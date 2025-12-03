<?php

declare(strict_types=1);

class XSenseDevice extends IPSModule
{
    // Data flow GUIDs - must match Gateway
    private const PARENT_DATA_TX_GUID = '{XSENSE-DEVICE-TX-GUID-F6E5D4C3B2A1}';
    private const PARENT_DATA_RX_GUID = '{XSENSE-DEVICE-RX-GUID-A1B2C3D4E5F6}';

    private const DEVICE_TYPES = [
        'XS01-M'   => ['name' => 'Smoke Detector', 'icon' => 'Flame', 'sensors' => ['alarm', 'batInfo', 'rfLevel']],
        'XS01-WX'  => ['name' => 'Smoke Detector WiFi', 'icon' => 'Flame', 'sensors' => ['alarm', 'batInfo', 'wifiRSSI']],
        'XC01-M'   => ['name' => 'CO Detector', 'icon' => 'Gas', 'sensors' => ['alarm', 'coPpm', 'batInfo', 'rfLevel']],
        'XC04-WX'  => ['name' => 'CO Detector WiFi', 'icon' => 'Gas', 'sensors' => ['alarm', 'coPpm', 'batInfo', 'wifiRSSI']],
        'SC06-WX'  => ['name' => 'Combo Detector', 'icon' => 'Warning', 'sensors' => ['alarm', 'coPpm', 'batInfo', 'wifiRSSI']],
        'SC07-WX'  => ['name' => 'Combo Detector', 'icon' => 'Warning', 'sensors' => ['alarm', 'coPpm', 'batInfo', 'wifiRSSI']],
        'XP0A-MR'  => ['name' => 'Alarm Hub', 'icon' => 'Alert', 'sensors' => ['alarm', 'alarmVol', 'voiceVol']],
        'XP02S-MR' => ['name' => 'Alarm Hub', 'icon' => 'Alert', 'sensors' => ['alarm', 'alarmVol', 'voiceVol']],
        'STH51'    => ['name' => 'Thermo-Hygrometer', 'icon' => 'Temperature', 'sensors' => ['temperature', 'humidity', 'batInfo']],
        'STH0A'    => ['name' => 'Thermo-Hygrometer', 'icon' => 'Temperature', 'sensors' => ['temperature', 'humidity', 'batInfo']],
        'SWS51'    => ['name' => 'Water Sensor', 'icon' => 'Drops', 'sensors' => ['water', 'batInfo', 'rfLevel']],
        'SBS10'    => ['name' => 'Base Station', 'icon' => 'Network', 'sensors' => ['online', 'wifiRSSI', 'ssid', 'ip', 'sw']],
    ];

    private const SENSOR_PROFILES = [
        'alarm'       => ['type' => VARIABLETYPE_BOOLEAN, 'profile' => '~Alert'],
        'water'       => ['type' => VARIABLETYPE_BOOLEAN, 'profile' => '~Alert'],
        'online'      => ['type' => VARIABLETYPE_BOOLEAN, 'profile' => '~Switch'],
        'coPpm'       => ['type' => VARIABLETYPE_FLOAT, 'profile' => ''],
        'temperature' => ['type' => VARIABLETYPE_FLOAT, 'profile' => '~Temperature'],
        'humidity'    => ['type' => VARIABLETYPE_FLOAT, 'profile' => '~Humidity.F'],
        'batInfo'     => ['type' => VARIABLETYPE_FLOAT, 'profile' => '~Battery.100'],
        'rfLevel'     => ['type' => VARIABLETYPE_INTEGER, 'profile' => 'XSense.RFLevel'],
        'wifiRSSI'    => ['type' => VARIABLETYPE_INTEGER, 'profile' => '~SignalStrength'],
        'alarmVol'    => ['type' => VARIABLETYPE_INTEGER, 'profile' => '~Intensity.100'],
        'voiceVol'    => ['type' => VARIABLETYPE_INTEGER, 'profile' => '~Intensity.100'],
        'ssid'        => ['type' => VARIABLETYPE_STRING, 'profile' => ''],
        'ip'          => ['type' => VARIABLETYPE_STRING, 'profile' => ''],
        'sw'          => ['type' => VARIABLETYPE_STRING, 'profile' => ''],
    ];

    private const SENSOR_NAMES = [
        'alarm'       => 'Alarm',
        'water'       => 'Water detected',
        'online'      => 'Online',
        'coPpm'       => 'CO (ppm)',
        'temperature' => 'Temperature',
        'humidity'    => 'Humidity',
        'batInfo'     => 'Battery',
        'rfLevel'     => 'RF Level',
        'wifiRSSI'    => 'WiFi Signal',
        'alarmVol'    => 'Alarm Volume',
        'voiceVol'    => 'Voice Volume',
        'ssid'        => 'WiFi SSID',
        'ip'          => 'IP Address',
        'sw'          => 'Firmware',
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('StationSN', '');
        $this->RegisterPropertyString('DeviceType', '');
        $this->RegisterPropertyString('HouseID', '');
        $this->RegisterPropertyBoolean('EnableActions', true);

        $this->RegisterAttributeString('LastUpdate', '');
        $this->RegisterAttributeString('DeviceData', '{}');

        $this->ConnectParent(self::PARENT_DATA_TX_GUID);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();

        $stationSn = $this->ReadPropertyString('StationSN');
        $deviceType = $this->ReadPropertyString('DeviceType');

        if ($stationSn === '' || $deviceType === '') {
            $this->SetStatus(201);
            return;
        }

        // Create variables based on device type
        $this->CreateDeviceVariables($deviceType);

        // Register message sink for parent
        $parentId = $this->GetParentID();
        if ($parentId > 0) {
            $this->RegisterMessage($parentId, IM_CHANGESTATUS);
        }

        $this->SetStatus(IS_ACTIVE);
    }

    public function GetConfigurationForm(): string
    {
        $deviceType = $this->ReadPropertyString('DeviceType');
        $typeInfo = self::DEVICE_TYPES[$deviceType] ?? null;
        $typeName = $typeInfo ? $this->Translate($typeInfo['name']) : $deviceType;

        $form = [
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'StationSN', 'caption' => $this->Translate('Serial number')],
                ['type' => 'Select', 'name' => 'DeviceType', 'caption' => $this->Translate('Device type'), 'options' => $this->GetDeviceTypeOptions()],
                ['type' => 'ValidationTextBox', 'name' => 'HouseID', 'caption' => $this->Translate('House ID'), 'visible' => false],
                ['type' => 'CheckBox', 'name' => 'EnableActions', 'caption' => $this->Translate('Enable actions')],
            ],
            'actions' => [
                ['type' => 'Label', 'caption' => sprintf($this->Translate('Device: %s'), $typeName)],
                ['type' => 'Label', 'caption' => sprintf($this->Translate('Last update: %s'), $this->ReadAttributeString('LastUpdate') ?: '-')],
                ['type' => 'Button', 'label' => $this->Translate('Refresh'), 'onClick' => 'XSENSEDEV_RequestUpdate($id);'],
                ['type' => 'Button', 'label' => $this->Translate('Test'), 'onClick' => 'XSENSEDEV_TriggerTest($id);', 'visible' => $this->SupportsAction('test')],
                ['type' => 'Button', 'label' => $this->Translate('Mute'), 'onClick' => 'XSENSEDEV_MuteAlarm($id);', 'visible' => $this->SupportsAction('mute')],
            ],
        ];

        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    public function RequestAction($ident, $value): void
    {
        if (!is_string($ident)) {
            return;
        }

        switch ($ident) {
            case 'action_test':
                $this->TriggerTest();
                break;
            case 'action_mute':
                $this->MuteAlarm();
                break;
        }
    }

    /**
     * Requests an update from the gateway
     */
    public function RequestUpdate(): void
    {
        // Request update via data flow
        $response = $this->SendToParent('RequestUpdate', []);
        
        if (!is_array($response) || !($response['success'] ?? false)) {
            $this->SendDebug('XSenseDevice', 'RequestUpdate failed', 0);
            return;
        }

        // Get our data from parent inventory
        $this->UpdateFromInventory();
    }

    /**
     * Triggers a self-test
     */
    public function TriggerTest(): bool
    {
        $stationSn = $this->ReadPropertyString('StationSN');
        if ($stationSn === '') {
            return false;
        }

        $response = $this->SendToParent('TriggerAction', [
            'StationSN' => $stationSn,
            'ActionName' => 'test',
        ]);

        return is_array($response) && ($response['success'] ?? false);
    }

    /**
     * Mutes an active alarm
     */
    public function MuteAlarm(): bool
    {
        $stationSn = $this->ReadPropertyString('StationSN');
        if ($stationSn === '') {
            return false;
        }

        $response = $this->SendToParent('TriggerAction', [
            'StationSN' => $stationSn,
            'ActionName' => 'mute',
        ]);

        return is_array($response) && ($response['success'] ?? false);
    }

    /**
     * Gets the current device status
     */
    public function GetStatus(): array
    {
        $data = json_decode($this->ReadAttributeString('DeviceData'), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Receives data from parent gateway (forwarded MQTT messages)
     */
    public function ReceiveData($JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return '';
        }

        $this->SendDebug('XSenseDevice', 'ReceiveData: ' . $JSONString, 0);

        // Check if this data is for us
        $myStationSn = $this->ReadPropertyString('StationSN');
        $incomingStationSn = (string) ($data['stationSn'] ?? '');
        
        if ($myStationSn === '' || $incomingStationSn === '') {
            return '';
        }

        // Check message type
        $type = (string) ($data['type'] ?? '');
        $deviceSn = (string) ($data['deviceSn'] ?? '');

        // For station messages: match by StationSN
        if ($type === 'station' && $incomingStationSn === $myStationSn) {
            $this->SendDebug('XSenseDevice', 'Processing station update for ' . $myStationSn, 0);
            $this->ProcessDeviceData($data['state'] ?? []);
            return '';
        }

        // For device messages: match by deviceSn (sub-device of station)
        if ($type === 'device' && $deviceSn === $myStationSn) {
            $this->SendDebug('XSenseDevice', 'Processing device update for ' . $deviceSn, 0);
            $this->ProcessDeviceData($data['state'] ?? []);
            return '';
        }

        // Also check if we're a sub-device and the parent station matches
        if ($type === 'device' && $incomingStationSn === $myStationSn) {
            // This is for a sub-device of our station, ignore unless we are that device
            return '';
        }

        return '';
    }

    /**
     * Updates device from parent inventory
     */
    private function UpdateFromInventory(): void
    {
        $stationSn = $this->ReadPropertyString('StationSN');
        if ($stationSn === '') {
            return;
        }

        // Request station data via data flow
        $response = $this->SendToParent('GetStationData', ['StationSN' => $stationSn]);
        
        if (!is_array($response) || !($response['success'] ?? false)) {
            $this->SendDebug('XSenseDevice', 'GetStationData failed', 0);
            return;
        }

        $stationData = $response['data'] ?? [];
        if (!empty($stationData)) {
            $this->ProcessStationData($stationData);
        }
    }

    /**
     * Sends a request to the parent gateway
     */
    private function SendToParent(string $action, array $params = []): ?array
    {
        $buffer = array_merge(['Action' => $action], $params);
        $packet = json_encode([
            'DataID' => self::PARENT_DATA_TX_GUID,
            'Buffer' => json_encode($buffer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $result = @$this->SendDataToParent($packet);
        if (!is_string($result) || $result === '') {
            return null;
        }

        $decoded = json_decode($result, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Processes station data from inventory
     */
    private function ProcessStationData(array $station): void
    {
        $state = [];

        // Extract state from shadows
        foreach ($station['shadows'] ?? [] as $shadow) {
            $reported = $shadow['state']['reported'] ?? [];
            $state = array_merge($state, $reported);
        }

        // Extract from definition
        $definition = $station['definition'] ?? [];
        foreach (['stationType', 'stationName', 'online'] as $key) {
            if (isset($definition[$key])) {
                $state[$key] = $definition[$key];
            }
        }

        $this->ProcessDeviceData($state);
    }

    /**
     * Processes device data and updates variables
     */
    private function ProcessDeviceData(array $state): void
    {
        if (empty($state)) {
            return;
        }

        $this->WriteAttributeString('DeviceData', json_encode($state, JSON_THROW_ON_ERROR));
        $this->WriteAttributeString('LastUpdate', date('Y-m-d H:i:s'));

        $deviceType = $this->ReadPropertyString('DeviceType');
        $typeInfo = self::DEVICE_TYPES[$deviceType] ?? null;
        $sensors = $typeInfo['sensors'] ?? array_keys(self::SENSOR_PROFILES);

        foreach ($sensors as $sensor) {
            if (!isset($state[$sensor])) {
                continue;
            }

            $value = $state[$sensor];
            $profile = self::SENSOR_PROFILES[$sensor] ?? null;
            if ($profile === null) {
                continue;
            }

            $varId = @$this->GetIDForIdent($sensor);
            if ($varId === false) {
                continue;
            }

            // Convert value to correct type
            switch ($profile['type']) {
                case VARIABLETYPE_BOOLEAN:
                    $value = (bool) $value;
                    break;
                case VARIABLETYPE_INTEGER:
                    $value = (int) $value;
                    break;
                case VARIABLETYPE_FLOAT:
                    $value = (float) $value;
                    break;
                case VARIABLETYPE_STRING:
                    $value = (string) $value;
                    break;
            }

            $this->SetValue($sensor, $value);
        }

        $this->SendDebug('XSenseDevice', 'Updated ' . count($state) . ' values', 0);
    }

    /**
     * Creates variables for the device type
     */
    private function CreateDeviceVariables(string $deviceType): void
    {
        $typeInfo = self::DEVICE_TYPES[$deviceType] ?? null;
        $sensors = $typeInfo ? $typeInfo['sensors'] : [];

        // Always include basic sensors if type unknown
        if (empty($sensors)) {
            $sensors = ['alarm', 'batInfo', 'online'];
        }

        foreach ($sensors as $sensor) {
            $profile = self::SENSOR_PROFILES[$sensor] ?? null;
            if ($profile === null) {
                continue;
            }

            $name = $this->Translate(self::SENSOR_NAMES[$sensor] ?? $sensor);
            $this->MaintainVariable($sensor, $name, $profile['type'], $profile['profile'], 0, true);
        }

        // Create action variables if enabled
        if ($this->ReadPropertyBoolean('EnableActions')) {
            if ($this->SupportsAction('test')) {
                $this->MaintainVariable('action_test', $this->Translate('Test'), VARIABLETYPE_BOOLEAN, '~Switch', 100, true);
                $this->EnableAction('action_test');
            }
            if ($this->SupportsAction('mute')) {
                $this->MaintainVariable('action_mute', $this->Translate('Mute'), VARIABLETYPE_BOOLEAN, '~Switch', 101, true);
                $this->EnableAction('action_mute');
            }
        }
    }

    /**
     * Checks if device supports an action
     */
    private function SupportsAction(string $action): bool
    {
        $deviceType = $this->ReadPropertyString('DeviceType');

        $actionMap = [
            'test' => ['XS01-M', 'XS01-WX', 'XC01-M', 'SC06-WX', 'XP0A-MR', 'XP02S-MR', 'STH51', 'STH0A', 'SWS51'],
            'mute' => ['XS01-M', 'XC01-M', 'XC04-WX', 'SC07-WX', 'STH51', 'STH0A', 'SWS51'],
        ];

        return in_array($deviceType, $actionMap[$action] ?? [], true);
    }

    /**
     * Gets device type options for form
     */
    private function GetDeviceTypeOptions(): array
    {
        $options = [['caption' => $this->Translate('Please select...'), 'value' => '']];

        foreach (self::DEVICE_TYPES as $type => $info) {
            $options[] = [
                'caption' => $type . ' - ' . $this->Translate($info['name']),
                'value' => $type,
            ];
        }

        return $options;
    }

    /**
     * Ensures required profiles exist
     */
    private function EnsureProfiles(): void
    {
        if (!IPS_VariableProfileExists('XSense.RFLevel')) {
            IPS_CreateVariableProfile('XSense.RFLevel', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('XSense.RFLevel', 0, 3, 1);
            IPS_SetVariableProfileAssociation('XSense.RFLevel', 0, $this->Translate('no signal'), '', 0xFF0000);
            IPS_SetVariableProfileAssociation('XSense.RFLevel', 1, $this->Translate('weak'), '', 0xFFA500);
            IPS_SetVariableProfileAssociation('XSense.RFLevel', 2, $this->Translate('medium'), '', 0xFFFF00);
            IPS_SetVariableProfileAssociation('XSense.RFLevel', 3, $this->Translate('good'), '', 0x00FF00);
        }
    }

    /**
     * Gets the parent gateway ID
     */
    private function GetParentID(): int
    {
        $instance = IPS_GetInstance($this->InstanceID);
        return (int) ($instance['ConnectionID'] ?? 0);
    }
}
