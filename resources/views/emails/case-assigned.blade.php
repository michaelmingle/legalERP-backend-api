<!DOCTYPE html>
<html>
<head>
    <title>Case Assigned</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #320DFF; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9fafb; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .button { background: #320DFF; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
        .info-box { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #320DFF; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Case Assignment Notification</h2>
        </div>
        <div class="content">
            <h3>Hello {{ $data['to_name'] }},</h3>
            
            <div class="info-box">
                <p><strong>Case Name:</strong> {{ $data['case_name'] }}</p>
                <p><strong>Case Number:</strong> {{ $data['case_number'] }}</p>
                @if(isset($data['assigned_lawyer']))
                    <p><strong>Assigned Lawyer:</strong> {{ $data['assigned_lawyer'] }}</p>
                @endif
                @if(isset($data['client_name']))
                    <p><strong>Client:</strong> {{ $data['client_name'] }}</p>
                @endif
                <p><strong>Role:</strong> {{ ucfirst($data['role']) }}</p>
            </div>
            
            <p>You can view the case details by logging into the system.</p>
            
            <p style="margin-top: 20px;">
                <a href="{{ config('app.frontend_url') }}/dashboard/cases" class="button">View Case</a>
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Legal ERP System. All rights reserved.</p>
            <p>This is an automated message, please do not reply.</p>
        </div>
    </div>
</body>
</html>