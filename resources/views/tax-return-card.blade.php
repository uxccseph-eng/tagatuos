<article class="return-card" id="return-{{ $form }}" data-return-code="{{ $form }}">
    <div class="return-card-top"><span class="return-category">{{ $card['category'] }}</span><div class="return-deadline"><span class="material-symbols-outlined" aria-hidden="true">event</span><span>{{ $card['due'] ? 'Due '.\Carbon\CarbonImmutable::parse($card['due'])->format('M j, Y') : 'Confirm deadline' }}</span>@if($card['due'] === now('Asia/Manila')->toDateString())<span class="return-due-today">Today</span>@endif</div></div>
    <h2><span class="material-symbols-outlined" aria-hidden="true">description</span>{{ $form }} <span>— {{ $card['title'] }}</span></h2><p class="return-description">{{ $card['description'] }}</p>
    <span class="return-period">{{ $card['label'] }} · {{ $card['from'] }} → {{ $card['to'] }}</span>
    @if(!$card['applicable'])<p class="return-reason">{{ $card['reason'] }}</p>@endif
    @if(in_array($form, ['1702-RT', '1702-EX', '1702-MX'], true))<p class="returns-caption">Review President and Treasurer names in the annual settings above. Choose the return that matches the company’s verified registration and income regime.</p>@endif
    <details class="return-adjustments"><summary><span class="material-symbols-outlined" aria-hidden="true">tune</span> Adjustments <span>(optional)</span></summary>
        <label class="return-check"><input type="checkbox" data-adjustment="amended"> Amended return worksheet</label>
        <p class="returns-caption">Amended worksheets require reviewed prior-payment figures. XML exports currently support original returns only.</p>
        @if($card['category'] !== 'Information return')<div class="return-review-grid">
            @foreach(['credits' => 'Prior remittances / credits', 'additional_tax' => 'Additional reviewed tax', 'surcharge' => 'Surcharge', 'interest' => 'Interest', 'compromise' => 'Compromise'] as $key => $label)<label>{{ $label }} (₱)<input data-adjustment="{{ $key }}" value="0.00" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?"></label>@endforeach
        </div>@else<p class="returns-caption">Information returns summarize the year; they do not create another tax payment.</p>@endif
    </details>
    <div class="return-actions"><button type="button" class="returns-primary" data-prepare-return="{{ $form }}" @disabled(!$card['applicable'])>Generate {{ $frequency === 'monthly' ? \Carbon\CarbonImmutable::parse($card['from'])->format('F Y') : ($frequency === 'quarterly' ? 'Q'.$period.' '.$year : $year) }}</button></div>
    @if($card['generated'])
        @php($generated = $card['generated'])
        <section class="return-generated" aria-label="Saved {{ $form }} preparation">
            <div class="return-generated-heading"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span><strong>{{ $generated['entry']->is_filed ? 'Filing recorded' : 'Return prepared' }}</strong><span>{{ $generated['entry']->amended ? 'Amended' : 'Original' }} · Revision {{ $generated['entry']->revision }}</span></div>
            <p class="return-generated-filename">{{ $generated['filename'] ?? 'Reviewed worksheet · XML not generated' }}</p>
            <div class="return-generated-bottom"><div class="return-generated-totals"><span>Tax before credits <strong>{{ $generated['tax'] === null ? '—' : number_format($generated['tax'] / 100, 2) }}</strong></span><span>Prepared amount due <strong>{{ $generated['payable'] === null ? '—' : number_format($generated['payable'] / 100, 2) }}</strong></span></div><div class="return-downloads">
                <button type="button" class="returns-button" data-print-preview="{{ route('tax-returns.show', ['id' => $generated['entry']->id, 'company_id' => $company['company_id']]) }}" data-print-label="{{ $form }} · {{ $card['label'] }}"><span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>Save PDF…</button>
                @if($generated['xml_available'])<button type="button" class="returns-button" data-xml-download="{{ route('tax-returns.xml', ['id' => $generated['entry']->id, 'company_id' => $company['company_id']]) }}"><span class="material-symbols-outlined" aria-hidden="true">download</span>Save XML…</button>@else<a class="returns-button" href="{{ route('tax-returns.download', ['id' => $generated['entry']->id, 'company_id' => $company['company_id']]) }}">Worksheet CSV</a>@endif
            </div></div>
        </section>
    @endif
    <details class="return-card-archive" @if($card['versions']->isNotEmpty() || $card['filing'] !== null) open @endif><summary><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span> Filing archive <span class="return-archive-state {{ $card['filing']?->filed_on ? 'return-filed-badge' : 'return-prepared-badge' }}">{{ $card['filing']?->filed_on ? 'Filed' : ($card['versions']->isNotEmpty() ? 'Prepared' : 'No preparation') }}</span></summary>
        @forelse($card['versions'] as $version)
            <div class="return-version" data-archive-id="{{ $version->id }}"><span>#{{ $version->id }} · {{ $version->amended ? 'Amended' : 'Original' }} · Revision {{ $version->revision }} <span class="{{ $version->is_filed ? 'return-filed-badge' : 'return-prepared-badge' }}">{{ $version->is_filed ? 'Filed' : 'Prepared' }}</span> · {{ \Carbon\CarbonImmutable::parse($version->updated_at)->timezone('Asia/Manila')->format('d M Y H:i') }}</span>
                @if($version->display_reference)<small>{{ $version->display_reference }}</small>@endif
                @if($version->settings_year !== null && (int) $version->year_settings_revision !== $annualSettings['revision'])<strong class="return-reason">Annual settings changed — review and regenerate</strong>@endif
                <div>@include('tax-return-archive-actions', ['entry' => $version, 'hideExports' => $card['generated'] && $version->id === $card['generated']['entry']->id, 'hideFiling' => !$version->amended && $card['original']?->id === $version->id])</div>
            </div>
        @empty<p>No prepared version for this period.</p>@endforelse
        @if($card['applicable'] || $card['filing'] !== null)
            @if($card['filing']?->filed_on)<p class="return-filed-status">External filing recorded · {{ $card['filing']->filed_on }}<br>{{ $card['filing']->reference ?: 'No reference supplied' }}</p>
            @elseif($card['applicable'])<form class="return-period-filing" data-url="{{ route('tax-returns.period-filed', ['company_id' => $company['company_id']]) }}">
                <input type="hidden" name="form" value="{{ $form }}"><input type="hidden" name="original_id" value="{{ $card['original']?->id }}"><input type="hidden" name="original_revision" value="{{ $card['original']?->revision ?? 0 }}"><div class="return-review-grid"><label>eBIR/eFPS confirmation ref (optional)<input name="reference" maxlength="150" autocomplete="off"></label><label>Filed on *<input name="filed_on" type="date" min="{{ $card['to'] }}" max="{{ now('Asia/Manila')->toDateString() }}" required></label></div>
                <label class="return-check"><input name="confirmed" type="checkbox" required> I filed this return externally and checked the filing date.</label><button type="submit" class="returns-button">Mark as filed</button><p>Record a return filed in eBIRForms or eFPS even if you did not generate it here. This records your team’s confirmation; it does not verify acceptance or payment.</p><p class="return-inline-status" role="status"></p>
            </form>@endif
            <section class="return-proofs"><h3>Proof of payment &amp; filing copies</h3>
                <ul>@forelse($card['proofs'] as $proof)<li><a href="{{ route('tax-returns.proofs.download', ['id' => $proof->id, 'company_id' => $company['company_id']]) }}">{{ $proof->original_name }}</a> <small>{{ number_format($proof->size / 1024, 0) }} KB</small></li>@empty<li>No files yet. Upload a payment confirmation, official receipt or filing receipt.</li>@endforelse</ul>
                @if($card['applicable'])<form class="return-proof-upload" data-url="{{ route('tax-returns.proofs.upload', ['company_id' => $company['company_id']]) }}"><input type="hidden" name="form" value="{{ $form }}"><label>Choose proof file<input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label><button type="submit" class="returns-button">Upload</button><p>PDF, JPG or PNG · up to 10 MB per file · 20 files per return.</p><p class="return-inline-status" role="status"></p></form>@endif
            </section>
        @endif
    </details>
</article>
