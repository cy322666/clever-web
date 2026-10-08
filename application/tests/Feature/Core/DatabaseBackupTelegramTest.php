<?php

namespace Tests\Feature\Core;

use App\Console\Commands\Core\BackupDatabase;
use App\Services\Core\DatabaseBackupTelegram;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;
use ZipArchive;

class DatabaseBackupTelegramTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['backup.telegram.enabled' => true, 'backup.telegram.disk' => 'local',
            'backup.telegram.token' => 'test-bot-token', 'backup.telegram.chat_id' => 'admin-chat',
            'backup.telegram.message_thread_id' => null, 'backup.backup.name' => 'test-backups',
            'backup.backup.password' => 'test-archive-password', 'backup.backup.destination.disks' => ['local'],
            'alerts.enabled' => false]);
    }

    private function archive(bool $encrypted = true): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('test-backups');
        $path = $disk->path('test-backups/current.zip');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('db-dumps/pgsql.sql', 'CREATE TABLE example (id INT);');
        if ($encrypted) {
            $zip->setEncryptionName('db-dumps/pgsql.sql', ZipArchive::EM_AES_256, 'test-archive-password');
        }
        $zip->close();

        return $path;
    }

    public function test_sends_encrypted_archive_to_admin_and_checks_complete_file_receipt(): void
    {
        $path = $this->archive();
        $size = filesize($path);
        $body = '';
        Http::fake(function ($request) use ($size, &$body) {
            $body = $request->body();

            return Http::response(['ok' => true, 'result' => ['message_id' => 42,
                'document' => ['file_size' => $size]]]);
        });
        $result = app(DatabaseBackupTelegram::class)->sendLatest(time() - 10);
        $this->assertSame($size, $result['size']);
        $this->assertSame(hash_file('sha256', $path), $result['sha256']);
        $this->assertSame(42, $result['message_id']);
        $this->assertStringContainsString('admin-chat', $body);
        $this->assertStringContainsString($result['sha256'], $body);
        $this->assertStringNotContainsString('test-archive-password', $body);
        $this->assertFileExists($path);
    }

    public function test_unencrypted_archive_is_never_sent(): void
    {
        $this->archive(false);
        Http::fake();
        try {
            app(DatabaseBackupTelegram::class)->sendLatest();
            $this->fail('Unencrypted database must never leave the server.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AES-256', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_old_archive_is_not_reported_as_a_new_successful_backup(): void
    {
        $path = $this->archive();
        touch($path, time() - 3600);
        Http::fake();
        $this->expectExceptionMessage('Свежий резервный архив не найден');
        app(DatabaseBackupTelegram::class)->sendLatest(time() - 60);
    }

    public function test_wrong_password_is_rejected_without_sending(): void
    {
        $this->archive();
        config(['backup.backup.password' => 'wrong-password']);
        Http::fake();
        $this->expectExceptionMessage('Пароль не подходит');
        app(DatabaseBackupTelegram::class)->sendLatest();
    }

    public function test_oversized_backup_stays_local(): void
    {
        $path = $this->archive();
        $stream = fopen($path, 'ab');
        ftruncate($stream, 49_000_001);
        fclose($stream);
        clearstatcache(true, $path);
        Http::fake();
        $this->expectExceptionMessage('превышает лимит');
        app(DatabaseBackupTelegram::class)->sendLatest();
    }

    public function test_telegram_rejection_does_not_expose_bot_token(): void
    {
        $this->archive();
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'private diagnostic'], 403)]);
        try {
            app(DatabaseBackupTelegram::class)->sendLatest();
            $this->fail('Telegram rejection must fail delivery.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('test-bot-token', $e->getMessage());
            $this->assertStringNotContainsString('private diagnostic', $e->getMessage());
        }
    }

    public function test_incomplete_receipt_is_not_accepted(): void
    {
        $this->archive();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 42,
            'document' => ['file_size' => 1]]])]);
        $this->expectExceptionMessage('не подтвердил получение полного архива');
        app(DatabaseBackupTelegram::class)->sendLatest();
    }

    private function command(int $backupExit = 0): BackupDatabase
    {
        $command = new class($backupExit) extends BackupDatabase
        {
            public array $calls = [];

            public function __construct(private int $backupExit)
            {
                parent::__construct();
            }

            public function call($command, array $arguments = [])
            {
                $this->calls[] = $command;

                return $command === 'backup:run' ? $this->backupExit : 0;
            }
        };
        $command->setLaravel($this->app);

        return $command;
    }

    public function test_cleanup_only_follows_successful_external_delivery(): void
    {
        $this->mock(DatabaseBackupTelegram::class)->shouldReceive('sendLatest')->once()
            ->withArgs(fn (int $since) => $since >= time() - 5)
            ->andReturn(['file' => 'example.zip', 'size' => 123, 'message_id' => 42]);
        $command = $this->command();
        $this->assertSame(0, (new CommandTester($command))->execute([]));
        $this->assertSame(['backup:run', 'backup:clean'], $command->calls);
    }

    public function test_failed_delivery_skips_cleanup_and_returns_failure(): void
    {
        $this->mock(DatabaseBackupTelegram::class)->shouldReceive('sendLatest')->once()
            ->andThrow(new RuntimeException('Telegram unavailable'));
        $command = $this->command();
        $this->assertSame(1, (new CommandTester($command))->execute([]));
        $this->assertSame(['backup:run'], $command->calls);
    }

    public function test_failed_dump_neither_sends_nor_cleans(): void
    {
        $this->mock(DatabaseBackupTelegram::class)->shouldNotReceive('sendLatest');
        $command = $this->command(1);
        $this->assertSame(1, (new CommandTester($command))->execute([]));
        $this->assertSame(['backup:run'], $command->calls);
    }

    public function test_send_latest_does_not_create_or_clean_archives(): void
    {
        $this->mock(DatabaseBackupTelegram::class)->shouldReceive('sendLatest')->once()->with(0)
            ->andReturn(['file' => 'example.zip', 'size' => 123, 'message_id' => 42]);
        $command = $this->command();
        $this->assertSame(0, (new CommandTester($command))->execute(['--send-latest' => true]));
        $this->assertSame([], $command->calls);
    }
}
