@props(['title'])

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:24px;font-family:Arial,Helvetica,sans-serif;background:#f8fafc;color:#0f172a;">
    <div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;">
        <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;">{{ $title }}</h1>
        {{ $slot }}
    </div>
</body>
</html>
