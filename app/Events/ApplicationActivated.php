<?php

namespace App\Events;

use App\AppInstance;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApplicationActivated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $organization;

    public $app_instance;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(AppInstance $app_instance)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
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
