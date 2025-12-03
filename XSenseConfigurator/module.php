<?php

declare(strict_types=1);

class XSenseConfigurator extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        
        $this->ConnectParent('{9B0C4989-3A7D-4D82-8A5F-59A7249A9163}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm(): string
    {
        $form = [
            'actions' => [
                [
                    'type' => 'Configurator',
                    'name' => 'Configurator',
                    'caption' => $this->Translate('X-Sense Devices'),
                    'rowCount' => 20,
                    'add' => false,
                    'delete' => false,
                    'sort' => [
                        'column' => 'name',
                        'direction' => 'ascending',
                    ],
                    'columns' => [
                        ['caption' => $this->Translate('Name'), 'name' => 'name', 'width' => 'auto'],
                        ['caption' => $this->Translate('Type'), 'name' => 'type', 'width' => '120px'],
                        ['caption' => $this->Translate('Serial number'), 'name' => 'serial', 'width' => '150px'],
                        ['caption' => $this->Translate('House'), 'name' => 'house', 'width' => '150px'],
                        ['caption' => $this->Translate('Status'), 'name' => 'status', 'width' => '100px'],
                    ],
                    'values' => $this->GetDeviceList(),
                ],
            ],
        ];
        
        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    /**
     * Gets the list of devices from the parent gateway
     */
    private function GetDeviceList(): array
    {
        $parentId = $this->GetParentID();
        if ($parentId === 0) {
            return [];
        }
        
        // Get inventory from parent
        $inventoryJson = @XSENSE_GetInventory($parentId);
        if ($inventoryJson === false || $inventoryJson === '') {
            return [];
        }
        
        $inventory = json_decode($inventoryJson, true);
        if (!is_array($inventory)) {
            return [];
        }
        
        $devices = [];
        
        foreach ($inventory as $houseId => $house) {
            $houseName = $house['definition']['houseName'] ?? $houseId;
            
            // Add stations
            foreach (($house['stations'] ?? []) as $stationSn => $station) {
                $stationType = $station['definition']['stationType'] ?? $station['definition']['type'] ?? '';
                $stationName = $station['definition']['stationName'] ?? $stationSn;
                $online = $this->IsStationOnline($station);
                
                $devices[] = [
                    'name' => $stationName,
                    'type' => $stationType,
                    'serial' => $stationSn,
                    'house' => $houseName,
                    'status' => $online ? $this->Translate('Online') : $this->Translate('Offline'),
                    'instanceID' => 0,
                    'create' => $this->GetCreateConfig($stationSn, $stationName, $stationType, $houseId),
                ];
                
                // Add devices connected to this station
                foreach (($station['devices'] ?? []) as $deviceSn => $device) {
                    $deviceType = $device['type'] ?? $device['deviceType'] ?? '';
                    $deviceName = $device['name'] ?? $device['deviceName'] ?? $deviceSn;
                    $deviceOnline = $device['online'] ?? true;
                    
                    $devices[] = [
                        'name' => '  └ ' . $deviceName,
                        'type' => $deviceType,
                        'serial' => $deviceSn,
                        'house' => $houseName,
                        'status' => $deviceOnline ? $this->Translate('Online') : $this->Translate('Offline'),
                        'instanceID' => 0,
                        'create' => $this->GetCreateConfig($deviceSn, $deviceName, $deviceType, $houseId, $stationSn),
                    ];
                }
            }
        }
        
        return $devices;
    }

    /**
     * Checks if a station is online
     */
    private function IsStationOnline(array $station): bool
    {
        // Check in shadows
        foreach ($station['shadows'] ?? [] as $shadow) {
            $reported = $shadow['state']['reported'] ?? [];
            if (isset($reported['online'])) {
                return (bool) $reported['online'];
            }
        }
        
        // Check in definition
        if (isset($station['definition']['online'])) {
            return (bool) $station['definition']['online'];
        }
        
        return true;
    }

    /**
     * Gets the create configuration for a device
     */
    private function GetCreateConfig(string $serial, string $name, string $type, string $houseId, ?string $stationSn = null): array
    {
        return [
            'moduleID' => '{7D2C8F3E-5A4B-4E9D-8C1F-3B2A4D5E6F7C}', // XSense Device
            'configuration' => [
                'StationSN' => $serial,
                'DeviceType' => $type,
                'HouseID' => $houseId,
                'EnableActions' => true,
            ],
            'name' => $name,
            'location' => [$this->Translate('X-Sense'), $type],
        ];
    }

    /**
     * Gets the parent gateway ID
     */
    private function GetParentID(): int
    {
        $parentId = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        return (int) $parentId;
    }
}
