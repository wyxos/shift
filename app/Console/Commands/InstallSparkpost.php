<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallSparkpost extends Command
{
    protected $signature = 'install:sparkpost';
    protected $description = 'Interactively set up SparkPost SMTP in .env';

    public function handle(): int
    {
        $this->info('Configuring SparkPost SMTP...');

        $appUrl = config('app.url');
        $domain = parse_url($appUrl, PHP_URL_HOST) ?? 'example.com';
        $defaultFrom = "hello@$domain";

        $smtpUser = $this->ask('SMTP username', 'SMTP_Injection');
        $smtpPass = $this->secret('SMTP password (API key)');
        $from     = $this->ask('MAIL_FROM_ADDRESS', $defaultFrom);
        $fromName = $this->ask('MAIL_FROM_NAME', config('app.name'));

        $this->updateEnv([
            'MAIL_MAILER'       => 'smtp',
            'MAIL_HOST'         => 'smtp.sparkpostmail.com',
            'MAIL_PORT'         => '587',
            'MAIL_ENCRYPTION'   => 'tls',
            'MAIL_USERNAME'     => $smtpUser,
            'MAIL_PASSWORD'     => $smtpPass,
            'MAIL_FROM_ADDRESS' => $from,
            'MAIL_FROM_NAME'    => $fromName,
        ]);

        $this->info('SparkPost configuration added to .env');

        // do you want to send a test?
        if ($this->confirm('Do you want to send a test email now?', true)) {
            // run sparkpost:test
            $this->call('sparkpost:test');
        }

        return self::SUCCESS;
    }

    protected function updateEnv(array $data): void
    {
        $envPath = base_path('.env');
        $env = file_get_contents($envPath);

        foreach ($data as $key => $value) {
            $line = "$key=" . $this->escape($value);
            if (preg_match("/^$key=.*$/m", $env)) {
                $env = preg_replace("/^$key=.*$/m", $line, $env);
            } else {
                $env .= PHP_EOL . $line;
            }
        }

        file_put_contents($envPath, $env);
    }

    protected function escape(string $value): string
    {
        return Str::contains($value, [' ', '#', '"']) ? '"' . addslashes($value) . '"' : $value;
    }

}
