<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accept workspace invitation</title>
</head>
<body style="margin:0;padding:32px;background:#f4f5f7;font-family:Arial,sans-serif;color:#20242a;">
    <main style="max-width:560px;margin:0 auto;padding:32px;background:#fff;border-radius:12px;">
        <h1 style="font-size:22px;margin-top:0;">Accept workspace invitation</h1>
        <p>You are signed in as <strong>{{ $membership->user->email }}</strong>.</p>
        <p>Join <strong>{{ $membership->tenant->name }}</strong> with the <strong>{{ $membership->role }}</strong> role?</p>
        <form method="POST" action="{{ route('tenants.memberships.accept', ['tenantId' => $membership->tenant_id]) }}">
            @csrf
            <button type="submit" style="padding:12px 20px;background:#20242a;color:#fff;border:0;border-radius:6px;cursor:pointer;">Accept invitation</button>
        </form>
    </main>
</body>
</html>
