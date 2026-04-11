<?php

namespace App\Jobs\Accounts;

use App\Organization;
use App\Support\Facades\ServerInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class UpdateOrganization implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(
        public Organization $organization
    ) {}

    /**
     * Handle the event.
     *
     * @param  UpdateOrganization  $event
     * @return void
     */
    public function handle()
    {
        $server_ips = [];
        foreach ($this->organization->servers as $server) {
            $connect = ServerInterface::connect($server);
            $server_info = $server->server;

            //
            if (! array_key_exists($server_info->type, $server_ips) && ! in_array($server_info->ip, $server_ips)) {
                $server_ips[$server_info->type] = $server_info->ip;
                try {
                    if ($connect->existsOrganization($this->organization)) {
                        $connect->updateOrganization($this->organization);
                    }
                } catch (Throwable $e) {
                    $this->fail($e);
                }
            }
        }
    }

    public function failed(Throwable $e)
    {
        report($e);
    }
}
