<?php

namespace App\Http\Controllers\Admin;

use App\Application;
use App\Http\Controllers\Controller;
use App\Services\TokenService;
use Illuminate\Http\Request;

class Tokens extends Controller
{
    // Default content shown in the demo editor on first load.
    // Kept here so the backend can scan it and load only the needed context.
    private const DEMO_DEFAULT_CONTENT =
        '<p>Hello <strong>{{user.name}}</strong>,</p>' .
        '<p>Welcome to <strong>{{org.name}}</strong>! ' .
        'Your account is currently on the <strong>{{plan.name}}</strong> plan.</p>' .
        '<p>You are using <strong>{{app.name}}</strong> (version {{app.version}}).</p>' .
        '<p>If you have questions, please contact us at {{org.contact_email}}.</p>';

    public function __construct(private TokenService $tokens) {}

    /**
     * Return the full token registry for use by the frontend token picker.
     */
    public function registry()
    {
        return response()->json($this->tokens->registry());
    }

    /**
     * Resolve tokens in the given content using live context data.
     * Only loads models for categories that actually appear in the content.
     */
    public function preview(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
        ]);

        $content = $request->input('content');
        $needed = $this->tokens->neededCategories($content);
        $context = $this->contextForCategories($needed);

        return response()->json([
            'resolved' => $this->tokens->resolve($content, $context),
        ]);
    }

    /**
     * Demo page — only loads context data for categories used in the default content.
     */
    public function demo()
    {
        $needed = $this->tokens->neededCategories(self::DEMO_DEFAULT_CONTENT);

        return inertia()->render('Admin/Tokens/TokenEditorDemo', [
            'registry' => $this->tokens->registry(),
            'sampleContext' => $this->contextForCategories($needed),
            'defaultContent' => self::DEMO_DEFAULT_CONTENT,
            'breadcrumbs' => [
                ['label' => 'Token Editor Demo'],
            ],
        ]);
    }

    /**
     * Build a flat key→value map by loading only the models whose category
     * prefix appears in $categories. No unnecessary DB queries are made.
     */
    private function contextForCategories(array $categories): array
    {
        $ctx = [];

        if (in_array('user', $categories)) {
            $user = auth()->user();
            $ctx['user.name'] = $user?->name ?? 'Jane Smith';
            $ctx['user.email'] = $user?->email ?? 'jane@example.com';
            $ctx['user.username'] = $user?->username ?? 'janesmith';
        }

        if (in_array('org', $categories)) {
            $org = auth()->user()?->organization;
            $ctx['org.name'] = $org?->name ?? 'Acme Corp';
            $ctx['org.slug'] = $org?->slug ?? 'acme-corp';
            $ctx['org.contact_email'] = $org?->contact_email ?? 'hello@acme.com';
            $ctx['org.city'] = $org?->city ?? 'New York';
            $ctx['org.country'] = $org?->country ?? 'US';
            $ctx['org.status'] = $org?->status ?? 'active';
        }

        if (in_array('plan', $categories)) {
            $plan = auth()->user()?->organization?->plan;
            $ctx['plan.name'] = $plan?->name ?? 'Professional';
            $ctx['plan.description'] = $plan?->description ?? 'Full-featured plan';
        }

        if (in_array('app', $categories)) {
            $app = Application::first();
            $ctx['app.name'] = $app?->name ?? 'My Application';
            $ctx['app.slug'] = $app?->slug ?? 'my-application';
            $ctx['app.description'] = $app?->description ?? 'A great application';
            $ctx['app.category'] = $app?->category ?? 'Productivity';
            $ctx['app.version'] = optional($app?->active_version())->version ?? '1.0.0';
        }

        return $ctx;
    }
}
