@props([
    'variant' => 'info',
    'label' => null,
])
@php
    $palette = match ($variant) {
        'success' => ['bg' => '#0f1f15', 'border' => '#14532d', 'accent' => '#22c55e'],
        'warning' => ['bg' => '#1f1808', 'border' => '#78350f', 'accent' => '#f59e0b'],
        'danger'  => ['bg' => '#1f0a0a', 'border' => '#7f1d1d', 'accent' => '#ef4444'],
        default   => ['bg' => '#0a1626', 'border' => '#1e3a8a', 'accent' => '#3b82f6'],
    };
@endphp
<table cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 24px;">
    <tr>
        <td style="background-color:{{ $palette['bg'] }}; border:1px solid {{ $palette['border'] }}; border-radius:10px; padding:16px 20px;">
            @if ($label)
                <p style="margin:0 0 4px; font-size:11px; color:{{ $palette['accent'] }}; font-weight:700; letter-spacing:0.18em; text-transform:uppercase;">
                    {{ $label }}
                </p>
            @endif
            <div style="font-size:14px; color:#ffffff; line-height:1.5;">
                {{ $slot }}
            </div>
        </td>
    </tr>
</table>
