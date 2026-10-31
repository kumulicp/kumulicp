<?php

namespace App\Listeners;

use App\Events\AppInstanceSubscriptionChanged;
use App\Events\Domains\DomainDeleted;
use App\Events\OrganizationCreated;
use App\Events\Users\DeletingUser;
use App\Events\Users\UserCreated;
use App\Events\Users\UserUpdated;
use App\Support\AccountManager\UserManager;
use App\Support\Facades\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Laravel\Cashier\Events\WebhookReceived;
use Throwable;

/**
 * Feeds the audit trail from events the app already fires: Laravel's authentication
 * events, the domain events for users / organizations / apps / domains, Stripe
 * webhooks and Eloquent model events for the models listed in config/audit.php.
 *
 * Every handler is a no-op while audit logging is disabled (see AuditService::record).
 */
class AuditSubscriber
{
    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            PasswordReset::class => 'onPasswordReset',
            Verified::class => 'onVerified',

            UserCreated::class => 'onUserCreated',
            UserUpdated::class => 'onUserUpdated',
            DeletingUser::class => 'onUserDeleted',
            OrganizationCreated::class => 'onOrganizationRegistered',
            AppInstanceSubscriptionChanged::class => 'onAppInstanceSubscriptionChanged',
            DomainDeleted::class => 'onDomainDeleted',
            WebhookReceived::class => 'onBillingWebhook',

            'eloquent.created: *' => 'onModelEvent',
            'eloquent.updated: *' => 'onModelEvent',
            'eloquent.deleted: *' => 'onModelEvent',
        ];
    }

    public function onLogin(Login $event): void
    {
        Audit::record('auth.login', properties: ['guard' => $event->guard, 'remember' => $event->remember], actor: $event->user);
    }

    public function onLogout(Logout $event): void
    {
        Audit::record('auth.logout', properties: ['guard' => $event->guard], actor: $event->user);
    }

    public function onFailed(Failed $event): void
    {
        $credentials = $event->credentials;

        // Only the identifier that was tried is kept, never the password.
        Audit::record(
            'auth.login_failed',
            $event->user ? ['type' => 'user', 'id' => $event->user->getAuthIdentifier(), 'label' => $event->user->email ?? null] : null,
            properties: [
                'guard' => $event->guard,
                'identifier' => $credentials['email'] ?? $credentials['username'] ?? null,
            ],
            outcome: 'failure',
            actor: $event->user,
        );
    }

    public function onLockout(Lockout $event): void
    {
        Audit::record(
            'auth.lockout',
            properties: ['identifier' => $event->request->input('email') ?? $event->request->input('username')],
            outcome: 'denied',
        );
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        Audit::record('auth.password_reset', actor: $event->user);
    }

    public function onVerified(Verified $event): void
    {
        Audit::record('auth.email_verified', actor: $event->user);
    }

    public function onUserCreated(UserCreated $event): void
    {
        Audit::record('account_user.created', $this->userSubject($event->user), target_organization_id: $this->organizationId($event->organization));
    }

    public function onUserUpdated(UserUpdated $event): void
    {
        Audit::record('account_user.updated', $this->userSubject($event->user), target_organization_id: $this->organizationId($event->organization));
    }

    public function onUserDeleted(DeletingUser $event): void
    {
        Audit::record('account_user.deleted', $this->userSubject($event->user), target_organization_id: $this->organizationId($event->organization));
    }

    public function onOrganizationRegistered(OrganizationCreated $event): void
    {
        Audit::record('organization.registered', $event->organization, actor: $event->user);
    }

    public function onAppInstanceSubscriptionChanged(AppInstanceSubscriptionChanged $event): void
    {
        Audit::record('app_instance.subscription_changed', $event->app_instance, target_organization_id: $this->organizationId($event->organization));
    }

    public function onDomainDeleted(DomainDeleted $event): void
    {
        Audit::record(
            'domain.deleted',
            ['type' => 'domain', 'id' => $event->domain_name, 'label' => $event->domain_name],
            target_organization_id: $this->organizationId($event->organization),
        );
    }

    public function onBillingWebhook(WebhookReceived $event): void
    {
        // Only the event type and id: webhook payloads carry customer and card details.
        Audit::record(
            'billing.webhook',
            ['type' => 'stripe_event', 'id' => $event->payload['id'] ?? null, 'label' => $event->payload['type'] ?? null],
        );
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    public function onModelEvent(string $event_name, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $model instanceof Model) {
            return;
        }

        // "eloquent.created: App\User" => "created"
        $action = trim(explode(':', substr($event_name, strlen('eloquent.')), 2)[0]);

        Audit::recordModelEvent($action, $model);
    }

    /**
     * @return array{type: string, id: mixed, label: mixed}|null
     */
    private function userSubject(UserManager $user): ?array
    {
        try {
            return ['type' => 'user', 'id' => $user->attribute('username'), 'label' => $user->attribute('name')];
        } catch (Throwable) {
            return null;
        }
    }

    private function organizationId(mixed $organization): ?int
    {
        return is_object($organization) && is_numeric($organization->id ?? null) ? (int) $organization->id : null;
    }
}
