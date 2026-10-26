<?php

use App\Http\Controllers\AccountAuthController;
use App\Http\Controllers\AccountDashboardController;
use App\Http\Controllers\BirReportController;
use App\Http\Controllers\BooksController;
use App\Http\Controllers\CollectionsController;
use App\Http\Controllers\CompanyBookController;
use App\Http\Controllers\CompanySetupController;
use App\Http\Controllers\CwtController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancialReportsController;
use App\Http\Controllers\Form2307Controller;
use App\Http\Controllers\Form2316Controller;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\LapsingController;
use App\Http\Controllers\MastersController;
use App\Http\Controllers\MastersImportController;
use App\Http\Controllers\MastersRegistryController;
use App\Http\Controllers\PaymentsController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PreviewSessionController;
use App\Http\Controllers\PurchasesController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaxReportsController;
use App\Http\Controllers\WtpController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::post('/generate', [HomeController::class, 'generate'])->name('generate');

Route::get('/signin', [AccountAuthController::class, 'show'])->name('login');
Route::post('/signin', [AccountAuthController::class, 'login'])->middleware('throttle:20,1')->name('login.store');
Route::get('/signup', [AccountAuthController::class, 'show'])->name('register');
Route::post('/signup', [AccountAuthController::class, 'register'])->middleware('throttle:10,1')->name('register.store');
Route::view('/preview', 'auth')->name('preview.login');
Route::post('/logout', [AccountAuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::middleware('auth')->group(function (): void {
    Route::get('/account', [AccountDashboardController::class, 'index'])->name('account.dashboard');
    Route::get('/account/password', [AccountAuthController::class, 'editPassword'])->name('account.password.edit');
    Route::put('/account/password', [AccountAuthController::class, 'updatePassword'])->middleware('throttle:10,1')->name('account.password.update');
});
Route::redirect('/login', '/signin');
Route::redirect('/register', '/signup');

Route::middleware('auth')->prefix('account/companies')->name('account.companies.')->group(function (): void {
    Route::get('/', [CompanyBookController::class, 'index'])->name('index');
    Route::post('/', [CompanyBookController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::get('/{companyId}', [CompanyBookController::class, 'show'])->whereNumber('companyId')->name('show');
    Route::post('/{companyId}/open', [CompanyBookController::class, 'open'])->whereNumber('companyId')->name('open');
});

Route::post('/preview/session', [PreviewSessionController::class, 'store'])->middleware('throttle:20,1')->name('preview.start');
Route::post('/preview/exit', [PreviewSessionController::class, 'destroy'])->name('preview.exit');
Route::get('/company/setup', [CompanySetupController::class, 'create'])->name('company.setup');
Route::post('/company/setup', [CompanySetupController::class, 'store'])->name('company.store');
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
Route::get('/settings/{section}', [SettingsController::class, 'index'])->where('section', 'company|address|bir|sheets')->name('settings.section');
Route::put('/settings/{section}', [SettingsController::class, 'update'])->where('section', 'company|address|bir|sheets')->name('settings.update');
Route::redirect('/sheets', '/sheets/sales');
Route::get('/lapsing', [LapsingController::class, 'index'])->name('lapsing');
Route::get('/lapsing/attention', [LapsingController::class, 'index'])->defaults('page', 'attention')->name('lapsing.attention');
Route::get('/lapsing/runs', fn (Request $request): RedirectResponse => to_route('lapsing', $request->only('period')));
Route::get('/lapsing/data', [LapsingController::class, 'preview'])->name('lapsing.data');
Route::post('/lapsing/runs', [LapsingController::class, 'postRun'])->name('lapsing.post');
Route::redirect('/sheets/lapsing', '/lapsing')->name('sheets.lapsing');
Route::get('/masters/ppe-items', [LapsingController::class, 'masters'])->name('masters.ppe');
Route::post('/masters/ppe-items', [LapsingController::class, 'store'])->name('masters.ppe.store');
Route::put('/masters/ppe-items/{asset}', [LapsingController::class, 'update'])->whereNumber('asset')->name('masters.ppe.update');
Route::post('/masters/ppe-items/{asset}/archive', [LapsingController::class, 'archive'])->whereNumber('asset')->name('masters.ppe.archive');
Route::get('/sheets/sales', [SalesController::class, 'index'])->name('sheets.sales');
Route::get('/sheets/collections', [CollectionsController::class, 'index'])->name('sheets.collections');
Route::get('/sheets/cwt', [CwtController::class, 'index'])->name('sheets.cwt');
Route::get('/sheets/purchases', [PurchasesController::class, 'index'])->name('sheets.purchases');
Route::get('/sheets/payments', [PaymentsController::class, 'index'])->name('sheets.payments');
Route::get('/sheets/payroll', [PayrollController::class, 'index'])->name('sheets.payroll');
Route::get('/sheets/wtp', [WtpController::class, 'index'])->name('sheets.wtp');

Route::get('/sheets/journal', [JournalController::class, 'index'])->name('sheets.journal');

Route::redirect('/books', '/books/general-journal')->name('books');
Route::get('/books/{book}', [BooksController::class, 'index'])->name('books.show');

Route::redirect('/masters', '/masters/chart-of-accounts')->name('masters');
Route::prefix('masters/{registry}')->where(['registry' => 'chart-of-accounts|parties|tax-codes|bank-accounts|atc-codes|employees'])->group(function (): void {
    Route::post('/import/preview', [MastersImportController::class, 'preview'])->name('masters.import.preview');
    Route::post('/import', [MastersImportController::class, 'store'])->name('masters.registry.import');
});
Route::get('/masters/chart-of-accounts', [MastersController::class, 'index'])->name('masters.accounts');
Route::get('/masters/chart-of-accounts/catalog.js', [MastersController::class, 'catalog'])->name('masters.accounts.catalog');
Route::get('/masters/chart-of-accounts/relief-catalog', [MastersController::class, 'reliefCatalog'])->name('masters.accounts.relief-catalog');
Route::post('/masters/chart-of-accounts', [MastersController::class, 'store'])->name('masters.accounts.store');
Route::put('/masters/chart-of-accounts/{account}', [MastersController::class, 'update'])->whereNumber('account')->name('masters.accounts.update');
Route::post('/masters/chart-of-accounts/{account}/archive', [MastersController::class, 'archive'])->whereNumber('account')->name('masters.accounts.archive');
Route::post('/masters/chart-of-accounts/{account}/restore', [MastersController::class, 'restore'])->whereNumber('account')->name('masters.accounts.restore');

Route::prefix('masters/{registry}')->where(['registry' => 'parties|tax-codes|bank-accounts|employees'])->group(function (): void {
    Route::get('/', [MastersRegistryController::class, 'index'])->name('masters.registry');
    Route::post('/', [MastersRegistryController::class, 'store'])->name('masters.registry.store');
    Route::put('/{record}', [MastersRegistryController::class, 'update'])->whereNumber('record')->name('masters.registry.update');
    Route::post('/{record}/archive', [MastersRegistryController::class, 'archive'])->whereNumber('record')->name('masters.registry.archive');
    Route::post('/{record}/restore', [MastersRegistryController::class, 'restore'])->whereNumber('record')->name('masters.registry.restore');
});

Route::get('/masters/atc-codes', [MastersRegistryController::class, 'index'])->defaults('registry', 'atc-codes')->name('masters.atc');

Route::redirect('/reports', '/reports/trial-balance')->name('reports');
Route::get('/tax-reports', [TaxReportsController::class, 'index'])->name('tax-reports');
Route::post('/tax-reports/relief-sales/excel', [TaxReportsController::class, 'exportSales'])->name('tax-reports.sales.excel');
Route::post('/tax-reports/relief-purchases/excel', [TaxReportsController::class, 'exportPurchases'])->name('tax-reports.purchases.excel');
Route::post('/reports/generate-1604e-dat', [BirReportController::class, 'export1604EDat'])->name('reports.qap.annual.dat');
Route::post('/reports/generate-qap-dat', [BirReportController::class, 'export1601EQDat'])->name('reports.qap.dat');
Route::post('/reports/generate-sawt-dat', [BirReportController::class, 'generateSawtFromPayload'])->name('reports.sawt.dat');
Route::post('/reports/generate-sales-dat', [BirReportController::class, 'generateFromPayload'])->name('reports.sales.dat');
Route::post('/reports/generate-purchases-dat', [BirReportController::class, 'generatePurchasesFromPayload'])->name('reports.purchases.dat');
Route::get('/tax-reports/{schedule}', [TaxReportsController::class, 'index'])->name('tax-reports.show');
Route::get('/reports/{report}/transactions', [FinancialReportsController::class, 'transactions'])->name('reports.transactions');
Route::get('/reports/{report}', [FinancialReportsController::class, 'index'])->name('reports.show');

Route::post('/reports/form-2316/{employeeId}', [Form2316Controller::class, 'print2316'])->whereNumber('employeeId')->name('reports.form2316');
Route::post('/reports/form-2307/{supplierId}', [Form2307Controller::class, 'print2307'])->whereNumber('supplierId')->name('reports.form2307');
