<?php

namespace App\Http\Controllers\Admin\Applications\Plans;

use App\Application;
use App\AppPlan;
use App\Http\Controllers\Controller;
use App\Services\Application\PlanSecretsService;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\PlanBreadcrumbs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class Secrets extends Controller
{
    // Only the names are sent to the browser: a secret's value can be replaced
    // but never read back.
    public function edit(Application $app, AppPlan $plan)
    {
        return inertia()->render('Admin/Applications/Plans/PlanSecrets', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
            ],
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'hidden' => $plan->hidden,
                'shared_app_active' => $plan->isSharedAppActive(),
            ],
            'secrets' => ApplicationFacade::plan($plan)->secrets()->names(),
            'breadcrumbs' => PlanBreadcrumbs::for($app, $plan),
        ]);
    }

    public function update(Request $request, Application $app, AppPlan $plan)
    {
        $validated = $request->validate([
            'secrets' => 'array',
            // Names become the env var name, and (lowercased) the Secret key, so they must differ ignoring case
            'secrets.*.name' => ['required', 'string', 'max:253', 'regex:'.PlanSecretsService::NAME_PATTERN, 'distinct:ignore_case'],
            'secrets.*.value' => 'nullable|string|max:10000',
        ]);

        $secrets = ApplicationFacade::plan($plan)->secrets();
        $existing = $secrets->names();

        foreach ($validated['secrets'] ?? [] as $index => $item) {
            if (! in_array($item['name'], $existing, true) && blank($item['value'] ?? null)) {
                throw ValidationException::withMessages(["secrets.$index.value" => __('admin.applications.plans.secret_value_required')]);
            }
        }

        $secrets->update($validated['secrets'] ?? []);

        return redirect("/admin/apps/{$app->slug}/plans/{$plan->id}/secrets")->with('success', __('admin.applications.plans.secrets_updated', ['plan' => $plan->name]));
    }

    // Returns one saved secret's value, only when an admin asks for it (show/copy
    // on the page): it is never part of a page load. Each reveal is logged by name.
    public function reveal(Request $request, Application $app, AppPlan $plan)
    {
        abort_unless($plan->application_id === $app->id, 404);

        $name = $request->validate(['name' => 'required|string'])['name'];
        $values = ApplicationFacade::plan($plan)->secrets()->values();

        abort_unless(array_key_exists($name, $values), 404);

        Log::info(__('messages.plan_secrets.revealed', ['name' => $name, 'plan' => $plan->name, 'app' => $app->name, 'user' => $request->user()->username]), ['organization_id' => 1]);

        return response()->json(['value' => $values[$name]])->header('Cache-Control', 'no-store, private');
    }
}
