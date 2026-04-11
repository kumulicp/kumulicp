<?php

namespace App\Jobs\Domains;

use App\Actions\AddWebDomain;
use App\OrgDomain;
use App\Support\Facades\Action;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ConfirmDomainsConnection
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $domain;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(?OrgDomain $domain = null)
    {
        $this->domain = $domain;
    }

    /**
     * Handle the event.
     *
     * @param  ConfirmDomainsConnection  $event
     * @return void
     */
    public function handle()
    {
        if ($this->domain) {
            $this->check($this->domain);
        } else {
            $confirming_domains = OrgDomain::where('type', 'connection')->where('status', 'confirming_domain')->get();

            // TODO: Check if each app links to the correct IP address
            foreach ($confirming_domains as $domain) {
                $this->check($domain);
            }
        }
    }

    private function check($domain)
    {
        $ip = gethostbyname($domain->name);
        $connection_info = $domain->app_instance->server;

        if ($ip == $connection_info->ip) {
            $task = Action::execute(new AddWebDomain($domain->organization, $domain));

            $domain->status = 'active';
            $domain->save();
        }
    }

    public function failed($e) {}

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return int
     */
    public function backoff()
    {
        return 30;
    }
}
