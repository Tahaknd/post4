<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The `url` of an Inertia page is what the browser writes into its address bar when the page loads. Inertia-Laravel
 * builds it from Request::fullUrl() by default, which sorts the query keys and keeps only the LAST of a repeated key, so
 * a Trends link `?e=a&e=b` became `?e=b`. HandleInertiaRequests::urlResolver() returns the request URI as it was sent.
 */
beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->member = User::factory()->create();
    $this->project->users()->attach($this->member);
    $this->trends = "/projects/{$this->project->id}/insights/trends";
});

function pageUrl($response): string
{
    return $response->viewData('page')['url'];
}

it('keeps repeated query keys, their order and their values as sent', function (string $query) {
    $response = $this->actingAs($this->member)->get($this->trends.$query)->assertOk();

    expect(pageUrl($response))->toBe($this->trends.$query);
})->with([
    'repeated key' => ['?e=a&e=b'],
    'repeated key between others' => ['?z=1&e=a&i=week&e=b&e=c'],
    'unsorted keys' => ['?m=unique_users&e=a&i=week'],
    'encoded values' => ['?e=a%20b&e=%C3%A7&f=k%3Dv%26x'],
    'bracket keys' => ['?e[]=a&e[]=b'],
]);

it('answers the same url to an Inertia visit, a partial reload and a version mismatch', function () {
    $url = $this->trends.'?e=a&e=b';
    $version = hash_file('md5', public_path('build/manifest.json'));

    $visit = $this->actingAs($this->member)->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk();
    expect($visit->json('url'))->toBe($url);

    $partial = $this->actingAs($this->member)->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Projects/Insights/Trends', 'X-Inertia-Partial-Data' => 'defaults'])->assertOk();
    expect($partial->json('url'))->toBe($url);

    // A deploy changed the assets: the client reloads this very location, repeated keys included.
    $stale = $this->actingAs($this->member)->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => 'old']);
    $stale->assertStatus(409);
    expect($stale->headers->get('X-Inertia-Location'))->toBe(url($url));
})->skip(fn () => ! is_file(public_path('build/manifest.json')), 'needs a built manifest');

it('does not change urls without a query, with a trailing slash or at the root', function (string $path) {
    $response = $this->actingAs($this->member)->get($path)->assertOk();

    expect(pageUrl($response))->toBe($path);
})->with(['/', '/projects', '/projects/create']);

it('drops an empty query marker, keeps a trailing slash as sent and cannot name another origin', function (string $target, string $expected) {
    $request = Request::create('http://localhost:8000'.$target);

    expect((new HandleInertiaRequests)->urlResolver()($request))->toBe($expected);
})->with([
    'empty marker' => ['/projects?', '/projects'],
    'trailing slash with query' => ['/projects/?a=1&a=2', '/projects/?a=1&a=2'],
    'root with query' => ['/?b=1&a=2&a=3', '/?b=1&a=2&a=3'],
    'doubled leading slash' => ['//evil.example/x?a=1&a=2', '/evil.example/x?a=1&a=2'],
]);
