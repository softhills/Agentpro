@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'placeholder' => null,
])
@php $id = $name . '-field'; @endphp
<div class="fieldset">
    <label class="flabel" for="{{ $id }}">{{ $label }}</label>
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ $type === 'password' ? '' : $value }}"
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
        {{ $attributes->merge(['class' => $errors->has($name) ? 'finput has-error' : 'finput']) }}
        @if ($errors->has($name)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
    >
    @if ($hint && ! $errors->has($name))
        <p class="fhint">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="ferror" id="{{ $id }}-error">{{ $message }}</p>
    @enderror
</div>
