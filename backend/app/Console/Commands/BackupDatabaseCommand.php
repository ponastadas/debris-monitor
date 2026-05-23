<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'db:backup
                            {--keep=7 : Number of recent backups to retain (others are deleted)}';

    protected $description = 'Dump the database to storage/app/backups/ and prune old backups';

    public function handle(): int
    {
        $dir = storage_path('app/backups');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $cfg = config('database.connections.mysql');
        $filename = 'db_backup_'.now()->format('Y-m-d_H-i-s').'.sql.gz';
        $path = $dir.'/'.$filename;

        // Two-step: dump then compress, so each has its own exit code.
        // --skip-ssl: MySQL 8.0 generates self-signed certs that MariaDB client rejects.
        // Stderr goes to a separate file so we can show the real error on failure.
        $tmpPath = $path.'.tmp';
        $errPath = $path.'.err';

        $dumpCmd = sprintf(
            'mysqldump --skip-ssl -h %s -P %s -u %s --password=%s %s > %s 2> %s',
            escapeshellarg($cfg['host']),
            escapeshellarg((string) $cfg['port']),
            escapeshellarg($cfg['username']),
            escapeshellarg($cfg['password']),
            escapeshellarg($cfg['database']),
            escapeshellarg($tmpPath),
            escapeshellarg($errPath)
        );

        exec($dumpCmd, $ignored, $dumpExit);

        $dumpStderr = file_exists($errPath) ? trim(file_get_contents($errPath)) : '';
        @unlink($errPath);

        if ($dumpExit !== 0 || ! file_exists($tmpPath) || filesize($tmpPath) === 0) {
            $this->error('Backup failed: '.($dumpStderr ?: 'mysqldump exited with code '.$dumpExit));
            @unlink($tmpPath);

            return self::FAILURE;
        }

        exec('gzip -9 -c '.escapeshellarg($tmpPath).' > '.escapeshellarg($path).' 2>&1', $gzipOutput, $gzipExit);
        unlink($tmpPath);

        if ($gzipExit !== 0 || ! file_exists($path) || filesize($path) === 0) {
            $this->error('Backup failed (compress): '.implode("\n", $gzipOutput));
            @unlink($path);

            return self::FAILURE;
        }

        $size = round(filesize($path) / 1024, 1);
        $this->info("Backup saved: {$filename} ({$size} KB)");

        $this->pruneOldBackups($dir, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    private function pruneOldBackups(string $dir, int $keep): void
    {
        $files = glob($dir.'/db_backup_*.sql.gz') ?: [];
        rsort($files); // newest first (lexicographic sort works because of Y-m-d_H-i-s format)

        $stale = array_slice($files, $keep);

        foreach ($stale as $file) {
            unlink($file);
            $this->line('Pruned: '.basename($file));
        }
    }
}
