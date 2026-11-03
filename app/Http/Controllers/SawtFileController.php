<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerateSavedSawtFileRequest;
use App\Services\Tax\CompanySawtSource;
use App\Services\Tax\SawtFileGeneratorService;
use App\Services\TaxPreflightService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SawtFileController extends Controller
{
    public function download(GenerateSavedSawtFileRequest $request, CompanySawtSource $source, SawtFileGeneratorService $generator, TaxPreflightService $audit): StreamedResponse
    {
        $selection = $request->safe()->only(['form_type', 'tax_kind', 'from', 'to']);
        $bundle = $source->read($request->attributes->get('active_company'), $selection);
        abort_unless($request->header('X-Ledger-Revision') === (string) $bundle['revision'], 409, 'Company records changed. Reload before exporting.');
        $prepared = $generator->prepare($bundle['records'], $bundle['settings']);
        $audit->recordExport($request, ['schedule' => 'sawt', 'form' => $selection['form_type'], ...$selection], $prepared['filename'], $generator->lines($prepared));

        return response()->streamDownload(function () use ($generator, $prepared): void {
            foreach ($generator->lines($prepared) as $line) {
                echo $line;
            }
        }, $prepared['filename'], [
            'Content-Type' => 'text/plain; charset=us-ascii',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Accel-Buffering' => 'no',
            'X-SAWT-Detail-Count' => (string) $prepared['count'],
        ]);
    }
}
