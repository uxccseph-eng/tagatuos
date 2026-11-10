<?php

namespace App\Services\Tax;

use App\Services\Tax\Forms\Bir0619EExporter;
use App\Services\Tax\Forms\Bir1601CExporter;
use App\Services\Tax\Forms\Bir1601EQExporter;
use App\Services\Tax\Forms\Bir1702QExporter;
use App\Services\Tax\Forms\Bir1702RTExporter;
use App\Services\Tax\Forms\Bir2550QExporter;
use Illuminate\Validation\ValidationException;

class BirExporterFactory
{
    public function make(string $form): BaseBirExporter
    {
        return match (strtoupper(str_replace('-', '', trim($form)))) {
            '1702Q' => app(Bir1702QExporter::class),
            '1702RT' => app(Bir1702RTExporter::class),
            '0619E' => app(Bir0619EExporter::class),
            '1601C' => app(Bir1601CExporter::class),
            '2550Q' => app(Bir2550QExporter::class),
            '1601EQ' => app(Bir1601EQExporter::class),
            default => throw ValidationException::withMessages(['form' => 'XML export is mapped for 0619-E, 1601-C v2018, 2550Q v2024 1601-EQ, 1702Q and 1702-RT.']),
        };
    }
}
