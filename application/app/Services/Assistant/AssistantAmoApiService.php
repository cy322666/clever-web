<?php

namespace App\Services\Assistant;

use App\Models\User;
use App\Services\amoCRM\Client;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AssistantAmoApiService
{
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

    public function getLead(int $leadId): array
    {
        return $this->request('/api/v4/leads/' . $leadId, [
            'with' => 'contacts,tags',
        ]);
    }

    private function request(string $path, array $query = []): array
    {
        $response = $this->sendRequest($path, $query);

        if ($response->status() === 401) {
            $this->amoApi->refreshAccessToken();
            $response = $this->sendRequest($path, $query);
        }

        if (!$response->successful()) {
            throw new RuntimeException(
                'amoCRM request failed: ' . $response->status() . ' ' . $response->body()
            );
        }

        return $response->json() ?? [];
    }

    private function http(): PendingRequest
    {
        $this->amoApi->ensureAccessToken();

        return Http::acceptJson()
            ->timeout(45)
            ->retry(2, 300)
            ->withToken($this->amoApi->account->access_token);
    }

    private function sendRequest(string $path, array $query = []): Response
    {
        return $this->http()->get($this->url($path), $query);
    }

    private function url(string $path): string
    {
        $zone = $this->amoApi->account->zone ?: 'ru';

        return 'https://' . $this->amoApi->account->subdomain . '.amocrm.' . $zone . $path;
    }

    public function getLeads(array $query = [], int $limit = 250): array
    {
        return $this->paginate('/api/v4/leads', $query, '_embedded.leads', $limit);
    }

    private function paginate(string $path, array $query, string $embeddedKey, int $limit = 250): array
    {
        $items = [];

        for ($page = 1; $page <= 20; $page++) {
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

    public function getTasks(array $query = [], int $limit = 250): array
    {
        return $this->paginate('/api/v4/tasks', $query, '_embedded.tasks', $limit);
    }
}
