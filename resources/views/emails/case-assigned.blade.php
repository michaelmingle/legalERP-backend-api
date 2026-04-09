<!DOCTYPE html>
<html>
<head>
    <title>Case Assignment Notification</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #320DFF, #5B2EFF); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
        .content { background: #f9fafb; padding: 30px; border-radius: 0 0 10px 10px; border: 1px solid #e5e7eb; border-top: none; }
        .button { display: inline-block; padding: 10px 20px; background: #320DFF; color: white; text-decoration: none; border-radius: 8px; margin-top: 20px; }
        .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Legal ERP System</h2>
        </div>
        
        <div class="content">
            <h3>Hello {{ $data['to_name'] }},</h3>
            
            @if($data['role'] === 'client')
                <p>A new case has been assigned to you:</p>
                <ul>
                    <li><strong>Case Number:</strong> {{ $data['case_number'] }}</li>
                    <li><strong>Case Name:</strong> {{ $data['case_name'] }}</li>
                    <li><strong>Assigned Lawyer:</strong> {{ $data['assigned_lawyer'] }}</li>
                </ul>
            @else
                <p>You have been assigned as the lawyer for a new case:</p>
                <ul>
                    <li><strong>Case Number:</strong> {{ $data['case_number'] }}</li>
                    <li><strong>Case Name:</strong> {{ $data['case_name'] }}</li>
                    <li><strong>Client:</strong> {{ $data['client_name'] }}</li>
                </ul>
            @endif
            
            <p>Please log in to the system for more details and to start working on this case.</p>
            
            <a href="{{ url('/login') }}" class="button">Login to Dashboard</a>
        </div>
        
        <div class="footer">
            <p>This is an automated message from Legal ERP System. Please do not reply to this email.</p>
            <p>&copy; {{ date('Y') }} Legal ERP. All rights reserved.</p>
        </div>
    </div>
</body>
</html>