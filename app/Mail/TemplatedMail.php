<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

/** A message already rendered from its template (MailTemplates::render). */
class TemplatedMail extends Mailable
{
    public function __construct(private readonly string $mailSubject, private readonly string $textBody, private readonly string $htmlBody, private readonly ?string $unsubscribe = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }

    public function build(): static
    {
        return $this->withSymfonyMessage(function (Email $message) {
            $message->text($this->textBody);

            // Mail clients show an "unsubscribe" control from this header and
            // open the link, which is a page with a button (no one-click POST,
            // which would need an exemption from the CSRF check).
            if ($this->unsubscribe !== null) {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribe.'>');
            }
        });
    }
}
