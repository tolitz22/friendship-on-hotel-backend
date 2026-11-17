<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class StaffInvitationNotification extends Notification
{
    public function __construct(
        private readonly string $token,
        private readonly string $hotelName,
        private readonly string $role,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Only the server-provided trusted URL is used; no request redirects.
        $url=(string) config('staff_auth.invite_url');
        $url.=(str_contains($url,'?')?'&':'?').http_build_query(
            ['token'=>$this->token], '', '&', PHP_QUERY_RFC3986
        );

        return (new MailMessage())
            ->subject('Invitation to Friendship On Hotel')
            ->greeting('You have been invited to '.$this->hotelName)
            ->line('Your hotel role will be: '.str_replace('_',' ',$this->role).'.')
            ->line('This invitation is valid for 48 hours, can only be used once, and requires you to set your own password.')
            ->action('Accept invitation', $url)
            ->line('If you did not expect this invitation, ignore this email. Never share the link.');
    }
}
