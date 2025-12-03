# X-Sense Gateway für IP-Symcon

[![IP-Symcon Version](https://img.shields.io/badge/IP--Symcon-7.0+-blue.svg)](https://www.symcon.de)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Dieses Modul integriert X-Sense Rauchmelder, CO-Melder und Sensoren in IP-Symcon.

## Inhaltsverzeichnis

- [Funktionsumfang](#funktionsumfang)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Konfiguration](#konfiguration)
- [Unterstützte Geräte](#unterstützte-geräte)
- [Variablen und Profile](#variablen-und-profile)
- [Aktionen](#aktionen)
- [Webhook-Alarme](#webhook-alarme)
- [PHP-Befehlsreferenz](#php-befehlsreferenz)

## Funktionsumfang

- **Cloud-Anbindung**: Automatische Authentifizierung mit X-Sense Cloud (AWS Cognito SRP)
- **MQTT-Echtzeit**: Live-Updates über AWS IoT MQTT
- **Sensordaten**: Temperatur, Luftfeuchtigkeit, CO-Werte, Batteriestatus
- **Alarme**: Echtzeit-Benachrichtigung bei Rauch-/CO-Alarm
- **Aktionen**: Test- und Stummschaltfunktionen für Melder
- **Webhook-Support**: HTTP-Callbacks bei Alarmereignissen
- **Diagnose**: WLAN-Signalstärke, Firmware-Version, Funkpegel

## Voraussetzungen

- IP-Symcon ab Version 7.0
- PHP GMP-Extension (für SRP-Login)
- X-Sense Account mit registrierten Geräten
- MQTT Client Instanz (Websocket)

## Installation

### Über den Module Store (empfohlen)

1. IP-Symcon Verwaltungskonsole öffnen
2. *Module Store* aufrufen
3. Nach "X-Sense" suchen und installieren

### Manuelle Installation

1. IP-Symcon Verwaltungskonsole öffnen
2. *Module Control* aufrufen
3. URL hinzufügen: `https://github.com/Jarnsen/ha-xsense-component_test`

## Module

Diese Bibliothek enthält drei Module:

| Modul | Beschreibung |
|-------|--------------|
| **XSense Gateway** | Hauptmodul für Cloud-Verbindung und MQTT |
| **XSense Configurator** | Geräte-Übersicht und automatische Instanzerstellung |
| **XSense Device** | Einzelne Geräte (Rauchmelder, CO-Melder, etc.) |

## Konfiguration

### 1. MQTT Client einrichten

Das Modul benötigt einen MQTT Client als Parent-Instanz:

1. Neue Instanz erstellen: *MQTT Client (Websocket)*
2. Die Verbindungsdaten werden automatisch vom X-Sense Gateway konfiguriert

### 2. X-Sense Gateway konfigurieren

| Eigenschaft | Beschreibung |
|-------------|--------------|
| **E-Mail** | X-Sense Account E-Mail |
| **Passwort** | X-Sense Account Passwort |
| **Polling-Intervall** | Aktualisierungsintervall in Sekunden (min. 60) |
| **MQTT Client** | Verknüpfte MQTT Client Instanz |
| **Shadow-Seitenpriorität** | Datenquelle für Sensorwerte |
| **Diagnose-Variablen** | WLAN, Firmware, RF-Pegel anzeigen |
| **Umweltsensoren** | Temperatur, Luftfeuchtigkeit, CO |
| **Alarm-Variablen** | Alarmstatus-Variablen anlegen |
| **Geräte synchronisieren** | Verbundene Sensoren (z.B. Raumfühler) |
| **Aktionen bereitstellen** | Test- und Stummschalt-Buttons |
| **Stationenauswahl** | Einzelne Stationen ein-/ausschließen |

## Unterstützte Geräte

| Modell | Typ | Aktionen |
|--------|-----|----------|
| **SBS10** | Basisstation | - |
| **XS01-M** | Rauchmelder | Test, Stummschalten |
| **XS01-WX** | Rauchmelder (WLAN) | Test |
| **XC01-M** | CO-Melder | Test, Stummschalten |
| **XC04-WX** | CO-Melder (WLAN) | Stummschalten |
| **SC06-WX** | Kombi-Melder | Test |
| **SC07-WX** | Kombi-Melder | Stummschalten |
| **XP0A-MR** | Alarmzentrale | Test, Feueralarm-Übung |
| **XP02S-MR** | Alarmzentrale | Test |
| **STH51** | Temperatur/Feuchte-Sensor | Test, Stummschalten |
| **STH0A** | Temperatur/Feuchte-Sensor | Test, Stummschalten |
| **SWS51** | Wassersensor | Test, Stummschalten |

## Variablen und Profile

### Station-Variablen

| Ident | Name | Typ | Profil |
|-------|------|-----|--------|
| `wifiRSSI` | WLAN RSSI | Integer | ~SignalStrength |
| `ssid` | WLAN SSID | String | - |
| `sw` | Firmware | String | - |
| `wifi_sw` | WLAN Firmware | String | - |
| `ip` | IP-Adresse | String | - |
| `alarmVol` | Alarm-Lautstärke | Integer | ~Intensity.100 |
| `voiceVol` | Sprach-Lautstärke | Integer | ~Intensity.100 |
| `alarm` | Alarm aktiv | Boolean | ~Alert |
| `coPpm` | CO (ppm) | Float | - |
| `temperature` | Temperatur | Float | ~Temperature |
| `humidity` | Feuchtigkeit | Float | ~Humidity.F |
| `batInfo` | Batterie | Float | ~Battery.100 |
| `rfLevel` | Funkpegel | Integer | XSense.RFLevel |

### Benutzerdefinierte Profile

| Profil | Beschreibung |
|--------|--------------|
| `XSense.RFLevel` | Funkpegel (0=kein Signal, 1=schwach, 2=mittel, 3=gut) |

## Aktionen

Aktionen werden als Boolean-Variablen mit Custom Action angelegt:

- **TEST**: Führt einen Selbsttest des Melders durch
- **MUTE**: Schaltet einen aktiven Alarm stumm
- **FIREDRILL**: Löst eine Feueralarm-Übung aus (nur XP0A-MR)

## Webhook-Alarme

Das Modul unterstützt HTTP-Webhooks für Echtzeit-Alarme.

### Webhook einrichten

```php
// Webhook-URL konfigurieren
XSENSE_SetWebhookURL(12345, 'https://example.com/alarm-webhook');

// Webhook aktivieren
XSENSE_EnableWebhook(12345, true);
```

### Webhook-Payload

```json
{
    "event": "alarm",
    "station": "ABC123456",
    "device": "XS01-M",
    "type": "smoke",
    "timestamp": "2024-01-15T10:30:00Z",
    "data": {
        "alarm": true,
        "coPpm": 0
    }
}
```

## PHP-Befehlsreferenz

### XSENSE_Update

Führt eine manuelle Synchronisation durch.

```php
XSENSE_Update(int $InstanceID): void
```

### XSENSE_AttemptReconnect

Versucht die MQTT-Verbindung wiederherzustellen.

```php
XSENSE_AttemptReconnect(int $InstanceID): void
```

### XSENSE_SetWebhookURL

Setzt die Webhook-URL für Alarm-Benachrichtigungen.

```php
XSENSE_SetWebhookURL(int $InstanceID, string $URL): void
```

### XSENSE_EnableWebhook

Aktiviert oder deaktiviert den Webhook.

```php
XSENSE_EnableWebhook(int $InstanceID, bool $Enable): void
```

### XSENSE_TriggerTest

Löst einen Selbsttest für eine Station aus.

```php
XSENSE_TriggerTest(int $InstanceID, string $StationSN): bool
```

### XSENSE_MuteAlarm

Schaltet einen aktiven Alarm stumm.

```php
XSENSE_MuteAlarm(int $InstanceID, string $StationSN): bool
```

### XSENSE_GetInventory

Gibt das aktuelle Inventar als JSON zurück.

```php
XSENSE_GetInventory(int $InstanceID): string
```

### XSENSE_GetStations

Gibt eine Liste aller Stationen zurück.

```php
XSENSE_GetStations(int $InstanceID): array
```

## XSense Device Befehle

### XSENSEDEV_RequestUpdate

Fordert ein Update vom Gateway an.

```php
XSENSEDEV_RequestUpdate(int $InstanceID): void
```

### XSENSEDEV_TriggerTest

Löst einen Selbsttest für das Gerät aus.

```php
XSENSEDEV_TriggerTest(int $InstanceID): bool
```

### XSENSEDEV_MuteAlarm

Schaltet einen aktiven Alarm stumm.

```php
XSENSEDEV_MuteAlarm(int $InstanceID): bool
```

### XSENSEDEV_GetStatus

Gibt den aktuellen Gerätestatus zurück.

```php
XSENSEDEV_GetStatus(int $InstanceID): array
```

## Fehlerbehebung

### Login schlägt fehl

- Prüfen Sie E-Mail und Passwort
- Stellen Sie sicher, dass die GMP PHP-Extension installiert ist
- Prüfen Sie die Internetverbindung

### MQTT verbindet nicht

- Prüfen Sie die MQTT Client Instanz
- Klicken Sie auf "MQTT neu verbinden"
- Prüfen Sie die Firewall-Einstellungen

### Keine Sensordaten

- Warten Sie auf das nächste Polling-Intervall
- Führen Sie eine manuelle Synchronisation durch
- Prüfen Sie ob die Station in der Stationenauswahl aktiviert ist

## Changelog

### 1.0.0

- Initiale Version
- Cloud-Authentifizierung mit AWS Cognito SRP
- MQTT-Echtzeit-Updates
- Unterstützung für alle gängigen X-Sense Geräte
- Aktionen (Test, Mute, FireDrill)
- Webhook-Support für Alarme
- Diagnose-Variablen

## Lizenz

MIT License - siehe [LICENSE](LICENSE)

## Credits

Basiert auf der Analyse der [Home Assistant X-Sense Integration](https://github.com/Jarnsen/ha-xsense-component_test).
