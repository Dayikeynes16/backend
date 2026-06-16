<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>@yield('title', config('app.name'))</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td, a { font-family: Arial, Helvetica, sans-serif !important; }
    </style>
    <![endif]-->
    <style type="text/css">
        body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { border: 0; outline: none; line-height: 100%; -ms-interpolation-mode: bicubic; }
        a { text-decoration: none; }
        .btn:hover { background-color: #991b1b !important; }
        @media only screen and (max-width: 620px) {
            .container { width: 100% !important; }
            .px-md { padding-left: 24px !important; padding-right: 24px !important; }
            .h1-md { font-size: 22px !important; line-height: 28px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <span style="display:none !important; visibility:hidden; mso-hide:all; font-size:1px; color:#f4f4f5; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">
        @yield('preheader', 'Una notificación de ' . config('app.name'))
    </span>

    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f4f4f5;">
        <tr>
            <td align="center" style="padding: 32px 12px;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" class="container" style="width:600px; max-width:600px;">
                    {{-- Header: red gradient banner with brand --}}
                    <tr>
                        <td style="background-color:#b91c1c; background-image: linear-gradient(135deg, #b91c1c 0%, #dc2626 50%, #991b1b 100%); border-radius: 16px 16px 0 0; padding: 36px 40px;" class="px-md">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td valign="middle" style="vertical-align: middle;">
                                        <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td valign="middle" style="vertical-align: middle; padding-right: 12px;">
                                                    <div style="display:inline-block; width:44px; height:44px; line-height:44px; text-align:center; background-color: rgba(255,255,255,0.2); border-radius: 12px; font-size: 24px;">
                                                        🥩
                                                    </div>
                                                </td>
                                                <td valign="middle" style="vertical-align: middle; color:#ffffff; font-size: 20px; font-weight: 700; letter-spacing: -0.02em;">
                                                    {{ config('app.name') }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Body card --}}
                    <tr>
                        <td style="background-color:#ffffff; padding: 40px; border-radius: 0 0 16px 16px;" class="px-md">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding: 24px 40px; text-align: center; color:#71717a; font-size: 12px; line-height: 18px;" class="px-md">
                            <p style="margin: 0 0 6px;">
                                Recibiste este correo porque tenés una cuenta en {{ config('app.name') }}.
                            </p>
                            <p style="margin: 0; color:#a1a1aa;">
                                &copy; {{ date('Y') }} {{ config('app.name') }}. Todos los derechos reservados.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
