<?php

namespace Tests\Unit\AmoCrm;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use Ufee\Amo\Base\Models\ApiModel;
use Ufee\Amo\Base\Services\LimitedList;
use Ufee\Amo\Models\Company;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;

class ApiModelConflictRetryTest extends TestCase
{
    #[DataProvider('entityModels')]
    public function test_conflict_retries_same_entity_with_magic_updated_at(string $modelClass): void
    {
        $service = $this->service();
        $model = new $modelClass(['id' => 42, 'name' => 'Before', 'updated_at' => 1000, 'responsible_user_id' => 7], $service);
        $fresh = new $modelClass(['id' => 42, 'name' => 'Remote', 'updated_at' => 2000, 'responsible_user_id' => 8], $service);
        $model->name = 'From YClients';

        $this->assertFalse(isset($fresh->updated_at));
        $this->assertSame(2000, $fresh->updated_at);
        $service->expects($this->once())->method('find')->with(42)->willReturn($fresh);
        $attempts = 0;
        $service->expects($this->exactly(2))->method('update')->willReturnCallback(function (ApiModel $current) use ($model, &$attempts): bool {
            $this->assertSame($model, $current);
            $this->assertSame(42, $current->id);
            if (++$attempts === 1) {
                throw $this->conflict();
            }
            $this->assertSame(['name' => 'From YClients', 'updated_at' => 2000], $current->getChangedRawApiData());
            return true;
        });
        $service->expects($this->never())->method('add');

        $this->assertTrue($model->save());
    }

    public static function entityModels(): array
    {
        return ['contact' => [Contact::class], 'lead' => [Lead::class], 'company' => [Company::class]];
    }

    public function test_retry_preserves_remote_timestamp_even_if_unchanged_locally(): void
    {
        $service = $this->service();
        $model = new Contact(['id' => 42, 'updated_at' => 2000], $service);
        $fresh = new Contact(['id' => 42, 'updated_at' => 2000], $service);
        $service->expects($this->once())->method('find')->with(42)->willReturn($fresh);
        $attempts = 0;
        $service->expects($this->exactly(2))->method('update')->willReturnCallback(function (ApiModel $current) use (&$attempts): bool {
            if (++$attempts === 1) {
                throw $this->conflict();
            }
            $this->assertSame(2000, $current->getChangedRawApiData()['updated_at']);
            return true;
        });

        $this->assertTrue($model->save());
    }

    public function test_each_conflict_reloads_the_latest_timestamp(): void
    {
        $service = $this->service();
        $model = new Contact(['id' => 42, 'updated_at' => 1000], $service);
        $fresh = new Contact(['id' => 42, 'updated_at' => 2000], $service);
        $newer = new Contact(['id' => 42, 'updated_at' => 3000], $service);
        $service->expects($this->exactly(2))->method('find')->with(42)->willReturnOnConsecutiveCalls($fresh, $newer);
        $versions = [];
        $service->expects($this->exactly(3))->method('update')->willReturnCallback(function (ApiModel $current) use (&$versions): bool {
            $versions[] = $current->updated_at;
            if (count($versions) < 3) {
                throw $this->conflict();
            }
            return true;
        });
        $service->expects($this->never())->method('add');

        $this->assertTrue($model->save());
        $this->assertSame([1000, 2000, 3000], $versions);
    }

    #[DataProvider('unusableReloads')]
    public function test_missing_or_mismatched_entity_does_not_trigger_create(?int $freshId, ?int $updatedAt): void
    {
        $service = $this->service();
        $model = new Contact(['id' => 42, 'updated_at' => 1000], $service);
        $fresh = $freshId === null ? null : new Contact(['id' => $freshId, 'updated_at' => $updatedAt], $service);
        $error = $this->conflict();
        $service->expects($this->once())->method('update')->willThrowException($error);
        $service->expects($this->once())->method('find')->with(42)->willReturn($fresh);
        $service->expects($this->never())->method('add');

        $this->assertThrowsSame($error, fn () => $model->save());
    }

    public static function unusableReloads(): array
    {
        return ['missing contact' => [null, null], 'missing version' => [42, null], 'wrong contact' => [43, 2000]];
    }

    public function test_conflict_retries_are_bounded(): void
    {
        $service = $this->service();
        $model = new Contact(['id' => 42, 'updated_at' => 1000], $service);
        $fresh = new Contact(['id' => 42, 'updated_at' => 2000], $service);
        $error = $this->conflict();
        $service->expects($this->exactly(6))->method('update')->willThrowException($error);
        $service->expects($this->exactly(5))->method('find')->with(42)->willReturn($fresh);
        $service->expects($this->never())->method('add');

        $this->assertThrowsSame($error, fn () => $model->save());
    }

    #[DataProvider('otherErrors')]
    public function test_other_errors_are_not_retried(Throwable $error): void
    {
        $service = $this->service();
        $model = new Contact(['id' => 42, 'updated_at' => 1000], $service);
        $service->expects($this->once())->method('update')->willThrowException($error);
        $service->expects($this->never())->method('find');
        $service->expects($this->never())->method('add');

        $this->assertThrowsSame($error, fn () => $model->save());
    }

    public static function otherErrors(): array
    {
        return [
            'forbidden' => [new Exception('Invalid response code: 403', 403)],
            'validation' => [new Exception('Invalid value for custom field')],
            'transport' => [new Exception('Invalid API response (non JSON), code: 502')],
        ];
    }

    public function test_transport_error_after_conflict_is_not_masked_by_old_conflict(): void
    {
        $service = $this->service();
        $model = new Contact(['id' => 42, 'updated_at' => 1000], $service);
        $fresh = new Contact(['id' => 42, 'updated_at' => 2000], $service);
        $transport = new Exception('Invalid API response (non JSON), code: 502');
        $service->expects($this->once())->method('find')->with(42)->willReturn($fresh);
        $attempts = 0;
        $service->expects($this->exactly(2))->method('update')->willReturnCallback(function () use (&$attempts, $transport): never {
            throw ++$attempts === 1 ? $this->conflict() : $transport;
        });

        $this->assertThrowsSame($transport, fn () => $model->save());
    }

    public function test_unknown_creation_outcome_is_never_replayed(): void
    {
        $service = $this->service();
        $model = new Contact(['name' => 'New contact'], $service);
        $error = new Exception('Invalid API response (non JSON), code: 0');
        $service->expects($this->once())->method('add')->willThrowException($error);
        $service->expects($this->never())->method('update');
        $service->expects($this->never())->method('find');

        $this->assertThrowsSame($error, fn () => $model->save());
    }

    private function service(): LimitedList
    {
        return $this->getMockBuilder(LimitedList::class)->disableOriginalConstructor()->onlyMethods(['find', 'update', 'add'])->getMock();
    }

    private function conflict(): Exception
    {
        return new Exception('API response errors: {"update":{"42":"Last modified date is older than in database"}}');
    }

    private function assertThrowsSame(Throwable $expected, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the original exception.');
        } catch (Throwable $actual) {
            $this->assertSame($expected, $actual);
        }
    }
}
