<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Grant (or revoke) admin rights to a user by email. */
class MakeAdmin extends Command
{
    protected $signature = 'user:make-admin {email} {--revoke : Remove admin rights instead of granting them}';

    protected $description = 'Grant or revoke admin rights for a user (by email).';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('Utilisateur introuvable : '.$this->argument('email'));

            return self::FAILURE;
        }

        $user->is_admin = ! $this->option('revoke');
        $user->save();

        $this->info(($user->is_admin ? 'Admin accordé à ' : 'Admin retiré de ').$user->email);

        return self::SUCCESS;
    }
}
