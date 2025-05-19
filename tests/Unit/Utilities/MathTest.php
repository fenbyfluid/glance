<?php

namespace Tests\Unit\Utilities;

use App\Utilities\Math;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MathTest extends TestCase
{
    #[TestWith([[0, 1], [0, 1], [0, 2]])]
    #[TestWith([[0, 0xFFFFFFFF], [0, 1], [1, 0]])]
    #[TestWith([[0, 0xFFFFFFFF], [0, 0xFFFFFFFF], [1, 0xFFFFFFFE]])]
    #[TestWith([[1, 0], [0, 1], [1, 1]])]
    #[TestWith([[1, 0], [1, 0], [2, 0]])]
    #[TestWith([[0xFFFFFFFF, 0xFFFFFFFF], [0xFFFFFFFF, 0xFFFFFFFF], [0xFFFFFFFF, 0xFFFFFFFE]])]
    #[TestWith([[0xE5F39754, 0xDD87305C], [0xDABED731, 0x8A611D0A], [0xC0B26E86, 0x67E84D66]])]
    public function test_add_unsigned_long_long($a, $b, $expected)
    {
        $sum = Math::addUnsignedLongLong($a, $b);

        $this->assertEquals($expected, $sum);
    }
}
