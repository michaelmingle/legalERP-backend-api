<!DOCTYPE html>
<html>
<head>
    <title>Document Uploaded</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #10b981; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9fafb; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .button { background: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Document Uploaded</h2>
        </div>
        <div class="content">
            <h3>Hello {{ $data['to_name'] }},</h3>
            
            <p>A new document has been uploaded to case <strong>{{ $data['case_name'] }}</strong></p>
            
            <div class="info-box">
                <p><strong>Document Name:</strong> {{ $data['document_name'] }}</p>
                <p><strong>Uploaded By:</strong> {{ $data['uploaded_by'] }}</p>
                <p><strong>Case:</strong> {{ $data['case_name'] }} ({{ $data['case_number'] }})</p>
            </div>
            
            <p style="margin-top: 20px;">
                <a href="{{ config('app.frontend_url') }}/dashboard/documents" class="button">View Document</a>
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Legal ERP System. All rights reserved.</p>
        </div>
    </div>
</body>
</html>