<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class ProjectCreate extends Command
{
    protected $signature = 'project:create {name} {--key-only : Sadece API anahtarını yazdır} {--json : Tek satır JSON yazdır: {"id":N,"api_key":"phc_..."}}';

    protected $description = 'Yeni bir proje ve API anahtarı oluşturur';

    public function handle(): int
    {
        if ($this->option('json') && $this->option('key-only')) {
            $this->error('--json and --key-only cannot be used together.');

            return self::INVALID;
        }

        $project = Project::create([
            'name' => $this->argument('name'),
            'api_key' => Project::generateApiKey(),
        ]);

        if ($this->option('json')) {
            $this->line(json_encode(['id' => $project->id, 'api_key' => $project->api_key], JSON_THROW_ON_ERROR));
        } elseif ($this->option('key-only')) {
            $this->line($project->api_key);
        } else {
            $this->info("Proje #{$project->id} oluşturuldu. API anahtarı: {$project->api_key}");
        }

        return self::SUCCESS;
    }
}
