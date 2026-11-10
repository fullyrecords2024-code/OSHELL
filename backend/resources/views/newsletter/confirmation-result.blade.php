<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Newsletter confirmation</title>
</head>
<body style="margin:0;background:#f7f5f0;color:#282822;font-family:Arial,sans-serif;line-height:1.6">
    <main style="max-width:560px;margin:10vh auto;padding:32px;background:#fff">
        @if ($confirmed)
            <h1 style="font-size:26px;font-weight:500">Subscription confirmed</h1>
            <p>Your email address is confirmed and the newsletter subscription is active.</p>
        @else
            <h1 style="font-size:26px;font-weight:500">Confirmation link unavailable</h1>
            <p>This link is invalid, expired, or has already been used. You can request a new confirmation from the Oshel website.</p>
        @endif
    </main>
</body>
</html>
