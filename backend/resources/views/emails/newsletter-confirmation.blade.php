<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm newsletter subscription</title>
</head>
<body style="margin:0;background:#f7f5f0;color:#282822;font-family:Arial,sans-serif;line-height:1.6">
    <main style="max-width:560px;margin:40px auto;padding:32px;background:#fff">
        <p style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#777">Newsletter subscription</p>
        <h1 style="font-size:26px;font-weight:500">{{ $organizationName }}</h1>
        <p>Someone entered this email address to receive marketing updates. Confirm that you own this address and want to subscribe.</p>
        <p style="margin:28px 0">
            <a href="{{ $confirmationUrl }}" style="display:inline-block;padding:13px 20px;background:#34483b;color:#fff;text-decoration:none">Confirm my email</a>
        </p>
        <p>This link expires in 24 hours. If you did not request this, you can ignore this message. An existing opt-out stays in place unless you confirm.</p>
    </main>
</body>
</html>
