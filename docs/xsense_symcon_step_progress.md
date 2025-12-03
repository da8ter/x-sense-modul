# Umsetzung der "Nächsten Schritte" für das Symcon-Modul

## Schritt 1 – Reverse Engineering der REST- und MQTT-Signaturen

* Die relevanten Cloud-Aufrufe (`bizCode` 101001, 101003, 102007, 103007) sowie die AWS-IoT-Schattenendpunkte wurden aus der asynchronen Python-Implementierung extrahiert. Die PHP-Portierung nutzt dieselben Parameter (ClientType, AppCode, MAC-Hash) und repliziert die AWS-Signaturlogik aus dem Helper `AWSSigner`.【F:references/python-xsense-0.0.16/xsense/async_xsense.py†L18-L120】【F:references/python-xsense-0.0.16/xsense/aws_signer.py†L12-L120】

## Schritt 2 – PHP-Prototyp `XSenseCloudClient`
* Der Prototyp kapselt Login, Token-Refresh, AWS-Credentials und Geräteabruf inklusive Shadow-Abfragen. Er speichert Sessions als Modul-Attribute und stellt `syncInventory()` für den Modul-Timer bereit.【F:symcon_module/XSenseGateway/XSenseCloudClient.php†L1-L206】

## Schritt 3 – Timer- und Variablen-Logik im Modul
* `module.php` initialisiert Properties, Timer und Attribute, ruft den Cloud-Client zyklisch auf und legt Kategorien/Variablen für Häuser und Stationen an. Die Konfigurationsmaske erlaubt Intervall- und MQTT-Auswahl.【F:symcon_module/XSenseGateway/module.php†L1-L170】

## Schritt 4 – MQTT-Anbindung und Subscription-Management
* Das Modul verbindet sich mit einer verknüpften `MQTTClient`-Instanz, erzeugt Topic-Subscriptions pro Haus und verarbeitet eingehende Daten über `ReceiveData`/`HandleMqttPayload` (Platzhalter für weitere Verarbeitung).【F:symcon_module/XSenseGateway/module.php†L96-L155】【F:symcon_module/XSenseGateway/module.php†L171-L230】

## Schritt 5 – Aktionen & Edge Cases
* Eine Action-Map in `EntityDefinitions.php` bildet zentrale Geräteaktionen (Test, Mute, FireDrill) ab. `EnsureActionButtons` und `ExecuteDeviceAction` erzeugen Symcon-Schalter und leiten Aufrufe an den Cloud-Client weiter (Hook für spätere `triggerAction`-Implementierung).【F:symcon_module/XSenseGateway/EntityDefinitions.php†L1-L78】【F:symcon_module/XSenseGateway/module.php†L231-L293】

## Schritt 6 – Dokumentation & Testleitfaden
* Die Datei enthält alle Zwischenergebnisse; ergänzend beschreibt Abschnitt 6 der ursprünglichen Analyse nun auch den Dokumentations-/Testschritt. Empfohlene Tests: erfolgreicher Login (Session-Cache prüfen), Inventarsync (Variablenstruktur), MQTT-Echtzeit (Payload-Log beobachten), Aktionen (Button triggert Debug-Ausgabe). Diese Tests decken die bereitgestellten Platzhalter ab und markieren offene Arbeiten (`triggerAction`).【F:docs/xsense_integration_analysis.md†L94-L97】【F:symcon_module/XSenseGateway/module.php†L70-L115】

