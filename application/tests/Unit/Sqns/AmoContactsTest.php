<?php

namespace Tests\Unit\Sqns;

use App\Models\Integrations\Sqns\Client as SqnsClient;
use App\Services\amoCRM\Client as AmoClient;
use App\Services\Sqns\AmoContacts;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Oauthapi;
use Ufee\Amo\Services\Contacts;

class AmoContactsTest extends TestCase
{
    #[DataProvider('lookupOutcomes')]
    public function test_resolves_contact_in_phone_then_email_order(?string $match, array $expectedCalls): void
    {
        $calls = [];
        $contact = $this->createMock(Contact::class);
        $contact->method('__get')->with('id')->willReturn(42);
        $contact->method('cf')->willReturn(new class
        {
            public function setValue(string $value): void {}
        });
        $contact->expects($this->once())->method('save');

        $contacts = $this->createMock(Contacts::class);
        $contacts->expects($this->once())->method('searchByPhone')->with('9261234567')
            ->willReturnCallback(function () use (&$calls, $match, $contact) {
                $calls[] = 'phone';

                return $match === 'phone' ? collect([$contact]) : collect();
            });
        $contacts->expects($match === 'phone' ? $this->never() : $this->once())
            ->method('searchByEmail')->with('owner@example.test')
            ->willReturnCallback(function () use (&$calls, $match, $contact) {
                $calls[] = 'email';

                return $match === 'email' ? $contact : [];
            });
        $contacts->expects($match === null ? $this->once() : $this->never())
            ->method('create')->willReturnCallback(function () use (&$calls, $contact) {
                $calls[] = 'create';

                return $contact;
            });
        $contacts->expects($this->once())->method('find')->with(42)->willReturn($contact);

        $client = $this->sqnsClient();
        $this->assertSame($contact, (new AmoContacts)->resolve($client, $this->amoClient($contacts), null));
        $this->assertSame($expectedCalls, $calls);
        $this->assertSame(42, $client->contact_id);
    }

    public static function lookupOutcomes(): array
    {
        return [
            'phone match' => ['phone', ['phone']],
            'email fallback' => ['email', ['phone', 'email']],
            'create after empty searches' => [null, ['phone', 'email', 'create']],
        ];
    }

    public function test_failed_email_lookup_does_not_create_contact(): void
    {
        $contacts = $this->createMock(Contacts::class);
        $contacts->expects($this->once())->method('searchByPhone')->willReturn([]);
        $contacts->expects($this->once())->method('searchByEmail')
            ->willThrowException(new RuntimeException('amoCRM unavailable'));
        $contacts->expects($this->never())->method('create');

        $this->expectExceptionMessage('amoCRM unavailable');

        (new AmoContacts)->resolve($this->sqnsClient(), $this->amoClient($contacts), null);
    }

    private function sqnsClient(): SqnsClient
    {
        $client = $this->getMockBuilder(SqnsClient::class)->onlyMethods(['save'])->getMock();
        $client->method('save')->willReturn(true);

        return $client->fill([
            'name' => 'Тестовый клиент',
            'phone' => '+7 (926) 123-45-67',
            'email' => ' Owner@Example.Test ',
        ]);
    }

    private function amoClient(Contacts $contacts): AmoClient
    {
        $amoApi = $this->createMock(AmoClient::class);
        $amoApi->service = $this->createMock(Oauthapi::class);
        $amoApi->service->method('__call')->with('contacts', [])->willReturn($contacts);

        return $amoApi;
    }
}
