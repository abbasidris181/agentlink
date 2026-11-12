<?php

namespace Tests\Feature;

use App\Livewire\NinModification;
use App\Models\ServicePrice;
use App\Models\User;
use App\ServiceCatalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NinModificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['first'])]
    #[TestWith(['second'])]
    public function test_cards_open_protected_modification_forms(string $stage): void
    {
        $url = route('nin.modification.form', ['stage' => $stage]);
        $this->get($url)->assertRedirect(route('home'));
        $this->actingAs(User::factory()->create());
        $this->get(route('nin.modification'))->assertOk()->assertSee($url, false);
        $this->get($url)->assertOk()->assertSeeLivewire(NinModification::class)
            ->assertSee(ucfirst($stage).' modification')->assertSee('Modification type')->assertSee('Clear photo')
            ->assertSeeInOrder(['id="modification-type-dob"', 'id="modification-type-dob_above_5"', 'id="modification-type-dob_above_10"', 'id="modification-type-dob_above_15"', 'id="modification-type-address"'], false);
    }

    #[DataProvider('modifications')]
    public function test_each_modification_type_accepts_its_requested_fields(string $stage, string $type): void
    {
        Storage::fake('local');
        ServicePrice::factory()->create(['service' => 'nin-modification', 'method' => $stage.'_'.$type, 'amount' => 0]);
        $user = User::factory()->create(['wallet_balance' => 100000]);
        $this->actingAs($user);
        Http::preventStrayRequests();
        $component = Livewire::test(NinModification::class, ['stage' => $stage])->set('types', [$type])
            ->set('nin', '01234567890')->set('details', $this->details());
        if ($type !== 'gender') {
            $component->set('photo', UploadedFile::fake()->image('portrait.jpg', 300, 400));
        }

        $component->call('submit')->assertHasNoErrors()->assertSet('showAvailability', true)
            ->assertSee('saved for admin review');
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('service_requests', 1);
        Http::assertNothingSent();
    }

    #[DataProvider('modifications')]
    public function test_modification_types_require_their_relevant_fields(string $stage, string $type): void
    {
        $this->actingAs(User::factory()->create());
        $required = match ($type) {
            'name' => ['nin', 'details.first_name', 'details.surname', 'photo'],
            'phone' => ['nin', 'details.phone', 'photo'],
            'dob', 'dob_above_5', 'dob_above_10', 'dob_above_15' => ['nin', 'details.dob', 'photo'],
            'address' => ['nin', 'details.address', 'photo'],
            'gender' => ['nin', 'details.gender'],
        };
        if ($stage === 'second' && $type === 'name') {
            $required = [...$required, 'details.old_first_name', 'details.old_surname'];
        }
        if ($stage === 'second' && in_array($type, ['dob', 'dob_above_5', 'dob_above_10', 'dob_above_15'], true)) {
            $required[] = 'details.old_dob';
        }

        Livewire::test(NinModification::class, ['stage' => $stage])->set('types', [$type])->call('submit')
            ->assertHasErrors($required)->assertHasNoErrors(['details.middle_name', 'details.old_middle_name'])
            ->assertSet('showAvailability', false);
    }

    #[TestWith(['first', 'name', false])]
    #[TestWith(['second', 'name', true])]
    #[TestWith(['first', 'dob', false])]
    #[TestWith(['second', 'dob', true])]
    #[TestWith(['first', 'dob_above_5', false])]
    #[TestWith(['second', 'dob_above_5', true])]
    #[TestWith(['first', 'dob_above_10', false])]
    #[TestWith(['second', 'dob_above_10', true])]
    #[TestWith(['first', 'dob_above_15', false])]
    #[TestWith(['second', 'dob_above_15', true])]
    public function test_old_details_only_appear_for_the_relevant_second_modification(string $stage, string $type, bool $oldFields): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(NinModification::class, ['stage' => $stage])->set('types', [$type]);
        $label = $type === 'name' ? 'Old first name' : 'Old date of birth';
        if ($oldFields) {
            $component->assertSee($label);
        } else {
            $component->assertDontSee($label);
        }
    }

    #[TestWith(['nin', 'invalid', 'nin'])]
    #[TestWith(['phone', 'abc', 'details.phone'])]
    #[TestWith(['dob', '2999-01-01', 'details.dob'])]
    #[TestWith(['gender', 'invalid', 'details.gender'])]
    #[TestWith(['address', '', 'details.address'])]
    public function test_invalid_modification_values_are_rejected(string $field, string $value, string $error): void
    {
        $this->actingAs(User::factory()->create());
        $type = $field === 'nin' ? 'gender' : $field;
        $component = Livewire::test(NinModification::class)->set('types', [$type])
            ->set('nin', '01234567890')->set('details', $this->details());
        if ($field === 'nin') {
            $component->set('nin', $value);
        } else {
            $component->set('details.'.$field, $value);
        }
        if ($type !== 'gender') {
            $component->set('photo', UploadedFile::fake()->image('portrait.jpg'));
        }
        $component->call('submit')->assertHasErrors([$error])->assertSet('showAvailability', false);
    }

    #[TestWith(['document.pdf', 'application/pdf', 10])]
    #[TestWith(['portrait.jpg', 'image/jpeg', 5121])]
    public function test_non_images_and_oversized_photos_are_rejected(string $name, string $mime, int $size): void
    {
        $this->actingAs(User::factory()->create());
        $upload = $mime === 'image/jpeg' ? UploadedFile::fake()->image($name)->size($size) : UploadedFile::fake()->create($name, $size, $mime);
        Livewire::test(NinModification::class)->set('nin', '01234567890')->set('details', $this->details())
            ->set('photo', $upload)->call('submit')->assertHasErrors(['photo'])->assertSet('showAvailability', false);
    }

    public function test_changing_types_preserves_retained_values_photo_and_clears_removed_fields(): void
    {
        Storage::fake('local');
        ServicePrice::factory()->create(['service' => 'nin-modification', 'method' => 'second_name', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        $names = array_intersect_key($this->details(), array_flip(['first_name', 'surname', 'old_first_name', 'old_surname']));
        $component = Livewire::test(NinModification::class, ['stage' => 'second'])
            ->set('nin', '01234567890')->set('details', $names)
            ->set('photo', UploadedFile::fake()->image('portrait.jpg'))->call('submit')
            ->assertHasNoErrors()->assertSet('showAvailability', true)
            ->set('types', ['name', 'phone', 'dob'])->assertSet('details', $names)->assertSet('showAvailability', false);
        $this->assertNotNull($component->get('photo'));
        $component->set('details.phone', '08165072514')->set('details.dob', '1990-01-01')->set('details.old_dob', '1991-01-01')
            ->set('types', ['phone', 'dob_above_5'])->assertSet('details', [
                'phone' => '08165072514', 'dob' => '1990-01-01', 'old_dob' => '1991-01-01',
            ])->assertDontSee('Old first name');
        $this->assertNotNull($component->get('photo'));
        $component->set('types', ['gender'])->assertSet('details', [])->assertSet('photo', null)
            ->assertSet('nin', '01234567890')->assertDontSee('Clear photo')
            ->call('submit')->assertHasErrors(['details.gender'])
            ->set('types', ['phone'])->assertHasNoErrors()->assertSee('New phone number');
    }

    public function test_guests_and_unknown_stages_are_rejected(): void
    {
        Livewire::test(NinModification::class)->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->get('/nin/modification/third')->assertNotFound();
        Livewire::test(NinModification::class, ['stage' => 'third'])->assertNotFound();
    }

    public function test_submission_requires_a_signed_in_user_and_known_type(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(NinModification::class);
        $component->set('types', ['unknown'])->call('submit')->assertHasErrors(['types.0']);
        Auth::logout();
        $component->call('submit')->assertForbidden();
    }

    #[TestWith(['first'])]
    #[TestWith(['second'])]
    public function test_combined_types_render_each_shared_input_once_and_validate_together(string $stage): void
    {
        Storage::fake('local');
        foreach (array_keys(ServiceCatalog::modificationTypes()) as $type) {
            ServicePrice::factory()->create(['service' => 'nin-modification', 'method' => $stage.'_'.$type, 'amount' => 0]);
        }
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(NinModification::class, ['stage' => $stage])->set('types', [
            'name', 'phone', 'dob', 'dob_above_5', 'dob_above_10', 'dob_above_15', 'address', 'gender',
        ]);
        $html = $component->html();
        foreach (['nin', 'dob', 'photo', 'first_name', 'surname', 'phone', 'address', 'gender'] as $field) {
            $this->assertSame(1, substr_count($html, 'id="modification-'.$field.'"'));
        }
        $this->assertSame($stage === 'second' ? 1 : 0, substr_count($html, 'id="modification-old_dob"'));
        $component->set('nin', '01234567890')->set('details', $this->details())
            ->set('photo', UploadedFile::fake()->image('portrait.jpg'))->call('submit')
            ->assertHasNoErrors()->assertSet('showAvailability', true);
    }

    #[TestWith([[], 'types'])]
    #[TestWith([['name', 'name'], 'types.0'])]
    #[TestWith([['unknown'], 'types.0'])]
    public function test_types_must_be_selected_valid_and_distinct(array $types, string $error): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(NinModification::class)->set('types', $types)->call('submit')
            ->assertHasErrors([$error])->assertSet('showAvailability', false);
    }

    #[TestWith([['gender', 'name']])]
    #[TestWith([['gender', 'phone']])]
    #[TestWith([['gender', 'dob_above_10']])]
    public function test_gender_combined_with_another_type_still_requires_one_photo(array $types): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(NinModification::class)->set('types', $types)->set('nin', '01234567890')
            ->set('details', $this->details())->call('submit')->assertHasErrors(['photo']);
    }

    public static function modifications(): array
    {
        $cases = [];
        foreach (['first', 'second'] as $stage) {
            foreach (['name', 'phone', 'dob', 'dob_above_5', 'dob_above_10', 'dob_above_15', 'address', 'gender'] as $type) {
                $cases[$stage.' '.$type] = [$stage, $type];
            }
        }

        return $cases;
    }

    private function details(): array
    {
        return [
            'first_name' => 'Taylor', 'surname' => 'Example',
            'old_first_name' => 'Alex', 'old_surname' => 'Example',
            'phone' => '08165072514', 'dob' => '1990-01-01', 'old_dob' => '1991-01-01',
            'address' => '12 Sample Street', 'gender' => 'female',
        ];
    }
}
