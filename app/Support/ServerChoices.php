<?php

namespace App\Support;

use App\Server;
use Closure;
use Illuminate\Support\Collection;

/**
 * The servers an administrator can pick for an app, or as another server's backup
 * server. These are `Server`s, not the organization's bindings to them: a server the
 * organization is not on yet is a valid choice, and is bound with
 * `OrgServerService::forServer()` once it is picked.
 */
class ServerChoices
{
    /**
     * Servers of $type that can be picked: active ones, and backup servers only
     * when $backup. The server the thing already uses ($keep) is always kept in the
     * list, so what is selected can still be shown when that server was since
     * turned off.
     *
     * @return Collection<int, Server>
     */
    public static function for(string $type, bool $backup = false, ?int $keep = null): Collection
    {
        return Server::query()
            ->where(fn ($query) => $query
                ->where(fn ($pickable) => $pickable
                    ->where('type', $type)
                    ->where('is_backup_server', $backup)
                    ->where('status', 'active'))
                ->when($keep, fn ($query) => $query->orWhere('id', $keep)))
            ->orderBy('name')
            ->get();
    }

    /**
     * What a select needs to show a choice.
     *
     * @param  Collection<int, Server>  $servers
     * @return Collection<int, array{id: int, name: string, type: string}>
     */
    public static function options(Collection $servers): Collection
    {
        return $servers->map(fn (Server $server) => [
            'id' => $server->id,
            'name' => $server->name,
            'type' => $server->type,
        ])->values();
    }

    /**
     * Validation rules for a server picked from the choices above.
     *
     * @return array<int, mixed>
     */
    public static function rules(string $type, bool $backup = false, ?int $keep = null): array
    {
        return [
            'nullable',
            'integer',
            function (string $attribute, mixed $value, Closure $fail) use ($type, $backup, $keep) {
                if (! self::for($type, $backup, $keep)->contains('id', (int) $value)) {
                    $fail(__('validation.exists', ['attribute' => $attribute]));
                }
            },
        ];
    }
}
