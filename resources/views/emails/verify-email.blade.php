@extends('emails.layout')

@section('title', 'Verifica tu correo · ' . config('app.name'))
@section('preheader', 'Confirmá tu dirección de correo para mayor seguridad en tu cuenta.')

@section('content')
    <h1 style="margin: 0 0 12px; font-size: 26px; line-height: 32px; color:#18181b; font-weight: 800; letter-spacing: -0.02em;" class="h1-md">
        Verifica tu correo
    </h1>
    <p style="margin: 0 0 20px; font-size: 16px; line-height: 24px; color:#3f3f46;">
        Hola <strong>{{ $user->name }}</strong>, confirmá que <strong>{{ $user->email }}</strong> es tu dirección haciendo clic en el botón. Es opcional, pero ayuda a recuperar tu cuenta si alguna vez perdés la contraseña.
    </p>

    @include('emails.partials.button', ['url' => $verificationUrl, 'label' => 'Verificar mi correo'])

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
                    <a href="{{ $verificationUrl }}" style="color:#dc2626; text-decoration: underline;">{{ $verificationUrl }}</a>
                </p>
            </td>
        </tr>
    </table>

    <p style="margin: 24px 0 0; font-size: 13px; line-height: 20px; color:#a1a1aa;">
        Si no creaste una cuenta en {{ config('app.name') }}, podés ignorar este correo.
    </p>
@endsection
