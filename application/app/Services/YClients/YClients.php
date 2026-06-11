<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Client;
use App\Models\Integrations\YClients\Setting;
use App\Models\Integrations\YClients\YClients as YC;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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
        return Http::withHeaders($this->getHeaders())
            ->get('https://api.yclients.com/api/v1/client/'.$companyId.'/'.$clientId)
            ->object();
    }

    /**
     * @throws ConnectionException
     */
    public function getRecord(string $companyId, string $recordId): ?object
    {
        return Http::withHeaders($this->getHeaders())
            ->get('https://api.yclients.com/api/v1/record/' . $companyId . '/' . $recordId)
            ->object();
    }

    /**
     * @throws ConnectionException
     */
    public function getUserPermissions(string $companyId, string $userId): ?object
    {
        return Http::withHeaders($this->getHeaders())
            ->get('https://api.yclients.com/api/v1/company/' . $companyId . '/users/' . $userId . '/permissions')
            ->object();
    }

    /**
     * @throws ConnectionException
     */
    public function getUserRoles(string $companyId, string $userId): ?object
    {
        return Http::withHeaders($this->getHeaders())
            ->get('https://api.yclients.com/api/v1/company/' . $companyId . '/users/' . $userId . '/roles')
            ->object();
    }

    /**
     * @throws ConnectionException
     */
    public function getStaff(string $companyId, string $staffId): ?object
    {
        return Http::withHeaders($this->getHeaders())
            ->get('https://api.yclients.com/api/v1/company/' . $companyId . '/staff/' . $staffId)
            ->object();
    }

    /**
     * @throws ConnectionException
     */
    public function findStaffByUserId(string $companyId, string $userId): ?object
    {
        $response = Http::withHeaders($this->getHeaders())
            ->get('https://api.yclients.com/api/v1/company/' . $companyId . '/staff')
            ->object();

        $staff = collect(data_get($response, 'data', []))
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
        $response = Http::withHeaders($this->getHeaders())
            ->get('https://api.yclients.com/api/v1/company/' . $companyId . '/users')
            ->object();

        $user = collect(data_get($response, 'data', []))
            ->first(fn($item) => (string)data_get($item, 'id') === (string)$userId);

        return $user ? (object)$user : null;
    }

    /**
     * @throws ConnectionException
     */
    public function getStaffPositions(string $companyId): ?object
    {
        return Http::withHeaders($this->getHeaders())
            ->get('https://api.yclients.com/api/v1/company/' . $companyId . '/staff/positions')
            ->object();
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
     * @throws ConnectionException
     */
    public function getBranchTitle(string $companyId): ?string
    {
        $companies = Http::withHeaders($this->getHeaders())
            ->get('https://api.yclients.com/api/v1/companies?my=1');

        $branches = $companies->json('data') ?? [];

        if (!is_iterable($branches)) {
            return null;
        }

        foreach ($branches as $branch) {
            if (data_get($branch, 'id') == $companyId) {
                return data_get($branch, 'title');
            }
        }

        return null;
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
