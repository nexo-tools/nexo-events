{{-- Operator-facing (not translated): goes to whoever runs the instance. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Reported event</title></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#18181b; background:#f4f4f5; margin:0; padding:24px;">
    <div style="max-width:600px; margin:0 auto; background:#ffffff; border:1px solid #e4e4e7; border-radius:12px; padding:24px;">
        <h1 style="margin:0 0 16px; font-size:18px;">An event was reported</h1>

        <p style="margin:0 0 8px; font-size:14px;"><strong>Event:</strong> {{ $event->title }}</p>
        <p style="margin:0 0 8px; font-size:14px;"><strong>Public page:</strong> <a href="{{ $publicUrl }}">{{ $publicUrl }}</a></p>
        <p style="margin:0 0 8px; font-size:14px;"><strong>Organizer:</strong> {{ $event->organizer->email }}</p>
        <p style="margin:0 0 8px; font-size:14px;"><strong>Reports so far:</strong> {{ $totalReports }}</p>
        <p style="margin:0 0 8px; font-size:14px;"><strong>Reporter:</strong> {{ $reporterEmail ?: 'not provided' }}</p>

        <p style="margin:16px 0 4px; font-size:14px;"><strong>Reason</strong></p>
        <p style="margin:0 0 16px; padding:12px; background:#f4f4f5; border-radius:8px; font-size:14px; white-space:pre-line;">{{ $reason }}</p>

        <p style="margin:16px 0 4px; font-size:13px; color:#71717a;">To take it down (reversible — the previous status is recorded):</p>
        <pre style="margin:0; padding:12px; background:#18181b; color:#ffffff; border-radius:8px; font-size:12px; overflow-x:auto;">php artisan events:kill {{ $event->slug }} --reason="..."</pre>
        <p style="margin:12px 0 0; font-size:12px; color:#a1a1aa;">Undo with <code>php artisan events:restore {{ $event->slug }}</code></p>
    </div>
</body>
</html>
