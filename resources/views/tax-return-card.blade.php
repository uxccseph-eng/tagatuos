<article class="return-card" id="return-{{ $form }}" data-return-code="{{ $form }}">
    <div class="return-card-top"><span class="return-category">{{ $card['category'] }}</span><span class="return-badge">{{ $card['source'] === 'manual' ? 'Reviewed input' : 'From saved records' }}</span></div>
    <h2>{{ $form }} <span>— {{ $card['title'] }}</span></h2><p>{{ $card['description'] }}</p>
    <div class="return-period">{{ $card['label'] }} · {{ $card['from'] }} → {{ $card['to'] }}</div>
    <p class="return-due">{{ $card['due'] ? 'Standard due '.\Carbon\CarbonImmutable::parse($card['due'])->format('M j, Y') : 'Confirm deadline with BIR' }}</p>
    @if(!$card['applicable'])<p class="return-reason">{{ $card['reason'] }}</p>@endif
    @if(in_array($form, ['1702-RT', '1702-EX', '1702-MX'], true))<p class="returns-caption">Review President and Treasurer names in the annual settings above. Choose the return that matches the company’s verified registration and income regime.</p>@endif
    <details class="return-adjustments"><summary>Adjustments <span>(optional)</span></summary>
        <label class="return-check"><input type="checkbox" data-adjustment="amended"> Amended return worksheet</label>
        <p class="returns-caption">Amended worksheets require reviewed prior-payment figures. XML exports currently support original returns only.</p>
        @if($card['category'] !== 'Information return')<div class="return-review-grid">
            @foreach(['credits' => 'Prior remittances / credits', 'additional_tax' => 'Additional reviewed tax', 'surcharge' => 'Surcharge', 'interest' => 'Interest', 'compromise' => 'Compromise'] as $key => $label)<label>{{ $label }} (₱)<input data-adjustment="{{ $key }}" value="0.00" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?"></label>@endforeach
        </div>@else<p class="returns-caption">Information returns summarize the year; they do not create another tax payment.</p>@endif
    </details>
    <div class="return-actions"><button type="button" class="returns-primary" data-prepare-return="{{ $form }}" @disabled(!$card['applicable'])>Generate {{ $frequency === 'monthly' ? \Carbon\CarbonImmutable::parse($card['from'])->format('F Y') : ($frequency === 'quarterly' ? 'Q'.$period.' '.$year : $year) }}</button><span class="returns-caption">Review before saving</span></div>
    <details class="return-card-archive" @if($card['versions']->isNotEmpty() || $card['filing'] !== null) open @endif><summary>Filing archive @if($card['filing']?->filed_on)<span class="return-filed-badge">Filed</span>@endif <span>{{ $card['versions']->count() ? '· latest '.$card['versions']->count() : '' }}</span></summary>
        @forelse($card['versions'] as $version)
            <div class="return-version" data-archive-id="{{ $version->id }}"><span>#{{ $version->id }} · {{ $version->amended ? 'Amended' : 'Original' }} · Revision {{ $version->revision }} <span class="{{ $version->is_filed ? 'return-filed-badge' : 'return-prepared-badge' }}">{{ $version->is_filed ? 'Filed' : 'Prepared' }}</span> · {{ \Carbon\CarbonImmutable::parse($version->updated_at)->timezone('Asia/Manila')->format('d M Y H:i') }}</span>
                @if($version->display_reference)<small>{{ $version->display_reference }}</small>@endif
                @if($version->settings_year !== null && (int) $version->year_settings_revision !== $annualSettings['revision'])<strong class="return-reason">Annual settings changed — review and regenerate</strong>@endif
                <div>@include('tax-return-archive-actions', ['entry' => $version])</div>
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
                @if($card['applicable'])<form class="return-proof-upload" data-url="{{ route('tax-returns.proofs.upload', ['company_id' => $company['company_id']]) }}"><input type="hidden" name="form" value="{{ $form }}"><label>Choose proof file<input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label><p>PDF, JPG or PNG · up to 10 MB per file · 20 files per return.</p><button type="submit" class="returns-button">Upload</button><p class="return-inline-status" role="status"></p></form>@endif
            </section>
        @endif
    </details>
</article>
