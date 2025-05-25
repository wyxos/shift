<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SparkpostTest extends Command
{
    protected $signature = 'sparkpost:test';
    protected $description = 'Send a test email using current SMTP configuration';

    public function handle(): int
    {
        $to = $this->ask('Enter recipient email address');

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address.');
            return self::FAILURE;
        }

        $subject = 'SparkPost Test Email';
        $body = 'This is a test email sent using your current SparkPost SMTP configuration.';

        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });

            $this->info("Test email sent successfully to: $to");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to send email: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
