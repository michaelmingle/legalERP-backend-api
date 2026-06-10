<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function build()
    {
        $subject = $this->data['subject']
            ?? ('Your ' . config('app.name', 'Legal ERP') . ' login credentials');

        return $this->subject($subject)
                    ->view('emails.user-credentials')
                    ->with('data', $this->data);
    }
}
