<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class ProjectCreate extends Command
{
    protected $signature = 'project:create {name} {--key-only : Sadece API anahtarını yazdır}';

    protected $description = 'Yeni bir proje ve API anahtarı oluşturur';

    public function handle(): int
    {
        $project = Project::create([
            'name' => $this->argument('name'),
            'api_key' => Project::generateApiKey(),
        ]);

        $this->option('key-only')
            ? $this->line($project->api_key)
            : $this->info("Proje #{$project->id} oluşturuldu. API anahtarı: {$project->api_key}");

        return self::SUCCESS;
    }
}
