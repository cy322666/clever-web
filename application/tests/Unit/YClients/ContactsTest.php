<?php

namespace Tests\Unit\YClients;

use App\Services\YClients\Contacts;
use Exception;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Ufee\Amo\Oauthapi;

class ContactsTest extends TestCase
{
    private mixed $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Facade::getFacadeApplication();
        $app = new Container();
        $app->instance('log', new NullLogger());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApplication);
        parent::tearDown();
    }

    public function test_phone_search_uses_last_ten_digits(): void
    {
        $this->assertSame('9261234567', Contacts::clearPhone('+7 (926) 123-45-67'));
        $this->assertSame('9261234567', Contacts::clearPhone('8 926 123 45 67'));
    }

    public function test_phone_store_uses_stable_russian_format(): void
    {
        $this->assertSame('+79261234567', Contacts::clearPhone('8 926 123 45 67', true));
        $this->assertSame('+79261234567', Contacts::clearPhone('79261234567', true));
        $this->assertSame('+79261234567', Contacts::clearPhone('9261234567', true));
    }

    public function test_email_normalization_trims_and_lowercases(): void
    {
        $method = (new ReflectionClass(Contacts::class))->getMethod('normalizeEmail');

        $this->assertSame('client@example.com', $method->invoke(null, ' Client@Example.COM '));
        $this->assertNull($method->invoke(null, 'not-email'));
    }

    public function test_empty_contact_search_result_is_treated_as_missing_contact(): void
    {
        $method = (new ReflectionClass(Contacts::class))->getMethod('firstContact');
        $emptyResult = new class {
            public function first(): bool
            {
                return false;
            }
        };

        $this->assertNull($method->invoke(null, $emptyResult));
    }

    public function test_magic_contact_collection_is_unpacked(): void
    {
        $method = (new ReflectionClass(Contacts::class))->getMethod('contactsFromSearchResult');
        $contact = $this->createMock(\Ufee\Amo\Models\Contact::class);
        $collection = new class($contact) {
            public function __construct(private object $contact) {}

            public function __call(string $method, array $arguments): mixed
            {
                if ($method === 'all') {
                    return [$this->contact];
                }

                throw new \BadMethodCallException($method);
            }
        };

        $result = $method->invoke(null, $collection);

        $this->assertCount(1, $result);
        $this->assertSame($contact, $result[0]);
    }

    public function test_failed_phone_lookup_is_not_treated_as_missing_contact(): void
    {
        $contacts = new class {
            public int $calls = 0;

            public function searchByPhone(string $phone): never
            {
                $this->calls++;

                throw new Exception('Invalid API response (non JSON), code: 502');
            }
        };
        $service = $this->createMock(Oauthapi::class);
        $service->method('__call')->with('contacts', [])->willReturn($contacts);
        $amoApi = $this->createMock(\App\Services\amoCRM\Client::class);
        $amoApi->service = $service;

        try {
            Contacts::search(['Телефон' => '+7 (926) 123-45-67'], $amoApi);
            $this->fail('A failed lookup must stop contact creation.');
        } catch (Exception $exception) {
            $this->assertSame('Invalid API response (non JSON), code: 502', $exception->getMessage());
        }

        $this->assertSame(3, $contacts->calls);
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(15)->seconds()]);
    }
}
