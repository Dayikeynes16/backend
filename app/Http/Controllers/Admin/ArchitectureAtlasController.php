<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;
use RuntimeException;

class ArchitectureAtlasController extends Controller
{
    public function __invoke(): Response
    {
        $manifestPath = resource_path('js/Features/Architecture/data/system-architecture.json');
        $manifestJson = file_get_contents($manifestPath);

        if ($manifestJson === false) {
            throw new RuntimeException('No se pudo leer el manifiesto del Atlas.');
        }

        try {
            $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('El manifiesto del Atlas contiene JSON inválido.', previous: $exception);
        }

        if (! is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('El manifiesto del Atlas debe ser un objeto JSON.');
        }

        return Inertia::render('Admin/ArchitectureAtlas/Index', [
            'manifest' => $manifest,
        ]);
    }
}
