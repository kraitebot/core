@props([
    'href' => '#',
    'variant' => 'primary',
])
@php
    $styles = match ($variant) {
        'secondary' => ['bg' => 'transparent', 'fg' => '#22c55e', 'border' => '#22c55e'],
        default => ['bg' => '#22c55e', 'fg' => '#000000', 'border' => '#22c55e'],
    };
@endphp
{{--
    Bulletproof button: padding lives on the <a>, NOT the <td>.
    Reason: padding on <td> leaves the area between the text and the
    visual edge of the button as "td space" — hovering there does NOT
    trigger the pointer cursor. Moving the padding onto an inline-block
    <a> makes the entire painted button area the link target, so the
    hand cursor shows everywhere inside the button (text + padding).
    mso-padding-alt keeps Outlook on Windows happy (it ignores padding
    on <a> and falls back to this MSO-specific hint).
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
    <tr>
        <td>
            <a href="{{ $href }}" style="display:inline-block; padding:14px 28px; background-color:{{ $styles['bg'] }}; color:{{ $styles['fg'] }}; border:1px solid {{ $styles['border'] }}; border-radius:10px; font-size:15px; font-weight:700; text-decoration:none; cursor:pointer; mso-padding-alt:14px 28px;">
                {{ $slot }}
            </a>
        </td>
    </tr>
</table>
