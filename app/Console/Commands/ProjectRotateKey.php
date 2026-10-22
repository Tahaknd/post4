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
        $project = Project::find($this->argument('id'));
        if ($project === null) {
            $this->error('Proje bulunamadı.');

            return self::FAILURE;
        }

        $oldKey = $project->api_key;
        $project->api_key = Project::generateApiKey();
        $project->save();

        // Without this the cached entry would keep authorising the old key for up to the cache TTL.
        $resolver->forget($oldKey);

        $this->option('key-only')
            ? $this->line($project->api_key)
            : $this->info("Proje #{$project->id} için yeni API anahtarı: {$project->api_key}");

        return self::SUCCESS;
    }
}
