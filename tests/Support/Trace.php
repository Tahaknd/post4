<?php

namespace Tests\Support;

/** The ordered list of everything the fakes were asked to do, to assert on ORDER (process, publish, commit). */
final class Trace
{
    /** @var list<string> */
    public array $events = [];

    public function add(string $event): void
    {
        $this->events[] = $event;
    }
}
