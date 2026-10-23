<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountInactivityDeletionWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $userName,
        public readonly int $inactivityMonths,
        public readonly int $graceDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'هشدار دربارهٔ حذف خودکار حساب پهناور کار');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-inactivity-deletion-warning');
    }
}
