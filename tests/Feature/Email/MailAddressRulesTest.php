<?php

namespace Tests\Feature\Email;

use App\OrgDomain;
use App\Rules\EmailLocalPart;
use App\Rules\MailAddressAvailable;
use App\Services\Email\MailAddressRegistry;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Email\FakeMailAddressRegistry;
use Tests\Support\TestSupports;
use Tests\TestCase;

class MailAddressRulesTest extends TestCase
{
    use RefreshDatabase;

    private FakeMailAddressRegistry $registry;

    private OrgDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        (new TestSupports)->seed();
        $user = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($user);

        $this->registry = new FakeMailAddressRegistry;
        $this->app->instance(MailAddressRegistry::class, $this->registry);

        $this->domain = OrgDomain::factory()->create([
            'organization_id' => $user->organization->id,
            'name' => 'example.com',
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validLocalParts(): array
    {
        return [
            'plain' => ['jane'],
            'dot' => ['jane.doe'],
            'hyphen' => ['j-doe'],
            'underscore' => ['jane_doe'],
            'digits' => ['a1'],
            'mixed separators' => ['j.d-o_e'],
            'longest' => [str_repeat('a', 64)],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidLocalParts(): array
    {
        return [
            'uppercase' => ['Jane'],
            'leading dot' => ['.jane'],
            'trailing dot' => ['jane.'],
            'double dot' => ['ja..ne'],
            'mixed double separator' => ['ja._ne'],
            'plus tag' => ['jane+tag'],
            'space' => ['jane doe'],
            'at sign' => ['jane@example.com'],
            'slash' => ['../etc'],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    #[DataProvider('validLocalParts')]
    public function test_a_valid_local_part_passes(string $local_part)
    {
        $validator = Validator::make(['email' => $local_part], ['email' => [new EmailLocalPart]]);

        $this->assertTrue($validator->passes());
    }

    #[DataProvider('invalidLocalParts')]
    public function test_an_invalid_local_part_is_rejected_with_the_explanation(string $local_part)
    {
        $validator = Validator::make(['email' => $local_part], ['email' => [new EmailLocalPart]]);

        $this->assertTrue($validator->fails());
        $this->assertSame(__('messages.rule.email_local_part'), $validator->errors()->first('email'));
    }

    public function test_an_empty_local_part_is_rejected_by_required()
    {
        // Laravel skips a rule that is not implicit for an empty value, so the controller pairs this one with `required`
        $validator = Validator::make(['email' => ''], ['email' => ['required', 'string', new EmailLocalPart]]);

        $this->assertTrue($validator->fails());
    }

    public function test_the_full_address_is_checked_not_just_the_local_part()
    {
        $this->registry->taken = ['jane@example.com'];

        $validator = Validator::make(
            ['email' => 'jane', 'domain' => $this->domain->id],
            ['email' => [new MailAddressAvailable]],
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['jane@example.com'], $this->registry->asked);
    }

    public function test_an_address_that_is_free_passes()
    {
        $this->registry->taken = ['someone.else@example.com'];

        $validator = Validator::make(
            ['email' => 'jane', 'domain' => $this->domain->id],
            ['email' => [new MailAddressAvailable]],
        );

        $this->assertTrue($validator->passes());
    }

    public function test_the_same_local_part_on_another_domain_does_not_block_it()
    {
        $this->registry->taken = ['jane@other.com'];

        $validator = Validator::make(
            ['email' => 'jane', 'domain' => $this->domain->id],
            ['email' => [new MailAddressAvailable]],
        );

        $this->assertTrue($validator->passes());
    }

    public function test_an_address_taken_in_another_case_still_counts_as_taken()
    {
        $this->registry->taken = ['jane@example.com'];
        $this->domain->name = 'EXAMPLE.com';
        $this->domain->save();

        $validator = Validator::make(
            ['email' => 'jane', 'domain' => $this->domain->id],
            ['email' => [new MailAddressAvailable]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_a_domain_the_organization_does_not_own_is_left_to_the_domain_rule()
    {
        $other = OrgDomain::factory()->create(['organization_id' => 999999, 'name' => 'foreign.com']);
        $this->registry->taken = ['jane@foreign.com'];

        $validator = Validator::make(
            ['email' => 'jane', 'domain' => $other->id],
            ['email' => [new MailAddressAvailable]],
        );

        $this->assertTrue($validator->passes());
        $this->assertSame([], $this->registry->asked);
    }
}
