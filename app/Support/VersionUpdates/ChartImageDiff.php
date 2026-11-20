<?php

namespace App\Support\VersionUpdates;

/**
 * What changes in the images a chart runs between two of its versions, so an
 * upgrade that adds, drops or moves one is seen before it is made.
 */
class ChartImageDiff
{
    public function __construct(private ChartImageScanner $scanner = new ChartImageScanner) {}

    /**
     * @return array{added: array<int, array<string, mixed>>, removed: array<int, array<string, mixed>>, changed: array<int, array{image: array<string, mixed>, from: string, to: string}>}
     */
    public function compare(ChartPackage $current, ChartPackage $candidate): array
    {
        $before = $this->index($this->scanner->scan($current));
        $after = $this->index($this->scanner->scan($candidate));

        $changed = [];
        foreach ($after as $identity => $image) {
            if (isset($before[$identity]) && ChartImageScanner::reference($before[$identity]) !== ChartImageScanner::reference($image)) {
                $changed[] = ['image' => $image, 'from' => ChartImageScanner::reference($before[$identity]), 'to' => ChartImageScanner::reference($image)];
            }
        }

        return [
            'added' => array_values(array_diff_key($after, $before)),
            'removed' => array_values(array_diff_key($before, $after)),
            'changed' => $changed,
        ];
    }

    /**
     * The changes in words, for an admin to read.
     *
     * @param  array{added: array<int, array<string, mixed>>, removed: array<int, array<string, mixed>>, changed: array<int, array{image: array<string, mixed>, from: string, to: string}>}  $diff
     * @return array<int, string>
     */
    public function lines(array $diff): array
    {
        $lines = [];

        foreach ($diff['added'] as $image) {
            $lines[] = __('admin.version_updates.image_added', ['image' => $this->name($image), 'reference' => ChartImageScanner::reference($image)]);
        }

        foreach ($diff['removed'] as $image) {
            $lines[] = __('admin.version_updates.image_removed', ['image' => $this->name($image), 'reference' => ChartImageScanner::reference($image)]);
        }

        foreach ($diff['changed'] as $change) {
            $lines[] = __('admin.version_updates.image_changed', ['image' => $this->name($change['image']), 'from' => $change['from'], 'to' => $change['to']]);
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $image
     */
    private function name(array $image): string
    {
        return $image['path'] !== '' ? $image['path'] : ($image['label'] ?: $image['repository']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $images
     * @return array<string, array<string, mixed>>
     */
    private function index(array $images): array
    {
        $index = [];

        foreach ($images as $image) {
            $index[ChartImageScanner::identity($image)] = $image;
        }

        return $index;
    }
}
