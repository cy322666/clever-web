<?php

namespace App\Services\amoCRM;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Ufee\Amo\Base\Storage\Oauth\AbstractStorage;
use Ufee\Amo\Oauthapi;

class EloquentStorage extends AbstractStorage
{
    public Model $model;

    public function __construct(array $options, Model $storage_model)
    {
        $this->options = $options;

        $this->model = $storage_model;
    }

    public function initClient(Oauthapi $client)
    {
        static::$_oauth = $this->getOauth();
    }

    public function setOauthData(Oauthapi $client, array $oauth): bool
    {
        static::$_oauth = $oauth;

        $this->setOauth($oauth);

        return true;
    }

    public function getOauthData(Oauthapi $client, $field = null): string|array
    {
        return $this->getOauth($field);
    }

    protected function getOauth(?string $field = null) : string|array
    {
        $data = [
            'token_type'    => 'Bearer',
            'access_token'  => $this->model->access_token,
            'refresh_token' => $this->model->refresh_token,
            'expires_in'    => $this->model->expires_in,
            'created_at'    => $this->model->created_at,
        ];

        return $field ? $data[$field] : $data;
    }

    private function setOauth(array $oauth): void
    {
        $this->model->access_token  = $oauth['access_token'];
        $this->model->refresh_token = $oauth['refresh_token'];
        $this->model->expires_in    = $oauth['expires_in'];
        $this->model->created_at    = $oauth['created_at'];
        $this->model->save();
    }
}
