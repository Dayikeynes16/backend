<?php

namespace App\Console\Commands;

use App\Models\ExpenseAttachment;
use App\Services\ExpenseAttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Diagnóstico del storage de adjuntos de gastos. Útil cuando algo no se
 * guarda o no se puede previsualizar en producción.
 *
 * Uso: php artisan gastos:diagnose
 */
class GastosDiagnoseCommand extends Command
{
    protected $signature = 'gastos:diagnose';

    protected $description = 'Diagnostica config + write/read del disk de adjuntos de gastos';

    public function handle(): int
    {
        $this->section('1. Variables de entorno y config cargada');
        $envDisk = env('EXPENSES_DISK');
        $cfgDisk = config('expenses.disk');
        $defaultDisk = config('filesystems.default');
        $envFs = env('FILESYSTEM_DISK');

        $this->kv('EXPENSES_DISK (env)', $envDisk ?: '(vacío)');
        $this->kv('expenses.disk (config)', $cfgDisk ?: '(vacío)');
        $this->kv('FILESYSTEM_DISK (env)', $envFs ?: '(vacío)');
        $this->kv('filesystems.default', $defaultDisk);

        if ($envDisk && $envDisk !== $cfgDisk) {
            $this->warn('   ⚠ La env EXPENSES_DISK no coincide con la config cargada — el config:cache puede estar viejo.');
            $this->warn('     Solución: php artisan optimize:clear');
        }

        $this->section('2. Disks registrados en filesystems.disks');
        $disks = array_keys(config('filesystems.disks'));
        $this->line('   '.implode(', ', $disks));

        $this->section('3. Disk efectivo que usa el módulo de gastos');
        $expensesDisk = ExpenseAttachmentService::disk();
        $this->kv('Disk', $expensesDisk);

        $diskConfig = config("filesystems.disks.$expensesDisk");
        if (! $diskConfig) {
            $this->error("   ❌ El disk '$expensesDisk' NO existe en filesystems.disks.");
            $this->error('     Esto significa que Storage::disk() va a fallar al intentar usarlo.');

            return self::FAILURE;
        }
        $this->kv('  driver', $diskConfig['driver'] ?? '(?)');
        $this->kv('  bucket', $diskConfig['bucket'] ?? '(local — sin bucket)');
        $this->kv('  endpoint', $diskConfig['endpoint'] ?? '(none)');
        $this->kv('  visibility', $diskConfig['visibility'] ?? '(default)');
        $key = $diskConfig['key'] ?? null;
        $this->kv('  access_key_id', $key ? substr($key, 0, 6).'…' : '(none)');

        $this->section('4. Prueba write / read / delete contra el disk');
        $testPath = '_diagnostic_/'.now()->format('Ymd_His').'.txt';
        $testContent = 'gastos:diagnose '.now()->toIso8601String();
        try {
            Storage::disk($expensesDisk)->put($testPath, $testContent, 'private');
            $this->info("   ✅ Write OK: $testPath");
        } catch (\Throwable $e) {
            $this->error('   ❌ WRITE FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $exists = Storage::disk($expensesDisk)->exists($testPath);
            $this->kv('   exists', $exists ? '✅ YES' : '❌ NO');
        } catch (\Throwable $e) {
            $this->error('   ❌ EXISTS FAILED: '.$e->getMessage());
        }

        try {
            $read = Storage::disk($expensesDisk)->get($testPath);
            $matches = $read === $testContent;
            $this->kv('   read content matches', $matches ? '✅ YES' : '❌ NO');
            if (! $matches) {
                $this->warn('     Recibido: '.substr((string) $read, 0, 80));
            }
        } catch (\Throwable $e) {
            $this->error('   ❌ READ FAILED: '.$e->getMessage());
        }

        try {
            Storage::disk($expensesDisk)->delete($testPath);
            $this->info('   ✅ Delete OK');
        } catch (\Throwable $e) {
            $this->error('   ❌ DELETE FAILED: '.$e->getMessage());
        }

        $this->section('5. Último adjunto en BD');
        $att = ExpenseAttachment::latest('id')->first();
        if (! $att) {
            $this->line('   (sin adjuntos en la BD aún)');

            return self::SUCCESS;
        }

        $this->kv('id', $att->id);
        $this->kv('expense_id', $att->expense_id);
        $this->kv('tenant_id', $att->tenant_id);
        $this->kv('path', $att->path);
        $this->kv('mime', $att->mime_type);
        $this->kv('size', number_format($att->size_bytes).' bytes');
        $this->kv('created_at', (string) $att->created_at);

        $this->section('6. ¿Existe el archivo del último adjunto?');
        foreach (['private', 'public', 'local', 's3', 's3_private'] as $d) {
            if (! array_key_exists($d, config('filesystems.disks'))) {
                continue;
            }
            try {
                $found = Storage::disk($d)->exists($att->path);
                $marker = $d === $expensesDisk ? ' ← disk configurado' : '';
                $this->kv("  [$d]", ($found ? '✅ EXISTE' : '❌ no existe').$marker);
            } catch (\Throwable $e) {
                $this->kv("  [$d]", '⚠ error: '.$e->getMessage());
            }
        }

        $this->newLine();
        $this->line('Diagnóstico terminado. Si "EXISTE" no aparece en el disk configurado:');
        $this->line('  - Si está en otro disk → config:cache vieja, corre: php artisan optimize:clear');
        $this->line('  - Si no está en ninguno → upload falló o storage efímero (local) en multi-instancia.');

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('─── '.$title.' ───');
    }

    private function kv(string $k, string $v): void
    {
        $this->line('   '.str_pad($k, 28, '.').' '.$v);
    }
}
