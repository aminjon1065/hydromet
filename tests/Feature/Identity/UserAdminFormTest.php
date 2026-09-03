<?php

namespace Tests\Feature\Identity;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The panel pages, exercised through the form an administrator actually uses.
 *
 * The service tests prove the rules; these prove the wiring — that the pages
 * route their writes through the service instead of saving the model
 * themselves, that a blank password box means "leave it alone", and that
 * nothing credential-shaped is ever loaded into the form.
 */
class UserAdminFormTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-9';

    #[Test]
    public function creating_an_account_through_the_form_goes_through_the_domain_service(): void
    {
        $administrator = $this->administrator();
        $this->actingAs($administrator);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Feruza Karimova',
                'email' => 'Feruza.Karimova@EXAMPLE.TJ',
                'role' => UserRole::Operator->value,
                'is_active' => true,
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $account = User::query()->where('email', 'feruza.karimova@example.tj')->sole();

        $this->assertSame(UserRole::Operator, $account->role);
        $this->assertTrue($account->is_active);
        $this->assertTrue(Hash::check(self::PASSWORD, $account->password));

        // The audit event is the proof the service ran rather than the page
        // saving a model of its own.
        $this->assertSame(1, AuditEvent::query()->where('action', 'identity.user.created')->count());
    }

    #[Test]
    public function the_confirmation_must_match(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Mismatch',
                'email' => 'mismatch@example.tj',
                'role' => UserRole::Editor->value,
                'is_active' => true,
                'password' => self::PASSWORD,
                'password_confirmation' => 'something-else-entirely',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.tj']);
    }

    #[Test]
    public function a_password_below_the_policy_is_rejected(): void
    {
        $this->actingAs($this->administrator());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Weak',
                'email' => 'weak@example.tj',
                'role' => UserRole::Editor->value,
                'is_active' => true,
                'password' => 'short1',
                'password_confirmation' => 'short1',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'weak@example.tj']);
    }

    /**
     * The stored hash must never reach the browser, and an untouched box must
     * never overwrite it.
     */
    #[Test]
    public function the_edit_form_never_loads_a_password_and_an_empty_box_keeps_it(): void
    {
        $administrator = $this->administrator();
        $this->actingAs($administrator);

        $account = User::factory()->create(['role' => UserRole::Editor, 'name' => 'Before']);
        $storedHash = $account->password;

        $page = Livewire::test(EditUser::class, ['record' => $account->id]);

        $page->assertFormSet([
            'password' => null,
            'password_confirmation' => null,
        ]);
        $this->assertStringNotContainsString($storedHash, (string) $page->html());

        $page->fillForm(['name' => 'After'])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();

        $this->assertSame('After', $account->name);
        $this->assertSame($storedHash, $account->password);
        $this->assertSame(0, AuditEvent::query()->where('action', 'identity.user.credentials_changed')->count());
    }

    #[Test]
    public function a_new_password_typed_into_the_edit_form_is_applied_and_audited(): void
    {
        $this->actingAs($this->administrator());
        $account = User::factory()->create(['role' => UserRole::Editor]);

        Livewire::test(EditUser::class, ['record' => $account->id])
            ->fillForm([
                'password' => 'a-brand-new-secret-42',
                'password_confirmation' => 'a-brand-new-secret-42',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('a-brand-new-secret-42', $this->reloaded($account)->password));
        $this->assertSame(1, AuditEvent::query()->where('action', 'identity.user.credentials_changed')->count());
    }

    /**
     * The two fields you may not turn on yourself are visibly closed, which
     * explains the rule before someone runs into it. The service refuses them
     * regardless; this is the courtesy half.
     */
    #[Test]
    public function role_and_activation_are_closed_on_your_own_account(): void
    {
        $administrator = $this->administrator();
        $this->actingAs($administrator);

        Livewire::test(EditUser::class, ['record' => $administrator->id])
            ->assertFormFieldIsDisabled('role')
            ->assertFormFieldIsDisabled('is_active');

        $other = User::factory()->create(['role' => UserRole::Editor]);

        Livewire::test(EditUser::class, ['record' => $other->id])
            ->assertFormFieldIsEnabled('role')
            ->assertFormFieldIsEnabled('is_active');
    }

    /**
     * A change of access takes effect on the very next page, not whenever the
     * person next signs in — and it does so through the panel form, not only
     * through the service the form calls.
     *
     * What a person with a session actually experiences is asserted in
     * `SessionRevocationTest`, over a real sign-in and three session drivers.
     * Here the point is narrower: the form moved the security stamp, and the
     * page the operator asks for next is refused.
     */
    #[Test]
    public function a_deactivated_user_cannot_open_the_next_admin_page(): void
    {
        $administrator = $this->administrator();
        $operator = User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);
        $stampBefore = $operator->session_version;

        // The operator is signed in and working.
        $this->actingAs($operator)->get('/admin')->assertOk();

        $this->actingAs($administrator);

        Livewire::test(EditUser::class, ['record' => $operator->id])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        // The stamp their session was opened against is no longer current...
        $deactivated = $this->reloaded($operator);

        $this->assertFalse($deactivated->is_active);
        $this->assertSame($stampBefore + 1, $deactivated->session_version);

        // ...and the next page they ask for is refused, not served.
        $response = $this->actingAs($deactivated)->get('/admin');

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertContains($response->getStatusCode(), [403, 302]);
    }

    /**
     * The stored row, re-read. `fresh()` is nullable in general; here the row
     * was created by the test and its absence would be the bug.
     */
    private function reloaded(User $account): User
    {
        $stored = $account->fresh();

        $this->assertNotNull($stored);

        return $stored;
    }

    private function administrator(string $email = 'admin@example.tj'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::Administrator,
            'is_active' => true,
        ]);
    }
}
