<?php

declare(strict_types=1);

namespace Kraite\Core\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Kraite\Core\Enums\NotificationSeverity;

final class AlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public string $notificationTitle,
        public string $notificationMessage,
        public ?NotificationSeverity $severity = null,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
        public ?string $details = null,
        public ?string $hostname = null,
        public ?string $userName = null,
        public ?string $exchange = null,
        public ?string $serverIp = null
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        // Build subject line with optional hostname prefix and exchange context
        $subject = $this->notificationTitle;

        // Prefix hostname if enabled
        if (config('kraite.prefix_hostname_on_notifications', false)) {
            $hostname = $this->hostname ?? gethostname();
            $subject = "[{$hostname}] {$subject}";
        }

        // Add exchange context if provided
        if ($this->exchange) {
            $subject .= ' - '.ucfirst($this->exchange);
        }

        $envelope = new Envelope(
            subject: $subject,
        );

        // Set email priority based on severity level
        // Critical and High severity get high priority headers
        if ($this->severity && in_array($this->severity, [NotificationSeverity::Critical, NotificationSeverity::High])) {
            $envelope->using(static function (\Symfony\Component\Mime\Email $message) {
                $message->priority(\Symfony\Component\Mime\Email::PRIORITY_HIGH);
                $message->getHeaders()
                    ->addTextHeader('X-Priority', '1')
                    ->addTextHeader('Importance', 'high');
            });
        }

        return $envelope;
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'kraite::emails.notification',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
