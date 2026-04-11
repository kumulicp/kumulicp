<?php

namespace App\Services;

use App\ServerSetting;
use Illuminate\Support\Arr;

class SettingsService
{
    private $settings = [];

    public function __construct()
    {
        $server_settings = ServerSetting::all();
        $setting = [];

        foreach ($server_settings as $server_setting) {
            $setting[$server_setting['key']] = $server_setting['value'];
        }

        $this->settings = $setting;
    }

    public function update($key, $value = null)
    {
        if ($value) {
            if (array_key_exists($key, $this->settings)) {
                $setting = ServerSetting::where('key', $key)->first();
                $setting->value = $value;
                $setting->save();
            } else {
                $setting = new ServerSetting;
                $setting->key = $key;
                $setting->value = $value;
                $setting->save();
            }

            Arr::set($this->settings, $key, $value);
        } elseif (array_key_exists($key, $this->settings)) {
            $this->remove($key);
        }
    }

    public function get($key, $fallback = '')
    {
        if (array_key_exists($key, $this->settings)) {
            return $this->settings[$key];
        }

        return $fallback;
    }

    public function remove($key)
    {
        if (array_key_exists($key, $this->settings)) {
            $setting = ServerSetting::where('key', $key)->first();
            $setting->delete();
        }
    }
}
