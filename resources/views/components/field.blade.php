@props(['name','label','type'=>'text','value'=>'','required'=>false,'options'=>[]])
<div class="mb-3"><label class="form-label" for="field_{{ $name }}">{{ $label }} @if($required)<span class="required-mark" aria-hidden="true">*</span>@endif</label>
@if($type==='select')<select id="field_{{ $name }}" name="{{ $name }}" class="form-select @error($name)is-invalid @enderror" @required($required)>@foreach($options as $key=>$text)<option value="{{ $key }}" @selected((string)old($name,$value)===(string)$key)>{{ $text }}</option>@endforeach</select>
@elseif($type==='textarea')<textarea id="field_{{ $name }}" name="{{ $name }}" class="form-control @error($name)is-invalid @enderror" rows="4" @required($required)>{{ old($name,$value) }}</textarea>
@else<input id="field_{{ $name }}" name="{{ $name }}" type="{{ $type }}" @if(!in_array($type,['file','password'])) value="{{ old($name,$value) }}" @endif class="form-control @error($name)is-invalid @enderror" @if($type==='number') min="0" step="0.01" @endif @required($required)>@endif
@error($name)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
