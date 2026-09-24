<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * S3-compatible document storage for MinIO using path-style requests and AWS Sig V4.
 */
final class MinioDocumentStorage
{
    public function __construct(
        private string $endpoint,
        private string $accessKey,
        private string $secretKey,
        private string $bucket,
        private string $region = 'us-east-1',
    ) {
    }

    public function store(string $key, string $contents, string $mimeType): string
    {
        $this->ensureBucket();
        $url = $this->objectUrl($key);
        $this->signedRequest('PUT', $url, $contents, $mimeType);

        return $key;
    }

    public function read(string $key): ?string
    {
        $url = $this->objectUrl($key);

        try {
            return $this->signedRequest('GET', $url);
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), '404')) {
                return null;
            }

            throw $exception;
        }
    }

    public function exists(string $key): bool
    {
        return $this->read($key) !== null;
    }

    public function delete(string $key): void
    {
        // S3 DELETE is idempotent: a missing object still returns 204.
        $this->signedRequest('DELETE', $this->objectUrl($key));
    }

    private function ensureBucket(): void
    {
        $url = rtrim($this->endpoint, '/').'/'.$this->bucket;
        $headers = $this->sign('PUT', $url, '', 'application/octet-stream', []);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!in_array($status, [200, 201, 409], true)) {
            throw new \RuntimeException(sprintf('Failed to ensure MinIO bucket (HTTP %d).', $status));
        }
    }

    private function signedRequest(string $method, string $url, string $body = '', string $contentType = 'application/octet-stream'): string
    {
        $headers = $this->sign($method, $url, $body, $contentType, []);
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
        ];

        if ($method === 'PUT') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('MinIO request failed: '.$error);
        }

        if ($status >= 400) {
            throw new \RuntimeException(sprintf('MinIO request failed with HTTP %d.', $status));
        }

        return $response;
    }

    /** @param array<string, string> $extraHeaders */
    private function sign(string $method, string $url, string $body, string $contentType, array $extraHeaders): array
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? 'localhost';
        if (isset($parsed['port'])) {
            $host .= ':'.$parsed['port'];
        }

        $path = $parsed['path'] ?? '/';
        $query = $parsed['query'] ?? '';
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);

        $headers = array_merge([
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
            'content-type' => $contentType,
        ], $extraHeaders);

        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaderNames = [];

        foreach ($headers as $name => $value) {
            $canonicalHeaders .= strtolower($name).':'.trim($value)."\n";
            $signedHeaderNames[] = strtolower($name);
        }

        sort($signedHeaderNames);
        $signedHeaders = implode(';', $signedHeaderNames);
        $canonicalRequest = implode("\n", [
            $method,
            $this->uriEncode($path),
            $query,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = $dateStamp.'/'.$this->region.'/s3/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->signingKey($dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $credentialScope,
            $signedHeaders,
            $signature,
        );

        return array_merge($headers, ['authorization' => $authorization]);
    }

    private function signingKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4'.$this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /** @param array<string, string> $headers */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $this->headerName($name).': '.$value;
        }

        return $formatted;
    }

    private function headerName(string $name): string
    {
        return match (strtolower($name)) {
            'content-type' => 'Content-Type',
            'host' => 'Host',
            'authorization' => 'Authorization',
            'x-amz-content-sha256' => 'x-amz-content-sha256',
            'x-amz-date' => 'x-amz-date',
            default => $name,
        };
    }

    private function objectUrl(string $key): string
    {
        return rtrim($this->endpoint, '/').'/'.$this->bucket.'/'.ltrim(str_replace('\\', '/', $key), '/');
    }

    private function uriEncode(string $path): string
    {
        return implode('/', array_map(static fn (string $segment): string => rawurlencode($segment), explode('/', $path)));
    }
}
