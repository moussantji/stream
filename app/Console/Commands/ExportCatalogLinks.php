<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Services\Catalog\CatalogExporter;
use App\Services\MovieBox\SubjectType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Full, unattended export of every persisted title + its download links to a
 * .txt file under storage/app/exports/. Resolving links hits the API for each
 * title, so this can take a while for large catalogs — run it from the CLI.
 */
class ExportCatalogLinks extends Command
{
    protected $signature = 'catalog:export-links
        {--type=all : all|movies|tv-series|animation}
        {--limit=0 : Max titles (0 = no limit)}';

    protected $description = 'Export all films/séries and their download links to a .txt file.';

    public function handle(CatalogExporter $exporter): int
    {
        $type = SubjectType::resolve($this->option('type'));
        $limit = (int) $this->option('limit');

        $query = CatalogItem::query()->orderBy('id');
        if ($type !== SubjectType::ALL) {
            $query->where('subject_type', $type->value);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->warn('Aucun titre en base. Lance d’abord "php artisan catalog:import".');

            return self::SUCCESS;
        }

        $dir = storage_path('app/exports');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/catalog-links-'.date('Ymd-His').'.txt';

        $handle = fopen($path, 'w');
        fwrite($handle, "MovieBox — export des liens de téléchargement\n");
        fwrite($handle, 'Généré le '.now()->toDateTimeString()."\n");
        fwrite($handle, "Titres: {$total}\n".str_repeat('=', 60)."\n\n");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $items = $query->cursor()->map(function (CatalogItem $r) use ($bar) {
            $bar->advance();

            return ['subjectId' => $r->subject_id, 'title' => $r->title, 'subjectType' => $r->subject_type];
        });

        foreach ($exporter->lines($items) as $line) {
            fwrite($handle, $line."\n");
        }

        fclose($handle);
        $bar->finish();
        $this->newLine(2);
        $this->info("Export terminé : {$path}");

        return self::SUCCESS;
    }
}
