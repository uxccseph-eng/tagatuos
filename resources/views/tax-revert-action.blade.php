<details class="tax-revert-action">
    <summary>{{ $revertLabel }}</summary>
    <form method="POST" action="{{ $revertUrl }}" class="field-stack">
        @csrf
        @isset($revertRevision)<input type="hidden" name="revision" value="{{ $revertRevision }}">@endisset
        <p>{{ $revertWarning }}</p>
        <label>Reason<textarea name="reason" minlength="5" maxlength="1000" rows="2" required></textarea></label>
        <label><input type="checkbox" name="confirmed" value="1" required> I confirm this correction. History will be retained.</label>
        <button class="button secondary compact" type="submit">{{ $revertLabel }}</button>
    </form>
</details>
