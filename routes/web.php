<?php

use Illuminate\Support\Facades\Route;
use UniSharp\LaravelFilemanager\Lfm;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::middleware(['auth', 'verified'])->namespace('App\Http\Controllers')->group(function () {

    Route::get('welcome', 'HomeController@welcome')->name('organization.welcome');

    Route::prefix('announcements')->group(function () {
        Route::get('{id}', 'Account\Announcements@show')->name('organization.announcements.show');
    });

    Route::prefix('admin')->middleware('can:admin')->group(function () {
        Route::prefix('apps')->group(function () {
            Route::get('', 'Admin\Applications@index')->name('app.index');
            Route::get('create', 'Admin\Applications@create')->name('app.create');
            Route::post('', 'Admin\Applications@store')->name('app.store');
            Route::prefix('{app:slug}')->group(function () {
                Route::get('', 'Admin\Applications@show');
                Route::post('enable', 'Admin\Applications@enable');
                Route::post('disable', 'Admin\Applications@disable');
                Route::get('edit', 'Admin\Applications@edit');
                Route::post('', 'Admin\Applications@update');
                Route::prefix('plans')->group(function () {
                    Route::get('', 'Admin\Applications\Plans@index')->name('app.plans');
                    Route::get('retrieve', 'Admin\Applications\Plans@retrieve')->name('app.plans.retrieve');
                    Route::get('create', 'Admin\Applications\Plans@create')->name('app.plans.create');
                    Route::post('', 'Admin\Applications\Plans@store')->name('app.plans.store');
                    Route::post('update_order', 'Admin\Applications\Plans@updateOrder')->name('app.plans.update_order');
                    Route::prefix('{plan}')->group(function () {
                        Route::get('', 'Admin\Applications\Plans@show')->name('app.plans.show');
                        Route::get('edit', 'Admin\Applications\Plans@edit')->name('app.plans.edit');
                        Route::post('', 'Admin\Applications\Plans@update')->name('app.plans.update');
                        Route::get('remove', 'Admin\Applications\Plans@remove')->name('app.plans.remove');
                        Route::get('archive', 'Admin\Applications\Plans@archive')->name('app.plans.archive');
                        Route::get('unarchive', 'Admin\Applications\Plans@unarchive')->name('app.plans.unarchive');
                        Route::get('features', 'Admin\Applications\Plans\Features@edit')->name('app.plans.features.edit');
                        Route::put('features', 'Admin\Applications\Plans\Features@update')->name('app.plans.features.update');
                        Route::get('configurations', 'Admin\Applications\Plans\Configurations@edit')->name('app.plans.configurations.edit');
                        Route::put('configurations', 'Admin\Applications\Plans\Configurations@update')->name('app.plans.configurations.update');
                    });
                });
                Route::prefix('versions')->group(function () {
                    Route::get('', 'Admin\Applications\Versions@index');
                    Route::get('create', 'Admin\Applications\Versions@create')->name('app.new.version');
                    Route::post('', 'Admin\Applications\Versions@store')->name('app.store.version');
                    Route::prefix('{version:name}')->group(function () {
                        Route::get('', 'Admin\Applications\Versions@edit');
                        Route::post('', 'Admin\Applications\Versions@update');
                        Route::get('enable', 'Admin\Applications\Versions@enable');
                        Route::get('disable', 'Admin\Applications\Versions@disable');
                        Route::get('roles', 'Admin\Applications\Versions@roles')->name('discover.apps.versions.roles');
                        Route::post('roles', 'Admin\Applications\Versions@updateRoles')->name('discover.apps.versions.roles.update');
                    });
                });
                Route::prefix('roles')->group(function () {
                    Route::get('', 'Admin\Applications\Roles@index')->name('admin.roles');
                    Route::get('create', 'Admin\Applications\Roles@create')->name('admin.roles.create');
                    Route::post('', 'Admin\Applications\Roles@store')->name('admin.roles.store');
                    Route::prefix('{group}')->group(function () {
                        Route::get('edit', 'Admin\Applications\Roles@edit')->name('admin.roles.edit');
                        Route::post('', 'Admin\Applications\Roles@update')->name('admin.roles.update');
                        Route::get('remove', 'Admin\Applications\Roles@remove')->name('admin.roles.remove');
                        Route::get('enable', 'Admin\Applications\Roles@enable')->name('admin.roles.enable');
                        Route::get('disable', 'Admin\Applications\Roles@disable')->name('admin.roles.disable');
                    });
                });
            });
        });

        Route::resource('organizations', 'Admin\Organizations');
        Route::prefix('organizations')->group(function () {
            Route::prefix('{organization}')->group(function () {
                Route::get('logs', 'Admin\Organizations@logs')->name('organizations.logs');
                Route::get('tasks', 'Admin\Organizations@tasks')->name('organizations.tasks');
                Route::get('remove', 'Admin\Organizations@remove')->name('organizations.remove');
                Route::get('reactivate', 'Admin\Organizations@reactivate')->name('organizations.reactivate');
                Route::get('deactivate', 'Admin\Organizations@deactivate')->name('organizations.deactivate');
                Route::post('update_subscription', 'Admin\Organizations@update_subscription')->name('organizations.update_subscription');
                Route::resource('backups', 'Admin\Organizations\BackupRestore');
                Route::get('backups/{backup}/restore', 'Admin\Organizations\BackupRestore@restore')->name('organizations.backup.restore');
                Route::resource('apps', 'Admin\Organizations\Applications');
                Route::prefix('apps')->group(function () {
                    Route::get('', 'Admin\Organizations\Applications@index')->name('organizations.applications.index');
                    Route::prefix('{app}')->group(function () {
                        Route::put('', 'Admin\Organizations\Applications@update')->name('organizations.app.update');
                        Route::get('run/{action}', 'Admin\Organizations\Applications@run')->name('organizations.app.run');
                        Route::get('update', 'Admin\Organizations\Applications@update_settings')->name('organizations.app.update_settings');
                        Route::delete('', 'Admin\Organizations\Applications@delete')->name('organizations.app.delete');
                        Route::get('activate/{version}', 'Admin\Organizations\Applications@activate')->name('organizations.app.activate');
                        Route::get('upgrade/{version}', 'Admin\Organizations\Applications@upgrade')->name('organizations.app.upgrade');
                    });
                });
                Route::get('domains', 'Admin\Organizations\Domains@index')->name('organizations.domains.index');
            });
        });

        Route::prefix('server')->group(function () {
            Route::prefix('tasks')->group(function () {
                Route::get('', 'Admin\Tasks@index')->name('tasks');
                Route::post('api', 'Admin\Tasks@api')->name('tasks.api');
                Route::delete('{task}', 'Admin\Tasks@delete_api')->name('tasks.delete.api');
                Route::get('pending', 'Admin\Tasks@pending')->name('tasks.pending');
                Route::get('{task}/restart', 'Admin\Tasks@restart')->name('tasks.restart');
                Route::get('{task}/delete', 'Admin\Tasks@delete')->name('tasks.delete');
                Route::post('{task}/delete_completed', 'API\Account\Tasks@delete')->name('tasks.delete_completed');
                Route::get('retrieve/notifications', 'API\Account\Tasks@index')->name('tasks.index');
                Route::get('dummy', 'Admin\Tasks@dummy')->name('tasks.dummy');
                Route::get('run_schedule', 'Admin\Tasks@run_schedule')->name('tasks.run_schedule');
                Route::get('restart_queue', 'Admin\Tasks@restart_queue')->name('tasks.restart_queue');
            });
            Route::prefix('logs')->group(function () {
                Route::get('', 'Admin\Logs@index')->name('admin.logs.index');
            });
            Route::resource('backup_scheduler/recurring', 'Admin\RecurringBackups');
            Route::prefix('backup_scheduler/recurring')->group(function () {
                Route::get('{recurrence}/activate', 'Admin\RecurringBackups@activate')->name('server.backup.recurring.activate');
                Route::get('{recurrence}/deactivate', 'Admin\RecurringBackups@deactivate')->name('server.backup.recurring.deactivate');
            });
            Route::resource('servers', 'Admin\Servers');
            Route::prefix('servers')->group(function () {
                Route::get('{server}/confirm', 'Admin\Servers@confirm')->name('server.servers.confirm');
                Route::get('{server}/set_default', 'Admin\Servers@set_default')->name('server.servers.set_default');
                Route::get('{server}/chart', 'Admin\Servers@chart')->name('server.servers.chart');
            });
            Route::resource('backup_scheduler', 'Admin\BackupScheduler');
        });

        Route::prefix('settings')->group(function () {
            Route::get('', 'Admin\Settings@index')->name('server.settings');
            Route::put('', 'Admin\Settings@update')->name('server.settings.update');
            Route::prefix('invoice')->group(function () {
                Route::get('', 'Admin\Settings\InvoiceSettings@index')->name('settings.invoice');
                Route::put('', 'Admin\Settings\InvoiceSettings@update')->name('settings.invoice.update');
            });
            Route::get('ldap', 'Admin\Settings\LdapSettings@index')->name('server.settings.ldap');
            Route::put('ldap', 'Admin\Settings\LdapSettings@update')->name('server.settings.ldap.update');
            Route::prefix('sso-providers')->group(function () {
                Route::get('/', 'App\Http\Controllers\Admin\Settings\SsoProviders@index');
                Route::post('/', 'App\Http\Controllers\Admin\Settings\SsoProviders@store');
                Route::get('{provider}', 'App\Http\Controllers\Admin\Settings\SsoProviders@show');
                Route::put('{provider}', 'App\Http\Controllers\Admin\Settings\SsoProviders@update');
                Route::delete('{provider}', 'App\Http\Controllers\Admin\Settings\SsoProviders@idestroy');
            });
        });

        Route::prefix('service')->group(function () {
            Route::prefix('plans')->group(function () {
                Route::get('', 'Admin\Plans@index')->name('service.plans');
                Route::get('create', 'Admin\Plans@create')->name('service.plans.create');
                Route::post('', 'Admin\Plans@store')->name('service.plans.store');
                Route::post('update_order', 'Admin\Plans@updateOrder')->name('service.plans.update_order');
                Route::get('{plan}', 'Admin\Plans@edit')->name('service.plans.edit');
                Route::post('{plan}', 'Admin\Plans@update')->name('service.plans.update');
                Route::get('{plan}/remove', 'Admin\Plans@remove')->name('service.plans.remove');
                Route::get('{plan}/archive', 'Admin\Plans@archive')->name('service.plans.archive');
                Route::get('{plan}/unarchive', 'Admin\Plans@unarchive')->name('service.plans.unarchive');
            });
            Route::resource('announcements', 'Admin\Announcements');
            Route::prefix('announcements')->group(function () {
                Route::post('{announcement}/archive', 'Admin\Announcements@archive')->name('server.announcements.archive');
                Route::get('{announcement}/notify', 'Admin\Announcements@notify')->name('server.announcements.notify');
            });
            Route::prefix('shared-apps')->group(function () {
                Route::get('activate', 'Admin\SharedApps@activate')->name('service.shared-apps.activate');
            });
            Route::resource('shared-apps', 'Admin\SharedApps');
            Route::prefix('domains')->group(function () {
                Route::get('', 'Admin\Domains@index')->name('service.domains.index');
                Route::get('tlds/refresh', 'Admin\Tlds@refresh')->name('service.domains.tlds.refresh');
                Route::resource('tlds', 'Admin\Tlds');
                Route::get('{domain}', 'Admin\Domains@update')->name('service.domains.update');
            });
        });
        Route::resource('server/tests', 'Admin\AccountTests');
        Route::get('server/tests/{test}/clear', 'Admin\AccountTests@clear');
        Route::get('server/tests/{test}/run', 'Admin\AccountTests@run');
    });

    Route::resources([
        'apps' => 'Account\Applications',
        'groups' => 'Account\Groups',
        'users' => 'Account\Users',
    ]);

    Route::prefix('apps/{app}')->group(function () {
        Route::post('reactivate', 'Account\Applications@reactivate')->name('organization.apps.reactivate');
        Route::get('plans', 'Account\Application\Plans@index')->name('organization.apps.plans');
        Route::get('plans/{plan}', 'Account\Application\Plans@show')->name('organization.apps.plans.view');
        Route::put('plans/{plan}/select', 'Account\Application\Plans@update')->name('organization.apps.plans.select');
        Route::get('plans/{plan}/api', 'Account\Application\Plans@review_api')->name('organization.apps.plans.api');
    });

    Route::prefix('subscription')->group(function () {
        Route::get('', 'Account\Subscription@index')->name('organization.subscription.summary');
        Route::delete('', 'Account\Subscription@cancel')->name('organization.cancel');
        Route::get('plans', 'Account\Subscription@plans')->name('organization.subscription.plans');
        Route::get('payment/method', 'Account\PaymentMethod@show')->name('organization.payment_method');
        Route::get('payment/', 'Account\PaymentMethod@edit')->name('organization.payment_method.edit');
        Route::post('payment/method', 'Account\PaymentMethod@update')->name('organization.payment_method.update');
        Route::get('payment/method/delete', 'Account\PaymentMethod@delete')->name('organization.payment_method.delete');
        Route::get('invoice/{invoice}/download', 'Account\Subscription@download')->name('organization.invoice.download');
        Route::post('billing/managers', 'Account\BillingManagers@store')->name('organization.billing.manager.store');
        Route::delete('billing/managers/{user}', 'Account\BillingManagers@destroy')->name('organization.billing.manager.destroy');
        Route::get('options', 'Account\Subscription@options')->name('organization.subscription.options.none');
        Route::prefix('{organization}')->group(function () {
            Route::delete('', 'Account\Subscription@cancel')->name('organization.cancel');
            Route::get('', 'Account\Subscription@show')->name('organization.subscription.show');
            Route::post('resubscribe', 'Account\Subscription@resubscribe')->name('organization.subscription.resubscribe');
            Route::get('options', 'Account\Subscription@options')->name('organization.subscription.options');
            Route::prefix('plans/{plan}')->group(function () {
                Route::get('', 'Account\Subscription@review')->name('organization.subscription.review');
                Route::post('', 'Account\Subscription@update')->name('organization.subscription.update');
            });
        });
    });

    Route::prefix('settings')->group(function () {
        Route::prefix('organization')->group(function () {
            Route::get('', 'Account\Organization@index')->name('settings.organization');
            Route::put('', 'Account\Organization@update')->name('settings.organization.update');
        });
        Route::prefix('suborganizations')->group(function () {
            Route::get('', 'Account\Organization\Suborganization@index')->name('settings.suborganizations');
            Route::post('', 'Account\Organization\Suborganization@store')->name('settings.suborganizations.store');
            Route::get('{organization}', 'Account\Organization\Suborganization@edit')->name('settings.suborganizations.edit');
            Route::put('{organization}', 'Account\Organization\Suborganization@update')->name('settings.suborganizations.update');
            Route::delete('{organization}', 'Account\Organization\Suborganization@destroy')->name('settings.suborganizations.update');
        });
        Route::prefix('domains')->group(function () {
            Route::get('', 'Account\Web\Domains@index')->name('organization.settings.web.domains');
            Route::get('availability', 'Account\Web\Register@availability')->name('organization.settings.web.availability');
            Route::post('availability', 'Account\Web\Register@select')->name('organization.settings.web.select');
            Route::post('check', 'Account\Web\Register@check')->name('organization.settings.web.check');
            Route::get('register/{domain}', 'Account\Web\Register@setup')->name('organization.settings.web.registration');
            Route::post('register/{domain}', 'Account\Web\Register@register')->name('organization.settings.web.register');
            Route::get('transfer', 'Account\Web\Transfer@setup')->name('organization.settings.web.transfer.setup');
            Route::post('transfer', 'Account\Web\Transfer@transfer')->name('organization.settings.web.transfer');
            Route::post('transfer/price', 'Account\Web\Transfer@price')->name('organization.settings.web.transfer.price');
            Route::get('connect', 'Account\Web\Connect@setup')->name('organization.settings.web.connect');
            Route::post('connect', 'Account\Web\Connect@add')->name('organization.settings.web.connect.add');
            Route::prefix('{domain:name}')->group(function () {
                Route::resource('subdomains', 'Account\Web\Subdomains');
                Route::get('', 'Account\Web\Domains@edit')->name('settings.web.domain.edit');
                Route::post('', 'Account\Web\Domains@update')->name('organization.settings.web.domain.update');
                Route::post('renew', 'Account\Web\Domains@renew')->name('organization.settings.web.domains.renew');
                Route::post('reactivate', 'Account\Web\Domains@reactivate')->name('organization.settings.web.domains.reactivate');
                Route::post('remove', 'Account\Web\Domains@remove')->name('organization.settings.web.domains.remove');
                Route::post('request_transfer', 'Account\Web\Domains@request_transfer')->name('organization.settings.web.domains.request_transfer');
                Route::post('self_manage', 'Account\Web\Domains@self_manage')->name('organization.settings.web.domains.self_manage');
                Route::post('transfer_in', 'Account\Web\Domains@transfer_in')->name('organization.settings.web.domains.transfer_in');
                Route::post('enable_email', 'Account\Web\Domains@enable_email')->name('organization.settings.web.domains.enable_email');
            });
        });
        Route::prefix('email')->group(function () {
            Route::get('setup', 'Account\Email\Accounts@setup')->name('settings.email.setup');
            Route::get('activate', 'Account\Email\Accounts@activate')->name('organization.settings.email.activate');
            Route::prefix('forwarders')->group(function () {
                Route::get('', 'Account\Email\Forwarders@index')->name('organization.email.forwarder');
                Route::get('create', 'Account\Email\Forwarders@add')->name('organization.email.forwarder.create');
                Route::post('', 'Account\Email\Forwarders@store')->name('organization.email.forwarder.store');
                Route::get('remove/{forwarder}/{destination}', 'Account\Email\Forwarders@remove')->name('organization.email.forwarder.remove');
            });
            Route::resource('accounts', 'Account\Email\Accounts');
        });
    });

    Route::prefix('discover')->group(function () {
        Route::get('', 'Account\Discover@index')->name('discover.apps');
        Route::prefix('{app:slug}')->group(function () {
            Route::get('', 'Account\Discover@show');
            Route::prefix('plans')->group(function () {
                Route::get('', 'Account\Discover@plans');
                Route::prefix('{plan}')->group(function () {
                    Route::get('review', 'Account\Discover@review');
                    Route::post('activate', 'Account\Discover@activate');
                });
            });
        });
    });

    Route::prefix('users')->group(function () {
        Route::get('basic', 'Account\Users@basic')->name('users.basic');
        Route::prefix('{user}')->group(function () {
            Route::prefix('permissions')->group(function () {
                Route::get('', 'Account\Permissions@edit')->name('users.permissions.edit');
                Route::post('', 'Account\Permissions@update')->name('users.permissions.update');
            });
            Route::prefix('groups')->group(function () {
                Route::get('', 'Account\User\Groups@edit')->name('users.groups.edit');
                Route::get('{groupid}/add', 'Account\User\Groups@add')->name('users.groups.add');
                Route::get('{groupid}/remove', 'Account\User\Groups@remove')->name('users.groups.remove');
            });
            Route::get('create/accountemail/{domain}', 'Account\Users@createAccountEmail');
            Route::get('remove/accountemail/{domain}', 'Account\Users@removeAccountEmail');
            Route::get('reset_password', 'Account\Users@resetPassword');
        });
        Route::post('retrieve', 'Account\Users@retrieve')->name('user.retrieve');
    });

    Route::prefix('notifications')->group(function () {
        Route::get('', 'Account\Notifications@index')->name('notifications.index');
        Route::delete('', 'Account\Notifications@destroy')->name('notifications.destroy');
    });

    Route::prefix('profile')->group(function () {
        Route::get('', 'Profile@index')->name('profile');
        Route::post('update/passwd', 'Profile@updatePassword')->name('profile.update.password');
        Route::post('', 'Profile@update')->name('profile.update');
    });

    Route::post('/support/ticket/submit', 'Account\Support@submit')->name('organization.support.submit');

    Route::get('/', 'HomeController@index')->name('home');
});

Route::prefix('public')->namespace('App\Http\Controllers')->group(function () {
    Route::get('{account}/{email}/changepassword', 'Pub\ChangePassword@edit')->name('public.password.edit');
    Route::get('setpassword/{code}', 'Pub\ChangePassword@set')->name('public.password.set');
    Route::post('setpassword/{code}/save', 'Pub\ChangePassword@store')->name('public.password.store');
    Route::post('{account}/{email}', 'Pub\ChangePassword@update')->name('public.password.update');
    Route::get('/users/done/{code}', 'Pub\ChangePassword@done')->name('public.changepassword.done');
});

Route::group(['prefix' => 'laravel-filemanager', 'middleware' => ['auth']], function () {
    Lfm::routes();
});

Route::get('setup', 'App\Http\Controllers\Setup@settings')->name('initial.setup');
Route::post('install', 'App\Http\Controllers\Setup@build')->name('initial.build');

Auth::routes(['verify' => true]);
Route::get('registered', 'App\Http\Controllers\Auth\RegisteredAccountController@registered')->name('auth.registered');

Route::get('/auth/{provider}', 'App\Http\Controllers\Auth\SsoController@redirect');
Route::get('/auth/{provider}/callback', 'App\Http\Controllers\Auth\SsoController@callback');
