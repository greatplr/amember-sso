<?php

namespace Greatplr\AmemberSso\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for aMember's REST API (the `api` module).
 *
 * One instance talks to one aMember installation. It holds only the base URL,
 * API key and timeout, and builds a fresh request for every call, so an
 * instance can be reused freely (queue workers, tests) without earlier calls
 * leaking into later ones.
 *
 * See docs/AMEMBER_REFERENCE.md for the API behaviour this follows.
 */
class AmemberApiClient
{
    /** aMember refuses larger pages. */
    public const MAX_PAGE_SIZE = 1000;

    protected string $baseUrl;

    /**
     * @param string $baseUrl The installation's API URL, `{amember_root}/api`
     * @param string $apiKey  An API key from aMember's REST API settings
     * @param int    $timeout Seconds before a request is abandoned
     */
    public function __construct(
        string $baseUrl,
        protected string $apiKey,
        protected int $timeout = 10,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Client for the installation configured in `amember-sso.api`
     * (AMEMBER_URL / AMEMBER_API_KEY).
     *
     * @throws AmemberApiException when the URL or key isn't configured
     */
    public static function fromConfig(): static
    {
        $url = config('amember-sso.api.url');
        $key = config('amember-sso.api.key');

        if (!$url || !$key) {
            throw new AmemberApiException(
                'aMember API is not configured: set AMEMBER_URL and AMEMBER_API_KEY, or pass an installation.'
            );
        }

        return new static($url, $key, (int) config('amember-sso.api.timeout', 10));
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Call a check-access action, e.g. `by-login`, `by-login-pass`.
     *
     * Returns aMember's JSON as is. A failed check is still HTTP 200 with
     * `ok` false, so callers must look at `ok`. On success `subscriptions`
     * is a map of product id => expiry date: read it by key.
     *
     * @return array<string, mixed>
     * @throws AmemberApiException
     */
    public function checkAccess(string $action, array $params): array
    {
        return $this->post('check-access/' . $action, $params);
    }

    /**
     * List records from a table controller (`users`, `access`, `products`, ...).
     *
     * Strips `_total` and returns the records as a list. Use
     * {@see listWithTotal()} when the total matters.
     *
     * @param array<string, scalar> $filter Exact-match column filters (`%` makes it LIKE)
     * @param list<string>          $nested Related records to include, if the controller declares them
     * @param array<string, scalar> $query  Any other parameters, e.g. `_sort`, `_order`
     * @return list<array<string, mixed>>
     * @throws AmemberApiException
     */
    public function list(
        string $controller,
        array $filter = [],
        int $count = 20,
        int $page = 0,
        array $nested = [],
        array $query = [],
    ): array {
        return $this->listWithTotal($controller, $filter, $count, $page, $nested, $query)['records'];
    }

    /**
     * As {@see list()}, also returning `_total` (the count ignoring paging).
     *
     * @return array{total: int|null, records: list<array<string, mixed>>}
     * @throws AmemberApiException
     */
    public function listWithTotal(
        string $controller,
        array $filter = [],
        int $count = 20,
        int $page = 0,
        array $nested = [],
        array $query = [],
    ): array {
        $params = $query;

        foreach ($filter as $field => $value) {
            $params['_filter'][$field] = $value;
        }

        $params['_count'] = max(1, min($count, self::MAX_PAGE_SIZE));
        $params['_page'] = max(0, $page);

        if ($nested) {
            $params['_nested'] = array_values($nested);
        }

        return $this->splitList($this->get($controller, $params));
    }

    /**
     * Fetch one record by id, or null when aMember returns none.
     *
     * @param list<string> $nested
     * @return array<string, mixed>|null
     * @throws AmemberApiException
     */
    public function find(string $controller, int|string $id, array $nested = []): ?array
    {
        $params = $nested ? ['_nested' => array_values($nested)] : [];
        $records = $this->splitList($this->get($controller . '/' . rawurlencode((string) $id), $params))['records'];

        return $records[0] ?? null;
    }

    /**
     * GET a path under the API URL and return the decoded JSON.
     *
     * @return array<mixed>
     * @throws AmemberApiException
     */
    public function get(string $path, array $query = []): array
    {
        $url = $this->url($path);

        if ($query) {
            $url .= '?' . $this->buildQuery($query);
        }

        return $this->send('GET', $path, fn (PendingRequest $request) => $request->get($url));
    }

    /**
     * POST form parameters to a path under the API URL and return the decoded JSON.
     *
     * @return array<mixed>
     * @throws AmemberApiException
     */
    public function post(string $path, array $params = []): array
    {
        $url = $this->url($path);

        return $this->send('POST', $path, fn (PendingRequest $request) => $request->asForm()->post($url, $params));
    }

    /**
     * @param callable(PendingRequest): Response $call
     * @return array<mixed>
     */
    protected function send(string $method, string $path, callable $call): array
    {
        $request = Http::withHeaders(['X-API-Key' => $this->apiKey])
            ->acceptJson()
            ->timeout($this->timeout);

        try {
            $response = $call($request);
        } catch (ConnectionException $e) {
            throw new AmemberApiException("aMember API {$method} {$path} failed: {$e->getMessage()}", null, $e);
        }

        if (!$response->successful()) {
            // Key and permission errors (10001-10004) land here with an HTML
            // or text body. Keep a short, tag-free excerpt: it names the code.
            $excerpt = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($response->body()))), 0, 200);

            throw new AmemberApiException(
                "aMember API {$method} {$path} returned HTTP {$response->status()}" . ($excerpt !== '' ? ": {$excerpt}" : ''),
                $response->status(),
            );
        }

        $data = json_decode($response->body(), true);

        if (!is_array($data)) {
            throw new AmemberApiException(
                "aMember API {$method} {$path} returned a non-JSON body (HTTP {$response->status()})",
                $response->status(),
            );
        }

        return $data;
    }

    /**
     * @return array{total: int|null, records: list<array<string, mixed>>}
     */
    protected function splitList(array $data): array
    {
        $total = isset($data['_total']) ? (int) $data['_total'] : null;
        unset($data['_total']);

        $records = [];
        foreach ($data as $key => $record) {
            if (is_int($key) && is_array($record)) {
                $records[] = $record;
            }
        }

        return ['total' => $total, 'records' => $records];
    }

    protected function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * http_build_query, but with list parameters written as `name[]=value`
     * (`_nested[]=access`), the form aMember documents.
     */
    protected function buildQuery(array $query): string
    {
        $query = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return preg_replace('/%5B\d+%5D=/', '%5B%5D=', $query);
    }
}
