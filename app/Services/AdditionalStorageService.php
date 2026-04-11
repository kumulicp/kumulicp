<?php

namespace App\Services;

use App\AdditionalStorage;
use App\AppInstance;
use App\Organization;
use App\Support\Facades\Application;
use App\Support\Facades\Subscription;
use Illuminate\Database\Eloquent\Collection;

class AdditionalStorageService
{
    private $storage;

    private $plan;

    private $has_updated = false;

    public function __construct(
        private Organization $organization,
        private string $entity,
        private string $name,
        ?AppInstance $app_instance = null,
    ) {
        $this->app_instance = $app_instance;

        $storage = AdditionalStorage::where('organization_id', $organization->id)->where('name', $name)->where('entity', $entity);

        if ($app_instance) {
            $storage->where('app_instance_id', $app_instance->id);
            $this->storage = $storage->first();
            $this->plan = Subscription::app_instance($this->app_instance);
        } else {
            $this->storage = $storage->get();
        }
    }

    public function get()
    {
        return $this->storage;
    }

    public function add($quantity)
    {
        if (! $this->storage) {
            $storage = new AdditionalStorage;
            $storage->organization_id = $this->organization->id;
            $storage->entity = $this->entity;
            $storage->name = $this->name;
            $storage->app_instance_id = $this->app_instance->id;
            $storage->application = $this->app_instance->application->slug;
            $storage->quantity = $quantity;
            $storage->save();
        }

        $this->storage = $storage;
    }

    public function delete()
    {
        if (is_array($this->storage) || is_a($this->storage, Collection::class)) {
            foreach ($this->storage as $storage) {
                $storage->delete();
            }
        } elseif (! is_null($this->storage)) {
            $this->storage->delete();
        }
    }

    public function quantity()
    {
        if ($this->storage) {
            return $this->storage->quantity;
        }

        return 0;
    }

    private function baseQuantity()
    {
        switch ($this->entity) {
            case 'group':
                return 1;
            default:
                return 0;
        }
    }

    public function quota()
    {
        $app_plan = Subscription::app_instance($this->app_instance);

        $quantity = $this->storage ? (int) $this->storage->quantity : 0;
        $amount = $app_plan ? (int) $app_plan->setting('storage.amount') : 1;

        return $quantity * $amount;
    }

    public function updateName($name)
    {
        $this->storage->name = $name;
        $this->storage->save();
    }

    public function updateQuantity(?int $quantity = null)
    {
        $max_storage = $this->maxAllowedAdditionalStorage();

        if ($quantity == 0 || $quantity == null) {
            $this->delete();
            $this->has_updated = true;

            return;
        }

        // If entered quantity is greater than the max allowed storage, only allow the difference so it can't go over the max
        if ($max_storage > 0 && $quantity > $max_storage) {
            $quantity -= ($quantity - $max_storage);
        }

        if ($max_storage > 0 || $quantity <= $max_storage) {
            if ($this->storage) {
                if ($this->storage->quantity !== $quantity) {
                    $this->storage->quantity = $quantity;
                    $this->storage->save();
                    $this->has_updated = true;
                }
            } else {
                $this->add($quantity);
                $this->has_updated = true;
            }
        }
    }

    public function additionalStorageOptions()
    {
        $max_storage = $this->maxAllowedAdditionalStorage();
        $storage_amount = $this->plan->setting('storage.amount');

        $options = [];

        for ($n = 1; $n <= $max_storage; $n++) {
            $options[] = [
                'text' => $n * $storage_amount.' GB',
                'value' => $n,
            ];
        }

        return $options;
    }

    public function additionalStorageUserOptions(string $access_type)
    {
        $max_storage = $this->maxAllowedAdditionalStorage();
        $storage_amount = $this->plan->setting('storage.amount');
        $user_storage = $this->plan->setting($access_type.'.storage');

        $options = [];

        for ($n = 0; $n <= $max_storage; $n++) {
            $options[] = [
                'text' => $user_storage + ($n * $storage_amount).' GB',
                'value' => $n,
            ];
        }

        return $options;
    }

    public function totalQuantity()
    {
        $additional_storage = $this->organization->additional_storage;
        $total = 0;

        foreach ($additional_storage as $storage) {
            $total += $storage->quantity;
        }

        return $total;
    }

    public function maxAllowedAdditionalStorage()
    {
        $storage_max = (int) ($this->plan->setting('storage.max') ?? 10);
        $total_quantity = Application::instance($this->app_instance)->countStorage();
        $quantity_left = $storage_max - $total_quantity;
        $additional_storage_diff = $quantity_left + $this->quantity();

        if ($additional_storage_diff < $this->quantity()) {
            return $this->quantity();
        } elseif ($quantity_left < 0) {
            return 0;
        }

        return ($additional_storage_diff < 10) ? $additional_storage_diff : 10;
    }

    public function increaseBy($quantity)
    {
        $this->storage->quantity += $quantity;
        $this->storage->save();
    }

    public function reduceBy($quantity)
    {
        $this->storage->quantity -= $quantity;
        $this->storage->save();
    }

    public function hasUpdated()
    {
        return $this->has_updated;
    }
}
