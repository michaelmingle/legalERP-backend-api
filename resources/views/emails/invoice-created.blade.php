<!DOCTYPE html>
<html>
<head>
    <title>Invoice Created</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f59e0b; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9fafb; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .button { background: #f59e0b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>New Invoice Created</h2>
        </div>
        <div class="content">
            <h3>Hello {{ $data['to_name'] }},</h3>
            
            <p>A new invoice has been created.</p>
            
            <div class="info-box">
                <p><strong>Invoice Number:</strong> {{ $data['invoice_number'] }}</p>
                <p><strong>Amount:</strong> ${{ number_format($data['amount'], 2) }}</p>
                <p><strong>Due Date:</strong> {{ $data['due_date'] }}</p>
                <p><strong>Case:</strong> {{ $data['case_name'] }}</p>
            </div>
            
            <p style="margin-top: 20px;">
                <a href="{{ config('app.frontend_url') }}/dashboard/invoices" class="button">View Invoice</a>
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Legal ERP System. All rights reserved.</p>
        </div>
    </div>
</body>
</html>