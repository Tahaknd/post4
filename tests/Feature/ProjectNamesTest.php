<?php

use App\Actions\Projects\CreateProject;
use App\Domain\Projects\ProjectRules;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Names a reviewer listed, with the way they are invisible or misleading. */
dataset('rejectedNames', [
    'right-to-left override' => ["Shop\u{202E}gnihtyna"],
    'next line (C1)' => ["Shop\u{0085}Two"],
    'line separator' => ["Shop\u{2028}Two"],
    'paragraph separator' => ["Shop\u{2029}Two"],
    'NBSP only' => ["\u{00A0}\u{00A0}"],
    'zero width space only' => ["\u{200B}"],
    'leading zero width space' => ["\u{200B}Shop"],
    'zero width joiner inside' => ["Sh\u{200D}op"],
    'trailing zero width space' => ["Shop\u{200B}"],
    'BOM' => ["\u{FEFF}Shop"],
    'newline' => ["Shop\nTwo"],
    'tab' => ["Shop\tTwo"],
    'NUL' => ["Shop\0Two"],
    'blank' => ['   '],
    'too long (101)' => [str_repeat('a', 101)],
]);

it('rejects the names that are invisible, misleading or blank, in the rules and in the action', function (string $name) {
    $validator = Validator::make(['name' => $name], ['name' => ProjectRules::name()]);

    expect($validator->fails())->toBeTrue()
        ->and(fn () => app(CreateProject::class)->handle($name))->toThrow(ValidationException::class)
        ->and(Project::count())->toBe(0);
})->with('rejectedNames');

it('accepts ordinary and international names, 100 characters, and stores them without surrounding space', function (string $name, string $stored) {
    $project = app(CreateProject::class)->handle($name);

    expect($project->fresh()->name)->toBe($stored);
})->with([
    'plain' => ['Shop', 'Shop'],
    'padded with NBSP and ideographic space' => ["\u{00A0}\u{3000}Shop\u{2003}", 'Shop'],
    'Turkish' => ['Mağaza İstanbul', 'Mağaza İstanbul'],
    'CJK' => ['商店', '商店'],
    'emoji without joiners' => ['Shop 🛒', 'Shop 🛒'],
    '100 characters' => [str_repeat('ü', 100), str_repeat('ü', 100)],
]);

it('normalises the same way for the form request and the action', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/projects', ['name' => "\u{00A0}Shop\u{00A0}", 'timezone' => 'UTC'])->assertStatus(303);

    expect(Project::firstOrFail()->name)->toBe('Shop');
});

it('rejects NBSP-only and invisible names on the form with the fixed messages', function (string $name, string $message) {
    $this->actingAs(User::factory()->create())->from('/projects/create')->post('/projects', ['name' => $name, 'timezone' => 'UTC'])
        ->assertSessionHasErrors(['name' => $message]);

    expect(Project::count())->toBe(0);
})->with([
    'NBSP only' => ["\u{00A0}", 'Enter a project name.'],
    'RLO' => ["a\u{202E}b", 'The name contains characters that are not allowed.'],
]);

describe('deleting', function () {
    beforeEach(fn () => Queue::fake());

    it('confirms with the visible name, ignoring invisible padding on either side', function (string $stored, string $typed) {
        $project = Project::factory()->create(['name' => $stored]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->delete("/projects/{$project->id}", ['confirm_name' => $typed])->assertStatus(303);

        expect(Project::whereKey($project->id)->exists())->toBeFalse();
    })->with([
        'plain' => ['Shop', 'Shop'],
        'typed with spaces around it' => ['Shop', '  Shop  '],
        'stored with NBSP padding (legacy row)' => ["\u{00A0}Shop\u{00A0}", 'Shop'],
        'stored with a zero width space inside (legacy row)' => ["Sh\u{200B}op", 'Shop'],
        'stored with an RLO (legacy row)' => ["Shop\u{202E}", 'Shop'],
        'typed with the invisible padding' => ['Shop', "\u{200B}Shop\u{00A0}"],
    ]);

    it('still refuses a different name', function () {
        $project = Project::factory()->create(['name' => 'Shop']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->delete("/projects/{$project->id}", ['confirm_name' => 'Shoq'])->assertSessionHasErrors('confirm_name');

        expect(Project::whereKey($project->id)->exists())->toBeTrue();
    });
});

it('trims whitespace and Unicode spaces at the ends, leaves inner text and invisible characters to the rules', function () {
    expect(ProjectRules::normaliseName("  \u{00A0}a b\u{3000}\t"))->toBe('a b')
        ->and(ProjectRules::normaliseName("\u{200B}a"))->toBe("\u{200B}a")
        ->and(ProjectRules::confirmationKey("a\u{200D}b\u{202E}"))->toBe('ab')
        ->and(ProjectRules::confirmationKey("\u{FEFF} a b \u{200B}"))->toBe('a b');
});
