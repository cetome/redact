{{-- Contact form e-mails, HTML part (to cetome), in the shell of the
     account e-mails (mail/auth/message). Inline styles are allowed in e-mails only (no CSP in mail
     clients). The message box has a left rule only, so no rounded corners. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Inter,'Segoe UI',Helvetica,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;border:1px solid #e2e8f0;">
                    <tr>
                        <td style="padding:28px 32px 8px 32px;">
                            <span style="display:inline-block;width:12px;height:12px;background:#0044aa;margin-right:8px;vertical-align:middle;"></span>
                            <span style="font-size:13px;font-weight:900;letter-spacing:.3em;text-transform:uppercase;color:#0044aa;vertical-align:middle;">REDact</span>
                            <span style="font-size:13px;color:#64748b;vertical-align:middle;">&nbsp;by cetome</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 8px 32px;font-size:16px;line-height:1.6;">
                            @if ($greeting)
                                <p style="margin:0 0 16px 0;">{{ $greeting }}</p>
                            @endif
                            @foreach ($paragraphs as $paragraph)
                                <p style="margin:0 0 16px 0;">{{ $paragraph }}</p>
                            @endforeach

                            @if ($fields !== [])
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px 0;font-size:14px;">
                                    @foreach ($fields as [$label, $value, $href])
                                        <tr>
                                            <td style="padding:4px 16px 4px 0;width:120px;vertical-align:top;font-size:11px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:#64748b;">{{ $label }}</td>
                                            <td style="padding:4px 0;vertical-align:top;color:#0f172a;">
                                                @if ($href)
                                                    <a href="{{ $href }}" style="color:#0044aa;">{{ $value }}</a>
                                                @else
                                                    {{ $value }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if ($body !== null)
                                <div style="background:#f8fafc;padding:16px 20px;border-left:4px solid #0044aa;margin:0 0 20px 0;">
                                    <p style="margin:0 0 8px 0;font-size:11px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:#0044aa;">{{ $bodyLabel }}</p>
                                    <p style="margin:0;font-size:15px;white-space:pre-wrap;">{{ $body }}</p>
                                </div>
                            @endif

                            @if ($button)
                                <p style="margin:24px 0;">
                                    <a href="{{ $button['url'] }}" style="display:inline-block;background:#0044aa;color:#ffffff;text-decoration:none;font-size:13px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;padding:14px 24px;border-radius:8px;">{{ $button['label'] }}</a>
                                </p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 28px 32px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
                            {{ $footer }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
