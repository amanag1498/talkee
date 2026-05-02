<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewApplicationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $type,        // 'agency' | 'host' | 'enroll'
        public int    $requestId,
        public string $fromName,
        public string $fromEmail
    ) {}

    public function via($notifiable)
    {
        return ['database']; // store in DB
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'       => $this->type,
            'request_id' => $this->requestId,
            'from_name'  => $this->fromName,
            'from_email' => $this->fromEmail,
            'message'    => "New {$this->type} application from {$this->fromName}",
            'url'        => $this->reviewUrl(), // link to the review screen
        ];
    }

    private function reviewUrl(): string
    {
        return match ($this->type) {
            'agency' => route('admin.agency-requests.show', $this->requestId),
            'host'   => route('admin.host-requests.show',   $this->requestId),
            'enroll' => route('admin.enroll-requests.show', $this->requestId),
            default  => route('admin.dashboard'),
        };
    }
}
