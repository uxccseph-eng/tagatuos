<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrintForm2316Request;
use App\Models\ChartAccount;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Services\Form2316Service;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class Form2316Controller extends Controller
{
    public function print2316(PrintForm2316Request $request, int $employeeId, Form2316Service $service): Response|JsonResponse
    {
        $data = $request->validated();
        $company = $request->session()->get('company_setup');
        $workspace = ChartAccount::workspaceKey($company);
        $employee = Employee::query()->where('workspace_key', $workspace)->findOrFail($employeeId);
        $company = array_replace($company, CompanySetting::query()->where('workspace_key', $workspace)->first()?->profile ?? []);
        $view = $service->prepare($data, $company, $employee);
        if (! $request->boolean('preview') && $view['draft']) {
            throw ValidationException::withMessages(['reviewed' => 'Review annual compensation, exemptions, prior employment and withholding before downloading.']);
        }
        $view['background'] = base64_encode(file_get_contents(resource_path('views/form2316-background.jpg')));
        $filename = 'BIR-2316-'.$employee->id.'-'.$data['year'].'.pdf';
        $pdf = Pdf::loadView('form2316', $view)->setPaper([0, 0, 612, 936])
            ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'Helvetica']);
        if ($request->boolean('preview')) {
            return response()->json(['filename' => $filename, 'pdf' => base64_encode($pdf->output()), 'html' => view('form2316', [...$view, 'preview' => true])->render(), 'draft' => $view['draft'], 'recorded_withheld' => $view['recordedWithheld'] / 100, 'tax_due' => $view['amounts'][24] / 100])->header('Cache-Control', 'private, no-store');
        }

        return $pdf->download($filename)->header('Cache-Control', 'private, no-store');
    }
}
