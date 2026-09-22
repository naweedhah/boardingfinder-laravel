<?php

namespace Tests\Unit;

use App\Enums\ScamFlag;
use App\Services\ScamDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScamDetectorTest extends TestCase
{
    #[Test]
    #[DataProvider('messages')]
    public function it_classifies_messages(string $text, ScamFlag $expected): void
    {
        $this->assertSame($expected, (new ScamDetector)->detect($text));
    }

    public static function messages(): array
    {
        return [
            'plain' => ['Is the room still available?', ScamFlag::Safe],
            'empty' => ['', ScamFlag::Safe],
            'advance payment' => ['I need an Advance Payment first', ScamFlag::Danger],
            'send money' => ['Please SEND MONEY today', ScamFlag::Danger],
            'urgent' => ['this is urgent', ScamFlag::Warning],
            'danger beats warning' => ['urgent: send money now', ScamFlag::Danger],
        ];
    }
}
