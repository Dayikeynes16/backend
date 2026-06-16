{{-- Bulletproof email button (table-based, works in Outlook). Props: $url, $label --}}
<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin: 28px auto;">
    <tr>
        <td align="center" style="border-radius: 10px; background-color:#dc2626;">
            <a href="{{ $url }}" target="_blank" rel="noopener" class="btn" style="display:inline-block; padding: 14px 32px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; font-weight: 600; color:#ffffff !important; text-decoration: none; border-radius: 10px; background-color:#dc2626; mso-padding-alt: 0;">
                <!--[if mso]>&nbsp;&nbsp;&nbsp;&nbsp;<![endif]-->
                {{ $label }}
                <!--[if mso]>&nbsp;&nbsp;&nbsp;&nbsp;<![endif]-->
            </a>
        </td>
    </tr>
</table>
