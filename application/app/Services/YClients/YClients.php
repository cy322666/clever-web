<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Client;
use App\Models\Integrations\YClients\Setting;
use App\Models\Integrations\YClients\YClients as YC;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class YClients
{
    private string $partnerToken;

    private string $userToken;

    public function __construct(Setting $setting)
    {
        $this->userToken = $setting->user_token;
        $this->partnerToken = $setting->partner_token;
    }

    public function getPartnerToken(): string
    {
        return $this->partnerToken;
    }

    public function getUserToken(): string
    {
        return $this->userToken;
    }

    /**
     * @param string $userToken
     */
    public function setUserToken(string $userToken): static
    {
        $this->userToken = $userToken;

        return $this;
    }

    /**
     * @throws ConnectionException
     */
    public function getClient(string $companyId, string $clientId): ?object
    {
        return $this->get('client/' . $companyId . '/' . $clientId);
    }

    /**
     * @throws ConnectionException
     */
    public function getRecord(string $companyId, string $recordId): ?object
    {
        return $this->get('record/' . $companyId . '/' . $recordId);
    }

    /**
     * @throws ConnectionException
     */
    public function getUserPermissions(string $companyId, string $userId): ?object
    {
        return $this->get('company/' . $companyId . '/users/' . $userId . '/permissions');
    }

    /**
     * @throws ConnectionException
     */
    public function getUserRoles(string $companyId, string $userId): ?object
    {
        return $this->get('company/' . $companyId . '/users/' . $userId . '/roles');
    }

    /**
     * @throws ConnectionException
     */
    public function getStaff(string $companyId, string $staffId): ?object
    {
        return $this->get('company/' . $companyId . '/staff/' . $staffId);
    }

    /**
     * @throws ConnectionException
     */
    public function findStaffByUserId(string $companyId, string $userId): ?object
    {
        $staff = collect($this->getCompanyStaff($companyId))
            ->first(function ($item) use ($userId) {
                return (string)data_get($item, 'user_id') === (string)$userId
                    || (string)data_get($item, 'user.id') === (string)$userId;
            });

        return $staff ? (object)$staff : null;
    }

    /**
     * @throws ConnectionException
     */
    public function findCompanyUserById(string $companyId, string $userId): ?object
    {
        $user = collect($this->getCompanyUsers($companyId))
            ->first(fn($item) => self::companyUserId($item) === (string)$userId);

        return $user ? (object)$user : null;
    }

    public static function companyUserId(mixed $user): string
    {
        return (string)(
            data_get($user, 'id')
            ?? data_get($user, 'user_id')
            ?? data_get($user, 'user.id')
            ?? ''
        );
    }

    public static function companyUserName(mixed $user): ?string
    {
        $name = data_get($user, 'name')
            ?? data_get($user, 'full_name')
            ?? data_get($user, 'display_name')
            ?? data_get($user, 'user.name')
            ?? data_get($user, 'user.full_name')
            ?? data_get($user, 'user.display_name');

        return filled($name) ? trim((string)$name) : null;
    }

    /**
     * @throws ConnectionException
     */
    public function getCompanyUsers(string $companyId): array
    {
        $users = data_get($this->get('company/' . $companyId . '/users'), 'data', []);

        return is_array($users) ? $users : [];
    }

    /**
     * @throws ConnectionException
     */
    public function getCompanyStaff(string $companyId): array
    {
        $staff = data_get($this->get('company/' . $companyId . '/staff'), 'data', []);

        return is_array($staff) ? $staff : [];
    }

    /**
     * @throws ConnectionException
     */
    public function getBranches(): array
    {
        $branches = data_get($this->get('companies?my=1'), 'data', []);

        return is_array($branches) ? $branches : [];
    }

    /**
     * @throws ConnectionException
     */
    public function getStaffPositions(string $companyId): ?object
    {
        return $this->get('company/' . $companyId . '/staff/positions');
    }

    /**
     * @throws ConnectionException
     */
    public function findPositionTitle(string $companyId, int|string|null $positionId): ?string
    {
        if (empty($positionId)) {
            return null;
        }

        $positions = $this->getStaffPositions($companyId);
        $position = collect(data_get($positions, 'data', []))
            ->first(function ($item) use ($positionId) {
                return (string)data_get($item, 'id') === (string)$positionId;
            });

        return $position ? data_get($position, 'title') : null;
    }

    private function getHeaders(): array
    {
        return [
            'Accept' => 'application/vnd.api.v2+json',
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->getPartnerToken().', User '.$this->getUserToken(),
        ];
    }

    /**
     * Retry transient network failures before marking the YClients record failed.
     *
     * @throws ConnectionException
     */
    private function get(string $path): ?object
    {
        return Http::withHeaders($this->getHeaders())
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(
                [500, 1000, 2000, 4000],
                0,
                fn(Throwable $exception): bool => $exception instanceof ConnectionException
            )
            ->get('https://api.yclients.com/api/v1/' . ltrim($path, '/'))
            ->object();
    }

    /**
     * @throws ConnectionException
     */
    public function getBranchTitle(string $companyId): ?string
    {
        $branch = collect($this->getBranches())
            ->first(fn($branch): bool => (string)data_get($branch, 'id') === (string)$companyId);

        return data_get($branch, 'title');
    }

    /**
     * @param string $partnerToken
     * @return YClients
     */
    public function setPartnerToken(string $partnerToken): static
    {
        $this->partnerToken = $partnerToken;

        return $this;
    }
}
