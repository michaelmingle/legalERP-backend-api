{{-- resources/views/emails/team-invite.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <title>Team Invitation</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #320DFF; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9fafb; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .button { 
            display: inline-block; 
            background: #320DFF; 
            color: white; 
            padding: 12px 24px; 
            text-decoration: none; 
            border-radius: 8px; 
            margin: 20px 0;
        }
        .info-box { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #320DFF; }
        .warning { color: #e67e22; font-size: 12px; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Team Invitation</h2>
        </div>
        <div class="content">
            <h3>Hello!</h3>
            
            <p>You have been invited to join <strong>{{ $organization->name }}</strong> on <strong>Legal ERP System</strong>.</p>
            
            <div class="info-box">
                <p><strong>Organization:</strong> {{ $organization->name }}</p>
                <p><strong>Invited by:</strong> The organization owner</p>
                <p><strong>Invite expires:</strong> {{ $invite->expires_at ? $invite->expires_at->format('F j, Y') : 'Never' }}</p>
            </div>
            
            <p>Click the button below to accept the invitation and join the organization:</p>
            
            <center>
                <a href="{{ $acceptUrl }}" class="button">Accept Invitation</a>
            </center>
            
            <p>If the button doesn't work, copy and paste this link into your browser:</p>
            <p style="word-break: break-all; background: #eee; padding: 10px; border-radius: 5px; font-size: 12px;">
                {{ $acceptUrl }}
            </p>
            
            <p class="warning">
                <strong>Note:</strong> If you don't have an account yet, you will be prompted to create one after accepting the invitation.
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Legal ERP System. All rights reserved.</p>
            <p>This is an automated message, please do not reply.</p>
        </div>
    </div>
</body>
</html>