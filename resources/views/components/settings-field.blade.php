@props(['name', 'label', 'value' => '', 'type' => 'text', 'options' => null, 'hint' => null, 'required' => false])
<label class="settings-field" for="setting-{{ $name }}">
    <span>{{ $label }} @if($required)<b aria-hidden="true">*</b>@endif</span>
    @if($options !== null)
        <select id="setting-{{ $name }}" name="{{ $name }}" @required($required) {{ $attributes }} @error($name) aria-invalid="true" aria-describedby="error-{{ $name }}" @enderror>
            @foreach($options as $key => $caption)<option value="{{ $key }}" @selected((string) old($name, $value) === (string) $key)>{{ $caption }}</option>@endforeach
        </select>
    @else
        <input id="setting-{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}" @required($required) {{ $attributes }} @error($name) aria-invalid="true" aria-describedby="error-{{ $name }}" @enderror>
    @endif
    @if($hint)<small>{{ $hint }}</small>@endif
    @error($name)<small class="settings-error" id="error-{{ $name }}">{{ $message }}</small>@enderror
</label>
