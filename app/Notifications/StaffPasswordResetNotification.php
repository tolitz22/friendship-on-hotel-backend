<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class StaffPasswordResetNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = (string) config('staff_auth.reset_url');
        $separator = str_contains($base, '?') ? '&' : '?';
        $url = $base.$separator.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);

        return (new MailMessage())
            ->subject('Reset your Friendship On Hotel staff password')
            ->greeting('Hello, '.$notifiable->name)
            ->line('We received a request to reset the password for your staff account.')
            ->action('Reset password', $url)
            ->line('This link expires in 60 minutes. If you did not request this, you can ignore this email.')
            ->line('Do not share this password reset link with anyone.');
    }
}
