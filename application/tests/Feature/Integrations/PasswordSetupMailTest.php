<?php

namespace Tests\Feature\Integrations;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordSetupMailTest extends TestCase
{
    public function test_password_setup_email_has_a_recipient_and_can_be_sent(): void
    {
        $user = new User(['name' => 'Installer', 'email' => 'installer@example.test']);
        $mail = (new ResetPassword('test-password-token'))->toMail($user);

        $this->assertTrue($mail->hasTo('installer@example.test'));
        $this->assertNotNull(Mail::mailer('array')->send($mail));
    }
}
