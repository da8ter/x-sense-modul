<?php

declare(strict_types=1);

namespace XSense\Gateway;

use DateTimeImmutable;
use DateTimeZone;

final class AWSSigner
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';
    private const SERVICE = 'iotdata';

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $sessionToken
    ) {
    }

    public function update(string $clientId, string $clientSecret, string $sessionToken): void
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->sessionToken = $sessionToken;
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|null $payload
     * @return array<string,string>
     */
    public function signHeaders(string $method, string $url, string $region, array $headers, ?array $payload = null): array
    {
        $timestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $amzDate = $timestamp->format('Ymd\THis\Z');
        $dateStamp = $timestamp->format('Ymd');

        $canonicalHeaders = $headers;
        $canonicalHeaders['host'] = parse_url($url, PHP_URL_HOST) ?? '';
        ksort($canonicalHeaders);

        $signedHeaders = implode(';', array_map('strtolower', array_keys($canonicalHeaders)));

        $payloadHash = hash('sha256', $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            parse_url($url, PHP_URL_PATH) ?? '/',
            $this->buildCanonicalQuery($url),
            $this->flattenHeaders($canonicalHeaders),
            '',
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = sprintf('%s/%s/%s/aws4_request', $dateStamp, $region, self::SERVICE);
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->getSigningKey($dateStamp, $region));

        return [
            'Authorization' => sprintf(
                '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                self::ALGORITHM,
                $this->clientId,
                $scope,
                $signedHeaders,
                $signature
            ),
            'X-Amz-Date' => $amzDate,
            'X-Amz-Security-Token' => $this->sessionToken,
        ];
    }

    public function buildPresignedUrl(string $url, string $region): string
    {
        $timestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $amzDate = $timestamp->format('Ymd\THis\Z');
        $dateStamp = $timestamp->format('Ymd');
        $scope = sprintf('%s/%s/%s/aws4_request', $dateStamp, $region, self::SERVICE);

        $query = [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => rawurlencode($this->clientId . '/' . $scope),
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => '60',
            'X-Amz-SignedHeaders' => 'host',
            'X-Amz-Security-Token' => rawurlencode($this->sessionToken),
        ];

        $canonicalRequest = implode("\n", [
            'GET',
            parse_url($url, PHP_URL_PATH) ?? '/',
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            sprintf('host:%s\n', parse_url($url, PHP_URL_HOST)),
            'host',
            hash('sha256', ''),
        ]);

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->getSigningKey($dateStamp, $region));
        $query['X-Amz-Signature'] = $signature;

        $base = sprintf('%s://%s%s', parse_url($url, PHP_URL_SCHEME), parse_url($url, PHP_URL_HOST), parse_url($url, PHP_URL_PATH));
        return $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function buildCanonicalQuery(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY) ?? '';
        if ($query === '') {
            return '';
        }
        parse_str($query, $params);
        ksort($params);
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string,string> $headers
     */
    private function flattenHeaders(array $headers): string
    {
        $lines = [];
        foreach ($headers as $key => $value) {
            $lines[] = strtolower($key) . ':' . trim($value);
        }
        return implode("\n", $lines);
    }

    private function getSigningKey(string $dateStamp, string $region): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->clientSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
