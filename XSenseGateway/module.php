<?php

declare(strict_types=1);

require_once __DIR__ . '/AWSSigner.php';
require_once __DIR__ . '/EntityDefinitions.php';
require_once __DIR__ . '/XSenseCloudClient.php';

use XSense\Gateway\EntityDefinitions;
use XSense\Gateway\XSenseCloudClient;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class XSenseGateway extends IPSModule
{
    private const TIMER_IDENT = 'XSenseUpdate';

    private ?XSenseCloudClient $client = null;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('UpdateInterval', 300);
        $this->RegisterPropertyInteger('MQTTClientID', 0);
        $this->RegisterPropertyBoolean('EnableDiagnostics', true);
        $this->RegisterPropertyBoolean('EnableBinarySensors', true);
        $this->RegisterPropertyBoolean('EnableActions', true);

        $this->RegisterTimer(self::TIMER_IDENT, 0, 'XSENSE_Update($_IPS["TARGET"]);');

        $this->RegisterAttributeString('SessionCache', '{}');
        $this->RegisterAttributeString('InventoryCache', '{}');

        $this->ConnectParent('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $interval = max(60, $this->ReadPropertyInteger('UpdateInterval')) * 1000;
        $this->SetTimerInterval(self::TIMER_IDENT, $interval);

        if (IPS_GetKernelRunlevel() === KR_READY && $this->ReadPropertyInteger('MQTTClientID') > 0) {
            $this->MaintainMqttLink($this->ReadPropertyInteger('MQTTClientID'));
        }
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'Email', 'caption' => 'E-Mail'],
                ['type' => 'PasswordTextBox', 'name' => 'Password', 'caption' => 'Passwort'],
                ['type' => 'NumberSpinner', 'name' => 'UpdateInterval', 'caption' => 'Polling-Intervall (s)', 'minimum' => 60],
                ['type' => 'SelectInstance', 'name' => 'MQTTClientID', 'caption' => 'MQTT Client', 'moduleID' => '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'],
                ['type' => 'CheckBox', 'name' => 'EnableDiagnostics', 'caption' => 'Diagnose-Variablen anlegen'],
                ['type' => 'CheckBox', 'name' => 'EnableBinarySensors', 'caption' => 'Statusvariablen anlegen'],
                ['type' => 'CheckBox', 'name' => 'EnableActions', 'caption' => 'Aktionen bereitstellen'],
            ],
            'actions' => [
                ['type' => 'Button', 'label' => 'Manueller Sync', 'onClick' => 'XSENSE_Update($id);'],
            ],
        ];
        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    public function RequestAction($ident, $value): void
    {
        if (!is_string($ident)) {
            throw new InvalidArgumentException('Ident muss ein String sein');
        }
        if (strpos($ident, 'action_') === 0) {
            $this->ExecuteDeviceAction(substr($ident, 7));
            return;
        }
        throw new InvalidArgumentException('Unbekannte Aktion: ' . $ident);
    }

    public function Update(): void
    {
        try {
            $client = $this->EnsureClient();
            $inventory = $client->syncInventory();
            $this->WriteAttributeString('SessionCache', json_encode($client->exportSession(), JSON_THROW_ON_ERROR));
            $this->WriteAttributeString('InventoryCache', json_encode($inventory, JSON_THROW_ON_ERROR));
            $this->SyncVariables($inventory);
            $this->EnsureMqttSubscriptions($inventory);
            $this->SetStatus(IS_ACTIVE);
        } catch (Throwable $exception) {
            $this->SendDebug('XSenseGateway', $exception->getMessage(), 0);
            $this->SetStatus(IS_EBASE + 1);
        }
    }

    public function MessageSink($timestamp, $senderID, $message, $data): void
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);
        if ($message === IM_CHANGESTATUS && $senderID === $this->ReadPropertyInteger('MQTTClientID')) {
            $this->SendDebug('XSenseGateway', 'MQTT status changed: ' . json_encode($data), 0);
        }
        if ($message === IM_MESSAGERECEIVE && $senderID === $this->ReadPropertyInteger('MQTTClientID')) {
            $this->HandleMqttPayload($data);
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

    private function SyncVariables(array $inventory): void
    {
        foreach ($inventory as $houseId => $house) {
            $houseCategory = $this->EnsureCategory('house_' . $houseId, $house['definition']['houseName'] ?? $houseId, $this->InstanceID);
            $this->CreateOrUpdateVariable('house_' . $houseId . '_online', 'Online', $houseCategory, VARIABLETYPE_BOOLEAN, '~Switch', $house['definition']['online'] ?? true);
            foreach ($house['stations'] as $station) {
                $stationIdent = 'station_' . ($station['stationId'] ?? $station['sn'] ?? uniqid());
                $stationCategory = $this->EnsureCategory($stationIdent, $station['stationName'] ?? ($station['sn'] ?? 'Station'), $houseCategory);
                if ($this->ReadPropertyBoolean('EnableDiagnostics')) {
                    $this->CreateOrUpdateVariable($stationIdent . '_wifi', 'WLAN RSSI', $stationCategory, VARIABLETYPE_INTEGER, '~Intensity.100', $station['wifiRSSI'] ?? 0);
                    $this->CreateOrUpdateVariable($stationIdent . '_fw', 'Firmware', $stationCategory, VARIABLETYPE_STRING, '', $station['firmware'] ?? '');
                }
                if ($this->ReadPropertyBoolean('EnableBinarySensors')) {
                    $this->CreateOrUpdateVariable($stationIdent . '_alarm', 'Alarm', $stationCategory, VARIABLETYPE_BOOLEAN, '~Alert', $station['alarm'] ?? false);
                }
                if ($this->ReadPropertyBoolean('EnableActions')) {
                    $this->EnsureActionButtons($stationIdent, $station, $stationCategory);
                }
            }
        }
    }

    private function EnsureMqttSubscriptions(array $inventory): void
    {
        $clientId = $this->ReadPropertyInteger('MQTTClientID');
        if ($clientId === 0) {
            return;
        }
        $topics = [];
        foreach ($inventory as $house) {
            if (!isset($house['definition']['houseId'])) {
                continue;
            }
            $topics[] = sprintf('xsense/%s/#', $house['definition']['houseId']);
        }
        $this->SendDebug('XSenseGateway', 'MQTT Subscriptions: ' . json_encode($topics), 0);
        foreach ($topics as $topic) {
            $this->SendDataToParent(json_encode([
                'DataID' => '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}',
                'Buffer' => json_encode(['Command' => 'Subscribe', 'Topic' => $topic]),
            ]));
        }
    }

    private function MaintainMqttLink(int $instanceID): void
    {
        if ($instanceID > 0 && IPS_InstanceExists($instanceID)) {
            $this->SendDebug('XSenseGateway', 'Linked to MQTT instance ' . $instanceID, 0);
            $this->SetStatus(IS_ACTIVE);
        }
    }

    private function HandleMqttPayload($payload): void
    {
        if (!is_string($payload) || $payload === '') {
            return;
        }
        $this->SendDebug('XSenseGateway', 'MQTT payload received', 0);
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

    private function CreateOrUpdateVariable(string $ident, string $name, int $parent, int $type, string $profile, $value): void
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($vid === false) {
            $vid = IPS_CreateVariable($type);
            IPS_SetIdent($vid, $ident);
            if ($profile !== '') {
                IPS_SetVariableCustomProfile($vid, $profile);
            }
        }
        IPS_SetParent($vid, $parent);
        IPS_SetName($vid, $name);
        SetValue($vid, $value);
    }

    private function EnsureActionButtons(string $stationIdent, array $station, int $parent): void
    {
        $definitions = EntityDefinitions::definitions();
        $type = $station['stationType'] ?? $station['type'] ?? '';
        if (!isset($definitions[$type])) {
            return;
        }
        foreach ($definitions[$type]['actions'] as $index => $action) {
            $ident = sprintf('action_%s_%d', $stationIdent, $index);
            $name = strtoupper($action['action']);
            $this->MaintainActionVariable($ident, $name, $parent);
        }
    }

    private function MaintainActionVariable(string $ident, string $name, int $parent): void
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($vid === false) {
            $vid = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);
            IPS_SetIdent($vid, $ident);
            IPS_SetVariableCustomAction($vid, $this->InstanceID);
        }
        IPS_SetParent($vid, $parent);
        IPS_SetName($vid, $name);
        SetValueBoolean($vid, false);
    }

    private function ExecuteDeviceAction(string $token): void
    {
        $pos = strrpos($token, '_');
        if ($pos === false) {
            throw new RuntimeException('Ungültiger Aktionsbezeichner');
        }
        $stationIdent = substr($token, 0, $pos);
        $index = (int) substr($token, $pos + 1);
        $inventory = json_decode($this->ReadAttributeString('InventoryCache'), true);
        if (!is_array($inventory)) {
            throw new RuntimeException('Kein Inventar vorhanden');
        }
        foreach ($inventory as $house) {
            foreach ($house['stations'] as $station) {
                $currentIdent = 'station_' . ($station['stationId'] ?? $station['sn'] ?? '');
                if ($currentIdent !== $stationIdent) {
                    continue;
                }
                $definitions = EntityDefinitions::definitions();
                $type = $station['stationType'] ?? $station['type'] ?? '';
                if (!isset($definitions[$type]['actions'][(int) $index])) {
                    throw new RuntimeException('Aktion nicht verfügbar');
                }
                $action = $definitions[$type]['actions'][(int) $index];
                $this->SendDebug('XSenseGateway', 'Trigger action ' . json_encode($action), 0);
                // TODO: call $this->EnsureClient()->triggerAction(...)
                return;
            }
        }
        throw new RuntimeException('Station nicht gefunden');
    }
}
