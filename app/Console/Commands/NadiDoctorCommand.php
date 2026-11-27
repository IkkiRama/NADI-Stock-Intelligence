<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NadiDoctorCommand extends Command
{
    protected $signature = 'nadi:doctor';

    protected $description = 'Verify NADI v1.0.0 shared-hosting & production readiness';

    public function handle(): int
    {
        $failed = false;

        $check = function (string $label, bool $ok, string $detail, bool $warnOnly = false) use (&$failed): void {
            if ($ok) {
                $this->line("[PASS] {$label}: {$detail}");
            } elseif ($warnOnly) {
                $this->warn("[WARN] {$label}: {$detail}");
            } else {
                $this->error("[FAIL] {$label}: {$detail}");
                $failed = true;
            }
        };

        $check('PHP Version', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);

        $requiredExts = ['bcmath', 'ctype', 'mbstring', 'openssl', 'pdo', 'xml'];
        $missingExts = array_values(array_filter($requiredExts, fn (string $ext): bool => ! extension_loaded($ext)));
        $check('PHP Extensions', empty($missingExts), empty($missingExts) ? implode(', ', $requiredExts) : 'Missing: '.implode(', ', $missingExts));

        $appKeyOk = trim((string) config('app.key')) !== '';
        $check('APP_KEY', $appKeyOk, $appKeyOk ? 'Configured' : 'Missing APP_KEY');

        $tzOk = config('app.timezone') === 'UTC';
        $check('APP_TIMEZONE', $tzOk, (string) config('app.timezone'));

        $version = (string) config('nadi.version');
        $check('NADI Version', $version === '1.0.0', $version);

        $plannedModules = config('nadi_modules', []);
        $check('Planned Modules Cleared', empty($plannedModules), empty($plannedModules) ? '0 pending placeholders' : count($plannedModules).' remaining');

        $manifestExists = is_file(public_path('build/manifest.json'));
        $hotAbsent = ! is_file(public_path('hot'));
        $check('Vite Build Manifest', $manifestExists, $manifestExists ? 'public/build/manifest.json present' : 'Run npm.cmd run build');
        $check('Vite Dev Hot File', $hotAbsent, $hotAbsent ? 'Absent (serving compiled assets)' : 'public/hot exists', true);

        $dbOk = false;
        $dbDetail = '';
        $migrationsReady = false;
        try {
            DB::connection()->getPdo();
            $dbOk = true;
            $driver = DB::connection()->getDriverName();
            $migrationsReady = Schema::hasTable('migrations');
            $count = $migrationsReady ? DB::table('migrations')->count() : 0;
            $dbDetail = "{$driver} connected ({$count} migrations)";
        } catch (\Throwable $e) {
            $dbDetail = $e->getMessage();
        }
        $check('Database Connectivity', $dbOk && $migrationsReady, $dbDetail);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
