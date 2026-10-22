<?php

it('serves the health route', function () {
    $this->get('/up')->assertOk();
});
