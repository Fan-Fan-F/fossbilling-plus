<?php

declare(strict_types=1);

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use Box\Mod\Order\Entity\Order;
use Box\Mod\Order\Entity\OrderMeta;
use Box\Mod\Order\Repository\OrderMetaRepository;
use Box\Mod\Order\Repository\OrderRepository;
use Box\Mod\Order\Service;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;

test('createFromOrder transitions order to failed_setup when ACTION_CREATE throws an exception', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $order = createEntity(Order::class, [
        'id' => 101,
        'status' => Order::STATUS_PENDING_SETUP,
        'service_type' => 'custom',
    ]);

    $serviceMock->shouldReceive('getOrderService')->with($order)->andReturn(null);
    $serviceMock->shouldReceive('withLifecycleLock')->andReturnUsing(fn ($order, $op) => $op());
    $serviceMock->shouldReceive('assertOrderNotDeleting')->byDefault();
    $serviceMock->shouldReceive('saveStatusChange')->byDefault();
    $serviceMock->shouldReceive('setOrderMetaValue')->byDefault();

    $modMock = Mockery::mock();
    $modService = new class {
        public function create(): never
        {
            throw new RuntimeException('Remote server unreachable during service creation');
        }
    };
    $modMock->shouldReceive('getService')->andReturn($modService);

    $serviceMock->shouldReceive('_callOnService')->with($order, Order::ACTION_CREATE)
        ->andThrow(new RuntimeException('Remote server unreachable during service creation'));

    $orderRepoMock = Mockery::mock(OrderRepository::class)->shouldIgnoreMissing();
    $orderRepoMock->shouldReceive('find')->with(101)->andReturn($order);

    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('isTransactionActive')->andReturn(false);
    $connection->shouldReceive('fetchOne')->andReturn(101);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $emMock->shouldReceive('getConnection')->andReturn($connection);
    $emMock->shouldReceive('refresh')->byDefault();
    $emMock->shouldReceive('getRepository')->with(Order::class)->andReturn($orderRepoMock);
    $emMock->shouldReceive('persist')->byDefault();
    $emMock->shouldReceive('flush')->byDefault();

    $di = container();
    $di['em'] = $emMock;
    $di['mod'] = $di->protect(fn () => $modMock);
    $serviceMock->setDi($di);

    expect(fn () => $serviceMock->createFromOrder($order))
        ->toThrow(RuntimeException::class, 'Remote server unreachable during service creation')
        ->and($order->getStatus())->toBe(Order::STATUS_FAILED_SETUP);
});

test('retryFailedOrder throws InformationException for orders not in failed_setup status', function (): void {
    $svc = new Service();
    $order = createEntity(Order::class, [
        'id' => 102,
        'status' => Order::STATUS_ACTIVE,
    ]);

    expect(fn () => $svc->retryFailedOrder($order))
        ->toThrow(FOSSBilling\Core\Exception\InformationException::class, 'Only orders with status "failed_setup" can be recovered');
});

test('retryFailedOrder increments retry count and sets last_retry_at metadata', function (): void {
    $svc = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $order = createEntity(Order::class, [
        'id' => 103,
        'status' => Order::STATUS_FAILED_SETUP,
    ]);

    $metaMap = [];
    $svc->shouldReceive('getOrderSetupRetryCount')->with($order)->andReturn(1);
    $svc->shouldReceive('setOrderMetaValue')->withArgs(function ($ord, $k, $v) use (&$metaMap): bool {
        $metaMap[$k] = $v;

        return true;
    });
    $svc->shouldReceive('activateOrder')->with($order, ['force' => true])->andReturn(true);
    $svc->shouldReceive('saveStatusChange')->byDefault();

    $result = $svc->retryFailedOrder($order);

    expect($result)->toBeTrue()
        ->and($metaMap)->toHaveKey(Service::META_SETUP_RETRY_COUNT, '2')
        ->and($metaMap)->toHaveKey(Service::META_SETUP_LAST_RETRY_AT)
        ->and($metaMap)->toHaveKey(Service::META_SETUP_LAST_ERROR, null);
});

test('resetFailedOrderRetries clears retry count and last error metadata', function (): void {
    $svc = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $order = createEntity(Order::class, [
        'id' => 104,
        'status' => Order::STATUS_FAILED_SETUP,
    ]);

    $metaMap = [];
    $svc->shouldReceive('setOrderMetaValue')->withArgs(function ($ord, $k, $v) use (&$metaMap): bool {
        $metaMap[$k] = $v;

        return true;
    });
    $svc->shouldReceive('saveStatusChange')->byDefault();

    $result = $svc->resetFailedOrderRetries($order);

    expect($result)->toBeTrue()
        ->and($metaMap[Service::META_SETUP_RETRY_COUNT])->toBe('0')
        ->and($metaMap[Service::META_SETUP_LAST_ERROR])->toBeNull()
        ->and($metaMap[Service::META_SETUP_LAST_RETRY_AT])->toBeNull();
});

test('batchRetryFailedSetup recovers eligible orders and skips exhausted and cooling down orders', function (): void {
    $svc = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $order1 = createEntity(Order::class, ['id' => 201, 'status' => Order::STATUS_FAILED_SETUP]);
    $order2 = createEntity(Order::class, ['id' => 202, 'status' => Order::STATUS_FAILED_SETUP]);
    $order3 = createEntity(Order::class, ['id' => 203, 'status' => Order::STATUS_FAILED_SETUP]);

    $orderRepoMock = Mockery::mock(OrderRepository::class);
    $orderRepoMock->shouldReceive('find')->with(201)->andReturn($order1);
    $orderRepoMock->shouldReceive('find')->with(202)->andReturn($order2);
    $orderRepoMock->shouldReceive('find')->with(203)->andReturn($order3);
    $svc->shouldReceive('getOrderRepository')->andReturn($orderRepoMock);

    // Order 201: 1 retry, last retry 10 minutes ago -> eligible, recovers
    $svc->shouldReceive('getOrderSetupRetryCount')->with($order1)->andReturn(1);
    $meta201 = createEntity(OrderMeta::class, ['value' => date('Y-m-d H:i:s', time() - 600)]);

    // Order 202: 3 retries -> exhausted (max_retries = 3)
    $svc->shouldReceive('getOrderSetupRetryCount')->with($order2)->andReturn(3);

    // Order 203: 0 retries, last retry 1 minute ago -> cooling down (retryDelay = 5 mins)
    $svc->shouldReceive('getOrderSetupRetryCount')->with($order3)->andReturn(0);
    $meta203 = createEntity(OrderMeta::class, ['value' => date('Y-m-d H:i:s', time() - 60)]);

    $metaRepoMock = Mockery::mock(OrderMetaRepository::class);
    $metaRepoMock->shouldReceive('findOneByOrderIdAndName')->with(201, Service::META_SETUP_LAST_RETRY_AT)->andReturn($meta201);
    $metaRepoMock->shouldReceive('findOneByOrderIdAndName')->with(203, Service::META_SETUP_LAST_RETRY_AT)->andReturn($meta203);
    $svc->shouldReceive('getOrderMetaRepository')->andReturn($metaRepoMock);

    $svc->shouldReceive('retryFailedOrder')->with($order1)->once()->andReturn(true);

    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('quoteSingleIdentifier')->with('client_order')->andReturn('`client_order`');
    $connection->shouldReceive('fetchAllAssociative')
        ->with('SELECT id FROM `client_order` WHERE status = :status ORDER BY id ASC LIMIT 20', ['status' => 'failed_setup'])
        ->andReturn([
            ['id' => 201],
            ['id' => 202],
            ['id' => 203],
        ]);

    $emMock = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $emMock->shouldReceive('getConnection')->andReturn($connection);

    $logger = Mockery::mock();
    $logger->shouldReceive('info')->byDefault();
    $logger->shouldReceive('error')->byDefault();

    $di = container();
    $di['em'] = $emMock;
    $di['logger'] = $logger;
    $svc->setDi($di);

    $stats = $svc->batchRetryFailedSetup(maxRetries: 3, retryDelayMinutes: 5, limit: 20);

    expect($stats)->toMatchArray([
        'total' => 3,
        'attempted' => 1,
        'recovered' => 1,
        'failed' => 0,
        'exhausted' => 1,
        'cooling_down' => 1,
    ]);
});
