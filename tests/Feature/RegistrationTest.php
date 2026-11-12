<?php

namespace Tests\Feature;

use App\Livewire\Auth\Register;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_can_open_registration_from_the_welcome_page(): void
    {
        $this->get(route('home'))->assertOk()->assertSee(route('register'));
        $this->get(route('register'))->assertOk()->assertSeeLivewire(Register::class);
    }

    #[TestWith(['12345678'])]
    #[TestWith(['abcdefgh'])]
    #[TestWith(['ABCDEFGH'])]
    public function test_registration_creates_a_user_and_signs_them_in(string $password): void
    {
        Event::fake([Registered::class]);
        $this->get(route('register'));
        $previousSessionId = session()->getId();

        Livewire::test(Register::class)
            ->set('first_name', '  Taylor  ')
            ->set('surname', '  Example  ')
            ->set('phone', '  +1 555 123 4567  ')
            ->set('business', '  Example Business  ')
            ->set('address', '  123 Main Street  ')
            ->set('email', '  TAYLOR@example.com  ')
            ->set('password', $password)
            ->set('password_confirmation', $password)
            ->call('register')
            ->assertHasNoErrors()
            ->assertSet('password', '')
            ->assertSet('password_confirmation', '')
            ->assertRedirect(route('dashboard'));

        $user = User::where('email', 'taylor@example.com')->sole();
        $this->assertSame('Taylor Example', $user->name);
        $this->assertSame('Taylor', $user->first_name);
        $this->assertSame('Example', $user->surname);
        $this->assertSame('+1 555 123 4567', $user->phone);
        $this->assertSame('Example Business', $user->business);
        $this->assertSame('123 Main Street', $user->address);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($previousSessionId, session()->getId());
        Event::assertDispatched(Registered::class, fn (Registered $event): bool => $event->user->is($user));
        $this->get(route('dashboard'))->assertOk()->assertSee('Your account has been created.')->assertDontSee('id="welcome-sign-in"', false);
    }

    public function test_required_fields_are_validated_without_creating_an_account(): void
    {
        Livewire::test(Register::class)->call('register')
            ->assertHasErrors(['first_name' => 'required', 'surname' => 'required', 'phone' => 'required', 'business' => 'required', 'address' => 'required', 'email' => 'required', 'password' => 'required'])
            ->assertSee('The first name field is required.');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    #[DataProvider('invalidRegistrationData')]
    public function test_invalid_input_is_rejected(string $field, string $value, string $rule): void
    {
        $component = Livewire::test(Register::class)
            ->set('first_name', 'Taylor')
            ->set('surname', 'Example')
            ->set('phone', '+1 555 123 4567')
            ->set('business', 'Example Business')
            ->set('address', '123 Main Street')
            ->set('email', 'taylor@example.com')
            ->set('password', 'SecurePassword123!')
            ->set('password_confirmation', 'SecurePassword123!')
            ->set($field, $value);

        if ($field === 'password') {
            $component->set('password_confirmation', $value);
        }

        $component->call('register')
            ->assertHasErrors([$field === 'password_confirmation' ? 'password' : $field => $rule]);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidRegistrationData(): array
    {
        return [
            'blank first name' => ['first_name', '   ', 'required'],
            'long first name' => ['first_name', str_repeat('a', 101), 'max'],
            'long surname' => ['surname', str_repeat('a', 101), 'max'],
            'blank surname' => ['surname', '   ', 'required'],
            'invalid phone' => ['phone', 'not-a-phone', 'regex'],
            'long phone' => ['phone', str_repeat('1', 31), 'max'],
            'blank business' => ['business', '   ', 'required'],
            'long business' => ['business', str_repeat('a', 256), 'max'],
            'blank address' => ['address', '   ', 'required'],
            'long address' => ['address', str_repeat('a', 1001), 'max'],
            'invalid email' => ['email', 'not-an-email', 'email'],
            'long email' => ['email', str_repeat('a', 250).'@example.com', 'max'],
            'short password' => ['password', 'Short1!', 'min'],
            'long password' => ['password', str_repeat('a', 73), 'max'],
            'long multibyte password' => ['password', str_repeat('é', 36).'Aa1!', 'The password must not exceed 72 bytes.'],
            'confirmation mismatch' => ['password_confirmation', 'DifferentPassword123!', 'confirmed'],
        ];
    }

    public function test_an_existing_email_cannot_be_registered_again(): void
    {
        $existingUser = User::factory()->create(['email' => 'taylor@example.com']);

        Livewire::test(Register::class)
            ->set('first_name', 'Another')
            ->set('surname', 'Person')
            ->set('phone', '+1 555 123 4567')
            ->set('business', 'Example Business')
            ->set('address', '123 Main Street')
            ->set('email', 'TAYLOR@example.com')
            ->set('password', 'SecurePassword123!')
            ->set('password_confirmation', 'SecurePassword123!')
            ->call('register')
            ->assertHasErrors(['email' => 'unique'])
            ->assertSee('The email has already been taken.');

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('taylor@example.com', $existingUser->fresh()->email);
        $this->assertGuest();
    }

    public function test_registration_is_rate_limited(): void
    {
        $component = Livewire::test(Register::class);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component->call('register')->assertHasErrors(['first_name' => 'required']);
        }

        $component->call('register')->assertHasErrors(['email'])
            ->assertSee('Too many registration attempts.');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_signed_in_users_cannot_open_registration_or_create_another_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('register'))->assertRedirect(route('dashboard'));
        Livewire::test(Register::class)->call('register')->assertForbidden();

        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($user);
    }
}
