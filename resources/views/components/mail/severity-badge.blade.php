@props([
    'severity' => null,
])
@php
    if (! $severity instanceof \Kraite\Core\Enums\NotificationSeverity) {
        return;
    }
    $palette = match ($severity) {
        \Kraite\Core\Enums\NotificationSeverity::Critical => ['bg' => '#1f0a0a', 'border' => '#7f1d1d', 'accent' => '#ef4444'],
        \Kraite\Core\Enums\NotificationSeverity::High     => ['bg' => '#1f1808', 'border' => '#78350f', 'accent' => '#f59e0b'],
        \Kraite\Core\Enums\NotificationSeverity::Medium   => ['bg' => '#0f1f15', 'border' => '#14532d', 'accent' => '#22c55e'],
        \Kraite\Core\Enums\NotificationSeverity::Info     => ['bg' => '#0a1626', 'border' => '#1e3a8a', 'accent' => '#3b82f6'],
    };
@endphp
<table cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
    <tr>
        <td style="background-color:{{ $palette['bg'] }}; border:1px solid {{ $palette['border'] }}; border-radius:6px; padding:4px 10px;">
            <span style="font-size:11px; color:{{ $palette['accent'] }}; font-weight:700; letter-spacing:0.18em; text-transform:uppercase;">
                {{ $severity->icon() }} {{ $severity->label() }}
            </span>
        </td>
    </tr>
</table>
