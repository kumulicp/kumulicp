<?php

namespace App\Events;

use App\AppInstance;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppInstanceSubscriptionChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $organization;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(public AppInstance $app_instance)
    {
        $this->organization = $this->app_instance->organization;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('channel-name');
    }
}
