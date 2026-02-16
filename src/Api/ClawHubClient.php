<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\ClawHubSkills\Api;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ClawHubClient
{
    private const DEFAULT_BASE_URL = 'https://clawhub.ai';

    private HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $token = '',
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => 20]);
    }

    /**
     * @return array{items: list<array<string, mixed>>, nextCursor: ?string, total: ?int}
     */
    public function search(string $query, int $limit = 10, ?string $cursor = null): array
    {
        $params = [
            'q' => $query,
            'query' => $query,
            'limit' => max(1, min(50, $limit)),
        ];

        if ($cursor !== null && $cursor !== '') {
            $params['cursor'] = $cursor;
        }

        $data = $this->requestJson('GET', '/api/v1/search', $params);
        $items = $data['results'] ?? $data['items'] ?? [];

        return [
            'items' => is_array($items) ? array_values($items) : [],
            'nextCursor' => isset($data['nextCursor']) && is_string($data['nextCursor']) ? $data['nextCursor'] : null,
            'total' => isset($data['total']) && is_int($data['total']) ? $data['total'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function skillDetails(string $slug): array
    {
        return $this->requestJson('GET', '/api/v1/skills/' . rawurlencode($slug));
    }

    /**
     * @return array{items: list<array<string, mixed>>, nextCursor: ?string}
     */
    public function skillVersions(string $slug, int $limit = 20, ?string $cursor = null): array
    {
        $params = [
            'limit' => max(1, min(100, $limit)),
        ];

        if ($cursor !== null && $cursor !== '') {
            $params['cursor'] = $cursor;
        }

        $data = $this->requestJson('GET', '/api/v1/skills/' . rawurlencode($slug) . '/versions', $params);
        $items = $data['versions'] ?? $data['items'] ?? [];

        return [
            'items' => is_array($items) ? array_values($items) : [],
            'nextCursor' => isset($data['nextCursor']) && is_string($data['nextCursor']) ? $data['nextCursor'] : null,
        ];
    }

    public function latestVersion(string $slug): ?string
    {
        $versions = $this->skillVersions($slug, limit: 1);
        $first = $versions['items'][0] ?? null;

        if (!is_array($first)) {
            return null;
        }

        $candidate = $first['version'] ?? $first['tag'] ?? null;

        return is_string($candidate) && $candidate !== '' ? $candidate : null;
    }

    /**
     * @return array{bytes: string, suggestedFilename: string}
     */
    public function downloadSkillArchive(string $slug, ?string $version = null): array
    {
        $params = [
            'slug' => $slug,
            'skill' => $slug,
            'name' => $slug,
        ];

        if ($version !== null && $version !== '') {
            $params['version'] = $version;
            $params['tag'] = $version;
        }

        $url = $this->buildUrl('/api/v1/download');

        $response = $this->httpClient->request('GET', $url, [
            'query' => $params,
            'headers' => $this->buildHeaders(),
        ]);

        try {
            $status = $response->getStatusCode();

            if ($status < 200 || $status >= 300) {
                $errorBody = $response->getContent(false);
                throw new \RuntimeException("ClawHub download failed (HTTP {$status}): {$errorBody}");
            }

            $bytes = $response->getContent();
            $disposition = $response->getHeaders(false)['content-disposition'][0] ?? '';

            $filename = $this->extractFilenameFromDisposition($disposition);
            if ($filename === null) {
                $suffix = $version !== null && $version !== '' ? $version : 'latest';
                $filename = sprintf('%s-%s.zip', $slug, $suffix);
            }

            return [
                'bytes' => $bytes,
                'suggestedFilename' => $filename,
            ];
        } catch (HttpExceptionInterface $e) {
            $body = $this->extractErrorBody($e);
            throw new \RuntimeException("ClawHub download failed (HTTP {$e->getResponse()->getStatusCode()}): {$body}");
        }
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, array $query = []): array
    {
        $response = $this->httpClient->request($method, $this->buildUrl($path), [
            'query' => $query,
            'headers' => $this->buildHeaders(),
        ]);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = $response->toArray();

            return $decoded;
        } catch (HttpExceptionInterface $e) {
            $body = $this->extractErrorBody($e);
            throw new \RuntimeException(
                sprintf('ClawHub request failed for %s (HTTP %d): %s', $path, $e->getResponse()->getStatusCode(), $body),
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('ClawHub request failed for %s: %s', $path, $e->getMessage()));
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'coqui-clawhub-skills/0.1.0',
        ];

        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        return $headers;
    }

    private function buildUrl(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function extractFilenameFromDisposition(string $disposition): ?string
    {
        if ($disposition === '') {
            return null;
        }

        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $matches) !== 1) {
            return null;
        }

        return trim(rawurldecode($matches[1]));
    }

    private function extractErrorBody(HttpExceptionInterface $e): string
    {
        try {
            $body = $e->getResponse()->getContent(false);

            if ($body === '') {
                return 'No response body';
            }

            return mb_substr($body, 0, 500);
        } catch (\Throwable) {
            return 'Unable to read response body';
        }
    }
}