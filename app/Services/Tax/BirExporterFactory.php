<?php

namespace App\Services\Tax;

use App\Services\Tax\Forms\Bir1601EQExporter;
use App\Services\Tax\Forms\Bir2550QExporter;
use Illuminate\Validation\ValidationException;

class BirExporterFactory
{
    public function make(string $form): BaseBirExporter
    {
        return match (strtoupper(str_replace('-', '', trim($form)))) {
            '2550Q' => app(Bir2550QExporter::class),
            '1601EQ' => app(Bir1601EQExporter::class),
            default => throw ValidationException::withMessages(['form' => 'XML export is currently mapped for 2550Q v2024 and 1601-EQ only.']),
        };
    }
}
