<?php

namespace App\Integrations\Applications\Nextcloud\API;

use App\Integrations\Applications\Nextcloud\Nextcloud;

class Groups extends Nextcloud
{
    public function find(string $group_id)
    {
        $path = $this->basePath().'/groups/'.$group_id;

        $this->ignoreErrorCode(404)->get($path);

        return $this->response_content();
    }

    public function add(string $group_id, string $display_name)
    {
        $path = $this->basePath().'/groups';
        $data = ['groupid' => $group_id, 'displayname' => $display_name];

        $this->action_description = __('messages.api.nextcloud.groups.add', ['group' => $group_id]);

        return $this->form()->post($path, $data);
    }

    // Find-or-create. Safe to call on every role grant since group creation
    // is otherwise a one-time, activation-time concern with no other
    // natural hook once folder provisioning happens lazily on first grant.
    public function ensure(string $group_id, string $display_name): void
    {
        if (! $this->find($group_id)) {
            $this->add($group_id, $display_name);
        }
    }
}
