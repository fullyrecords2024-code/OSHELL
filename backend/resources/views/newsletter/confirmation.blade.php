<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm newsletter subscription</title>
</head>
<body style="margin:0;background:#f7f5f0;color:#282822;font-family:Arial,sans-serif;line-height:1.6">
    <main style="max-width:560px;margin:10vh auto;padding:32px;background:#fff">
        <p style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#777">Oshel newsletter</p>
        <h1 style="font-size:26px;font-weight:500">Confirm your email</h1>
        <p>Confirm that you own this email address and agree to receive marketing updates. An existing opt-out will remain active unless you confirm.</p>
        <form method="post" action="{{ $action }}">
            <button type="submit" style="border:0;padding:13px 20px;background:#34483b;color:#fff;font-size:16px;cursor:pointer">Confirm subscription</button>
        </form>
    </main>
</body>
</html>
