<?php

declare(strict_types=1);

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use Box\Mod\Invoice\Entity\PayGateway;
use Box\Mod\Invoice\ServicePayGateway;

use function Tests\Helpers\container;

test('AlipayGlobal is discovered in available payment gateways', function (): void {
    $service = new ServicePayGateway();
    $di = container();

    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchAllAssociative')
        ->with('SELECT id, gateway, name
            FROM pay_gateway')
        ->andReturn([]);

    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($connection);
    $em->shouldReceive('getRepository')->with(PayGateway::class)->andReturn(Mockery::mock(Box\Mod\Invoice\Repository\PayGatewayRepository::class));

    $di['em'] = $em;
    $service->setDi($di);

    $available = $service->getAvailable();
    expect($available)->toBeArray()
        ->and($available)->toContain('AlipayGlobal');

    $pg = new PayGateway();
    $pg->setGateway('AlipayGlobal');
    $className = $service->getAdapterClassName($pg);
    expect($className)->toBe('Payment_Adapter_AlipayGlobal')
        ->and(class_exists($className))->toBeTrue();
});
