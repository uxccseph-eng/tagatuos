<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyBookRequest;
use App\Jobs\ProvisionClientDatabase;
use App\Models\Company;
use App\Services\CompanyBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyBookController extends Controller
{
    public function index(Request $request, CompanyBookService $books): JsonResponse
    {
        $filters = $request->validate(['ids' => ['sometimes', 'array', 'max:25'], 'ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $companies = $books->accessibleBooks($request->user())->when(isset($filters['ids']), fn ($query) => $query->whereIn('companies.id', $filters['ids']))->paginate(25)
            ->through(fn (Company $company): array => $this->bookData($company));

        return response()->json($companies)->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreCompanyBookRequest $request, CompanyBookService $books): JsonResponse|RedirectResponse
    {
        $company = $books->createBook($request->user(), $request->validated('name'), $request->validated('slug'));

        if (config('tenancy.enabled') && ! $request->expectsJson()) {
            return to_route('account.dashboard')->with('status', 'Client created. Its database and private folder are being prepared. Setup status updates automatically.');
        }
        if (! $request->expectsJson()) {
            $request->session()->put('active_company_id', $company->getKey());
            $request->session()->forget(['company_setup', 'preview_access']);

            return to_route('company.setup', ['company_id' => $company->id])->with('status', 'Company book created. Complete its registration details below.');
        }

        return response()->json(['data' => $this->bookData($company)], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, int $companyId, CompanyBookService $books): JsonResponse
    {
        $company = $books->findAccessibleBook($request->user(), $companyId);

        return response()->json(['data' => $this->bookData($company)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function open(Request $request, int $companyId, CompanyBookService $books): RedirectResponse
    {
        $company = $books->findAccessibleBook($request->user(), $companyId);
        abort_if(config('tenancy.enabled') && ! $company->databaseReady(), 409, 'The client database is not ready. Check Company books for setup status.');
        $request->session()->put('active_company_id', $company->getKey());
        $request->session()->forget(['company_setup', 'preview_access']);

        return to_route('dashboard', ['company_id' => $company->id]);
    }

    /** @return array{id: int, name: string, slug: ?string, role: string, setup_status: string, database_ready: bool, setup_message: ?string} */
    private function bookData(Company $company): array
    {
        return [
            'id' => $company->getKey(),
            'name' => $company->name,
            'slug' => $company->slug,
            'role' => $company->pivot->role,
            'setup_status' => $company->provisioning_status,
            'database_ready' => $company->databaseReady(),
            'setup_message' => $company->provisioning_error,
        ];
    }

    public function retry(Request $request, int $companyId, CompanyBookService $books): RedirectResponse
    {
        $company = $books->findAccessibleBook($request->user(), $companyId);
        abort_unless(config('tenancy.enabled') && $company->pivot->role === 'owner', 403, 'Only the book owner can retry client setup.');
        abort_if($company->deletionPending(), 409, 'This client is being deleted. Retry deletion instead.');
        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($company): void {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
            abort_if($locked->deletionPending(), 409, 'This client is being deleted. Retry deletion instead.');
            if (! $locked->databaseReady()) {
                $locked->forceFill(['provisioning_status' => 'pending', 'provisioning_error' => null])->save();
                ProvisionClientDatabase::dispatch($locked->id)->afterCommit();
            }
        });

        return to_route('account.dashboard')->with('status', 'Client setup queued. Status updates automatically.');
    }
}
