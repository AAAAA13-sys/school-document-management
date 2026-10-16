<?php

namespace Tests\Unit;

use App\Services\HistoryService;
use PHPUnit\Framework\TestCase;

class CanonicalHashTest extends TestCase
{
    public function test_nested_keys_sort_but_lists_preserve_order(): void
    {
        $this->assertSame(HistoryService::hash(['b' => ['y' => 2, 'x' => 1], 'a' => 0]), HistoryService::hash(['a' => 0, 'b' => ['x' => 1, 'y' => 2]]));
        $this->assertNotSame(HistoryService::hash([1, 2]), HistoryService::hash([2, 1]));
        $this->assertNotSame(HistoryService::hash(['x' => 1]), HistoryService::hash(['x' => '1']));
    }
}
