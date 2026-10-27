<?php

namespace App\Console\Commands;

use App\ChartSource;
use App\Support\Catalog\ChartCatalogSync;
use Illuminate\Console\Command;

class CatalogSync extends Command
{
    protected $signature = 'catalog:sync
        {source? : Slug of one chart source (default: all enabled sources)}
        {--report : Also list the charts skipped, failed and only partially mapped}';

    protected $description = 'Import the charts of the configured chart sources as catalog applications';

    public function handle(ChartCatalogSync $sync): int
    {
        $sources = ChartSource::where('enabled', true)
            ->when($this->argument('source'), fn ($query, $slug) => $query->where('slug', $slug))
            ->get();

        if ($sources->isEmpty()) {
            $this->components->warn('No enabled chart sources found.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($sources as $source) {
            $this->components->info("Syncing {$source->name} ({$source->url})");

            $report = $sync->sync($source);

            if ($report['error']) {
                $this->components->error($report['error']);
                $failed = true;

                continue;
            }

            $this->line(sprintf(
                '  %d created, %d updated, %d skipped, %d failed, %d with limited mapping',
                count($report['created']),
                count($report['updated']),
                count($report['skipped']),
                count($report['failed']),
                count($report['limited']),
            ));

            if ($this->option('report')) {
                $this->printReport($report);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function printReport(array $report): void
    {
        foreach ($report['skipped'] as $chart => $reason) {
            $this->line("  skipped  $chart: $reason");
        }

        foreach ($report['failed'] as $chart => $error) {
            $this->line("  failed   $chart: $error");
        }

        foreach ($report['limited'] as $chart => $missing) {
            $this->line("  limited  $chart: no ".implode(', ', $missing));
        }
    }
}
