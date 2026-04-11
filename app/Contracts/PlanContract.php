<?php

namespace App\Contracts;

use App\Enums\AccessType;
use App\Enums\PlanEntity;
use App\Support\AccountManager\UserManager;

interface PlanContract
{
    public function itemName(): string;

    public function itemLabel(): string;

    public function model();

    public function get();

    public function pricing(): array;

    public function pricingOptions(): array;

    public function status(): string;

    public function stats(): array;

    public function baseStats(): array;

    public function standardStats(): array;

    public function basicStats(): array;

    public function storageStats(): array;

    public function emailStats(): array;

    public function applicationStats(): array;

    public function refresh();

    public function availableAccessTypes(UserManager $user): array;

    public function availableAccessTypesList(UserManager $user): array;

    public function priceIds(): array;

    public function domainsEnabled(): bool;

    public function isAnyMaxBroken();

    public function isMaxApps(Application $app);

    public function totalPrice(): float;

    public function isMaxAppsBroken(): bool;

    public function isDomainMax(): bool;

    public function isMaxBroken(PlanEntity $entity): bool;

    public function accessTypeName(?AccessType $access_type = null): string;

    public function save(): void;
}
