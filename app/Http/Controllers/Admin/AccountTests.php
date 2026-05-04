<?php

namespace App\Http\Controllers\Admin;

use App\AccountTest;
use App\Actions\Tests\ClearTestAccounts;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Http\Controllers\Controller;
use App\Plan;
use App\Support\Facades\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AccountTests extends Controller
{
    public function index()
    {
        $tests = AccountTest::paginate(10);

        return inertia()->render('Admin/Tests/TestsList', [
            'tests' => $tests->map(function ($test) {
                return [
                    'id' => $test->id,
                    'description' => $test->description,
                    'created_date' => $test->created_at,
                    'status' => $test->status,
                ];
            }),
            'breadcrumbs' => [
                [
                    'label' => __('admin.tests.tests'),
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        /* Validate */
        $data = $request->validate([
            'description' => 'required|string',
        ]);

        $user = auth()->user();

        $test = new AccountTest;
        $test->test_number = 1;
        $test->description = $data['description'];
        $test->status = 'pending';
        $test->settings = [
            'base_plan' => '',
            'apps' => [],
        ];
        $test->created_by()->associate($user);
        $test->save();

        return redirect('/admin/server/tests/'.$test->id.'/edit');
    }

    public function show(AccountTest $test)
    {
        $base_plan = Arr::has($test->settings, 'base_plan') ? Plan::find($test->settings['base_plan']) : null;
        $apps = Arr::get($test->settings, 'apps', []);
        $app_plans = [];

        foreach ($apps as $slug => $app) {
            $application = Application::find($app['id']);
            $version = AppVersion::find($app['version']);
            $plan = AppPlan::find($app['plan']);
            $app_plans[] = [
                'app' => [
                    'id' => $application->id,
                    'name' => $application->name,
                ],
                'version' => [
                    'id' => $version ? $version->id : '',
                    'version' => $version ? $version->name : '',
                ],
                'plan' => [
                    'id' => $plan ? $plan->id : '',
                    'name' => $plan ? $plan->name : '',
                ],
            ];
        }

        return inertia()->render('Admin/Tests/TestView', [
            'test' => [
                'id' => $test->id,
                'description' => $test->description,
                'test_number' => $test->test_number,
                'status' => $test->status,
                'created_by' => [
                    'id' => $test->created_by->id,
                    'name' => $test->created_by->name,
                ],
                'settings' => $test->settings,
                'base_plan' => $base_plan ? [
                    'id' => $base_plan->id,
                    'name' => $base_plan->name,
                ] : [],
                'apps' => $app_plans,
            ],
            'breadcrumbs' => [
                [
                    'url' => '/admin/server/tests',
                    'label' => __('admin.tests.tests'),
                ],
                [
                    'label' => $test->description,
                ],
            ],
        ]);
    }

    public function edit(AccountTest $test)
    {
        $apps = Application::all();
        $base_plans = Plan::all();

        return inertia()->render('Admin/Tests/TestEdit', [
            'apps' => $apps->map(function ($app) {
                return [
                    'id' => $app->id,
                    'name' => $app->name,
                    'slug' => $app->slug,
                    'plans' => $app->plans->map(function ($plan) {
                        return [
                            'id' => $plan->id,
                            'name' => $plan->name,
                        ];
                    }),
                    'versions' => $app->versions->map(function ($version) {
                        return [
                            'id' => $version->id,
                            'version' => $version->name,
                        ];
                    }),
                ];
            }),
            'base_plans' => $base_plans->map(function ($plan) {
                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                ];
            }),
            'test' => [
                'id' => $test->id,
                'description' => $test->description,
                'test_number' => $test->test_number,
                'status' => $test->status,
                'created_by' => [
                    'id' => $test->created_by->id,
                    'name' => $test->created_by->name,
                ],
                'settings' => $test->settings,
            ],
            'breadcrumbs' => [
                [
                    'url' => '/admin/server/tests',
                    'label' => __('admin.tests.tests'),
                ],
                [
                    'label' => $test->description,
                ],
            ],
        ]);
    }

    public function update(Request $request, AccountTest $test)
    {
        $data = $request->validate([
            'test_number' => 'nullable|integer',
            'description' => 'required_with:test_number|string',
            'status' => 'required_without:description|in:in_progress,failed,succeeded',
            'base_plan' => 'required_with:test_number|exists:plans,id',
            'apps' => 'required_with:test_number|array',
        ]);

        if (Arr::has($data, 'test_number')) {
            $test->description = $data['description'];
            $test->test_number = $data['test_number'];
            $test->settings = [
                'apps' => $data['apps'],
                'base_plan' => $data['base_plan'],
            ];
        } else {
            $test->status = $data['status'];
        }
        $test->save();

        return redirect('/admin/server/tests/'.$test->id)->with('success', __('admin.tests.updated'));
    }

    public function destroy(AccountTest $test)
    {
        $test->delete();

        return redirect('/admin/server/tests')->with('success', __('admin.tests.deleted'));
    }

    public function run(AccountTest $test)
    {
        $action = Action::dispatch('system', 'create_tests', [$test]);
        $test->status = 'in_progress';
        $test->save();

        return redirect('/admin/server/tests/'.$test->id)->with('success', __('admin.tests.running'));
    }

    public function clear(AccountTest $test)
    {
        $task = Action::execute(new ClearTestAccounts($test));

        $test->status = 'clearing';
        $test->save();

        return redirect('/admin/server/tests')->with('success', __('admin.tests.clearing'));
    }
}
