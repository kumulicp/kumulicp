<?php

declare(strict_types=1);

/*
 * Larastan infers Auth::user()'s return type from
 * config('auth.providers.{provider}.model') for every configured guard (see
 * vendor/larastan/larastan/src/Concerns/LoadsAuthModel.php). Our 'ldap'
 * provider's top-level 'model' (App\Ldap\Models\User) is the raw LdapRecord
 * model used internally by LdapRecord to bind/search LDAP entries -- it is
 * NOT what Auth::user() actually returns.
 *
 * Because the 'ldap' provider also configures a 'database' sync block (see
 * config/auth.php), LdapRecord\Laravel\Auth\DatabaseUserProvider is used
 * instead of the plain LDAP provider. That class authenticates against LDAP
 * but returns the synced Eloquent App\User model from
 * retrieveByCredentials()/retrieveById() (see
 * vendor/directorytree/ldaprecord-laravel/src/Auth/DatabaseUserProvider.php
 * and vendor/directorytree/ldaprecord-laravel/src/Import/Synchronizer.php,
 * whose run() method is typed to return EloquentModel). So at runtime,
 * Auth::user() returns App\User (or App\Organization for the 'api' guard),
 * never the raw App\Ldap\Models\User.
 *
 * We override the model Larastan sees here -- for static analysis only, via
 * this dedicated bootstrap file -- so its inferred type for Auth::user()
 * matches what the app actually returns. The real config/auth.php is left
 * untouched, since its top-level 'model' key is still required at runtime
 * for LdapRecord's own repository lookups.
 */

require __DIR__.'/vendor/larastan/larastan/bootstrap.php';

config(['auth.providers.ldap.model' => \App\User::class]);
