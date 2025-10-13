<?php

declare(strict_types=1);

namespace XSense\Gateway;

final class EntityDefinitions
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function definitions(): array
    {
        return [
            'SC06-WX' => [
                'actions' => [
                    self::testAction(),
                ],
            ],
            'SC07-WX' => [
                'actions' => [
                    self::muteAction('1', '2nd_appmute'),
                ],
            ],
            'STH0A' => [
                'actions' => [
                    self::testAction('thSelfTest'),
                    self::muteAction('1', 'extendMute'),
                ],
            ],
            'STH51' => [
                'actions' => [
                    self::testAction('thSelfTest'),
                    self::muteAction('1', 'extendMute'),
                ],
            ],
            'SWS51' => [
                'actions' => [
                    self::testAction('waterSelfTest'),
                    self::muteAction('appWater', '2nd_appwater', ['silencetime' => '', 'setType' => '0']),
                ],
            ],
            'XC01-M' => [
                'actions' => [
                    self::testAction('appCoSelfTest'),
                    self::muteAction('1', 'appCoMute'),
                ],
            ],
            'XC04-WX' => [
                'actions' => [
                    self::muteAction('1', '2nd_appmute'),
                ],
            ],
            'XP0A-MR' => [
                'actions' => [
                    self::testAction('app2ndSelfTest'),
                    [
                        'action' => 'firedrill',
                        'topic' => '2nd_firedrill',
                        'shadow' => 'appFireDrill',
                    ],
                ],
            ],
            'XP02S-MR' => [
                'actions' => [
                    self::testAction('app2ndSelfTest'),
                ],
            ],
            'XS01-M' => [
                'actions' => [
                    self::testAction(),
                    self::muteAction(),
                ],
            ],
            'XS01-WX' => [
                'actions' => [
                    self::testAction(),
                ],
            ],
        ];
    }

    private static function testAction(string $shadow = 'appSelfTest'): array
    {
        return [
            'action' => 'test',
            'shadow' => $shadow,
            'topic' => static fn(array $device): string => '2nd_selftest_' . ($device['sn'] ?? ''),
        ];
    }

    private static function muteAction(string $shadow = 'appMute', string $topic = '2nd_appmute', array $extra = []): array
    {
        return [
            'action' => 'mute',
            'shadow' => $shadow,
            'topic' => $topic,
            'extra' => $extra,
        ];
    }
}
