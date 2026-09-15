<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public ?string $name = null,
        public int $expiryMinutes = 10,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Como Italy — رمز التحقق / Verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlTemplate(),
        );
    }

    private function htmlTemplate(): string
    {
        $template = file_get_contents(resource_path('views/emails/otp.html'));

        return str_replace(
            ['%%CODE%%', '%%NAME%%', '%%EXPIRY_MINUTES%%', '%%YEAR%%'],
            [
                htmlspecialchars($this->code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($this->name ?: '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                (string) $this->expiryMinutes,
                date('Y'),
            ],
            $template,
        );
    }
}
