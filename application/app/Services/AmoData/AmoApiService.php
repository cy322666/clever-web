<?php

namespace App\Services\AmoData;

use App\Models\User;
use App\Services\amoCRM\Client;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AmoApiService
{
    public const PAGE_LIMIT = 50;

    public function __construct(private readonly Client $amoApi)
    {
    }

    /**
     * @throws \Exception
     */
    public static function forUser(User $user): self
    {
        return new self(new Client($user->account));
    }

    public function getLeads(?Carbon $updatedFrom = null, int $limit = self::PAGE_LIMIT): array
    {
        $query = [];

        if ($updatedFrom) {
            $query['filter'] = [
                'updated_at' => [
                    'from' => $updatedFrom->timestamp,
                ],
            ];
        }

        return $this->paginate('/api/v4/leads', $query, '_embedded.leads', $limit);
    }

    public function getLeadsPage(?Carbon $updatedFrom = null, int $page = 1, int $limit = self::PAGE_LIMIT): array
    {
        $query = [
            'page' => $page,
            'limit' => $limit,
        ];

        if ($updatedFrom) {
            $query['filter'] = [
                'updated_at' => [
                    'from' => $updatedFrom->timestamp,
                ],
            ];
        }

        $response = $this->request('/api/v4/leads', $query);

        return data_get($response, '_embedded.leads', []);
    }

    public function syncLeads(?Carbon $updatedFrom, callable $callback, int $limit = self::PAGE_LIMIT): int
    {
        $query = [];

        if ($updatedFrom) {
            $query['filter'] = [
                'updated_at' => [
                    'from' => $updatedFrom->timestamp,
                ],
            ];
        }

        return $this->paginateEach('/api/v4/leads', $query, '_embedded.leads', $callback, $limit);
    }

    private function paginate(string $path, array $query, string $embeddedKey, int $limit = self::PAGE_LIMIT): array
    {
        $items = [];

        for ($page = 1; $page <= 2000; $page++) {
            $response = $this->request(
                $path,
                array_merge($query, [
                    'page' => $page,
                    'limit' => $limit,
                ])
            );

            $pageItems = data_get($response, $embeddedKey, []);

            if (!is_array($pageItems) || $pageItems === []) {
                break;
            }

            $items = array_merge($items, $pageItems);

            if (count($pageItems) < $limit) {
                break;
            }
        }

        return $items;
    }

    private function paginateEach(
        string $path,
        array $query,
        string $embeddedKey,
        callable $callback,
        int $limit = self::PAGE_LIMIT,
    ): int {
        $processed = 0;

        for ($page = 1; $page <= 2000; $page++) {
            $response = $this->request(
                $path,
                array_merge($query, [
                    'page' => $page,
                    'limit' => $limit,
                ])
            );

            $pageItems = data_get($response, $embeddedKey, []);

            if (!is_array($pageItems) || $pageItems === []) {
                break;
            }

            $callback($pageItems);
            $processed += count($pageItems);

            if (count($pageItems) < $limit) {
                break;
            }
        }

        return $processed;
    }

    private function request(string $path, array $query = []): array
    {
        $response = $this->http()->get($this->url($path), $query);

        if (!$response->successful()) {
            throw new RuntimeException(
                'amoCRM request failed: ' . $response->status() . ' ' . $response->body()
            );
        }

        return $response->json() ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(60)
            ->retry(2, 300)
            ->withToken($this->amoApi->account->access_token);
    }

    private function url(string $path): string
    {
        $zone = $this->amoApi->account->zone ?: 'ru';

        return 'https://' . $this->amoApi->account->subdomain . '.amocrm.' . $zone . $path;
    }

    public function getTasks(?Carbon $updatedFrom = null, int $limit = self::PAGE_LIMIT): array
    {
        $query = [];

        if ($updatedFrom) {
            $query['filter'] = [
                'updated_at' => [
                    'from' => $updatedFrom->timestamp,
                ],
            ];
        }

        return $this->paginate('/api/v4/tasks', $query, '_embedded.tasks', $limit);
    }

    public function getTasksPage(?Carbon $updatedFrom = null, int $page = 1, int $limit = self::PAGE_LIMIT): array
    {
        $query = [
            'page' => $page,
            'limit' => $limit,
        ];

        if ($updatedFrom) {
            $query['filter'] = [
                'updated_at' => [
                    'from' => $updatedFrom->timestamp,
                ],
            ];
        }

        $response = $this->request('/api/v4/tasks', $query);

        return data_get($response, '_embedded.tasks', []);
    }

    public function syncTasks(?Carbon $updatedFrom, callable $callback, int $limit = self::PAGE_LIMIT): int
    {
        $query = [];

        if ($updatedFrom) {
            $query['filter'] = [
                'updated_at' => [
                    'from' => $updatedFrom->timestamp,
                ],
            ];
        }

        return $this->paginateEach('/api/v4/tasks', $query, '_embedded.tasks', $callback, $limit);
    }
}
