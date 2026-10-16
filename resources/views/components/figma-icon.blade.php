@props (['name', 'size' => 20])
@php
    $assets = ['home'=>'9569e.svg','home-active'=>'5f3cd.svg','file'=>'f178c.svg','file-active'=>'f883c.svg','history'=>'d874c.svg','history-active'=>'9af02.svg','link'=>'c695b.svg','link-active'=>'4603b.svg','folder'=>'84004.svg','folder-card'=>'04fc1.svg','folder-hero'=>'c04fb.svg','users'=>'376df.svg','users-active'=>'463f7.svg','star'=>'ff825.svg','trash'=>'0976d.svg','trash-active'=>'43fe1.svg','shield'=>'4fb4e.svg','shield-active'=>'011d9.svg','shield-small'=>'46ae0.svg','shield-warning'=>'53f50.svg','logout'=>'877c1.svg','bell'=>'88f7d.svg','search'=>'ca24f.svg','list'=>'de826.svg','mobile-menu'=>'8e56c.svg','grid'=>'3ce8b.svg','arrow'=>'83a7e.svg','arrow-card'=>'dbee4.svg','more'=>'0843a.svg','check'=>'7a915.svg','upload'=>'3797b.svg','link-notice'=>'66a36.svg'];
@endphp
<img src="/assets/figma/{{ $assets[$name] }}" width="{{ $size }}" height="{{ $size }}" alt="" aria-hidden="true" {{ $attributes->class(['figma-icon']) }} />
