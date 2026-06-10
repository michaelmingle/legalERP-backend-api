<!DOCTYPE html>
<html>
<head>
    <title>{{ $data['heading'] ?? 'Your account credentials' }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #320DFF; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { padding: 20px; background: #f9fafb; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .button { display: inline-block; background: #320DFF; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; margin: 20px 0; }
        .info-box { background: white; padding: 16px; border-radius: 8px; margin: 16px 0; border-left: 4px solid #320DFF; }
        .credential { font-family: 'Courier New', monospace; background: #eef; padding: 8px 12px; border-radius: 6px; display: inline-block; }
        .warning { background: #fff8e1; border-left: 4px solid #f59e0b; padding: 10px 14px; border-radius: 6px; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin:0;">{{ $data['heading'] ?? 'Your account credentials' }}</h2>
        </div>
        <div class="content">
            <h3>Hello {{ $data['user_name'] ?? $data['client_name'] ?? 'there' }},</h3>

            <p>{{ $data['intro'] ?? 'An account has been created for you on ' . config('app.name', 'Legal ERP') . '. Use the credentials below to sign in.' }}</p>

            <div class="info-box">
                <p><strong>Email:</strong> <span class="credential">{{ $data['email'] }}</span></p>
                <p><strong>Password:</strong> <span class="credential">{{ $data['password'] ?? $data['new_password'] }}</span></p>
                @if(!empty($data['role']))
                    <p><strong>Role:</strong> {{ ucfirst($data['role']) }}</p>
                @endif
            </div>

            @if(!empty($data['login_url']))
                <p style="text-align:center;">
                    <a href="{{ $data['login_url'] }}" class="button">Sign in now</a>
                </p>
            @endif

            <p class="warning">
                For your security, please sign in and change this password as soon as possible.
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name', 'Legal ERP') }}. All rights reserved.</p>
            <p>This is an automated message, please do not reply.</p>
        </div>
    </div>
</body>
</html>
