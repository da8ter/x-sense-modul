# X-Sense Home Assistant Integration – Funktionsweise und Portierung nach Symcon

## 1. Architektur der Home-Assistant-Integration

### 1.1 Manifest, Abhängigkeiten und Domain
Das Custom-Component-Manifest registriert die Domain `xsense`, deklariert die Integration als Cloud-Hub und bindet die Python-Bibliothek `python-xsense` sowie einen dedizierten MQTT-Client als Abhängigkeiten ein.【F:references/ha-xsense-component_test/custom_components/xsense/manifest.json†L1-L16】

Der Konstantenblock legt den Herstellernamen, die Standard-Polling-Intervalle sowie Hilfsstrukturen wie die Funkfeldstärke-Werte fest. Diese Konstanten werden quer durch die Integration (Koordinator, Entitäten) wiederverwendet.【F:references/ha-xsense-component_test/custom_components/xsense/const.py†L1-L19】

### 1.2 Einrichtung & Authentifizierung
Der `config_flow` führt einen UI-basierten Login mit E-Mail und Passwort durch. Er validiert die Zugangsdaten über die asynchrone X-Sense API, setzt die E-Mail-Adresse als eindeutige ID des Config Entries und implementiert einen Reauth-Flow für erneute Anmeldungen bei Token-Problemen.【F:references/ha-xsense-component_test/custom_components/xsense/config_flow.py†L1-L140】

### 1.3 Initialisierung und Plattform-Setup
Beim Laden des Config Entries erzeugt die Integration einen `XSenseDataUpdateCoordinator`, führt unmittelbar den ersten Refresh aus und richtet anschließend die Plattformen für Binary-Sensoren, Buttons und Sensoren ein. Ein Update-Listener sorgt dafür, dass Konfigurationsänderungen einen Reload auslösen.【F:references/ha-xsense-component_test/custom_components/xsense/__init__.py†L1-L39】

## 2. Datenbeschaffung über den DataUpdateCoordinator

### 2.1 Session-Handling und Periodische Updates
Der Koordinator verwaltet die asynchrone Client-Session, verbindet sich bei Bedarf neu und ruft zyklisch die Gerätedaten ab. Dabei lädt er Häuser, Stationen und Geräte aus der Cloud, verarbeitet Session-Verluste und propagiert API-Fehler als `UpdateFailed` an Home Assistant.【F:references/ha-xsense-component_test/custom_components/xsense/coordinator.py†L24-L153】

### 2.2 MQTT-Kopplung und Live-Daten
Zusätzlich zur Polling-Logik baut der Koordinator für jedes Haus eine MQTT-Verbindung über den Helfer `XSenseMQTT` auf, sichert Subscriptions auf die für Geräte relevanten Topics und triggert Bedarfspolls für Echtzeit-Sensoren (z. B. Temperatur). Eingehende MQTT-Payloads werden dem X-Sense Gerätebaum zugeordnet, so dass Entitäten ihre Werte direkt aktualisieren können.【F:references/ha-xsense-component_test/custom_components/xsense/coordinator.py†L155-L250】 Die MQTT-Klasse kapselt die Paho-Logik, verwaltet asynchrone Verbindungen über WebSockets, Debouncing von Subscribe/Unsubscribe-Aufrufen und automatische Reconnect-Loops.【F:references/ha-xsense-component_test/custom_components/xsense/mqtt.py†L1-L360】

## 3. Entitätsmodell und bereitgestellte Funktionen

### 3.1 Gemeinsame Basis-Klasse
Alle Entitäten erben von `XSenseEntity`, die den Coordinator-Status, eindeutige IDs, Geräteinformationen (inkl. MAC-Verknüpfungen) und Verfügbarkeitslogik kapselt. Geräte, die über eine Station eingebunden sind, werden zusätzlich über `ATTR_VIA_DEVICE` hierarchisch verknüpft.【F:references/ha-xsense-component_test/custom_components/xsense/entity.py†L1-L72】

### 3.2 Binary Sensoren
Die Binary-Sensor-Plattform stellt Diagnose- und Sicherheitszustände bereit (Lebensdauer-Ende, Alarm, Stummschaltung, Aktivierung, Türkontakte). Für jede Station existiert ein zusätzlicher MQTT-Verbindungsstatussensor, der den Verbindungszustand der House-spezifischen MQTT-Sitzung überwacht.【F:references/ha-xsense-component_test/custom_components/xsense/binary_sensor.py†L1-L154】

### 3.3 Standard-Sensoren
Die Sensor-Plattform deckt Diagnosewerte (Signalstärke, Firmware, IP), Umweltdaten (CO-ppm, Temperatur, Feuchte) und Batteriestatus ab. Einige Werte werden in menschenlesbare Enumerationen umgerechnet (z. B. Funk-Signalstärke).【F:references/ha-xsense-component_test/custom_components/xsense/sensor.py†L1-L212】

### 3.4 Buttons / Aktionen
Buttons prüfen über die X-Sense API, ob ein Gerät bestimmte Aktionen unterstützt (z. B. Selbsttest) und stellen diese als Home-Assistant-Service bereit. Beim Auslösen wird der passende API-Befehl über den koordinierten Client gesendet.【F:references/ha-xsense-component_test/custom_components/xsense/button.py†L1-L111】

## 4. Funktionale Anforderungen für eine Symcon-Portierung

Aus der Analyse ergeben sich folgende Kernerfordernisse:

1. **Authentifizierung & Token-Management** – Login über E-Mail/Passwort, sichere Speicherung der Sessiondaten und automatisches Refresh bei Ablauf analog zur Home-Assistant-Implementierung.【F:references/ha-xsense-component_test/custom_components/xsense/coordinator.py†L46-L75】
2. **Geräteinventar & Hierarchie** – Abbildung von Häusern, Stationen und Geräten inklusive Typ- und Seriennummerninformation sowie Zuordnung von Stations-Parent-Geräten.【F:references/ha-xsense-component_test/custom_components/xsense/coordinator.py†L126-L178】【F:references/ha-xsense-component_test/custom_components/xsense/entity.py†L41-L58】
3. **Messwerte & Zustände** – Erfassen und Aktualisieren der in HA bereitgestellten Sensorwerte inklusive Batterie- und Signaldiagnose.【F:references/ha-xsense-component_test/custom_components/xsense/sensor.py†L42-L154】
4. **Alarm- und Statuszustände** – Implementierung von Binary-Sensoren inkl. MQTT-Verbindungsüberwachung.【F:references/ha-xsense-component_test/custom_components/xsense/binary_sensor.py†L35-L154】
5. **Steueraktionen** – Bereitstellung von Buttons für Geräteselbsttests (und perspektivisch weitere Actions wie Stummschaltung).【F:references/ha-xsense-component_test/custom_components/xsense/button.py†L36-L111】
6. **Echtzeit-Updates** – MQTT-Kanal für Ereignisse und Sensordaten inklusive Subscription-Management und Reconnect-Logik.【F:references/ha-xsense-component_test/custom_components/xsense/coordinator.py†L155-L250】【F:references/ha-xsense-component_test/custom_components/xsense/mqtt.py†L260-L344】

## 5. Umsetzungskonzept für ein Symcon PHP Modul

### 5.1 Modul- und Instanzstruktur
- **Bibliothek & Moduldefinition**: `library.json` und `module.json` registrieren eine Bibliothek „XSense“ mit einem Hauptmodul `XSenseGateway` (Typ *Splitter*), das Benutzerkonten/Stationen verwaltet.
- **Child-Instanzen**: Für Stationen und Endgeräte können optionale Geräte-Module (`XSenseStation`, `XSenseDevice`) vorgesehen werden, die Variablen strukturieren und individuelle Aktionen kapseln. Alternativ können Variablen komplett im Gateway-Modul erzeugt werden, solange die Anzahl der Geräte überschaubar bleibt.
- **Abhängigkeiten**: Verwendung vorhandener Symcon-I/O-Module (z. B. `MQTTClient`) als Parent-Verbindung für Echtzeitdaten. Das Gateway hält Referenzen auf die jeweiligen MQTT-Client-Instanzen pro Haus (ähnlich zur `mqtt_servers`-Map im Koordinator).【F:references/ha-xsense-component_test/custom_components/xsense/coordinator.py†L39-L74】

### 5.2 Konfigurationsformular (`GetConfigurationForm`)
- Eingabefelder für E-Mail, Passwort, optionale Region/MQTT-Override sowie Polling-Intervall.
- Auswahlfelder für zu erzeugende Entitätstypen (Umweltmesswerte, Diagnose, Aktionen) analog zu den HA-Beschreibungen.【F:references/ha-xsense-component_test/custom_components/xsense/binary_sensor.py†L35-L110】【F:references/ha-xsense-component_test/custom_components/xsense/sensor.py†L42-L154】
- Abschnitt zur Zuordnung bestehender Symcon-MQTT-Clients oder Option zum automatischen Anlegen einer untergeordneten `MQTTClient`-Instanz pro Haus.

### 5.3 PHP-Client für die X-Sense Cloud
- Implementierung einer Klasse `XSenseCloudClient` (z. B. `XSenseCloud.php`) auf Basis der Endpunkte, die das Python-Paket nutzt (`bizCode`-basierte POST-Calls, AWS-IoT-Signing). Die Klasse kapselt Login, Token-Refresh, Laden der Häuser/Stationen/Geräte sowie Actions (`set_state`, `action`).
- Verwendung von `IPS_HttpRequest` oder cURL (PHP `curl`-Extension) für REST-Aufrufe; Token und Ablaufzeiten werden als Modul-Attribute gespeichert (`RegisterAttributeString`).
- Mapping der Polling-Methoden (`load_all`, `get_house_state`, `get_station_state`, `get_state`) auf PHP-Methoden, inklusive Fehlerbehandlung für Session-Expiration (analog `SessionExpired`).

### 5.4 Lebenszyklus und Polling
- `Create`: Registrierung der Properties (E-Mail, Passwort, Polling-Intervall, Aktivierte Entitätstypen) sowie eines Timers `XSenseUpdate` (`RegisterTimer`).
- `ApplyChanges`: Validierung der Konfiguration, Authentifizierung gegen die Cloud, Starten des Polling-Timers und initialer Datenabruf (entspricht `async_config_entry_first_refresh`).【F:references/ha-xsense-component_test/custom_components/xsense/__init__.py†L15-L26】
- `XSENSE_Update`: Methode, die `XSenseCloudClient` aufruft, um Geräte-/Stationsdaten zu aktualisieren, Variablen zu setzen und MQTT-Subscriptions sicherzustellen. Fehlerzustände werden via `SetStatus` und Debug-Log (`SendDebug`) gemeldet.

### 5.5 Variablen- und Profilmanagement
- Pro Gerät Variablen für Sensorwerte/Binary-Status gemäß den HA-Entitätsbeschreibungen. Verwendung von Standard-Profilen (`~Temperature`, `~Humidity`, `~Alert`, `~Battery.100`) oder Erstellung kundenspezifischer Profile für Enumerationen (z. B. Funkfeldstärke `STATE_SIGNAL`).
- `MaintainVariable`/`MaintainAction` dienen zur Anlage bzw. Freigabe der Variablen im Modul.
- Hierarchische Abbildung: Stationen als Kategorien oder Geräte-Instanzen, darunter Variablen für Endgeräte. Eltern-Kind-Relationen spiegeln die `ATTR_VIA_DEVICE`-Logik wider.【F:references/ha-xsense-component_test/custom_components/xsense/entity.py†L41-L58】

### 5.6 Aktionen & RequestAction
- `RequestAction` verarbeitet Button-Befehle (z. B. `TEST`), ruft `XSenseCloudClient->Action()` auf und aktualisiert nach erfolgreichem Aufruf den Status. Damit wird das Verhalten der HA-Buttons nachgebildet.【F:references/ha-xsense-component_test/custom_components/xsense/button.py†L36-L111】
- Erweiterbar für zusätzliche Actions wie Stummschaltung, sobald im Python-Modul aktiviert.

### 5.7 MQTT-Einbindung

- Aufbau einer WebSocket-basierten Verbindung zum X-Sense MQTT-Server über Symcon-Mittel (entweder `MQTTClient` mit WebSocket-URL oder eigenes PHP-WebSocket-Handling mittels `MQTTClient Socket`). Vor jeder Verbindung muss – analog zum Python-Helper – eine signierte URL erzeugt werden. Die Signierlogik lässt sich aus `xsense.mqtt_helper.MQTTHelper` ableiten (AWS SigV4, Pfaderzeugung).
- Subscription-Management: Listen der erforderlichen Topics (Events, Shadow-Updates) pro Haus entsprechen `assure_subscriptions` aus dem Koordinator.【F:references/ha-xsense-component_test/custom_components/xsense/coordinator.py†L180-L214】 Verwaltung offener Subscriptions analog zum Python-Debouncer (z. B. mit internen Arrays und zeitlich verzögerter Verarbeitung).
- Empfangene MQTT-Payloads werden in `MessageSink` oder einem dedizierten Callback verarbeitet, dem passenden Station/Device zugeordnet und lösen Variable-Updates aus – vergleichbar mit `async_event_received`.【F:references/ha-xsense-component_test/custom_components/xsense/coordinator.py†L165-L178】

### 5.8 Fehlerbehandlung & Reconnect
- Implementierung eines Reconnect-Timers für MQTT analog zu `_reconnect_loop`, falls Verbindungen verloren gehen.【F:references/ha-xsense-component_test/custom_components/xsense/mqtt.py†L260-L344】
- API-Fehler, Auth-Fehler und Netzwerkprobleme setzen den Modulstatus (`SetStatus`) auf definierte Fehlercodes; Logausgaben helfen beim Debugging.

### 5.9 Tests & Qualitätssicherung
- Unit-Tests des PHP-Clients (z. B. via PHPUnit oder Symcon Testumgebung) mit Mock-Responses der Cloud.
- Integrationstests im Symcon-Testsystem: Login, Geräteabruf, MQTT-Subscription, Auslösung einer Testaktion.
- Dokumentation für Anwender (README, Variablenliste, Voraussetzungen wie aktiviertes OpenSSL für WebSockets).

## 6. Nächste Schritte
1. **Reverse Engineering der REST- und MQTT-Signaturen** anhand des `python-xsense` Pakets zur PHP-Portierung.
2. **Prototyp des `XSenseCloudClient`** in PHP mit Login und Geräteabruf.
3. **Timer- und Variablen-Logik** im Symcon-Modul implementieren und mit Testkonto verifizieren.
4. **MQTT-Anbindung** und Echtzeit-Updates integrieren.
5. **Aktionen & Edge Cases** (z. B. Session-Timeouts, Offline-Geräte) abdecken.
