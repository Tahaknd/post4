<?php

use App\Domain\Capture\Enrichment\LogThrottle;

it('lets one line through per interval', function () {
    $now = 1000;
    $throttle = new LogThrottle(60, function () use (&$now) {
        return $now;
    });

    expect($throttle->allow())->toBeTrue()
        ->and($throttle->allow())->toBeFalse();

    $now = 1059;
    expect($throttle->allow())->toBeFalse();

    $now = 1060;
    expect($throttle->allow())->toBeTrue();
});
