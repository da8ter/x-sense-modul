<?php

declare(strict_types=1);

require_once __DIR__ . '/../symcon_module/XSenseGateway/EntityDefinitions.php';
require_once __DIR__ . '/../symcon_module/XSenseGateway/XSenseCloudClient.php';

if (!class_exists('IPSModule')) {
    class IPSModule
    {
        public function __construct(int $InstanceID = 0)
        {
        }

        public function SendDebug(string $ident, string $message, int $format): void
        {
        }
    }
}

final class TestModule extends IPSModule
{
}

final class TestClient extends XSense\Gateway\XSenseCloudClient
{
    public array $lastPayload = [];

    protected function postStationShadow(array $house, array $station, string $page, array $payload): array
    {
        $this->lastPayload = [
            'house' => $house,
            'station' => $station,
            'page' => $page,
            'payload' => $payload,
        ];
        return ['state' => ['desired' => $payload['state']['desired']]];
    }
}

function validateDefinitions(): void
{
    $definitions = XSense\Gateway\EntityDefinitions::definitions();
    foreach ($definitions as $type => $config) {
        if (!isset($config['actions']) || !is_array($config['actions'])) {
            continue;
        }
        foreach ($config['actions'] as $index => $action) {
            foreach (['action', 'shadow', 'topic'] as $required) {
                if (!array_key_exists($required, $action)) {
                    throw new RuntimeException(sprintf('Action %s[%d] missing key %s', $type, $index, $required));
                }
            }
        }
    }
}

function validateSensorRequest(): void
{
    $module = new TestModule();
    $client = new TestClient($module);
    $refUsername = new ReflectionProperty(XSense\Gateway\XSenseCloudClient::class, 'username');
    $refUsername->setAccessible(true);
    $refUsername->setValue($client, 'tester@example.com');
    $refAwsExpiry = new ReflectionProperty(XSense\Gateway\XSenseCloudClient::class, 'awsExpiry');
    $refAwsExpiry->setAccessible(true);
    $refAwsExpiry->setValue($client, new DateTimeImmutable('+1 hour'));
    $refAwsToken = new ReflectionProperty(XSense\Gateway\XSenseCloudClient::class, 'awsSessionToken');
    $refAwsToken->setAccessible(true);
    $refAwsToken->setValue($client, 'token');

    $house = ['definition' => ['houseId' => '123', 'mqttRegion' => 'us-east-1']];
    $station = ['definition' => ['stationSN' => 'ABC123', 'stationType' => 'STH51']];
    $client->requestSensorReport($house, $station, ['devA', 'devA', 'devB'], ['timeoutM' => '5']);

    if ($client->lastPayload === []) {
        throw new RuntimeException('Sensor report payload was not captured');
    }
    if ($client->lastPayload['page'] !== '2nd_apptempdata') {
        throw new RuntimeException('Unexpected shadow page for sensor report');
    }
    $desired = $client->lastPayload['payload']['state']['desired'];
    if ($desired['shadow'] !== 'appTempData') {
        throw new RuntimeException('Unexpected shadow key in desired payload');
    }
    if ($desired['deviceSN'] !== ['devA', 'devB']) {
        throw new RuntimeException('Device serials were not normalised');
    }
    if ($desired['timeoutM'] !== '5') {
        throw new RuntimeException('Timeout was not propagated');
    }
}

try {
    validateDefinitions();
    validateSensorRequest();
    echo "XSense gateway smoke test passed." . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Smoke test failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
