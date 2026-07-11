<?php

namespace App\Contracts\AccountManager;

use App\AppInstance;

interface GroupInterface
{
    public function attribute($attribute);

    public function name();

    public function category();

    public function categoryName();

    public function managers();

    public function managerNames();

    public function members();

    public function updateManagers(array $managers);

    public function updateMembers(array $members);

    public function updateName($name);

    public function updateCategory($category);

    public function updateQuota(AppInstance $app_instance, $quantity);

    public function additionalStorage(AppInstance $app_instance);

    public function allAddtionalStorage();

    public function delete();

    public function save();

    public function disableAutoSave();
}
