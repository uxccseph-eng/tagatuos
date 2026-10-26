<?php

namespace App\Console\Commands;

use App\Services\RegistrationApprovalService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('system:appoint-owner {email : Existing registered email} {--username= : Optional owner username}')]
#[Description('Designate the existing account that owns the system without changing its password or company memberships')]
class AppointSystemOwner extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RegistrationApprovalService $approval): int
    {
        try {
            $owner = $approval->appointOwner($this->argument('email'), $this->option('username'));
        } catch (ValidationException $exception) {
            $this->error(implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        }
        $this->info('System-owner access enabled for '.$owner->email.'. Existing password and company memberships are unchanged.');
        $this->info('Dashboard: '.route('system.dashboard'));

        return self::SUCCESS;
    }
}
