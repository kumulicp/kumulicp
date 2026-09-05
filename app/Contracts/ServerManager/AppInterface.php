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
}
