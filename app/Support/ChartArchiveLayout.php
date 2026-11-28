<?php

namespace App\Support;

use App\AppInstance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Which app instances a chart values archive holds, and what its files are called, so that what writes one
 * (`backup:charts`) and what reads it (`volumes:verify`) always agree.
 *
 * A file is `<organization>/<app instance>/<chart>.yaml`.
 */
final class ChartArchiveLayout
{
    /** The folder of the backups disk the archives are in */
    public const FOLDER = 'chart-values';

    /** @var array<string, true> */
    private array $used = [];

    /**
     * The instances that are deployed with a chart of their own: not deactivated, and with a web server.
     *
     * @return Builder<AppInstance>
     */
    public static function instances(): Builder
    {
        return AppInstance::query()
            ->notDeactivated()
            ->whereNotNull('web_server_id')
            ->with(['organization', 'application', 'version']);
    }

    /**
     * A shared app's child is covered by the release of the app it lives in.
     */
    public static function covers(AppInstance $app_instance): bool
    {
        return $app_instance->usesOwnResources();
    }

    /**
     * `<organization>/<app instance>`, made different from another's when the two would be the same. Asked
     * once for each instance, in the order they are walked.
     */
    public function folder(AppInstance $app_instance): string
    {
        $folder = self::segment($app_instance->organization->slug).'/'.self::segment($app_instance->name);

        if (isset($this->used[$folder])) {
            $folder .= '-'.$app_instance->id;
        }

        $this->used[$folder] = true;

        return $folder;
    }

    public static function file(string $folder, string $chart): string
    {
        return $folder.'/'.self::segment($chart).'.yaml';
    }

    // Only what is safe in a path inside a zip
    public static function segment(?string $name): string
    {
        return Str::of((string) $name)->replaceMatches('/[^A-Za-z0-9._-]+/', '-')->trim('-.')->toString() ?: 'unnamed';
    }
}
