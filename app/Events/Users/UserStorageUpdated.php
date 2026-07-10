<?php

namespace App\Events\Users;

use App\AppInstance;
use App\Organization;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserStorageUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  string|null  $user_id  Limit the storage update to a single user. Null updates every user with storage in scope.
     * @param  \App\AppInstance|null  $app_instance  Limit the storage update to a single app instance. Null updates every app instance in the organization.
     * @return void
     */
    public function __construct(
        public Organization $organization,
        public ?string $user_id = null,
        public ?AppInstance $app_instance = null,
    ) {}

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
