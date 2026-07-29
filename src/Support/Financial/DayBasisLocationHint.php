<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Financial;

/**
 * A one-time offer to change the trading day basis after a trader turns up in
 * a different country.
 *
 * Deliberately an offer and never an action. The basis exists so Kraite and
 * the trader's exchange agree on which hours are "today", and the exchange
 * setting does not travel: on 2026-07-29 Bruno was in Lisbon (UTC+1) with his
 * Binance rolling at UTC+2, and silently following his location would have
 * re-broken the alignment that had just been built. So we say what we noticed,
 * offer the switch, and leave the decision alone.
 *
 * Shown once per country. Somewhere new asks again; the same place does not.
 */
final class DayBasisLocationHint
{
    /**
     * Countries that sit on exactly one UTC offset, mapped to it in minutes.
     *
     * Deliberately partial. A country spanning several bases — the United
     * States, Russia, Brazil, Australia — cannot be resolved from a country
     * code, and guessing at one would be worse than saying nothing. Offsets
     * are the standard-time value the country's exchanges quote, matching how
     * an exchange stores its own change-of-day setting.
     *
     * @var array<string, array{name: string, offset: int}>
     */
    private const SINGLE_OFFSET_COUNTRIES = [
        'PT' => ['name' => 'Portugal', 'offset' => 60],
        'GB' => ['name' => 'the United Kingdom', 'offset' => 60],
        'IE' => ['name' => 'Ireland', 'offset' => 60],
        'IS' => ['name' => 'Iceland', 'offset' => 0],
        'CH' => ['name' => 'Switzerland', 'offset' => 120],
        'FR' => ['name' => 'France', 'offset' => 120],
        'ES' => ['name' => 'Spain', 'offset' => 120],
        'DE' => ['name' => 'Germany', 'offset' => 120],
        'IT' => ['name' => 'Italy', 'offset' => 120],
        'NL' => ['name' => 'the Netherlands', 'offset' => 120],
        'BE' => ['name' => 'Belgium', 'offset' => 120],
        'AT' => ['name' => 'Austria', 'offset' => 120],
        'PL' => ['name' => 'Poland', 'offset' => 120],
        'SE' => ['name' => 'Sweden', 'offset' => 120],
        'NO' => ['name' => 'Norway', 'offset' => 120],
        'DK' => ['name' => 'Denmark', 'offset' => 120],
        'CZ' => ['name' => 'Czechia', 'offset' => 120],
        'HU' => ['name' => 'Hungary', 'offset' => 120],
        'GR' => ['name' => 'Greece', 'offset' => 180],
        'FI' => ['name' => 'Finland', 'offset' => 180],
        'RO' => ['name' => 'Romania', 'offset' => 180],
        'BG' => ['name' => 'Bulgaria', 'offset' => 180],
        'TR' => ['name' => 'Türkiye', 'offset' => 180],
        'AE' => ['name' => 'the United Arab Emirates', 'offset' => 240],
        'IN' => ['name' => 'India', 'offset' => 330],
        'NP' => ['name' => 'Nepal', 'offset' => 345],
        'TH' => ['name' => 'Thailand', 'offset' => 420],
        'VN' => ['name' => 'Vietnam', 'offset' => 420],
        'SG' => ['name' => 'Singapore', 'offset' => 480],
        'HK' => ['name' => 'Hong Kong', 'offset' => 480],
        'CN' => ['name' => 'China', 'offset' => 480],
        'PH' => ['name' => 'the Philippines', 'offset' => 480],
        'JP' => ['name' => 'Japan', 'offset' => 540],
        'KR' => ['name' => 'South Korea', 'offset' => 540],
        'NZ' => ['name' => 'New Zealand', 'offset' => 720],
        'ZA' => ['name' => 'South Africa', 'offset' => 120],
        'IL' => ['name' => 'Israel', 'offset' => 180],
        'SA' => ['name' => 'Saudi Arabia', 'offset' => 180],
        'AR' => ['name' => 'Argentina', 'offset' => -180],
        'CL' => ['name' => 'Chile', 'offset' => -180],
        'CO' => ['name' => 'Colombia', 'offset' => -300],
        'PE' => ['name' => 'Peru', 'offset' => -300],
        'PA' => ['name' => 'Panama', 'offset' => -300],
    ];

    private function __construct(
        public readonly object $user,
        public readonly string $countryCode,
        public readonly string $countryName,
        public readonly int $suggestedOffsetMinutes,
        public readonly string $suggestedLabel,
        public readonly string $currentLabel,
    ) {}

    /**
     * The offer to show this trader, or null when there is nothing worth
     * saying — no country from the edge, a country we cannot pin to one
     * offset, a country that already matches their basis, or one they have
     * already been asked about.
     *
     * Always records where they were last seen, so the answer to "where is
     * this trader now" stays current even on the quiet path.
     */
    public static function for(object $user, ?string $countryCode): ?self
    {
        $country = self::normalise($countryCode);

        if ($country === null) {
            return null;
        }

        self::rememberLastSeen($user, $country);

        $known = self::SINGLE_OFFSET_COUNTRIES[$country] ?? null;

        if ($known === null) {
            return null;
        }

        $current = ReportingDay::forUser($user);

        if ($known['offset'] === $current->offsetMinutes) {
            return null;
        }

        if (($user->basis_hint_country ?? null) === $country) {
            return null;
        }

        return new self(
            user: $user,
            countryCode: $country,
            countryName: $known['name'],
            suggestedOffsetMinutes: $known['offset'],
            suggestedLabel: (new ReportingDay($known['offset']))->label(),
            currentLabel: $current->label(),
        );
    }

    /**
     * Mark this country as already offered, so the suggestion does not
     * reappear on the trader's next page load. Called once the offer has
     * actually been shown, not when it was merely computed.
     */
    public function remember(): void
    {
        $this->user->basis_hint_country = $this->countryCode;
        $this->user->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'country_code' => $this->countryCode,
            'country_name' => $this->countryName,
            'suggested_offset_minutes' => $this->suggestedOffsetMinutes,
            'suggested_label' => $this->suggestedLabel,
            'current_label' => $this->currentLabel,
        ];
    }

    /** Two uppercase letters, or null. `XX` is the CDN's "unknown". */
    private static function normalise(?string $countryCode): ?string
    {
        $country = strtoupper(trim((string) $countryCode));

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1 || $country === 'XX') {
            return null;
        }

        return $country;
    }

    private static function rememberLastSeen(object $user, string $country): void
    {
        if (($user->last_seen_country ?? null) === $country) {
            return;
        }

        $user->last_seen_country = $country;
        $user->save();
    }
}
