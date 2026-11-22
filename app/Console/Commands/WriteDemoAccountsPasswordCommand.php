<?php

namespace App\Console\Commands;

use App\Support\Operations\DemoAccountsPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class WriteDemoAccountsPasswordCommand extends Command
{
    protected $signature = 'demo:accounts-password
                            {--output= : Absolute path to write the demo accounts password (required)}';

    protected $description = 'Write the local demo accounts password to a file (never prints the secret)';

    public function handle(DemoAccountsPassword $demoAccountsPassword): int
    {
        if (app()->isProduction()) {
            $this->error('demo:accounts-password refuses to run in production.');

            return self::FAILURE;
        }

        $outputPath = trim((string) $this->option('output'));
        if ($outputPath === '') {
            $this->error('The --output option is required.');

            return self::FAILURE;
        }

        $directory = dirname($outputPath);
        if ($directory === '' || $directory === '.' || ! File::isDirectory($directory)) {
            $this->error('The --output directory does not exist.');

            return self::FAILURE;
        }

        try {
            $password = $demoAccountsPassword->resolve();
        } catch (Throwable $exception) {
            $this->error('Could not resolve the demo accounts password: '.$exception->getMessage());

            return self::FAILURE;
        }

        $written = File::put($outputPath, $password."\n");
        if ($written === false) {
            $this->error('Could not write the demo accounts password file.');

            return self::FAILURE;
        }

        $this->info('Demo accounts password written.');
        $this->line('Output: '.$outputPath);

        return self::SUCCESS;
    }
}
