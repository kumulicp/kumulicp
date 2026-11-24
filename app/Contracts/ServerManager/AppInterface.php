<?php

namespace App\Contracts\ServerManager;

interface AppInterface
{
    public function exists();

    public function notFoundMessage(): string;

    public function get();

    public function isActive();

    /**
     * Per-chart deployment status, for surfacing to the user while polling
     * for completion (e.g. a task's error_message) rather than just a bool.
     *
     * @return array{active: bool, pending: bool, message: string}
     */
    public function checkStatus(): array;

    public function add();

    public function update();

    public function delete();

    /**
     * Removes the app's release, and what it keeps, from this server, and does nothing else: no
     * redirects are rewritten and nothing is installed. For the server an app was moved away from,
     * where delete() would do both.
     */
    public function uninstallRelease(): bool;
}
