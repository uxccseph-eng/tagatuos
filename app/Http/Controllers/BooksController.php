<?php

namespace App\Http\Controllers;

use App\BooksExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BooksController extends Controller
{
    public const TITLES = ['general-journal' => 'General Journal', 'general-ledger' => 'General Ledger', 'subsidiary-ledger' => 'Subsidiary Ledger', 'subsidiary-sales' => 'Subsidiary Sales', 'subsidiary-purchase' => 'Subsidiary Purchase', 'cash-disbursements' => 'Cash Disbursements', 'cash-receipts' => 'Cash Receipts', 'cash-analysis' => 'Cash Analysis', 'settlement-trace' => 'Settlement Trace'];

    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get('preview_access')) {
            return to_route('login');
        }

        $company = $request->session()->get('company_setup');

        if (! $company) {
            return to_route('company.setup');
        }

        $book = $request->route('book') ?? 'general-journal';
        $titles = self::TITLES;
        abort_unless(isset($titles[$book]), 404);

        $bookRoutes = [];
        $bookRoutes['lapsing'] = route('lapsing');
        foreach (['sales', 'collections', 'cwt', 'purchases', 'payments', 'wtp', 'journal', 'payroll'] as $sheet) {
            $bookRoutes[$sheet] = route('sheets.'.$sheet);
        }

        return view('books', ['company' => $company, 'book' => $book, 'bookTitle' => $titles[$book], 'bookTitles' => $titles, 'bookRoutes' => $bookRoutes]);
    }

    public function export(Request $request, string $book, string $format, BooksExport $export): BinaryFileResponse|Response
    {
        abort_unless($request->session()->get('preview_access') && $request->session()->has('company_setup'), 403);
        abort_unless(isset(self::TITLES[$book]) && in_array($format, ['xlsx', 'pdf'], true), 404);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'columns' => ['required', 'array', 'list', 'min:2', 'max:24'],
            'columns.*' => ['required', 'array:key,title,format'],
            'columns.*.key' => ['required', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9]*$/', 'distinct', 'max:40'],
            'columns.*.title' => ['required', 'string', 'max:60'],
            'columns.*.format' => ['nullable', 'in:money'],
            'rows' => ['present', 'array', 'list', 'max:'.($format === 'pdf' ? 1500 : 10000)],
            'rows.*' => ['required', 'array'],
            'rows.*.kind' => ['nullable', 'in:opening,subtotal,closing,balance'],
        ], ['rows.max' => 'This '.$format.' export supports up to '.($format === 'pdf' ? '1,500' : '10,000').' report rows. Choose a shorter date range or filter a single account or party.']);
        $rules = [];
        foreach ($data['columns'] as $column) {
            $rules['rows.*.'.$column['key']] = ($column['format'] ?? '') === 'money'
                ? ['nullable', 'integer', 'between:-99999999999999,99999999999999']
                : ($column['key'] === 'date' ? ['nullable', 'date_format:Y-m-d'] : ['nullable', 'string', 'max:500']);
        }
        foreach (['group', 'groupAccount', 'groupParty', 'voucherId'] as $key) {
            $rules['rows.*.'.$key] = ['nullable', 'string', 'max:500'];
        }
        $request->validate($rules);
        $columns = $data['columns'];
        $keys = array_merge(array_column($columns, 'key'), ['kind', 'group', 'groupAccount', 'groupParty', 'voucherId']);
        $inputRows = array_map(fn (array $row): array => array_intersect_key($row, array_flip($keys)), $request->input('rows'));
        $rows = $export->rows($columns, $inputRows, $book);
        $company = $request->session()->get('company_setup');
        $headings = $export->headings($company, self::TITLES[$book], $data['from'], $data['to'], $request->user()?->email ?? $company['full_name'] ?? 'Workspace user');
        $code = ['general-ledger' => 'gl', 'subsidiary-ledger' => 'sl', 'subsidiary-sales' => 'ssj', 'subsidiary-purchase' => 'spj', 'cash-disbursements' => 'cdj', 'cash-receipts' => 'crj'][$book] ?? $book;
        $filename = $code.'-'.$data['from'].'-to-'.$data['to'].'.'.$format;
        if ($format === 'pdf') {
            $widths = array_map(fn (array $column): int => $export->pdfWidth($column['key']), $columns);

            $pdf = Pdf::loadView('books-export', compact('headings', 'columns', 'rows', 'widths'))
                ->setPaper('a4', 'landscape')
                ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
            $pdf->render();
            $pdf->getDomPDF()->getCanvas()->page_text(750, 578, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans'), 6);

            return $pdf->download($filename)->header('Cache-Control', 'private, no-store');
        }

        return response()->download($export->workbook($headings, $columns, $rows), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }
}
