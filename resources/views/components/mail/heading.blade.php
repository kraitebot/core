@props([
    'size' => 'lg',
])
@php
    $sizes = [
        'lg' => '24px',
        'md' => '18px',
        'sm' => '15px',
    ];
    $fontSize = $sizes[$size] ?? $sizes['lg'];
@endphp
<h2 style="margin:0 0 16px; font-size:{{ $fontSize }}; font-weight:800; color:#ffffff; line-height:1.2;">
    {{ $slot }}
</h2>
