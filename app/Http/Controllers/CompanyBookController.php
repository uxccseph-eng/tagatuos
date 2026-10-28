<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyBookRequest;
use App\Models\Company;
use App\Services\CompanyBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanyBookController extends Controller
{
    public function index(Request $request, CompanyBookService $books): JsonResponse
    {
        $companies = $books->accessibleBooks($request->user())->paginate(25)
            ->through(fn (Company $company): array => $this->bookData($company));

        return response()->json($companies)->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreCompanyBookRequest $request, CompanyBookService $books): JsonResponse|RedirectResponse
    {
        $company = $books->createBook($request->user(), $request->validated('name'), $request->validated('slug'));

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
        $request->session()->put('active_company_id', $company->getKey());
        $request->session()->forget(['company_setup', 'preview_access']);

        return to_route('dashboard', ['company_id' => $company->id]);
    }

    /** @return array{id: int, name: string, slug: ?string, role: string} */
    private function bookData(Company $company): array
    {
        return [
            'id' => $company->getKey(),
            'name' => $company->name,
            'slug' => $company->slug,
            'role' => $company->pivot->role,
        ];
    }
}
