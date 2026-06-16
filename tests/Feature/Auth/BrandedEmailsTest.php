<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordBranded;
use App\Notifications\VerifyEmailBranded;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Asserts the three transactional emails render via the branded carniceria
 * blade templates (and not via the framework defaults).
 */
class BrandedEmailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_welcome_notification_uses_branded_view(): void
    {
        $user = User::factory()->create(['name' => 'Pepe Carnicero']);

        $mail = (new WelcomeNotification)->toMail($user);

        $this->assertSame('emails.welcome', $mail->view);
        $this->assertSame($user, $mail->viewData['user']);
        $this->assertArrayHasKey('loginUrl', $mail->viewData);
        $this->assertStringContainsString('Bienvenido', $mail->subject);
    }

    public function test_verify_email_notification_uses_branded_view(): void
    {
        $user = User::factory()->unverified()->create();

        $mail = (new VerifyEmailBranded)->toMail($user);

        $this->assertSame('emails.verify-email', $mail->view);
        $this->assertArrayHasKey('verificationUrl', $mail->viewData);
        $this->assertArrayHasKey('expiresInMinutes', $mail->viewData);
        $this->assertStringContainsString('Verifica tu correo', $mail->subject);
        $this->assertStringContainsString('/verify-email/', $mail->viewData['verificationUrl']);
        $this->assertStringContainsString('signature=', $mail->viewData['verificationUrl']);
    }

    public function test_reset_password_notification_uses_branded_view(): void
    {
        $user = User::factory()->create();

        $mail = (new ResetPasswordBranded('test-token'))->toMail($user);

        $this->assertSame('emails.reset-password', $mail->view);
        $this->assertArrayHasKey('resetUrl', $mail->viewData);
        $this->assertArrayHasKey('expiresInMinutes', $mail->viewData);
        $this->assertStringContainsString('Restablece tu contraseña', $mail->subject);
        $this->assertStringContainsString('test-token', $mail->viewData['resetUrl']);
    }

    public function test_forgot_password_dispatches_branded_reset(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPasswordBranded::class);
    }

    public function test_resend_verification_dispatches_branded_verify(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post('/email/verification-notification')
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, VerifyEmailBranded::class);
    }

    public function test_branded_email_views_render_without_errors(): void
    {
        $user = User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com']);

        $welcome = view('emails.welcome', [
            'user' => $user,
            'loginUrl' => 'https://example.test/login',
        ])->render();
        $verify = view('emails.verify-email', [
            'user' => $user,
            'verificationUrl' => 'https://example.test/verify',
            'expiresInMinutes' => 60,
        ])->render();
        $reset = view('emails.reset-password', [
            'user' => $user,
            'resetUrl' => 'https://example.test/reset',
            'expiresInMinutes' => 60,
        ])->render();

        foreach ([$welcome, $verify, $reset] as $html) {
            $this->assertStringContainsString(config('app.name'), $html);
            $this->assertStringContainsString('Test User', $html);
        }
        $this->assertStringContainsString('https://example.test/verify', $verify);
        $this->assertStringContainsString('https://example.test/reset', $reset);
        $this->assertStringContainsString('https://example.test/login', $welcome);
    }
}
