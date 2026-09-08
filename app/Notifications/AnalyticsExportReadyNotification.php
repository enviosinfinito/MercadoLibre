<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AnalyticsExportReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $exportRunId,
        public readonly string $scheduleName,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Analytics export scheduled: '.$this->scheduleName)
            ->line('Your scheduled analytics export "'.$this->scheduleName.'" was queued.')
            ->line('Export run #'.$this->exportRunId.' will be available in Exports when ready.')
            ->action('Open exports', url('/exports'));
    }
}
