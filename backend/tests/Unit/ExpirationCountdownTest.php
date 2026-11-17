<?php

namespace Tests\Unit;

use App\Services\ExpirationCountdown;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ExpirationCountdownTest extends TestCase
{
    public function test_countdowns_in_both_languages_and_date_policy(): void
    {
        $now = CarbonImmutable::parse('2026-11-17 16:00:00', ExpirationCountdown::TIMEZONE);
        CarbonImmutable::setTestNow($now);
        try {
            $formatter = new ExpirationCountdown;
            foreach ([277200 => '3 days, 5 hours', 172800 => '2 days', 111600 => '1 day, 7 hours', 86400 => '1 day', 45000 => '12 hours, 30 minutes', 3600 => '1 hour', 4500 => '1 hour, 15 minutes', 1500 => '25 minutes', 30 => 'Less than 1 minute', 0 => 'Expired less than 1 minute ago', -172800 => 'Expired 2 days ago'] as $seconds => $expected) {
                $this->assertSame($expected, $formatter->format($now->addSeconds($seconds)->toIso8601String()));
            }
            foreach ([86400 => '១ ថ្ងៃ', 3600 => '១ ម៉ោង', 1500 => '២៥ នាទី', 30 => 'តិចជាង ១ នាទី', 0 => 'ផុតកំណត់ តិចជាង ១ នាទី មុន', -172800 => 'ផុតកំណត់ ២ ថ្ងៃ មុន'] as $seconds => $expected) {
                $this->assertSame($expected, $formatter->format($now->addSeconds($seconds)->toIso8601String(), 'km'));
            }
            $this->assertEqualsWithDelta(111600, 1.291666666667 * 86400, 0.001);
            $this->assertSame('១ ថ្ងៃ ៧ ម៉ោង', $formatter->format($now->addSeconds(111600)->toIso8601String(), 'km'));
            $this->assertSame('1 day, 7 hours', $formatter->format('2026-11-18'));
            $this->assertSame('23:59:59', $formatter->timestamp('2026-11-18')->format('H:i:s'));
        } finally { CarbonImmutable::setTestNow(); }
    }
}
