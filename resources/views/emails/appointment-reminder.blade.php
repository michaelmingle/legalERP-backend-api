<!DOCTYPE html>
<html>
<head>
    <title>Appointment Reminder</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #8b5cf6; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9fafb; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .button { background: #8b5cf6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Appointment Reminder</h2>
        </div>
        <div class="content">
            <h3>Hello {{ $data['to_name'] }},</h3>
            
            <p>This is a reminder for your upcoming appointment.</p>
            
            <div class="info-box">
                <p><strong>Title:</strong> {{ $data['title'] }}</p>
                <p><strong>Case:</strong> {{ $data['case_name'] }}</p>
                <p><strong>Date:</strong> {{ $data['date'] }}</p>
                <p><strong>Time:</strong> {{ $data['time'] }}</p>
                @if(isset($data['location']))
                    <p><strong>Location:</strong> {{ $data['location'] }}</p>
                @endif
                @if(isset($data['meeting_link']))
                    <p><strong>Meeting Link:</strong> <a href="{{ $data['meeting_link'] }}">{{ $data['meeting_link'] }}</a></p>
                @endif
            </div>
            
            <p style="margin-top: 20px;">
                <a href="{{ config('app.frontend_url') }}/dashboard/appointments" class="button">View Appointment</a>
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Legal ERP System. All rights reserved.</p>
        </div>
    </div>
</body>
</html>