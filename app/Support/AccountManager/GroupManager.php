<?php

namespace App\Support\AccountManager;

abstract class GroupManager
{
    abstract public function attribute($attribute);

    abstract public function name();

    abstract public function categoryName();

    abstract public function managerNames();

    abstract public function members();

    abstract public function updateManagers(array $managers);

    abstract public function updateMembers(array $members);

    abstract public function updateName(string $name);

    abstract public function updateCategory($category);

    abstract public function disableAutoSave();

    abstract public function save();

    abstract public function delete();
}
