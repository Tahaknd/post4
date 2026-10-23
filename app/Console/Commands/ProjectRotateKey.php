<?php

namespace App\Console\Commands;

use App\Domain\Capture\ProjectResolver;
use App\Models\Project;
use Illuminate\Console\Command;

class ProjectRotateKey extends Command
{
    protected $signature = 'project:rotate-key {id : Proje kimliği} {--key-only : Sadece yeni API anahtarını yazdır}';

    protected $description = 'Bir projenin API anahtarını yeniler ve eski anahtarın önbellek girdisini siler';

    public function handle(ProjectResolver $resolver): int
    {
        $id = (string) $this->argument('id');
        // Anything else (or a value beyond bigint) would reach Postgres as a query error.
        if (! ctype_digit($id) || strlen($id) > 18) {
            $this->getOutput()->getErrorStyle()->error('Geçersiz proje kimliği.');

            return self::FAILURE;
        }

        $project = Project::find($id);
        if ($project === null) {
            $this->getOutput()->getErrorStyle()->error('Proje bulunamadı.');

            return self::FAILURE;
        }

        $oldKey = $project->api_key;
        $project->api_key = Project::generateApiKey();
        $project->save();

        // The new key is already saved: print it first so a failing cache can never hide it.
        $this->option('key-only')
            ? $this->line($project->api_key)
            : $this->info("Proje #{$project->id} için yeni API anahtarı: {$project->api_key}");

        // Without this the cached entry would keep authorising the old key for up to the cache TTL.
        try {
            $resolver->forget($oldKey);
        } catch (\Throwable $e) {
            // stderr keeps `--key-only` stdout to just the key.
            $this->getOutput()->getErrorStyle()->warning('Eski anahtarın önbellek girdisi silinemedi; en fazla '.$this->ttlText().' daha geçerli kalabilir: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    /** The resolver's positive TTL as "N dakika", "N saniye" or "N dakika M saniye". */
    private function ttlText(): string
    {
        $minutes = intdiv(ProjectResolver::POSITIVE_TTL_SECONDS, 60);
        $seconds = ProjectResolver::POSITIVE_TTL_SECONDS % 60;

        return trim(($minutes > 0 ? "{$minutes} dakika " : '').($seconds > 0 ? "{$seconds} saniye" : ''));
    }
}
