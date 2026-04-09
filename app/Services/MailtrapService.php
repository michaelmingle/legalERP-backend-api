<?php
// app/Services/MailtrapService.php

namespace App\Services;

use Mailtrap\MailtrapClient;
use Mailtrap\Helper\ResponseHelper;
use Mailtrap\EmailHeader\CategoryHeader;
use Mailtrap\EmailHeader\CustomVariableHeader;
use Mailtrap\EmailHeader\UnsubscribeHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Illuminate\Support\Facades\Log;

class MailtrapService
{
    protected $client;
    protected $fromEmail;
    protected $fromName;

    public function __construct()
    {
        $this->client = MailtrapClient::initSendingEmails(
            apiKey: env('MAILTRAP_API_TOKEN')
        );
        
        $this->fromEmail = env('MAIL_FROM_ADDRESS', 'noreply@legalerp.com');
        $this->fromName = env('MAIL_FROM_NAME', 'Legal ERP System');
    }

    public function sendCaseNotification($toEmail, $toName, $caseData, $role)
    {
        try {
            $email = (new Email())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to(new Address($toEmail, $toName))
                ->subject($role === 'client' ? "New Case Assigned: {$caseData['case_name']}" : "New Case Assignment: {$caseData['case_name']}")
                ->html($this->buildEmailBody($caseData, $role))
                ->text($this->buildEmailText($caseData, $role));

            // Add custom headers
            $email->getHeaders()
                ->add(new CategoryHeader('Case Assignment'))
                ->add(new CustomVariableHeader('case_id', $caseData['case_id']))
                ->add(new CustomVariableHeader('case_number', $caseData['case_number']));

            $response = $this->client->send($email);
            
            Log::info("Mailtrap email sent to: {$toEmail}", [
                'case_id' => $caseData['case_id'],
                'role' => $role
            ]);
            
            return ResponseHelper::toArray($response);
            
        } catch (\Exception $e) {
            Log::error("Mailtrap email failed: " . $e->getMessage());
            throw $e;
        }
    }

    public function sendTestEmail($toEmail, $toName = 'Test User')
    {
        try {
            $email = (new Email())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to(new Address($toEmail, $toName))
                ->subject('Test Email from Legal ERP System')
                ->html('<h1>Hello ' . htmlspecialchars($toName) . '!</h1>
                        <p>This is a test email from your Legal ERP System.</p>
                        <p>Your email configuration is working correctly!</p>
                        <p>Best regards,<br>Legal ERP Team</p>')
                ->text("Hello {$toName}!\n\nThis is a test email from your Legal ERP System.\n\nYour email configuration is working correctly!\n\nBest regards,\nLegal ERP Team");

            $email->getHeaders()->add(new CategoryHeader('Test Email'));

            $response = $this->client->send($email);
            
            Log::info("Test email sent to: {$toEmail}");
            
            return ResponseHelper::toArray($response);
            
        } catch (\Exception $e) {
            Log::error("Test email failed: " . $e->getMessage());
            throw $e;
        }
    }

    private function buildEmailBody($caseData, $role)
    {
        if ($role === 'client') {
            return "
                <!DOCTYPE html>
                <html>
                <head>
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
                    <div class='container'>
                        <div class='header'>
                            <h2>Legal ERP System</h2>
                        </div>
                        <div class='content'>
                            <h3>Hello {$caseData['client_name']},</h3>
                            <p>A new case has been assigned to you:</p>
                            <ul>
                                <li><strong>Case Number:</strong> {$caseData['case_number']}</li>
                                <li><strong>Case Name:</strong> {$caseData['case_name']}</li>
                                <li><strong>Assigned Lawyer:</strong> {$caseData['assigned_lawyer']}</li>
                            </ul>
                            <p>Please log in to the system for more details.</p>
                            <a href='" . url('/login') . "' class='button'>Login to Dashboard</a>
                        </div>
                        <div class='footer'>
                            <p>This is an automated message. Please do not reply.</p>
                        </div>
                    </div>
                </body>
                </html>
            ";
        } else {
            return "
                <!DOCTYPE html>
                <html>
                <head>
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
                    <div class='container'>
                        <div class='header'>
                            <h2>Legal ERP System</h2>
                        </div>
                        <div class='content'>
                            <h3>Hello {$caseData['lawyer_name']},</h3>
                            <p>You have been assigned as the lawyer for a new case:</p>
                            <ul>
                                <li><strong>Case Number:</strong> {$caseData['case_number']}</li>
                                <li><strong>Case Name:</strong> {$caseData['case_name']}</li>
                                <li><strong>Client:</strong> {$caseData['client_name']}</li>
                            </ul>
                            <p>Please log in to the system for more details.</p>
                            <a href='" . url('/login') . "' class='button'>Login to Dashboard</a>
                        </div>
                        <div class='footer'>
                            <p>This is an automated message. Please do not reply.</p>
                        </div>
                    </div>
                </body>
                </html>
            ";
        }
    }

    private function buildEmailText($caseData, $role)
    {
        if ($role === 'client') {
            return "Hello {$caseData['client_name']},\n\n"
                . "A new case has been assigned to you:\n\n"
                . "Case Number: {$caseData['case_number']}\n"
                . "Case Name: {$caseData['case_name']}\n"
                . "Assigned Lawyer: {$caseData['assigned_lawyer']}\n\n"
                . "Please log in to the system for more details.\n\n"
                . "Best regards,\nLegal ERP Team";
        } else {
            return "Hello {$caseData['lawyer_name']},\n\n"
                . "You have been assigned as the lawyer for a new case:\n\n"
                . "Case Number: {$caseData['case_number']}\n"
                . "Case Name: {$caseData['case_name']}\n"
                . "Client: {$caseData['client_name']}\n\n"
                . "Please log in to the system for more details.\n\n"
                . "Best regards,\nLegal ERP Team";
        }
    }
}