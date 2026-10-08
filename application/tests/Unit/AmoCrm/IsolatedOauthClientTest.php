<?php

namespace Tests\Unit\AmoCrm;

use App\Models\Core\Account;
use App\Services\amoCRM\EloquentStorage;
use App\Services\amoCRM\IsolatedOauthClient;
use PHPUnit\Framework\TestCase;
use Ufee\Amo\Api\Oauth\Query;
use Ufee\Amo\Base\Storage\Query\AbstractStorage as InMemoryQueryStorage;
use Ufee\Amo\Base\Storage\Query\FileStorage;

class IsolatedOauthClientTest extends TestCase
{
    public function test_each_account_keeps_its_own_sdk_instance(): void
    {
        $first = $this->client(68);
        $firstQuery = new Query($first, \Ufee\Amo\Services\Account::class);

        $second = $this->client(111);
        $secondQuery = new Query($second, \Ufee\Amo\Services\Account::class);

        $this->assertSame($first, $firstQuery->instance());
        $this->assertSame($second, $secondQuery->instance());
        $this->assertNotSame($first->getAuth('id'), $second->getAuth('id'));
    }

    public function test_query_cache_is_process_local(): void
    {
        $client = $this->client(111);
        $storage = $client->queries->getCacheStorage();

        $this->assertInstanceOf(InMemoryQueryStorage::class, $storage);
        $this->assertNotInstanceOf(FileStorage::class, $storage);
    }

    private function client(int $accountId): IsolatedOauthClient
    {
        $account = new Account;
        $account->setAttribute('id', $accountId);
        $account->setAttribute('access_token', 'access-'.$accountId);
        $account->setAttribute('refresh_token', 'refresh-'.$accountId);
        $account->setAttribute('expires_in', 86400);
        $account->setAttribute('created_at', time());

        $storage = new EloquentStorage([
            'domain' => 'dostavkaamber',
            'client_id' => '0be256d8-dfac-4af7-b729-d8fdd1a4b177',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://app.clevercrm.pro/api/amocrm/install/tilda',
            'zone' => 'ru',
        ], $account);

        return IsolatedOauthClient::forAccount([
            'domain' => 'dostavkaamber',
            'client_id' => '0be256d8-dfac-4af7-b729-d8fdd1a4b177',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://app.clevercrm.pro/api/amocrm/install/tilda',
            'zone' => 'ru',
        ], $storage);
    }
}
