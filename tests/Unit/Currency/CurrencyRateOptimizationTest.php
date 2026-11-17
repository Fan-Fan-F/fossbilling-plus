<?php

declare(strict_types=1);

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use Box\Mod\Currency\Service as CurrencyService;

test('CurrencyService getRate returns 1.0 immediately when source and target currencies match', function (): void {
    $service = new class extends CurrencyService {
        public function testGetRate(string $from, string $to): float
        {
            return $this->getRate($from, $to);
        }
    };

    expect($service->testGetRate('USD', 'USD'))->toBe(1.0)
        ->and($service->testGetRate('EUR', 'EUR'))->toBe(1.0)
        ->and($service->testGetRate('CNY', 'CNY'))->toBe(1.0);
});
