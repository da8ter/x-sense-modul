<?php

declare(strict_types=1);

namespace XSense\Gateway;

final class SensorDefinitions
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function stationSensors(): array
    {
        return [
            self::wifiRssi(),
            self::wifiSsid(),
            self::swVersion(),
            self::wifiFirmware(),
            self::ipAddress(),
            self::alarmVolume(),
            self::voiceVolume(),
            self::alarmFlag(),
            self::coPpm(),
            self::temperature(),
            self::humidity(),
            self::battery(),
            self::rfLevel(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function deviceSensors(): array
    {
        return [
            self::temperature(),
            self::humidity(),
            self::coPpm(),
            self::battery(),
            self::rfLevel(),
        ];
    }

    private static function wifiRssi(): array
    {
        return self::definition('wifiRSSI', 'WLAN RSSI', \VARIABLETYPE_INTEGER, '~SignalStrength', 'diagnostic');
    }

    private static function wifiSsid(): array
    {
        return self::definition('ssid', 'WLAN SSID', \VARIABLETYPE_STRING, '', 'diagnostic');
    }

    private static function swVersion(): array
    {
        return self::definition('sw', 'Firmware', \VARIABLETYPE_STRING, '', 'diagnostic');
    }

    private static function wifiFirmware(): array
    {
        return self::definition('wifi_sw', 'WLAN Firmware', \VARIABLETYPE_STRING, '', 'diagnostic');
    }

    private static function ipAddress(): array
    {
        return self::definition('ip', 'IP-Adresse', \VARIABLETYPE_STRING, '', 'diagnostic');
    }

    private static function alarmVolume(): array
    {
        return self::definition('alarmVol', 'Alarm-Lautstärke', \VARIABLETYPE_INTEGER, '~Intensity.100', 'core');
    }

    private static function voiceVolume(): array
    {
        return self::definition('voiceVol', 'Sprach-Lautstärke', \VARIABLETYPE_INTEGER, '~Intensity.100', 'core');
    }

    private static function alarmFlag(): array
    {
        return self::definition('alarm', 'Alarm aktiv', \VARIABLETYPE_BOOLEAN, '~Alert', 'core', static fn($value): bool => (bool) $value);
    }

    private static function coPpm(): array
    {
        return self::definition('coPpm', 'CO (ppm)', \VARIABLETYPE_FLOAT, '', 'environment');
    }

    private static function temperature(): array
    {
        return self::definition('temperature', 'Temperatur', \VARIABLETYPE_FLOAT, '~Temperature', 'environment');
    }

    private static function humidity(): array
    {
        return self::definition('humidity', 'Feuchtigkeit', \VARIABLETYPE_FLOAT, '~Humidity.F', 'environment');
    }

    private static function battery(): array
    {
        return self::definition('batInfo', 'Batterie', \VARIABLETYPE_FLOAT, '~Battery.100', 'core', static function ($value): float {
            if (!is_numeric($value)) {
                return 0.0;
            }
            return round(((float) $value) * 100 / 3, 1);
        });
    }

    private static function rfLevel(): array
    {
        return self::definition('rfLevel', 'Funkpegel', \VARIABLETYPE_INTEGER, 'XSense.RFLevel', 'diagnostic', static function ($value): int {
            return is_numeric($value) ? max(0, min(3, (int) $value)) : 0;
        });
    }

    private static function definition(string $key, string $label, int $type, string $profile, string $group, ?callable $formatter = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'profile' => $profile,
            'group' => $group,
            'formatter' => $formatter,
        ];
    }
}
