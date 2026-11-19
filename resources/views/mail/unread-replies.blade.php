{{--
    Threadlive: replies the visitor has not read, by e-mail.
    $team: config('threadlive.agent_display_name')
    $messages: list of ['author' => ?string, 'body' => string, 'files' => [['name', 'url']]]
    $link: where "Continue the conversation" goes, or null
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('A reply from :name', ['name' => $team]) }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1a2233;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fa;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;">
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <p style="margin:0 0 4px;font-size:18px;font-weight:600;">{{ __('A reply from :name', ['name' => $team]) }}</p>
                        <p style="margin:0;font-size:14px;color:#5b6577;">{{ __('You have a reply in the chat you left.') }}</p>
                    </td>
                </tr>
                @foreach ($messages as $reply)
                    <tr>
                        <td style="padding:12px 28px 0;">
                            @if ($reply['author'])
                                <p style="margin:0 0 4px;font-size:12px;color:#5b6577;">{{ $reply['author'] }}</p>
                            @endif
                            <div style="background:#f4f6fa;border-radius:12px;padding:12px 14px;font-size:15px;line-height:21px;white-space:pre-wrap;word-break:break-word;">{{ $reply['body'] }}</div>
                            @foreach ($reply['files'] as $file)
                                <p style="margin:6px 0 0;font-size:14px;">📎 <a href="{{ $file['url'] }}" style="color:#0066ff;">{{ $file['name'] }}</a></p>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <td style="padding:24px 28px 28px;">
                        @if ($link)
                            <a href="{{ $link }}" style="display:inline-block;background:#0066ff;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:12px 20px;border-radius:10px;">{{ __('Continue the conversation') }}</a>
                        @endif
                        <p style="margin:16px 0 0;font-size:12px;color:#8a93a3;">{{ __('You get this because you left your e-mail in the chat. Replies you have read in the chat are not sent.') }}</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
