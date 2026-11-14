<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Workspace invitation</title>
</head>
<body style="margin:0;padding:32px;background:#f4f5f7;font-family:Arial,sans-serif;color:#20242a;">
    <main style="max-width:560px;margin:0 auto;padding:32px;background:#fff;border-radius:12px;">
        <h1 style="font-size:22px;margin-top:0;">You have a workspace invitation</h1>
        <p>You have been invited to join <strong>{{ $tenantName }}</strong> as <strong>{{ $role }}</strong>.</p>
        <p>Sign in using the email address that received this message, then accept the invitation.</p>
        <p style="margin:28px 0;">
            <a href="{{ $invitationUrl }}" style="display:inline-block;padding:12px 20px;background:#20242a;color:#fff;text-decoration:none;border-radius:6px;">Review invitation</a>
        </p>
        <p style="font-size:13px;color:#626a73;">This secure link expires in 7 days. It only displays the invitation for the account it was sent to. Accepting requires you to be signed in and complete the normal account security checks.</p>
        <p style="font-size:13px;color:#626a73;">If you were not expecting this invitation, you can ignore this email.</p>
    </main>
</body>
</html>
