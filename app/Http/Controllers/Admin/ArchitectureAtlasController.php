<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ArchitectureAtlasController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Admin/ArchitectureAtlas/Index');
    }
}
