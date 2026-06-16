<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;

/**
 * Branded "reset your password" notification.
 *
 * Renders the carniceria HTML template; reuses the framework token + URL logic.
 */
class ResetPasswordBranded extends ResetPassword
{
    /**
     * Build the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $resetUrl = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject('Restablece tu contraseña en '.Config::get('app.name'))
            ->view('emails.reset-password', [
                'user' => $notifiable,
                'resetUrl' => $resetUrl,
                'expiresInMinutes' => Config::get('auth.passwords.'.Config::get('auth.defaults.passwords').'.expire', 60),
            ]);
    }
}
