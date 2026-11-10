@if(!($hideExports ?? false))
<button type="button" data-print-preview="{{ route('tax-returns.show', ['id' => $entry->id, 'company_id' => $company['company_id']]) }}" data-print-label="{{ $entry->form }} · {{ $entry->period_from }} to {{ $entry->period_to }}">View / Print</button>
<a href="{{ route('tax-returns.download', ['id' => $entry->id, 'company_id' => $company['company_id']]) }}">Worksheet CSV</a>
@if(in_array($entry->form, ['2550Q', '1601-EQ', '0619-E', '1601-C'], true))<button type="button" data-xml-download="{{ route('tax-returns.xml', ['id' => $entry->id, 'company_id' => $company['company_id']]) }}">eBIRForms XML</button>@endif
@endif
@if(!$entry->is_filed)
    @if(!($hideFiling ?? false))
    <button type="button" data-record-filing="{{ route('tax-returns.filed', ['id' => $entry->id, 'company_id' => $company['company_id']]) }}" data-filing-label="{{ $entry->form }} · {{ $entry->period_to }} · {{ $entry->amended ? 'Amended' : 'Original' }}" data-revision="{{ $entry->revision }}">Mark as filed</button>
    @endif
    <button type="button" class="return-delete" data-delete-preparation="{{ route('tax-returns.destroy', ['id' => $entry->id, 'company_id' => $company['company_id']]) }}" data-delete-label="{{ $entry->form }} · {{ $entry->period_from }} to {{ $entry->period_to }} · #{{ $entry->id }}" data-revision="{{ $entry->revision }}">Delete</button>
@endif
