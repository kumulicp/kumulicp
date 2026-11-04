<?php

namespace App;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A free-form label used to find and filter applications.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property-read Collection<int, Application> $applications
 */
class Tag extends Model
{
    protected $fillable = ['name', 'slug'];

    /**
     * @return BelongsToMany<Application, $this>
     */
    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(Application::class, 'application_tag');
    }

    public static function slugFor(string $name): string
    {
        return Str::slug($name);
    }

    /**
     * Resolve names to tags, creating the ones that don't exist yet.
     * Names that differ only by case or punctuation share one tag.
     *
     * @param  iterable<int, string>  $names
     * @return Collection<int, Tag>
     */
    public static function findOrCreateMany(iterable $names): Collection
    {
        $tags = collect($names)
            ->map(fn ($name) => trim((string) $name))
            ->filter(fn ($name) => self::slugFor($name) !== '')
            ->unique(fn ($name) => self::slugFor($name))
            ->map(fn ($name) => self::firstOrCreate(['slug' => self::slugFor($name)], ['name' => $name]));

        return new Collection($tags->values()->all());
    }
}
