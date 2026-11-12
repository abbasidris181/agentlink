<?php

namespace App\Console\Commands;

use App\ChangeUserAccess;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class GrantAdministrator extends Command
{
    protected $signature = 'admin:grant {email : Existing account email} {--revoke : Remove super administrator access}';

    protected $description = 'Grant or revoke super administrator access for an existing account';

    public function handle(ChangeUserAccess $access): int
    {
        $user = User::query()->where('email', mb_strtolower(trim($this->argument('email'))))->first();
        if ($user === null) {
            $this->error('No existing account matches that email.');

            return self::FAILURE;
        }
        if (! $user->is_active) {
            $this->error('Activate this account before changing its administrator access.');

            return self::FAILURE;
        }
        try {
            $access->apply(null, $user->id, $this->option('revoke') ? 'users' : 'super_admin');
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info($this->option('revoke') ? 'Administrator access removed.' : 'Administrator access granted.');

        return self::SUCCESS;
    }
}
