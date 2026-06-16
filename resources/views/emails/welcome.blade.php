@extends('emails.layout')

@section('title', '¡Bienvenido a ' . config('app.name') . '!')
@section('preheader', 'Tu cuenta está lista. Empezá a gestionar tu carnicería en segundos.')

@section('content')
    <h1 style="margin: 0 0 12px; font-size: 26px; line-height: 32px; color:#18181b; font-weight: 800; letter-spacing: -0.02em;" class="h1-md">
        ¡Hola, {{ $user->name }}! 👋
    </h1>
    <p style="margin: 0 0 20px; font-size: 16px; line-height: 24px; color:#3f3f46;">
        Tu cuenta en <strong>{{ config('app.name') }}</strong> ya está lista. Estamos contentos de que te sumes para hacer crecer tu carnicería.
    </p>

    <p style="margin: 0 0 12px; font-size: 16px; line-height: 24px; color:#3f3f46;">
        Desde tu panel vas a poder:
    </p>
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 0 0 24px;">
        <tr>
            <td style="padding: 8px 0; font-size: 15px; line-height: 22px; color:#3f3f46;">
                <span style="color:#dc2626; font-weight: 700;">✓</span>&nbsp;&nbsp;Registrar ventas y cobrar en caja al instante
            </td>
        </tr>
        <tr>
            <td style="padding: 8px 0; font-size: 15px; line-height: 22px; color:#3f3f46;">
                <span style="color:#dc2626; font-weight: 700;">✓</span>&nbsp;&nbsp;Controlar inventario y precios por sucursal
            </td>
        </tr>
        <tr>
            <td style="padding: 8px 0; font-size: 15px; line-height: 22px; color:#3f3f46;">
                <span style="color:#dc2626; font-weight: 700;">✓</span>&nbsp;&nbsp;Ver reportes y métricas en tiempo real
            </td>
        </tr>
        <tr>
            <td style="padding: 8px 0; font-size: 15px; line-height: 22px; color:#3f3f46;">
                <span style="color:#dc2626; font-weight: 700;">✓</span>&nbsp;&nbsp;Gestionar clientes, proveedores y gastos
            </td>
        </tr>
    </table>

    @include('emails.partials.button', ['url' => $loginUrl, 'label' => 'Ir al panel'])

    <p style="margin: 24px 0 0; padding: 16px 20px; background-color:#fef2f2; border-left: 4px solid #dc2626; border-radius: 6px; font-size: 14px; line-height: 22px; color:#52525b;">
        <strong style="color:#991b1b;">Próximo paso:</strong> en breve recibirás un correo separado para verificar tu cuenta. No es obligatorio, pero te recomendamos hacerlo para mayor seguridad.
    </p>

    <p style="margin: 28px 0 0; font-size: 14px; line-height: 22px; color:#71717a;">
        Si tenés cualquier duda, simplemente respondé este correo y te ayudamos.<br>
        — El equipo de {{ config('app.name') }}
    </p>
@endsection
