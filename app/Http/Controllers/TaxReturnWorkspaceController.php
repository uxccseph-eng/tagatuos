<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Services\Tax\TaxReturnCatalog;
use App\Services\Tax\TaxReturnWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TaxReturnWorkspaceController extends Controller
{
    public function filed(Request $request, TaxReturnCatalog $catalog, TaxReturnWorkspace $workspace): JsonResponse
    {
        [$company, $input, $period] = $this->selection($request, $catalog);
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:150'], 'filed_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$period['to'], 'before_or_equal:'.CarbonImmutable::now('Asia/Manila')->toDateString()], 'confirmed' => ['accepted'], 'original_id' => ['nullable', 'integer', 'min:1'], 'original_revision' => ['nullable', 'integer', 'min:0']]);
        $workspace->mutate($request, $company, function () use ($company, $input, $period, $data, $workspace, $request): void {
            $row = $workspace->filing($company, $input['form'], $period);
            $reference = trim((string) ($data['reference'] ?? ''));
            if ($row->filed_on !== null) {
                abort_unless($row->filed_on === $data['filed_on'] && ($row->reference ?? '') === $reference, 409, 'A filing is already recorded for this form and period. Its reference cannot be overwritten.');

                return;
            }
            $original = DB::table('tax_return_preparations')->where('company_id', $company->id)->where('form', $input['form'])->where('period_from', $period['from'])->where('period_to', $period['to'])->where('original_slot', 1)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless((int) ($data['original_id'] ?? 0) === (int) ($original?->id ?? 0) && (int) ($data['original_revision'] ?? 0) === (int) ($original?->revision ?? 0), 409, 'The original preparation changed. Reload and review it before recording this filing.');
            if ($original) {
                DB::table('tax_return_preparations')->where('company_id', $company->id)->where('id', $original->id)->update(['status' => 'filed', 'filing_reference' => $reference ?: null, 'filed_on' => $data['filed_on'], 'filed_by' => $request->user()->id, 'revision' => $original->revision + 1, 'updated_at' => now()]);
            }
            DB::table('tax_return_filing_records')->where('id', $row->id)->where('company_id', $company->id)->update(['reference' => $reference ?: null, 'filed_on' => $data['filed_on'], 'filed_by' => $request->user()->id, 'updated_at' => now()]);
        });

        return response()->json(['message' => 'External filing recorded. Keep the receipt as proof.'])->header('Cache-Control', 'no-store, private');
    }

    public function upload(Request $request, TaxReturnCatalog $catalog, TaxReturnWorkspace $workspace): JsonResponse
    {
        [$company, $input, $period] = $this->selection($request, $catalog);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:10240']]);
        $file = $request->file('file');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['file' => 'Choose a genuine PDF, JPG or PNG file.']);
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $path = null;
        try {
            $workspace->mutate($request, $company, function () use ($request, $company, $input, $period, $workspace, $file, $hash, $mime, &$path): void {
                $row = $workspace->filing($company, $input['form'], $period);
                $proofs = DB::table('tax_return_proofs')->where('company_id', $company->id)->where('filing_id', $row->id);
                if ((clone $proofs)->where('sha256', $hash)->exists()) {
                    return;
                }
                abort_if($proofs->count() >= 20, 422, 'This return already has 20 proof files.');
                $path = $file->store($company->storageDirectory().'/tax-returns/'.$row->id, 'client_files');
                $name = preg_replace('/[\x00-\x1F\x7F]/u', '_', str_replace([chr(92), '/'], '_', $file->getClientOriginalName()));
                DB::table('tax_return_proofs')->insert(['company_id' => $company->id, 'filing_id' => $row->id, 'path' => $path, 'original_name' => mb_substr($name, 0, 180), 'mime_type' => $mime, 'size' => $file->getSize(), 'sha256' => $hash, 'uploaded_by' => $request->user()->id, 'created_at' => now()]);
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('client_files')->delete($path);
            }
            throw $exception;
        }

        return response()->json(['message' => 'Proof saved privately for this return. Uploading does not mark it filed or paid.'], 201)->header('Cache-Control', 'no-store, private');
    }

    public function proof(Request $request, int $id): StreamedResponse
    {
        $company = $request->attributes->get('active_company');
        $proof = DB::table('tax_return_proofs')->where('company_id', $company->id)->where('id', $id)->firstOrFail();
        $prefix = $company->storageDirectory().'/tax-returns/'.$proof->filing_id.'/';
        abort_unless(str_starts_with($proof->path, $prefix) && ! str_contains($proof->path, '..'), 404);
        abort_unless(Storage::disk('client_files')->exists($proof->path), 404, 'The proof file is unavailable. Contact the book owner.');

        return Storage::disk('client_files')->download($proof->path, $proof->original_name, ['Content-Type' => $proof->mime_type, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function yearSettings(Request $request, TaxReturnWorkspace $workspace): JsonResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2023,2199'], 'revision' => ['required', 'integer', 'min:0'], 'regime' => ['required', Rule::in(['regular', 'special', 'exempt'])], 'deduction_method' => ['required', Rule::in(['itemized', 'osd'])], 'mcit_start_year' => ['nullable', 'integer', 'between:1900,2199'], 'president' => ['nullable', 'string', 'max:150'], 'treasurer' => ['nullable', 'string', 'max:150'], 'reviewed' => ['accepted']]);
        $company = $request->attributes->get('active_company');
        abort_unless(in_array('settings', $request->attributes->get('company_allowed_tabs', []), true), 403, 'Your role needs Settings access to change annual tax settings.');
        $workspace->mutate($request, $company, function () use ($company, $data, $workspace, $request): void {
            $current = $workspace->yearSettings($company, (int) $data['year']);
            abort_unless($current['revision'] === (int) $data['revision'], 409, 'Another accountant changed these annual settings. Reload and review them first.');
            $settings = [...$current['settings'], ...array_intersect_key($data, array_flip(['regime', 'deduction_method', 'mcit_start_year', 'president', 'treasurer']))];
            $values = ['revision' => $current['revision'] + 1, 'settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_by' => $request->user()->id, 'updated_at' => now()];
            $key = ['company_id' => $company->id, 'year' => $data['year']];
            if ($current['revision'] === 0) {
                DB::table('tax_return_year_settings')->insert([...$key, ...$values, 'created_at' => now()]);
            } else {
                DB::table('tax_return_year_settings')->where($key)->update($values);
            }
        });

        return response()->json(['message' => 'Annual settings saved. Review and regenerate affected income tax returns.'])->header('Cache-Control', 'no-store, private');
    }

    /** @return array{Company, array<string, mixed>, array<string, mixed>} */
    private function selection(Request $request, TaxReturnCatalog $catalog): array
    {
        $input = $request->validate(['form' => ['required', Rule::in(array_keys(TaxReturnCatalog::forms()))], 'year' => ['required', 'integer', 'between:2023,2199'], 'period' => ['required', 'integer', 'between:1,12']]);
        $company = $request->attributes->get('active_company');
        $profile = CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->profile;
        $period = $catalog->period($input['form'], (int) $input['year'], (int) $input['period'], $profile);
        abort_unless($period['applicable'], 422, $period['reason']);

        return [$company, $input, $period];
    }
}
