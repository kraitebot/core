{{--
    Universal Kraite notification email.

    Two rendering paths:
      1. NEW — canonicals return $emailBlocks (structured array). Each block
         is dispatched to a <x-kraite::mail.X /> component. Preferred.
      2. LEGACY — canonicals return only the $notificationMessage string with
         [COPY]...[/COPY] and [CMD]...[/CMD] markers. Existing trading
         canonicals (38 of them as of 2026-05-14) still use this. Parsed
         inline at the bottom of this view so they keep rendering without
         per-canonical migration.

    Both paths share the same dark/branded layout (kraite header + footer).
--}}
<x-kraite::mail.layout :title="$notificationTitle">

    @isset($severity)
        @if($severity instanceof \Kraite\Core\Enums\NotificationSeverity)
            <x-kraite::mail.severity-badge :severity="$severity" />
        @endif
    @endisset

    <x-kraite::mail.heading>{{ $notificationTitle }}</x-kraite::mail.heading>

    @if(! empty($emailBlocks))
        {{-- New component-based rendering --}}
        @foreach($emailBlocks as $block)
            @switch($block['type'] ?? null)
                @case('heading')
                    <x-kraite::mail.heading :size="$block['size'] ?? 'md'">
                        {{ $block['text'] ?? '' }}
                    </x-kraite::mail.heading>
                @break

                @case('paragraph')
                    <x-kraite::mail.paragraph>
                        {!! nl2br(e($block['text'] ?? '')) !!}
                    </x-kraite::mail.paragraph>
                @break

                @case('button')
                    <x-kraite::mail.button
                        :href="$block['href'] ?? '#'"
                        :variant="$block['variant'] ?? 'primary'">
                        {{ $block['label'] ?? 'Open' }}
                    </x-kraite::mail.button>
                @break

                @case('code')
                    <x-kraite::mail.code :lang="$block['lang'] ?? null">{{ $block['content'] ?? '' }}</x-kraite::mail.code>
                @break

                @case('callout')
                    <x-kraite::mail.callout
                        :variant="$block['variant'] ?? 'info'"
                        :label="$block['label'] ?? null">
                        {!! nl2br(e($block['body'] ?? '')) !!}
                    </x-kraite::mail.callout>
                @break

                @case('fineprint')
                    <x-kraite::mail.fineprint>
                        {{ $block['text'] ?? '' }}
                    </x-kraite::mail.fineprint>
                @break

                @case('divider')
                    <x-kraite::mail.divider />
                @break
            @endswitch
        @endforeach
    @else
        {{-- Legacy [COPY]/[CMD] marker parsing for the 38 existing canonicals --}}
        @if(filled($userName ?? null))
            <x-kraite::mail.paragraph>Hello {{ $userName }},</x-kraite::mail.paragraph>
        @endif

        @php
            $segments = [];
            $remaining = (string) ($notificationMessage ?? '');

            // Greedy left-to-right split on [COPY] / [CMD] markers, keeping order.
            $pattern = '/\[(COPY|CMD)\](.*?)\[\/\1\]/s';
            $offset = 0;
            if (preg_match_all($pattern, $remaining, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $i => $whole) {
                    $start = $whole[1];
                    $length = strlen($whole[0]);
                    if ($start > $offset) {
                        $segments[] = ['type' => 'text', 'content' => substr($remaining, $offset, $start - $offset)];
                    }
                    $marker = $matches[1][$i][0];
                    $content = trim($matches[2][$i][0]);
                    $segments[] = ['type' => $marker === 'COPY' ? 'copy' : 'cmd', 'content' => $content];
                    $offset = $start + $length;
                }
            }
            if ($offset < strlen($remaining)) {
                $segments[] = ['type' => 'text', 'content' => substr($remaining, $offset)];
            }
            if (empty($segments)) {
                $segments[] = ['type' => 'text', 'content' => $remaining];
            }
        @endphp

        @foreach($segments as $seg)
            @switch($seg['type'])
                @case('copy')
                    <x-kraite::mail.code>{{ $seg['content'] }}</x-kraite::mail.code>
                @break
                @case('cmd')
                    <x-kraite::mail.code lang="cmd">{{ $seg['content'] }}</x-kraite::mail.code>
                @break
                @default
                    @if(trim($seg['content']) !== '')
                        <x-kraite::mail.paragraph>
                            {!! nl2br(e($seg['content'])) !!}
                        </x-kraite::mail.paragraph>
                    @endif
            @endswitch
        @endforeach

        @if(! empty($actionUrl))
            <x-kraite::mail.button :href="$actionUrl">
                {{ $actionLabel ?? 'Open' }}
            </x-kraite::mail.button>
        @endif

        @if(! empty($details))
            <x-kraite::mail.callout variant="info" label="Details">
                {!! nl2br(e($details)) !!}
            </x-kraite::mail.callout>
        @endif
    @endif

</x-kraite::mail.layout>
