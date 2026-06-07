<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New shop registered</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #f6f7fb; padding: 32px;">
    <div style="max-width: 560px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; border: 1px solid #e5e7eb;">
        <h2 style="margin: 0 0 8px; color: #111827;">New shop registered</h2>
        <p style="margin: 0 0 16px; color: #6b7280; font-size: 14px;">
            A new business just signed up on Vendex POS.
        </p>

        <h3 style="margin: 16px 0 4px; font-size: 14px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em;">Shop</h3>
        <table cellpadding="6" cellspacing="0" style="font-size: 14px; color: #111827; border-collapse: collapse; width: 100%;">
            <tr><td style="color:#6b7280; width: 140px;">Name</td><td><strong>{{ $shop->name }}</strong></td></tr>
            <tr><td style="color:#6b7280;">Email</td><td>{{ $shop->email }}</td></tr>
            @if($shop->phone)<tr><td style="color:#6b7280;">Phone</td><td>{{ $shop->phone }}</td></tr>@endif
            <tr><td style="color:#6b7280;">Store type</td><td>{{ ucfirst($shop->store_type) }}</td></tr>
            @if($shop->city || $shop->country)
                <tr><td style="color:#6b7280;">Location</td><td>{{ trim(($shop->city ?? '') . ', ' . ($shop->country ?? ''), ', ') }}</td></tr>
            @endif
            <tr><td style="color:#6b7280;">Created at</td><td>{{ $shop->created_at?->toDayDateTimeString() }}</td></tr>
        </table>

        <h3 style="margin: 24px 0 4px; font-size: 14px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em;">Admin</h3>
        <table cellpadding="6" cellspacing="0" style="font-size: 14px; color: #111827; border-collapse: collapse; width: 100%;">
            <tr><td style="color:#6b7280; width: 140px;">Name</td><td>{{ $admin->name }}</td></tr>
            <tr><td style="color:#6b7280;">Email</td><td>{{ $admin->email }}</td></tr>
            @if($admin->phone)<tr><td style="color:#6b7280;">Phone</td><td>{{ $admin->phone }}</td></tr>@endif
        </table>

        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;" />
        <p style="margin: 0; color: #6b7280; font-size: 12px;">
            Provision the shop's SMS sender ID from the Super Admin → Shops page so they can start sending SMS.
        </p>
    </div>
</body>
</html>
