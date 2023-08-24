<?php

namespace App\Services\amoCRM;

use App\Models\Core\Account;
use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Ufee\Amo\Oauthapi;

class Client
{
    public Oauthapi $service;
    public EloquentStorage $storage;

    public bool $auth = false;

    public function __construct(Account $account)
    {
        $this->storage = new EloquentStorage([
            'domain'    => $account->subdomain ?? null,
            'client_id' => $account->client_id ?? null,
            'client_secret' => $account->client_secret ?? null,
            'redirect_uri'  => $account->redirect_uri ?? null,
            'zone' => $account->zone ?? null,
        ], $account);

        Oauthapi::setOauthStorage($this->storage);
    }

    /**
     * @throws Exception
     */
    public function init(): Client
    {
        if ($this->storage->model->code == null) {

            return $this;
        }

        $this->service = Oauthapi::setInstance([
            'domain'        => $this->storage->model->subdomain,
            'client_id'     => $this->storage->model->client_id,
            'client_secret' => $this->storage->model->client_secret,
            'redirect_uri'  => $this->storage->model->redirect_uri,
            'zone'          => $account->zone ?? null,
        ]);

        try {
            $this->service->account;

            $this->auth = true;

        } catch (Exception $e) {

            Log::warning(__METHOD__, [$e->getMessage()]);

            try {
                if ($this->storage->model->refresh_token) {

                    $oauth = $this->service->refreshAccessToken($this->storage->model->refresh_token);
                } else
                    $oauth = $this->service->fetchAccessToken($this->storage->model->code);

            } catch (\Throwable $e) {

                Log::error(__METHOD__, [$e->getMessage()]);
            }

            $this->storage->setOauthData($this->service, [
                'token_type'    => 'Bearer',
                'expires_in'    => $oauth['expires_in'],
                'access_token'  => $oauth['access_token'],
                'refresh_token' => $oauth['refresh_token'],
                'created_at'    => $oauth['created_at'] ?? time(),
            ]);

            $this->auth = true;
        }

        return $this;
    }
}
