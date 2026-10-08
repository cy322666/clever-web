<?php

namespace App\Services\amoCRM;

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
        $client->ownedQueries = new QueryCollection;
        $client->ownedQueries->boot($client);

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

    public function __get($target)
    {
        return $target === 'queries' && isset($this->ownedQueries)
            ? $this->ownedQueries : parent::__get($target);
    }
}
