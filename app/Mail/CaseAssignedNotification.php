<?php
// app/Mail/CaseAssignedNotification.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CaseAssignedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function build()
    {
        $subject = $this->data['role'] === 'client' 
            ? "New Case Assigned: {$this->data['case_name']}"
            : "New Case Assignment: {$this->data['case_name']}";

        return $this->subject($subject)
                    ->view('emails.case-assigned')
                    ->with('data', $this->data);
    }
}