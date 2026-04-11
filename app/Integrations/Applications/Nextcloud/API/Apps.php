<?php

namespace App\Integrations\Applications\Nextcloud\API;

use App\Integrations\Applications\Nextcloud\Nextcloud;

class Apps extends Nextcloud
{
    public $data;

    public function find($app)
    {
        $path = $this->basePath().'/apps/'.$app;
        $this->form()->get($path);

        $this->data = $this->response_content();

        return $this;
    }

    public function findByFilter($filter)
    {
        $this->request_type = 'GET';
        $this->form()->get($this->basePath().'/apps', [
            'filter' => $filter,
        ]);
        if (! $this->error()) {

            return $this->response_content()->apps->element;

        }

        return null;
    }

    public function enable($app)
    {
        $this->request_type = 'POST';
        $path = $this->basePath().'/apps/'.$app;

        $this->post($path, null);

        return $this->response_content();
    }

    public function disable($app)
    {
        $path = $this->basePath().'/apps/'.$app;

        $this->form()->delete($path);

        return $this->response_content();
    }

    public function isEnabled(string $app)
    {
        $list = (array) $this->findByFilter('enabled');

        if (is_array($list) && in_array($app, $list)) {
            return true;
        }

        return false;
    }
}
