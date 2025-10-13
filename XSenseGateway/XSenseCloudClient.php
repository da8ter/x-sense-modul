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

        $this->module->SendDebug('XSenseCloudClient', 'Loading AWS IoT credentials', 0);
        $this->refreshAwsCredentials();
    }

    public function refreshSession(): void
    {
        if ($this->shouldRefreshAccessToken() && $this->refreshToken !== null) {
            $this->module->SendDebug('XSenseCloudClient', 'Refreshing Cognito session', 0);
            $response = $this->invokeJson('https://cognito-idp.' . $this->region . '.amazonaws.com', [
                'AuthFlow' => 'REFRESH_TOKEN_AUTH',
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

    private function fetchHouseShadow(string $houseId, string $page): array
    {
        if ($this->signer === null) {
            throw new Exception('AWS signer not initialized');
        }
        $region = $this->region ?? 'us-east-1';
        $host = sprintf('%s.x-sense-iot.com', $region);
        $url = sprintf('https://%s/things/%s/shadow?name=%s', $host, $houseId, rawurlencode($page));
        $headers = [
            'Content-Type' => 'application/x-amz-json-1.0',
            'User-Agent' => 'aws-sdk-php/3.320.0',
        ];
        $signed = $this->signer->signHeaders('GET', $url, $region, $headers);
        $response = $this->invoke('GET', $url, null, $this->mergeHeaders($headers, $signed));
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
}
