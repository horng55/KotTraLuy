<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class ExpirationCountdown
{
    public const TIMEZONE = 'Asia/Phnom_Penh';

    // Batches remain sellable throughout their expiration date, matching inventory policy.
    public function timestamp(string $expiration): CarbonImmutable
    {
        $date = CarbonImmutable::parse($expiration, self::TIMEZONE)->setTimezone(self::TIMEZONE);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiration) ? $date->endOfDay() : $date;
    }

    public function format(string $expiration, string $language = 'en'): string
    {
        $seconds = CarbonImmutable::now(self::TIMEZONE)->diffInSeconds($this->timestamp($expiration), false);
        $expired = $seconds <= 0;
        $minutes = intdiv((int) abs($seconds), 60);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $parts = [];
        foreach (['day' => $days, 'hour' => $hours, 'minute' => $minutes % 60] as $unit => $value) {
            if ($unit === 'minute' && $days > 0) break;
            if ($value > 0) $parts[] = $language === 'km'
                ? $value.' '.['day' => 'ថ្ងៃ', 'hour' => 'ម៉ោង', 'minute' => 'នាទី'][$unit]
                : $value.' '.$unit.($value === 1 ? '' : 's');
        }
        $text = $parts ? implode($language === 'km' ? ' ' : ', ', $parts) : ($language === 'km' ? 'តិចជាង ១ នាទី' : 'Less than 1 minute');
        if ($expired) $text = $language === 'km' ? 'ផុតកំណត់ '.$text.' មុន' : 'Expired '.lcfirst($text).' ago';
        return $language === 'km' ? strtr($text, array_combine(range(0, 9), ['០','១','២','៣','៤','៥','៦','៧','៨','៩'])) : $text;
    }
}
