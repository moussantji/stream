<?php

namespace App\Console\Commands;

use App\Services\Catalog\CatalogRepository;
use App\Services\MovieBox\MovieBoxClient;
use App\Support\ItemNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Imports the full films / séries / animation catalog from the MovieBox API
 * and stores it as JSON files under storage/app/catalog/.
 *
 * This is what "save everything as JSON on startup" resolves to: it is invoked
 * automatically once when the site boots (see AppServiceProvider) and can also
 * be run manually with `php artisan catalog:import`.
 */
class ImportCatalog extends Command
{
    protected $signature = 'catalog:import
        {--no-persist : Skip writing to the catalog_items table (JSON only)}';

    protected $description = 'Import all films, séries and animation from the MovieBox API and save them as JSON.';

    /** Category slug => tab-operating tab id (mirrors CatalogController::CATEGORIES). */
    protected const CATEGORIES = [
        'films' => 2,
        'series' => 5,
        'animation' => 8,
    ];

    public function __construct(
        protected MovieBoxClient $client,
        protected CatalogRepository $repo,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dir = storage_path('app/catalog');
        File::ensureDirectoryExists($dir);

        $everything = [];

        foreach (self::CATEGORIES as $slug => $tabId) {
            $items = $this->fetchTab($tabId);

            $this->writeJson($dir, $slug, [
                'type' => $slug,
                'tabId' => $tabId,
                'count' => count($items),
                'updatedAt' => now()->toIso8601String(),
                'items' => $items,
            ]);

            if (! $this->option('no-persist')) {
                $this->repo->saveItems($items);
            }

            foreach ($items as $item) {
                $everything[$item['subjectId']] = $item;
            }

            $this->line(sprintf('  %-10s %d titre(s)', $slug, count($items)));
        }

        $all = array_values($everything);
        $this->writeJson($dir, 'all', [
            'count' => count($all),
            'updatedAt' => now()->toIso8601String(),
            'items' => $all,
        ]);

        $this->info(sprintf('Catalogue enregistré : %d titre(s) uniques dans %s', count($all), $dir));

        return self::SUCCESS;
    }

    /**
     * Flatten every subject of a tab-operating tab into a de-duplicated,
     * normalized list.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function fetchTab(int $tabId): array
    {
        try {
            $data = $this->client->home($tabId);
        } catch (Throwable $e) {
            report($e);
            $this->warn("  API indisponible pour l'onglet {$tabId} — ignoré.");

            return [];
        }

        $seen = [];
        $items = [];
        foreach (($data['items'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }
            foreach (ItemNormalizer::many($block['subjects'] ?? []) as $item) {
                if (! isset($seen[$item['subjectId']])) {
                    $seen[$item['subjectId']] = true;
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    protected function writeJson(string $dir, string $name, array $data): void
    {
        try {
            File::put(
                $dir.DIRECTORY_SEPARATOR.$name.'.json',
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } catch (Throwable $e) {
            report($e);
            $this->warn("  Impossible d'écrire {$name}.json : {$e->getMessage()}");
        }
    }
}
