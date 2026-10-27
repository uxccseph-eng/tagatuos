<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\InboxDocument;
use App\Models\Party;
use App\Models\TaxCode;
use App\Models\User;
use App\TeamPermissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentInboxService
{
    public function __construct(private CompanyBookService $books, private CompanyLedgerService $ledger) {}

    public function company(User $user, int $companyId): Company
    {
        $company = $this->books->findAccessibleBook(User::query()->findOrFail($user->id), $companyId);
        abort_unless(in_array('sheets', TeamPermissions::allowed($company->pivot->role, $company->pivot->allowed_tabs), true), 403, 'Your book owner must enable Sheets before you can access source documents.');

        return $company;
    }

    public function document(Company $company, int $documentId): InboxDocument
    {
        return InboxDocument::query()->where('company_id', $company->id)->findOrFail($documentId);
    }

    /** @param array{category: string, notes?: ?string} $data */
    public function upload(User $actor, int $companyId, UploadedFile $file, array $data): InboxDocument
    {
        $this->company($actor, $companyId);
        $hash = hash_file('sha256', $file->getRealPath());
        $this->checkUploadDuplicate($companyId, $hash);
        $extension = match ($file->getMimeType()) {
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', default => abort(422, 'Upload a PDF, JPG or PNG document.')
        };
        $path = $companyId.'/'.Str::uuid().'.'.$extension;
        $disk = Storage::disk('documents');
        try {
            $stream = fopen($file->getRealPath(), 'rb');
            try {
                if (! $disk->put($path, $stream)) {
                    throw new \RuntimeException('The source document could not be stored.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return DB::transaction(function () use ($actor, $companyId, $file, $data, $path, $hash): InboxDocument {
                Company::query()->lockForUpdate()->findOrFail($companyId);
                $this->company($actor, $companyId);
                $this->checkUploadDuplicate($companyId, $hash);
                $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $document = (new InboxDocument)->forceFill(['company_id' => $companyId, 'uploaded_by' => $actor->id, 'original_name' => Str::limit(preg_replace('/[\x00-\x1F\x7F]/', '', $name), 240, ''), 'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => $hash, 'category' => $data['category'], 'notes' => $data['notes'] ?? null, 'status' => 'received', 'version' => 1]);
                if (DB::table('company_document_backups')->where('company_id', $companyId)->where('enabled', true)->whereNotNull('verified_at')->exists()) {
                    $document->forceFill(['lark_status' => 'pending', 'lark_next_attempt_at' => now()]);
                }
                $document->save();
                $this->event($document, $actor, 'uploaded', ['name' => $document->original_name, 'size' => $document->size]);

                return $document;
            }, attempts: 3);
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, int $companyId, int $documentId, array $data): InboxDocument
    {
        return DB::transaction(function () use ($actor, $companyId, $documentId, $data): InboxDocument {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $company = $this->company($actor, $companyId);
            $document = InboxDocument::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($documentId);
            abort_unless($document->version === (int) $data['version'], 409, 'Another team member updated this document. Reload before saving.');
            $before = $document->only(['status', 'assigned_to', 'notes', 'linked_sheet', 'linked_record_id']);
            if ($data['action'] === 'metadata') {
                $assigneeId = $data['assigned_to'] ?? null;
                if ($assigneeId !== null) {
                    $member = $company->users()->where('users.id', $assigneeId)->where('registration_status', 'approved')->where('must_change_password', false)->wherePivot('is_suspended', false)->first();
                    if (! $member || ! in_array('sheets', TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs), true)) {
                        $this->fail('assigned_to', 'Choose an active accountant with Sheets access in this company book.');
                    }
                }
                $status = $document->linked_record_id && $data['status'] !== 'archived' ? 'linked' : $data['status'];
                $document->forceFill(['assigned_to' => $assigneeId, 'notes' => $data['notes'] ?? null, 'status' => $status]);
            } else {
                abort_if($document->status === 'archived', 422, 'Restore this document before linking or creating an entry.');
                $stored = CompanyLedger::query()->where('company_id', $companyId)->first();
                $revision = $stored?->revision ?? 0;
                abort_unless($revision === (int) $data['ledger_revision'], 409, 'The company book changed. Reload before creating or linking an entry.');
                $state = $stored?->state ?? CompanyLedgerService::emptyState();
                $payroll = $stored?->payroll ?? [];
                if ($data['action'] === 'unlink') {
                    $sheet = $document->linked_sheet;
                    $id = $document->linked_record_id;
                    if ($sheet && $id) {
                        $rows = $sheet === 'payroll' ? $payroll : ($state[$sheet] ?? []);
                        foreach ($rows as &$record) {
                            if ((string) $record['id'] === $id) {
                                $record['attachments'] = array_values(array_filter($record['attachments'] ?? [], fn (array $file): bool => ($file['id'] ?? '') !== 'inbox-'.$document->id));
                            }
                        }
                        unset($record);
                        if ($sheet === 'payroll') {
                            $payroll = $rows;
                        } else {
                            $state[$sheet] = $rows;
                        }
                    }
                    $document->forceFill(['linked_sheet' => null, 'linked_record_id' => null, 'status' => 'needs_entry']);
                } else {
                    abort_if($document->linked_record_id !== null, 422, 'This document is already linked. Remove its current link before choosing another transaction.');
                    if ($data['action'] === 'create_draft') {
                        if (! CompanySetting::query()->where('workspace_key', $company->workspaceKey())->exists()) {
                            $this->fail('sheet', 'Complete company setup before creating entries.');
                        }
                        $sheet = $data['sheet'];
                        if (collect($state[$sheet])->where('status', 'draft')->isNotEmpty()) {
                            $this->fail('sheet', 'This sheet already has a draft. Link this document to that draft, or finish it before creating another.');
                        }
                        $record = $this->draft($company, $document, $data, $state[$sheet]);
                        $state[$sheet][] = $record;
                        $id = (string) $record['id'];
                    } else {
                        [$sheet, $id] = array_pad(explode(':', $data['transaction'], 2), 2, '');
                        abort_unless(in_array($sheet, [...CompanyLedgerService::SHEETS, 'payroll'], true), 422, 'Choose a transaction from this company book.');
                    }
                    $rows = $sheet === 'payroll' ? $payroll : $state[$sheet];
                    $found = false;
                    foreach ($rows as &$record) {
                        if ((string) $record['id'] === $id && in_array($record['status'], ['draft', 'posted'], true)) {
                            $found = true;
                            $record['attachments'] = [...($record['attachments'] ?? []), $this->attachment($document)];
                        }
                    }
                    unset($record);
                    if (! $found) {
                        $this->fail('transaction', 'Choose an active draft or posted transaction from this company book.');
                    }
                    if ($sheet === 'payroll') {
                        $payroll = $rows;
                    } else {
                        $state[$sheet] = $rows;
                    }
                    $document->forceFill(['linked_sheet' => $sheet, 'linked_record_id' => $id, 'status' => 'linked']);
                }
                $this->ledger->save($company, $actor, ['state' => $state, 'payroll' => $payroll, 'revision' => $revision, 'request_id' => (string) Str::uuid()]);
            }
            $document->version++;
            $document->save();
            $this->event($document, $actor, $data['action'], ['before' => $before, 'after' => $document->only(['status', 'assigned_to', 'notes', 'linked_sheet', 'linked_record_id'])]);

            return $document;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function draft(Company $company, InboxDocument $document, array $data, array $rows): array
    {
        $workspace = $company->workspaceKey();
        $sales = $data['sheet'] === 'sales';
        $party = Party::query()->where('workspace_key', $workspace)->where('archived', false)->whereIn('classification', [$sales ? 'Customer' : 'Supplier', 'Both'])->find($data['party_id']);
        $account = ChartAccount::query()->where('workspace_key', $workspace)->where('archived', false)->whereIn('type', $sales ? ['Income'] : ['Asset', 'Expense'])->find($data['account_id']);
        $tax = TaxCode::query()->where('workspace_key', $workspace)->where('archived', false)->whereIn('kind', ['VAT', 'Exempt', 'Zero Rated'])->find($data['tax_code_id']);
        if (! $party) {
            $this->fail('party_id', 'Select a matching customer or supplier from this company’s Masters.');
        }
        if (! $account) {
            $this->fail('account_id', 'Select an active account from this company’s Masters. Sales require an income account.');
        }
        if (! $tax) {
            $this->fail('tax_code_id', 'Select a tax code from this company’s Masters.');
        }
        [$whole, $fraction] = array_pad(explode('.', $data['gross'], 2), 2, '');
        $gross = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($gross <= 0) {
            $this->fail('gross', 'Enter an amount greater than zero.');
        }
        $reference = trim($data['reference'] ?? '');
        foreach ($rows as $existing) {
            if (! in_array($existing['status'], ['draft', 'posted'], true)) {
                continue;
            }
            $sameParty = (isset($existing['partyId']) && (string) $existing['partyId'] === (string) $party->id) || mb_strtolower(trim($existing[$sales ? 'customer' : 'supplier'] ?? '')) === mb_strtolower(trim($party->name));
            $sameReference = $reference !== '' && mb_strtolower(trim($existing['reference'] ?? '')) === mb_strtolower($reference);
            $sameAmountAndDate = $reference === '' && ($existing['date'] ?? '') === $data['date'] && ($existing['gross'] ?? null) === $gross;
            if ($sameParty && ($sameReference || $sameAmountAndDate)) {
                $this->fail('reference', 'Possible duplicate of '.$existing['number'].'. Review or link the existing transaction before creating another.');
            }
        }
        $sequence = 0;
        foreach ($rows as $existing) {
            if (preg_match('/(\d+)$/', $existing['number'], $match)) {
                $sequence = max($sequence, (int) $match[1]);
            }
        }
        $atc = $account->ewt && $account->ewt_atc_code ? collect(TaxCode::atcCatalog($workspace))->firstWhere('code', $account->ewt_atc_code) : null;
        $record = ['id' => (string) Str::uuid(), 'number' => ($sales ? 'SI-' : 'BILL-').str_pad((string) ($sequence + 1), 6, '0', STR_PAD_LEFT), 'date' => $data['date'], 'status' => 'draft', 'draftSaved' => true, 'draftTouched' => true, 'partyId' => $party->id, 'tin' => $party->tin ?? '', 'address' => $party->address ?? '', 'gross' => $gross, 'tax' => 'TAX|'.$tax->code.'|'.$tax->kind.'|'.$tax->rate, 'account' => $account->code.' · '.$account->name, 'terms' => 'On Account', 'reference' => $reference, 'description' => $data['description'] ?? '', 'notes' => '', 'attachments' => [], 'createdAt' => now()->toIso8601String(), 'preparedFromDocument' => $document->id];

        return $sales ? [...$record, 'customer' => $party->name, 'cwt' => $atc ? $atc['code'].' · '.$atc['rate'].'%' : 'None', 'vatCwt' => 'None', 'classification' => 'Services'] : [...$record, 'supplier' => $party->name, 'ewt' => $atc ? (string) $atc['rate'] : 0, 'ewtCode' => $atc['code'] ?? '', 'wvat' => 0, 'wvatCode' => ''];
    }

    /** @return array<string, mixed> */
    private function attachment(InboxDocument $document): array
    {
        $url = route('documents.file', ['companyId' => $document->company_id, 'documentId' => $document->id], false);

        return ['id' => 'inbox-'.$document->id, 'name' => $document->original_name, 'type' => $document->mime_type, 'size' => $document->size, 'url' => $url, 'data' => $url];
    }

    private function checkUploadDuplicate(int $companyId, string $hash): void
    {
        if (InboxDocument::query()->where('company_id', $companyId)->where('sha256', $hash)->exists()) {
            $this->fail('file', 'This exact file is already in this company’s inbox, including archived documents. Open the existing copy instead.');
        }
    }

    /** @param array<string, mixed> $details */
    private function event(InboxDocument $document, User $actor, string $action, array $details): void
    {
        DB::table('inbox_document_events')->insert(['inbox_document_id' => $document->id, 'company_id' => $document->company_id, 'actor_id' => $actor->id, 'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
