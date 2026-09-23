<?php

use Spatie\FlareDaemon\QuotaState;

it('pauses and resumes a single key and type', function () {
    $quotaState = new QuotaState;

    $quotaState->pause('api-key', 'traces', 10.0, 'Trace quota exceeded');

    expect($quotaState->isPaused('api-key', 'traces', 9.0))->toBeTrue()
        ->and($quotaState->reason('api-key', 'traces'))->toBe('Trace quota exceeded');

    expect($quotaState->resumeExpired(10.0))->toBe([
        ['api_key' => 'api-key', 'type' => 'traces'],
    ]);

    expect($quotaState->isPaused('api-key', 'traces', 11.0))->toBeFalse();
});
