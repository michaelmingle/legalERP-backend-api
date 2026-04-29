<?php
// app/Mail/TeamInviteMail.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\TeamInvite;
use App\Models\Organization;

class TeamInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invite;
    public $organization;
    public $acceptUrl;

    public function __construct(TeamInvite $invite, Organization $organization)
    {
        $this->invite = $invite;
        $this->organization = $organization;
        $this->acceptUrl = config('app.frontend_url') . '/invite/accept/' . $invite->token;
    }

    public function build()
    {
        return $this->subject('You\'ve been invited to join ' . $this->organization->name . ' on Legal ERP')
                    ->view('emails.team-invite')
                    ->with([
                        'invite' => $this->invite,
                        'organization' => $this->organization,
                        'acceptUrl' => $this->acceptUrl,
                    ]);
    }
}