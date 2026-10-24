<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        // The project list is the shared `projects` prop (id and name, never a key).
        return Inertia::render('Home');
    }
}
