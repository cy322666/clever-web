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

    private function getHeaders(): array
    {
        return [
            'Accept'        => 'Accept: application/vnd.api.v2+json',
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

        foreach ($companies->json()['data'] as $branch) {

            if ($branch['id'] == $companyId)

                return $branch['title'] ?? null;
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
