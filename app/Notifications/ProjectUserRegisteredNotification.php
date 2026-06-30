<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectUserRegisteredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The user who registered.
     *
     * @var \App\Models\User
     */
    protected $registeredUser;

    /**
     * The project instance.
     *
     * @var \App\Models\Project
     */
    protected $project;

    /**
     * Create a new notification instance.
     */
    public function __construct(User $registeredUser, Project $project)
    {
        $this->registeredUser = $registeredUser;
        $this->project = $project;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $organisation = $this->project->accessOrganisation();
        $url = $organisation
            ? route('organisation.projects', $organisation)
            : route('dashboard');

        return (new MailMessage)
            ->subject('New User Registration in Your Project')
            ->line('A new user has completed registration for your project: '.$this->project->name)
            ->line('User: '.$this->registeredUser->name)
            ->line('Email: '.$this->registeredUser->email)
            ->action('View Project', $url)
            ->line('Please do not reply to this email directly.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $organisation = $this->project->accessOrganisation();

        return [
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'organisation_id' => $organisation?->id,
            'organisation_name' => $organisation?->name,
            'user_id' => $this->registeredUser->id,
            'user_name' => $this->registeredUser->name,
            'user_email' => $this->registeredUser->email,
        ];
    }
}
