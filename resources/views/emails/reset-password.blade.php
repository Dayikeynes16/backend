@extends('emails.layout')

@section('title', 'Restablecer contraseña · ' . config('app.name'))
@section('preheader', 'Recibimos una solicitud para restablecer tu contraseña.')

@section('content')
    <h1 style="margin: 0 0 12px; font-size: 26px; line-height: 32px; color:#18181b; font-weight: 800; letter-spacing: -0.02em;" class="h1-md">
        Restablecer contraseña
    </h1>
    <p style="margin: 0 0 20px; font-size: 16px; line-height: 24px; color:#3f3f46;">
        Hola <strong>{{ $user->name }}</strong>, recibimos una solicitud para restablecer la contraseña de tu cuenta en <strong>{{ config('app.name') }}</strong>. Hacé clic en el botón para elegir una nueva.
    </p>

    @include('emails.partials.button', ['url' => $resetUrl, 'label' => 'Elegir nueva contraseña'])

    <p style="margin: 0 0 16px; font-size: 13px; line-height: 20px; color:#71717a; text-align: center;">
        Este enlace expira en <strong>{{ $expiresInMinutes }} minutos</strong>.
    </p>

    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 32px 0 0;">
        <tr>
            <td style="border-top: 1px solid #e4e4e7; padding-top: 20px; font-size: 13px; line-height: 20px; color:#71717a;">
                <p style="margin: 0 0 8px;">
                    <strong>¿No funciona el botón?</strong> Copiá y pegá este enlace en tu navegador:
                </p>
                <p style="margin: 0; word-break: break-all;">
                    <a href="{{ $resetUrl }}" style="color:#dc2626; text-decoration: underline;">{{ $resetUrl }}</a>
                </p>
            </td>
        </tr>
    </table>

    <p style="margin: 24px 0 0; padding: 14px 18px; background-color:#fef2f2; border-radius: 6px; font-size: 13px; line-height: 20px; color:#7f1d1d;">
        <strong>¿No fuiste vos?</strong> Si no solicitaste este cambio, ignorá este correo: tu contraseña actual seguirá funcionando.
    </p>
@endsection
