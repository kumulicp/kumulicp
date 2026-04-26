<?php

namespace App\Services;

use App\Application;
use App\Organization;
use App\Plan;
use App\User;

class TokenService
{
    /**
     * Returns the full token registry: all available tokens grouped by category.
     * Each entry includes the token key, a human-readable label, and an example.
     */
    public function registry(): array
    {
        return [
            'user' => [
                'label' => 'User',
                'icon' => 'person',
                'tokens' => [
                    ['key' => 'user.name', 'label' => 'Full Name', 'example' => 'Jane Smith'],
                    ['key' => 'user.email', 'label' => 'Email Address', 'example' => 'jane@example.com'],
                    ['key' => 'user.username', 'label' => 'Username', 'example' => 'janesmith'],
                ],
            ],
            'org' => [
                'label' => 'Organization',
                'icon' => 'business',
                'tokens' => [
                    ['key' => 'org.name', 'label' => 'Name', 'example' => 'Acme Corp'],
                    ['key' => 'org.slug', 'label' => 'Slug', 'example' => 'acme-corp'],
                    ['key' => 'org.contact_email', 'label' => 'Contact Email', 'example' => 'hello@acme.com'],
                    ['key' => 'org.city', 'label' => 'City', 'example' => 'New York'],
                    ['key' => 'org.country', 'label' => 'Country', 'example' => 'US'],
                    ['key' => 'org.status', 'label' => 'Status', 'example' => 'active'],
                ],
            ],
            'plan' => [
                'label' => 'Plan',
                'icon' => 'workspace_premium',
                'tokens' => [
                    ['key' => 'plan.name', 'label' => 'Name', 'example' => 'Professional'],
                    ['key' => 'plan.description', 'label' => 'Description', 'example' => 'Full-featured plan'],
                ],
            ],
            'app' => [
                'label' => 'Application',
                'icon' => 'apps',
                'tokens' => [
                    ['key' => 'app.name', 'label' => 'Name', 'example' => 'My App'],
                    ['key' => 'app.slug', 'label' => 'Slug', 'example' => 'my-app'],
                    ['key' => 'app.description', 'label' => 'Description', 'example' => 'A great application'],
                    ['key' => 'app.category', 'label' => 'Category', 'example' => 'Productivity'],
                    ['key' => 'app.version', 'label' => 'Active Version', 'example' => '2.1.0'],
                ],
            ],
        ];
    }

    /**
     * Return the unique category prefixes (e.g. ['user', 'plan']) that appear
     * in $content. Callers use this to load only the models they actually need.
     */
    public function neededCategories(string $content): array
    {
        preg_match_all('/\{\{([a-z_]+)\.[a-z_]+\}\}/', $content, $matches);
        return array_unique($matches[1]);
    }

    /**
     * Resolve all {{token.key}} occurrences in $content using live model data.
     * Any token without a matching context value is left as-is.
     */
    public function resolve(string $content, array $context = []): string
    {
        $values = $this->buildValues($context);

        return preg_replace_callback('/\{\{([a-z_]+\.[a-z_]+)\}\}/', function ($matches) use ($values) {
            $token = $matches[1];
            return array_key_exists($token, $values) ? (string) $values[$token] : $matches[0];
        }, $content);
    }

    /**
     * Build a flat key→value map from the provided context models.
     *
     * Context keys: 'user' (User), 'org' (Organization), 'plan' (Plan), 'app' (Application)
     */
    private function buildValues(array $context): array
    {
        $values = [];

        if (isset($context['user']) && $context['user'] instanceof User) {
            $user = $context['user'];
            $values['user.name'] = $user->name;
            $values['user.email'] = $user->email;
            $values['user.username'] = $user->username ?? explode('@', $user->email)[0];
        }

        if (isset($context['org']) && $context['org'] instanceof Organization) {
            $org = $context['org'];
            $values['org.name'] = $org->name;
            $values['org.slug'] = $org->slug;
            $values['org.contact_email'] = $org->contact_email;
            $values['org.city'] = $org->city;
            $values['org.country'] = $org->country;
            $values['org.status'] = $org->status;
        }

        if (isset($context['plan']) && $context['plan'] instanceof Plan) {
            $plan = $context['plan'];
            $values['plan.name'] = $plan->name;
            $values['plan.description'] = $plan->description;
        }

        if (isset($context['app']) && $context['app'] instanceof Application) {
            $app = $context['app'];
            $values['app.name'] = $app->name;
            $values['app.slug'] = $app->slug;
            $values['app.description'] = $app->description;
            $values['app.category'] = $app->category;
            $values['app.version'] = optional($app->active_version())->version;
        }

        return $values;
    }
}
