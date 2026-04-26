<?php

namespace App\Http\Controllers\Admin;

use App\Application;
use App\Http\Controllers\Controller;
use App\Organization;
use App\Plan;
use App\Services\TokenService;
use App\User;
use Illuminate\Http\Request;

class Tokens extends Controller
{
    public function __construct(private TokenService $tokens) {}

    /**
     * Return the full token registry for use by the frontend token picker.
     */
    public function registry()
    {
        return response()->json($this->tokens->registry());
    }

    /**
     * Resolve tokens in the given content string using sample context data.
     * The request may optionally supply explicit override values per token key.
     */
    public function preview(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
            'context' => 'nullable|array',
        ]);

        $content = $request->input('content');
        $overrides = $request->input('context', []);

        // Build a real context from the authenticated user's environment when possible
        $context = $this->buildPreviewContext($overrides);

        $resolved = $this->tokens->resolve($content, $context);

        return response()->json(['resolved' => $resolved]);
    }

    /**
     * Demo page — renders the Inertia token editor demo.
     */
    public function demo()
    {
        $registry = $this->tokens->registry();

        $sampleContext = [
            'user.name' => auth()->user()?->name ?? 'Jane Smith',
            'user.email' => auth()->user()?->email ?? 'jane@example.com',
            'user.username' => auth()->user()?->username ?? 'janesmith',
            'org.name' => auth()->user()?->organization?->name ?? 'Acme Corp',
            'org.slug' => auth()->user()?->organization?->slug ?? 'acme-corp',
            'org.contact_email' => auth()->user()?->organization?->contact_email ?? 'hello@acme.com',
            'org.city' => auth()->user()?->organization?->city ?? 'New York',
            'org.country' => auth()->user()?->organization?->country ?? 'US',
            'org.status' => auth()->user()?->organization?->status ?? 'active',
        ];

        // Enrich with plan and first app if available
        $org = auth()->user()?->organization;
        if ($org?->plan) {
            $sampleContext['plan.name'] = $org->plan->name;
            $sampleContext['plan.description'] = $org->plan->description ?? '';
        } else {
            $sampleContext['plan.name'] = 'Professional';
            $sampleContext['plan.description'] = 'Full-featured plan';
        }

        $firstApp = Application::first();
        if ($firstApp) {
            $sampleContext['app.name'] = $firstApp->name;
            $sampleContext['app.slug'] = $firstApp->slug;
            $sampleContext['app.description'] = $firstApp->description ?? '';
            $sampleContext['app.category'] = $firstApp->category ?? '';
            $sampleContext['app.version'] = optional($firstApp->active_version())->version ?? '1.0.0';
        } else {
            $sampleContext['app.name'] = 'My Application';
            $sampleContext['app.slug'] = 'my-application';
            $sampleContext['app.description'] = 'A great application';
            $sampleContext['app.category'] = 'Productivity';
            $sampleContext['app.version'] = '1.0.0';
        }

        return inertia()->render('Admin/Tokens/TokenEditorDemo', [
            'registry' => $registry,
            'sampleContext' => $sampleContext,
            'breadcrumbs' => [
                ['label' => 'Token Editor Demo'],
            ],
        ]);
    }

    private function buildPreviewContext(array $overrides): array
    {
        // This method allows future extension to load real models from override IDs.
        // For now, the frontend supplies flat key→value overrides resolved on the client.
        return [];
    }
}
