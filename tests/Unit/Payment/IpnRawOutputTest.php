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
use Box\Mod\Invoice\Entity\Transaction;
use Box\Mod\Invoice\Repository\TransactionRepository;
use Box\Mod\Invoice\ServicePayGateway;
use Box\Mod\Invoice\ServiceTransaction;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;

test('ServiceTransaction persists custom output from payment adapter onto the transaction', function (): void {
    $service = new ServiceTransaction();
    $di = container();

    $gtw = createEntity(PayGateway::class, ['id' => 9, 'name' => 'CustomGateway']);
    $tx = createEntity(Transaction::class, ['id' => 88]);
    $tx->setGateway($gtw);

    $txRepo = Mockery::mock(TransactionRepository::class);
    $txRepo->shouldReceive('find')->with(88)->andReturn($tx);

    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(Transaction::class)->andReturn($txRepo);
    $em->shouldReceive('persist')->with($tx)->once();
    $em->shouldReceive('flush')->once();

    $adapter = new class {
        public function processTransaction(object $api_admin, int $id, array $data, int $gateway_id): void
        {
        }

        public function getOutput(): string
        {
            return 'success';
        }
    };

    $payGatewayService = Mockery::mock(ServicePayGateway::class);
    $payGatewayService->shouldReceive('getPaymentAdapter')->with($gtw)->andReturn($adapter);

    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (string $mod, ?string $sub = null) => match (strtolower($mod)) {
        'invoice' => $payGatewayService,
        default => null,
    });
    $di['api_system'] = Mockery::mock();

    $service->setDi($di);
    $service->processTransaction(88);

    expect($tx->getOutput())->toBe('success');
});
