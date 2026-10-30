<?php

use App\Services\HistoryService;

test('nested keys sort but lists preserve order', function () {
    expect(HistoryService::hash(['b' => ['y' => 2, 'x' => 1], 'a' => 0]))->toBe(HistoryService::hash(['a' => 0, 'b' => ['x' => 1, 'y' => 2]]))
        ->and(HistoryService::hash([1, 2]))->not->toBe(HistoryService::hash([2, 1]))
        ->and(HistoryService::hash(['x' => 1]))->not->toBe(HistoryService::hash(['x' => '1']));
});
