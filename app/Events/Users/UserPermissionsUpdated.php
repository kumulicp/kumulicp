<?php

namespace App\Events\Users;

use App\Support\AccountManager\UserManager;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserPermissionsUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $user;

    public $organization;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(UserManager $user)
    {
        $this->user = $user;
        $this->organization = $user->organization();
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
