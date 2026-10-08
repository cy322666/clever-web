<?php

namespace App\Services\amoCRM;

use Ufee\Amo\Base\Storage\Query\AbstractStorage as InMemoryQueryStorage;
use Ufee\Amo\Collections\QueryCollection;
use Ufee\Amo\Oauthapi;

final class IsolatedOauthClient extends Oauthapi
{
    private string $instanceKey;

    private EloquentStorage $ownedStorage;

    private QueryCollection $ownedQueries;

    public static function forAccount(array $options, EloquentStorage $storage): self
    {
        /** @var self $client */
        $client = parent::setInstance($options);
        $client->ownedStorage = $storage;
        $client->instanceKey = 'clever:'.$storage->model->getKey().':'.hash('sha256', implode('|', [
            $options['client_id'], $options['domain'], $options['zone'] ?: 'ru',
        ]));

        // SDK query models resolve clients by getAuth('id'), not by the OAuth UUID.
        unset(self::$_instances[$options['client_id']]);
        self::$_instances[$client->instanceKey] = $client;
        $client->ownedQueries = new ThrottledQueryCollection($storage->model);
        $client->ownedQueries->boot($client);

        // Serialized file-cache entries retain another process/account instance key.
        $client->ownedQueries->setCacheStorage(new InMemoryQueryStorage($client, []));

        return $client;
    }

    public function getAuth($key = null)
    {
        return $key === 'id' && isset($this->instanceKey)
            ? $this->instanceKey : parent::getAuth($key);
    }

    public function getOauth($key = null)
    {
        if ($key === true) {
            $this->ownedStorage->initClient($this);
            $key = null;
        }

        return $this->ownedStorage->getOauthData($this, $key);
    }

    public function setOauth(array $oauth)
    {
        return $this->ownedStorage->setOauthData($this, $oauth);
    }

    public function fetchAccessToken($code)
    {
        // Token endpoints bypass Query::execute(), so they need their own admission.
        app(AmoCrmRequestThrottle::class)->acquire($this->ownedStorage->model);

        return parent::fetchAccessToken($code);
    }

    public function refreshAccessToken($refresh_token = null)
    {
        app(AmoCrmRequestThrottle::class)->acquire($this->ownedStorage->model);

        // Preserve the SDK token callbacks and do not replay token exchanges.
        return parent::refreshAccessToken($refresh_token);
    }

    public function __get($target)
    {
        return $target === 'queries' && isset($this->ownedQueries)
            ? $this->ownedQueries : parent::__get($target);
    }
}
