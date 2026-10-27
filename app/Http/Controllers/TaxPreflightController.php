<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyBookService;
use App\Services\TaxPreflightService;
use App\TeamPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaxPreflightController extends Controller
{
    public function show(Request $request, TaxPreflightService $service): JsonResponse
    {
        $company = $this->company($request);
        $result = $service->check($company, $this->selection($request));
        $history = DB::table('tax_preflight_reviews')->join('users', 'users.id', '=', 'tax_preflight_reviews.user_id')
            ->where('company_id', $company->id)->where('schedule', $result['schedule'])->where('period_from', $result['from'])->where('period_to', $result['to'])->where('form', $result['form'])
            ->orderByDesc('tax_preflight_reviews.id')->limit(20)->get(['tax_preflight_reviews.id', 'users.name as actor', 'action', 'snapshot_hash', 'filename', 'file_hash', 'note', 'tax_preflight_reviews.created_at']);

        return response()->json([...$result, 'history' => $history])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, TaxPreflightService $service): JsonResponse
    {
        $company = $this->company($request);
        $selection = $this->selection($request);
        $data = $request->validate(['snapshot_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'note' => ['required', 'string', 'min:3', 'max:2000']]);
        $id = DB::transaction(function () use ($request, $company, $selection, $data, $service): int {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $this->company($request);
            $result = $service->check($company, $selection);
            abort_unless(hash_equals($result['snapshot_hash'], $data['snapshot_hash']), 409, 'Company records or Masters changed. Run the checks again before recording your review.');

            return DB::table('tax_preflight_reviews')->insertGetId([
                'company_id' => $company->id, 'user_id' => $request->user()->id, 'schedule' => $result['schedule'], 'period_from' => $result['from'], 'period_to' => $result['to'], 'form' => $result['form'],
                'snapshot_hash' => $result['snapshot_hash'], 'rules_version' => TaxPreflightService::VERSION, 'action' => 'review', 'note' => $data['note'], 'summary' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        });

        return response()->json(['id' => $id, 'message' => 'Review recorded for this snapshot. This is not BIR validator acceptance.'])->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string, string> */
    private function selection(Request $request): array
    {
        $data = $request->validate(['schedule' => ['required', 'in:relief-sales,relief-purchases,sawt,qap-ewt'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'form' => ['nullable', 'in:1701,1702,1701Q,1702Q,2550Q'], 'tax_kind' => ['nullable', 'in:income,vat']]);

        return ['schedule' => $data['schedule'], 'from' => $data['from'], 'to' => $data['to'], 'form' => $data['schedule'] === 'sawt' ? ($data['form'] ?? '1702Q') : '', 'tax_kind' => $data['schedule'] === 'sawt' ? ($data['tax_kind'] ?? 'income') : 'income'];
    }

    private function company(Request $request): Company
    {
        abort_unless($request->user() && $request->attributes->get('active_company'), 403, 'Open an assigned company book.');
        $company = app(CompanyBookService::class)->findAccessibleBook(User::query()->findOrFail($request->user()->id), $request->attributes->get('active_company')->id);
        abort_unless(in_array('tax-reports', TeamPermissions::allowed($company->pivot->role, $company->pivot->allowed_tabs), true), 403);

        return $company;
    }
}
