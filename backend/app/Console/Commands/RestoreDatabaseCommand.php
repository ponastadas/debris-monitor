<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RestoreDatabaseCommand extends Command
{
    protected $signature = 'db:restore
                            {file? : Backup filename (basename only) — omit to list available backups}';

    protected $description = 'Restore the database from a backup in storage/app/backups/';

    public function handle(): int
    {
        $dir = storage_path('app/backups');
        $files = glob($dir.'/db_backup_*.sql.gz') ?: [];
        rsort($files);

        if (! $this->argument('file')) {
            if (! $files) {
                $this->warn('No backups found in '.$dir);

                return self::FAILURE;
            }
            $this->info('Available backups (newest first):');
            foreach ($files as $f) {
                $size = round(filesize($f) / 1024, 1);
                $this->line('  '.basename($f)." ({$size} KB)");
            }
            $this->newLine();
            $this->line('Run:  php artisan db:restore <filename>');

            return self::SUCCESS;
        }

        $path = $dir.'/'.basename($this->argument('file'));

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        if (! $this->confirm('Restore from '.basename($path).'? This will overwrite the current database.')) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $cfg = config('database.connections.mysql');

        $tmpPath = $path.'.tmp';

        exec('gunzip -c '.escapeshellarg($path).' > '.escapeshellarg($tmpPath).' 2>&1', $gunzipOutput, $gunzipExit);

        if ($gunzipExit !== 0 || ! file_exists($tmpPath)) {
            $this->error('Restore failed (decompress): '.implode("\n", $gunzipOutput));
            @unlink($tmpPath);

            return self::FAILURE;
        }

        $restoreCmd = sprintf(
            'mysql --skip-ssl -h %s -P %s -u %s --password=%s %s < %s 2>&1',
            escapeshellarg($cfg['host']),
            escapeshellarg((string) $cfg['port']),
            escapeshellarg($cfg['username']),
            escapeshellarg($cfg['password']),
            escapeshellarg($cfg['database']),
            escapeshellarg($tmpPath)
        );

        exec($restoreCmd, $output, $exit);
        unlink($tmpPath);

        if ($exit !== 0) {
            $this->error('Restore failed: '.implode("\n", $output));

            return self::FAILURE;
        }

        $this->info('Restore complete from '.basename($path));

        return self::SUCCESS;
    }
}
