<?php

declare(strict_types=1);

namespace XSense\Gateway;

use DateInterval;
use DateTimeImmutable;

use Exception;
use IPSModule;

final class XSenseCloudClient
{
    private const API_URL = 'https://api.x-sense-iot.com/app';

    private string $username = '';
    private ?string $accessToken = null;
    private ?string $refreshToken = null;
    private ?DateTimeImmutable $accessTokenExpiry = null;

    private ?string $clientId = null;
    private ?string $clientSecret = null;
    private ?string $userPoolId = null;
    private ?string $region = null;

    private ?string $awsAccessKey = null;
    private ?string $awsSecretKey = null;
    private ?string $awsSessionToken = null;
    private ?DateTimeImmutable $awsExpiry = null;

    private ?AWSSigner $signer = null;

    /** @var array<string,mixed> */
    private array $houses = [];

    public function __construct(private IPSModule $module)
    {
    }

    public function restoreSession(array $session): void
    {
        $this->username = $session['username'] ?? '';
        $this->accessToken = $session['accessToken'] ?? null;
        $this->refreshToken = $session['refreshToken'] ?? null;
        $this->clientId = $session['clientId'] ?? null;
        $this->clientSecret = isset($session['clientSecret']) ? base64_decode((string) $session['clientSecret']) : null;
        $this->userPoolId = $session['userPoolId'] ?? null;
        $this->region = $session['region'] ?? null;

        $this->awsAccessKey = $session['awsAccessKey'] ?? null;
        $this->awsSecretKey = $session['awsSecretKey'] ?? null;
        $this->awsSessionToken = $session['awsSessionToken'] ?? null;
        $this->awsExpiry = isset($session['awsExpiry']) ? new DateTimeImmutable($session['awsExpiry']) : null;
        if ($this->clientId && $this->clientSecret && $this->awsSessionToken) {
            $this->signer = new AWSSigner($this->awsAccessKey ?? '', $this->awsSecretKey ?? '', $this->awsSessionToken);
        }
        if (isset($session['accessTokenExpiry'])) {
            $this->accessTokenExpiry = new DateTimeImmutable($session['accessTokenExpiry']);
        }
    }

    public function exportSession(): array
    {
        return [
            'username' => $this->username,
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret ? base64_encode($this->clientSecret) : null,
            'userPoolId' => $this->userPoolId,
            'region' => $this->region,
            'awsAccessKey' => $this->awsAccessKey,
            'awsSecretKey' => $this->awsSecretKey,
            'awsSessionToken' => $this->awsSessionToken,
            'awsExpiry' => $this->awsExpiry?->format(DateTimeImmutable::ATOM),
            'accessTokenExpiry' => $this->accessTokenExpiry?->format(DateTimeImmutable::ATOM),

        ];
    }

    public function login(string $username, string $password): void
    {
        $this->username = $username;
        $this->module->SendDebug('XSenseCloudClient', 'Fetching client bootstrap', 0);
        $bootstrap = $this->apiCall('101001', [], true);
        $this->clientId = $bootstrap['clientId'];
        $this->clientSecret = $this->decodeSecret($bootstrap['clientSecret']);
        $this->region = $bootstrap['cgtRegion'];
        $this->userPoolId = $bootstrap['userPoolId'];

        $this->module->SendDebug('XSenseCloudClient', 'Performing Cognito SRP login', 0);
        $auth = $this->performSrpLogin($username, $password);
        $this->accessToken = $auth['AccessToken'];
        $this->refreshToken = $auth['RefreshToken'];
        $this->accessTokenExpiry = (new DateTimeImmutable('now'))->add(new DateInterval('PT' . $auth['ExpiresIn'] . 'S'));
        $this->userId = $auth['UserId'] ?? null;


        $this->module->SendDebug('XSenseCloudClient', 'Loading AWS IoT credentials', 0);
        $this->refreshAwsCredentials();
    }

    public function refreshSession(): void
    {
        if ($this->shouldRefreshAccessToken() && $this->refreshToken !== null) {
            $this->module->SendDebug('XSenseCloudClient', 'Refreshing Cognito session', 0);
            $response = $this->invokeJson('https://cognito-idp.' . $this->region . '.amazonaws.com', [
                'AuthFlow' => 'REFRESH_TOKEN_AUTH',
                'AuthParameters' => array_filter([
                    'REFRESH_TOKEN' => $this->refreshToken,
                    'SECRET_HASH' => $this->clientSecret !== null ? $this->calculateSecretHash($this->username) : null,
                ]),

                'AuthParameters' => [
                    'REFRESH_TOKEN' => $this->refreshToken,
                    'SECRET_HASH' => base64_encode(hash_hmac('sha256', $this->username . $this->clientId, $this->clientSecret, true)),
                ],

                'ClientId' => $this->clientId,
                'UserContextData' => new \stdClass(),
            ], [
                'Content-Type: application/x-amz-json-1.1',
                'X-Amz-Target: AWSCognitoIdentityProviderService.InitiateAuth',
            ]);
            $result = $response['AuthenticationResult'];
            $this->accessToken = $result['AccessToken'];
            $this->accessTokenExpiry = (new DateTimeImmutable('now'))->add(new DateInterval('PT' . $result['ExpiresIn'] . 'S'));
            if (isset($result['RefreshToken'])) {
                $this->refreshToken = $result['RefreshToken'];
            }
        }

        if ($this->shouldRefreshAws()) {
            $this->module->SendDebug('XSenseCloudClient', 'Refreshing AWS credentials', 0);
            $this->refreshAwsCredentials();
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function syncInventory(): array
    {
        $this->refreshSession();
        $houses = $this->apiCall('102007', ['utctimestamp' => '0']);
        $result = [];
        foreach ($houses as $house) {
            if (!isset($house['houseId'])) {
                continue;
            }
            $houseId = (string) $house['houseId'];
            $primary = $this->fetchHouseShadow($house, 'mainpage');
            $secondary = $this->fetchHouseShadow($house, '2nd_mainpage', true);
            $stations = $this->apiCall('103007', ['houseId' => $houseId, 'utctimestamp' => '0']);
            $stationMap = [];
            foreach ($stations as $station) {
                $stationSn = $this->extractStationSn($station);
                if ($stationSn === null) {
                    continue;
                }
                $stationMap[$stationSn] = [
                    'stationSn' => $stationSn,
                    'definition' => $station,
                    'shadows' => $this->fetchStationShadows($house, $station),
                ];
            }
            $result[$houseId] = [
                'definition' => $house,
                'state' => array_filter([
                    'mainpage' => $primary,
                    'secondary' => $secondary,
                ]),
                'stations' => $stationMap,

            $houseId = $house['houseId'];
            $houseState = $this->fetchHouseShadow($houseId, 'mainpage');
            $stations = $this->apiCall('103007', ['houseId' => $houseId, 'utctimestamp' => '0']);
            $result[$houseId] = [
                'definition' => $house,
                'state' => $houseState,
                'stations' => $stations,

            ];
        }
        $this->houses = $result;
        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function getHouses(): array
    {
        return $this->houses;
    }

    /**
     * @param array<string,mixed> $house
     * @param array<string,mixed> $station
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    public function triggerAction(array $house, array $station, array $action): array
    {
        $this->refreshSession();
        $stationDefinition = $station['definition'] ?? $station;
        $stationSn = $this->extractStationSn($stationDefinition);
        if ($stationSn === null) {
            throw new Exception('Station serial number missing');
        }
        $shadow = $action['shadow'] ?? null;
        if (!is_string($shadow) || $shadow === '') {
            throw new Exception('Action shadow missing');
        }
        $topic = $action['topic'] ?? '';
        if (is_callable($topic)) {
            $topic = $topic($stationDefinition);
        }
        if (!is_string($topic) || $topic === '') {
            throw new Exception('Action topic missing');
        }

        $desired = [
            'deviceSN' => $stationSn,
            'shadow' => $shadow,
            'stationSN' => $stationSn,
            'time' => (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('YmdHis'),
            'userId' => $this->userId ?? $this->username,
        ];
        if (isset($action['extra']) && is_array($action['extra'])) {
            $desired = array_merge($desired, $action['extra']);
        }

        $payload = ['state' => ['desired' => $desired]];
        $houseDefinition = $house['definition'] ?? $house;
        $response = $this->postStationShadow($houseDefinition, $stationDefinition, $topic, $payload);
        if (!isset($response['state']['desired'])) {
            throw new Exception('Unexpected response while triggering action');
        }
        return $response;
    }

    /**
     * @param array<string,mixed> $house
     * @param array<string,mixed> $station
     * @param array<int,string> $deviceSerials
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function requestSensorReport(array $house, array $station, array $deviceSerials, array $options = []): array
    {
        if ($deviceSerials === []) {
            return [];
        }
        $this->refreshSession();
        $stationDefinition = $station['definition'] ?? $station;
        $stationSn = $this->extractStationSn($stationDefinition);
        if ($stationSn === null) {
            throw new Exception('Station serial number missing');
        }
        $shadow = (string) ($options['shadow'] ?? 'appTempData');
        $topic = (string) ($options['topic'] ?? '2nd_apptempdata');
        if ($topic === '') {
            throw new Exception('Sensor report topic missing');
        }
        $timeout = (string) ($options['timeoutM'] ?? '10');
        $desired = [
            'shadow' => $shadow,
            'deviceSN' => array_values(array_unique(array_map('strval', $deviceSerials))),
            'source' => (string) ($options['source'] ?? '1'),
            'report' => (string) ($options['report'] ?? '1'),
            'reportDst' => (string) ($options['reportDst'] ?? '1'),
            'timeoutM' => $timeout,
            'userId' => $this->userId ?? $this->username,
            'time' => (new DateTimeImmutable('now'))->format('YmdHis'),
            'stationSN' => $stationSn,
        ];
        if (isset($options['extra']) && is_array($options['extra'])) {
            $desired = array_merge($desired, $options['extra']);
        }
        $payload = ['state' => ['desired' => $desired]];
        $houseDefinition = $house['definition'] ?? $house;
        return $this->postStationShadow($houseDefinition, $stationDefinition, $topic, $payload);
    }

    private function shouldRefreshAccessToken(): bool
    {
        return $this->accessTokenExpiry === null || $this->accessTokenExpiry < (new DateTimeImmutable('now'))->add(new DateInterval('PT60S'));
    }

    private function shouldRefreshAws(): bool
    {
        return $this->awsExpiry === null || $this->awsExpiry < (new DateTimeImmutable('now'))->add(new DateInterval('PT60S'));
    }

    private function refreshAwsCredentials(): void
    {
        $credentials = $this->apiCall('101003', ['userName' => $this->username]);
        $this->awsAccessKey = $credentials['accessKeyId'];
        $this->awsSecretKey = $credentials['secretAccessKey'];
        $this->awsSessionToken = $credentials['sessionToken'];
        $this->awsExpiry = new DateTimeImmutable($credentials['expiration']);
        if ($this->awsAccessKey === null || $this->awsSecretKey === null || $this->awsSessionToken === null) {
            throw new Exception('Incomplete AWS credentials');
        }
        $this->signer = new AWSSigner($this->awsAccessKey, $this->awsSecretKey, $this->awsSessionToken);
    }

    /**
     * @param array<string,mixed> $house
     */
    private function fetchHouseShadow(array $house, string $page, bool $optional = false): ?array
    {
        if ($this->signer === null) {
            throw new Exception('AWS signer not initialized');
        }
        $region = (string) ($house['mqttRegion'] ?? $this->region ?? 'us-east-1');
        $host = sprintf('%s.x-sense-iot.com', $region);
        $houseId = (string) ($house['houseId'] ?? '');
        $url = sprintf('https://%s/things/%s/shadow?name=%s', $host, $houseId, rawurlencode($page));
        $headers = [
            'Content-Type' => 'application/x-amz-json-1.0',
            'User-Agent' => 'aws-sdk-php/3.320.0',
        ];
        $signed = $this->signer->signHeaders('GET', $url, $region, $headers);
        $response = $this->invoke('GET', $url, null, $this->mergeHeaders($headers, $signed));
        if ($optional && $response === '') {
            return null;
        }
        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $house
     * @param array<string,mixed> $station
     * @return array<string,array<string,mixed>>
     */
    private function fetchStationShadows(array $house, array $station): array
    {
        $result = [];
        foreach (['mainpage', '2nd_mainpage'] as $page) {
            $data = $this->fetchStationShadow($house, $station, $page, true);
            if ($data !== null) {
                $result[$page] = $data;
            }
        }
        $stationSn = $this->extractStationSn($station);
        if ($stationSn !== null) {
            foreach (['info', '2nd_info'] as $prefix) {
                $data = $this->fetchStationShadow($house, $station, sprintf('%s_%s', $prefix, $stationSn), true);
                if ($data !== null) {
                    $result[$prefix] = $data;
                }
            }
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $house
     * @param array<string,mixed> $station
     */
    private function fetchStationShadow(array $house, array $station, string $page, bool $optional = false): ?array

    private function fetchHouseShadow(string $houseId, string $page): array

    {
        if ($this->signer === null) {
            throw new Exception('AWS signer not initialized');
        }
        $region = (string) ($house['mqttRegion'] ?? $this->region ?? 'us-east-1');
        $host = sprintf('%s.x-sense-iot.com', $region);
        $shadowName = $this->buildStationShadowName($station);
        if ($shadowName === null) {
            return null;
        }
        $url = sprintf('https://%s/things/%s/shadow?name=%s', $host, $shadowName, rawurlencode($page));

        $region = $this->region ?? 'us-east-1';
        $host = sprintf('%s.x-sense-iot.com', $region);

        $url = sprintf('https://%s/things/%s/shadow?name=%s', $host, $houseId, rawurlencode($page));
        $headers = [
            'Content-Type' => 'application/x-amz-json-1.0',
            'User-Agent' => 'aws-sdk-php/3.320.0',
        ];
        $signed = $this->signer->signHeaders('GET', $url, $region, $headers);
        $response = $this->invoke('GET', $url, null, $this->mergeHeaders($headers, $signed));
        if ($optional && $response === '') {
            return null;
        }
        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $house
     * @param array<string,mixed> $station
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    protected function postStationShadow(array $house, array $station, string $page, array $payload): array
    {
        if ($this->signer === null) {
            throw new Exception('AWS signer not initialized');
        }
        $region = (string) ($house['mqttRegion'] ?? $this->region ?? 'us-east-1');
        $host = sprintf('%s.x-sense-iot.com', $region);
        $shadowName = $this->buildStationShadowName($station);
        if ($shadowName === null) {
            throw new Exception('Unable to determine station shadow name');
        }
        $url = sprintf('https://%s/things/%s/shadow?name=%s', $host, $shadowName, rawurlencode($page));
        $headers = [
            'Content-Type' => 'application/x-amz-json-1.0',
            'User-Agent' => 'aws-sdk-php/3.320.0',
        ];
        $signed = $this->signer->signHeaders('POST', $url, $region, $headers, $payload);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new Exception('Failed to encode station payload');
        }
        $response = $this->invoke('POST', $url, $body, $this->mergeHeaders($headers, $signed));
        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $station
     */
    private function buildStationShadowName(array $station): ?string
    {
        $type = (string) ($station['stationType'] ?? $station['type'] ?? '');
        $sn = $this->extractStationSn($station);
        if ($sn === null) {
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

    /**
     * @param array<string,mixed> $station
     */
    private function extractStationSn(array $station): ?string
    {
        $sn = $station['sn'] ?? $station['stationSN'] ?? $station['stationId'] ?? $station['deviceSN'] ?? null;
        if (!is_string($sn) || $sn === '') {
            return null;
        }
        return $sn;
    }


        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    private function apiCall(string $code, array $payload, bool $unauthenticated = false): array
    {
        $body = $payload + [
            'clientType' => '1',
            'mac' => $unauthenticated ? 'abcdefg' : $this->calculateMac($payload),
            'appVersion' => 'v1.22.0_20240914.1',
            'bizCode' => $code,
            'appCode' => '1220',
        ];
        $headers = ['Content-Type: application/json'];
        if (!$unauthenticated && $this->accessToken !== null) {
            $headers[] = 'Authorization: ' . $this->accessToken;
        }
        $result = $this->invokeJson(self::API_URL, $body, $headers);
        if (($result['reCode'] ?? 0) !== 200) {
            throw new Exception('API call failed: ' . ($result['reMsg'] ?? 'unknown'));
        }
        return $result['reData'] ?? [];
    }

    private function calculateMac(array $payload): string
    {
        if ($this->clientSecret === null) {
            throw new Exception('Client secret missing');
        }
        $values = [];
        foreach ($payload as $value) {
            if (is_array($value)) {
                $values[] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $values[] = (string) $value;
            }
        }
        return md5(implode('', $values) . $this->clientSecret);
    }

    private function decodeSecret(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new Exception('Invalid client secret encoding');
        }
        return substr($decoded, 4, -1);
    }

    private function performSrpLogin(string $username, string $password): array
    {
        if (!extension_loaded('gmp')) {
            throw new Exception('SRP login requires the GMP extension');
        }
        if ($this->clientId === null || $this->region === null || $this->userPoolId === null) {
            throw new Exception('Missing Cognito bootstrap information');
        }

        $a = $this->generateRandomSmallA();
        $A = $this->calculateA($a);
        $authParameters = [
            'USERNAME' => $username,
            'SRP_A' => strtoupper($this->padHex($A)),
        ];
        if ($this->clientSecret !== null) {
            $authParameters['SECRET_HASH'] = $this->calculateSecretHash($username);
        }

        $challenge = $this->invokeJson(
            'https://cognito-idp.' . $this->region . '.amazonaws.com',
            [
                'AuthFlow' => 'USER_SRP_AUTH',
                'AuthParameters' => $authParameters,
                'ClientId' => $this->clientId,
            ],
            [
                'Content-Type: application/x-amz-json-1.1',
                'X-Amz-Target: AWSCognitoIdentityProviderService.InitiateAuth',
            ]
        );

        $params = $challenge['ChallengeParameters'] ?? [];
        $BHex = $params['SRP_B'] ?? null;
        $saltHex = $params['SALT'] ?? null;
        $secretBlock = $params['SECRET_BLOCK'] ?? null;
        $userId = $params['USER_ID_FOR_SRP'] ?? null;
        if (!is_string($BHex) || !is_string($saltHex) || !is_string($secretBlock) || !is_string($userId)) {
            throw new Exception('Incomplete SRP challenge response');
        }

        $hkdf = $this->computePasswordAuthenticationKey($username, $password, $saltHex, $BHex, $a, $A);
        $timestamp = gmdate('D M d H:i:s \U\T\C Y');
        $poolName = explode('_', $this->userPoolId, 2)[1] ?? $this->userPoolId;
        $secretBlockBinary = base64_decode($secretBlock, true);
        if ($secretBlockBinary === false) {
            throw new Exception('Unable to decode Cognito secret block');
        }
        $message = $poolName . $userId . $secretBlockBinary . $timestamp;
        $signature = base64_encode(hash_hmac('sha256', $message, $hkdf, true));

        $responses = [
            'PASSWORD_CLAIM_SIGNATURE' => $signature,
            'PASSWORD_CLAIM_SECRET_BLOCK' => $secretBlock,
            'TIMESTAMP' => $timestamp,
            'USERNAME' => $username,
            'USER_ID_FOR_SRP' => $userId,
        ];
        if ($this->clientSecret !== null) {
            $responses['SECRET_HASH'] = $this->calculateSecretHash($userId);
        }

        $authResult = $this->invokeJson(
            'https://cognito-idp.' . $this->region . '.amazonaws.com',
            [
                'ChallengeName' => 'PASSWORD_VERIFIER',
                'ClientId' => $this->clientId,
                'ChallengeResponses' => $responses,
            ],
            [
                'Content-Type: application/x-amz-json-1.1',
                'X-Amz-Target: AWSCognitoIdentityProviderService.RespondToAuthChallenge',
            ]
        );

        $result = $authResult['AuthenticationResult'] ?? null;
        if (!is_array($result)) {
            throw new Exception('SRP authentication failed');
        }
        $result['UserId'] = $userId;
        return $result;
    }

    private function calculateSecretHash(string $username): string
    {
        if ($this->clientSecret === null || $this->clientId === null) {
            return '';
        }
        return base64_encode(hash_hmac('sha256', $username . $this->clientId, $this->clientSecret, true));
    }

    private function generateRandomSmallA(): \GMP
    {
        $random = bin2hex(random_bytes(128));
        return gmp_init($random, 16);
    }

    private function calculateA(\GMP $a): \GMP
    {
        $N = gmp_init(self::SRP_N_HEX, 16);
        $g = gmp_init(self::SRP_G_HEX, 16);
        return gmp_powm($g, $a, $N);
    }

    private function computePasswordAuthenticationKey(string $username, string $password, string $saltHex, string $BHex, \GMP $a, \GMP $A): string
    {
        $N = gmp_init(self::SRP_N_HEX, 16);
        $g = gmp_init(self::SRP_G_HEX, 16);
        $k = gmp_init(hash('sha256', $this->padHex($N) . $this->padHex($g)), 16);
        $B = gmp_init($BHex, 16);
        if (gmp_cmp(gmp_mod($B, $N), gmp_init(0, 10)) === 0) {
            throw new Exception('Invalid SRP server public value');
        }
        $u = gmp_init(hash('sha256', $this->padHex($A) . $this->padHex($B)), 16);
        if (gmp_cmp($u, gmp_init(0, 10)) === 0) {
            throw new Exception('SRP hash of public values is zero');
        }

        $salt = gmp_init($saltHex, 16);
        $poolName = explode('_', $this->userPoolId ?? '', 2)[1] ?? ($this->userPoolId ?? '');
        $userHash = hash('sha256', $poolName . $username . ':' . $password, true);
        $x = gmp_init(hash('sha256', $this->padHex($salt) . bin2hex($userHash)), 16);
        $gModPowX = gmp_powm($g, $x, $N);
        $tmp = gmp_sub($B, gmp_mod(gmp_mul($k, $gModPowX), $N));
        if (gmp_cmp($tmp, gmp_init(0, 10)) < 0) {
            $tmp = gmp_add($tmp, $N);
        }
        $exp = gmp_add($a, gmp_mul($u, $x));
        $S = gmp_powm($tmp, $exp, $N);
        $key = hex2bin($this->padHex($S));
        if ($key === false) {
            throw new Exception('Failed to convert SRP shared secret');
        }
        return hash_hkdf('sha256', $key, 16, 'Caldera Derived Key', '');
    }

    private function padHex(\GMP $value): string
    {
        $hex = gmp_strval($value, 16);
        if (strpos($hex, '-') === 0) {
            $hex = substr($hex, 1);
        }
        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }
        return str_pad($hex, 64, '0', STR_PAD_LEFT);

        throw new Exception('SRP login not yet implemented; supply cached tokens via RestoreSession.');
    }

    private function invoke(string $method, string $url, ?string $body, array $headers): string
    {
        $options = [
            'Timeout' => 5000,
            'Headers' => $headers,
        ];
        if ($method === 'POST') {
            $options['Content'] = $body;
        }
        $result = Sys_GetURLContentEx($url, $options);
        if ($result === false) {
            throw new Exception('HTTP request failed for ' . $url);
        }
        return $result;
    }

    private function invokeJson(string $url, array $body, array $headers): array
    {
        $headers[] = 'Accept: application/json';
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new Exception('Failed to encode JSON payload');
        }
        $response = $this->invoke('POST', $url, $payload, $headers);
        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,string> $base
     * @param array<string,string> $additional
     * @return array<int,string>
     */
    private function mergeHeaders(array $base, array $additional): array
    {
        $result = [];
        foreach ($base as $header) {
            $result[] = $header;
        }
        foreach ($additional as $key => $value) {
            $result[] = $key . ': ' . $value;
        }
        return $result;
    }

    private const SRP_N_HEX = 'FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7EDEE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3DC2007CB8A163BF0598DA48361C55D39A69163FA8FD24CF5F83655D23DCA3AD961C62F356208552BB9ED529077096966D670C354E4ABC9804F1746C08CA18217C32905E462E36CE3BE39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9DE2BCBF6955817183995497CEA956AE515D2261898FA051015728E5A8AAAC42DAD33170D04507A33A85521ABDF1CBA64ECFB850458DBEF0A8AEA71575D060C7DB3970F85A6E1E4C7ABF5AE8CDB0933D71E8C94E04A25619DCEE3D2261AD2EE6BF12FFA06D98A0864D87602733EC86A64521F2B18177B200CBBE117577A615D6C770988C0BAD946E208E24FA074E5AB3143DB5BFCE0FD108E4B82D120A92108011A723C12A787E6D788719A10BDBA5B2699C327186AF4E23C1A946834B6150BDA2583E9CA2AD44CE8DBBBC2DB04DE8EF92E8EFC141FBECAA6287C59474E6BC05D99B2964FA090C3A2233BA186515BE7ED1F612970CEE2D7AFB81BDD762170481CD0069127D5B05AA993B4EA988D8FDDC186FFB7DC90A6C08F4DF435C934063199FFFFFFFFFFFFFFFF';
    private const SRP_G_HEX = '2';
}
