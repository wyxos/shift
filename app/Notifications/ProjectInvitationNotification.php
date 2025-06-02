<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\ProjectUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class ProjectInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The project user instance.
     *
     * @var \App\Models\ProjectUser
     */
    protected $projectUser;

    /**
     * The project instance.
     *
     * @var \App\Models\Project
     */
    protected $project;

    /**
     * Create a new notification instance.
     */
    public function __construct(ProjectUser $projectUser, Project $project)
    {
        $this->projectUser = $projectUser;
        $this->project = $project;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::signedRoute('register', [
            'email' => $this->projectUser->user_email,
            'name' => $this->projectUser->user_name,
            'project_id' => $this->project->id,
        ]);

        return (new MailMessage)
            ->subject('You have been invited to join a project')
            ->line('You have been invited to join the project: ' . $this->project->name)
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
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
        ];
    }
}
