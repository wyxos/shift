<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules;

class BootstrapUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shift:bootstrap-user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the first verified SHIFT user on an empty installation';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('A user already exists. This command only bootstraps an empty installation.');

            return self::FAILURE;
        }

        $attributes = [
            'name' => trim((string) $this->ask('Name')),
            'email' => strtolower(trim((string) $this->ask('Email address'))),
            'password' => (string) $this->secret('Password'),
            'password_confirmation' => (string) $this->secret('Confirm password'),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create($validator->safe()->only(['name', 'email', 'password']));
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->info('The initial SHIFT user was created and marked as verified.');

        return self::SUCCESS;
    }
}
