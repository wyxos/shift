<?php

namespace App\Notifications;

use App\Models\Organisation;
use App\Models\OrganisationUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class OrganisationInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The organisation user instance.
     *
     * @var \App\Models\OrganisationUser
     */
    protected $organisationUser;

    /**
     * The organisation instance.
     *
     * @var \App\Models\Organisation
     */
    protected $organisation;

    /**
     * Create a new notification instance.
     */
    public function __construct(OrganisationUser $organisationUser, Organisation $organisation)
    {
        $this->organisationUser = $organisationUser;
        $this->organisation = $organisation;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::signedRoute('register', [
            'email' => $this->organisationUser->user_email,
            'name' => $this->organisationUser->user_name,
            'organisation_id' => $this->organisation->id,
        ]);

        return (new MailMessage)
            ->subject('You have been invited to join an organisation')
            ->line('You have been invited to join the organisation: ' . $this->organisation->name)
            ->action('Register to join', $url)
            ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'organisation_id' => $this->organisation->id,
            'organisation_name' => $this->organisation->name,
        ];
    }
}
