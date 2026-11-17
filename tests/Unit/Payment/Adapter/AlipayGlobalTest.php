<?php

declare(strict_types=1);

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use Box\Mod\Invoice\Entity\Invoice;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;

test('AlipayGlobal configuration declares single payment and secret key fields', function (): void {
    $config = Payment_Adapter_AlipayGlobal::getConfig();

    expect($config['supports_one_time_payments'])->toBeTrue()
        ->and($config['supports_subscriptions'])->toBeFalse()
        ->and(Payment_Adapter_AlipayGlobal::getSecretFields())->toContain('security_key')
        ->and(Payment_Adapter_AlipayGlobal::requiresManualApproval())->toBeFalse();
});

test('AlipayGlobal signature generation and verification works with MD5 and HMAC-SHA256', function (): void {
    $adapter = new Payment_Adapter_AlipayGlobal([
        'return_url' => 'http://localhost/return',
        'cancel_url' => 'http://localhost/cancel',
        'notify_url' => 'http://localhost/notify',
        'redirect_url' => 'http://localhost/redirect',
        'partner' => '2088123456789012',
        'security_key' => 'secret_test_key_123',
    ]);

    $params = [
        'service' => 'create_forex_trade',
        'partner' => '2088123456789012',
        'out_trade_no' => '1001_1600000000',
        'total_fee' => '50.00',
        'currency' => 'USD',
    ];

    // MD5 test
    $md5Sign = $adapter->signParams($params, 'secret_test_key_123', 'MD5');
    expect($md5Sign)->toBeString()->not->toBeEmpty();
    expect($adapter->verifySignature($params, $md5Sign, 'secret_test_key_123', 'MD5'))->toBeTrue();
    expect($adapter->verifySignature($params, 'wrong_signature', 'secret_test_key_123', 'MD5'))->toBeFalse();

    // HMAC-SHA256 test
    $hmacSign = $adapter->signParams($params, 'secret_test_key_123', 'HMAC-SHA256');
    expect($hmacSign)->toBeString()->not->toBeEmpty();
    expect($adapter->verifySignature($params, $hmacSign, 'secret_test_key_123', 'HMAC-SHA256'))->toBeTrue();
    expect($adapter->verifySignature($params, $md5Sign, 'secret_test_key_123', 'HMAC-SHA256'))->toBeFalse();
});

test('AlipayGlobal returns correct gateway URL based on test mode', function (): void {
    $adapter = new Payment_Adapter_AlipayGlobal([
        'return_url' => 'http://localhost/return',
        'cancel_url' => 'http://localhost/cancel',
        'notify_url' => 'http://localhost/notify',
        'redirect_url' => 'http://localhost/redirect',
    ]);

    expect($adapter->getServiceUrl())->toBe('https://intlmapi.alipay.com/gateway.do');

    $adapter->setTestMode(true);
    expect($adapter->getServiceUrl())->toBe('https://openapi.alipaydev.com/gateway.do');
});

test('AlipayGlobal getHtml renders signed payment redirect form', function (): void {
    $adapter = new Payment_Adapter_AlipayGlobal([
        'return_url' => 'http://localhost/return',
        'cancel_url' => 'http://localhost/cancel',
        'notify_url' => 'http://localhost/notify',
        'redirect_url' => 'http://localhost/redirect',
        'partner' => '2088123456789012',
        'security_key' => 'secret_key_alipay',
        'settlement_currency' => 'USD',
    ]);

    $apiAdmin = Mockery::mock();
    $apiAdmin->shouldReceive('invoice_get')->with(['id' => 123])->andReturn([
        'id' => 123,
        'serie_nr' => '00123',
        'total' => '88.50',
        'currency' => 'USD',
    ]);

    $html = $adapter->getHtml($apiAdmin, 123, false);

    expect($html)->toContain('action="https://intlmapi.alipay.com/gateway.do"')
        ->and($html)->toContain('name="partner" value="2088123456789012"')
        ->and($html)->toContain('name="total_fee" value="88.50"')
        ->and($html)->toContain('name="sign"')
        ->and($html)->toContain('name="sign_type" value="MD5"');
});

test('AlipayGlobal processTransaction processes successful payment, marks invoice paid, and sets output to success', function (): void {
    $adapter = new Payment_Adapter_AlipayGlobal([
        'return_url' => 'http://localhost/return',
        'cancel_url' => 'http://localhost/cancel',
        'notify_url' => 'http://localhost/notify',
        'redirect_url' => 'http://localhost/redirect',
        'partner' => '2088123456789012',
        'security_key' => 'my_secret_key',
    ]);

    $di = container();
    $invoice = createEntity(Invoice::class, ['id' => 77, 'client_id' => 10, 'currency' => 'USD', 'status' => Invoice::STATUS_UNPAID]);

    $invoiceRepo = Mockery::mock(Doctrine\ORM\EntityRepository::class);
    $invoiceRepo->shouldReceive('find')->with(77)->andReturn($invoice);

    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(Invoice::class)->andReturn($invoiceRepo);

    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('quoteSingleIdentifier')->with('transaction')->andReturn('`transaction`');
    $connection->shouldReceive('fetchAllAssociative')->andReturn([]);
    $em->shouldReceive('getConnection')->andReturn($connection);

    $clientService = Mockery::mock();
    $clientService->shouldReceive('get')->with(['id' => 10])->andReturn(['id' => 10]);
    $clientService->shouldReceive('addFunds')->once()->andReturn(true);

    $invoiceService = Mockery::mock();
    $invoiceService->shouldReceive('issueInvoice')->byDefault();
    $invoiceService->shouldReceive('markAsPaid')->once()->with($invoice, true, true)->andReturn(true);

    $logger = Mockery::mock();
    $logger->shouldReceive('info')->byDefault();

    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (string $mod) => match (strtolower($mod)) {
        'client' => $clientService,
        'invoice' => $invoiceService,
        default => null,
    });
    $di['logger'] = $logger;
    $adapter->setDi($di);

    $apiAdmin = Mockery::mock();
    $apiAdmin->shouldReceive('invoice_transaction_get')->with(['id' => 500])->andReturn([
        'id' => 500,
        'invoice_id' => 77,
    ]);
    $apiAdmin->shouldReceive('invoice_transaction_update')->twice()->andReturn(true);

    $postData = [
        'trade_no' => 'ALI202611170001',
        'out_trade_no' => '77_1700000000',
        'trade_status' => 'TRADE_SUCCESS',
        'total_fee' => '30.00',
        'currency' => 'USD',
    ];
    $sign = $adapter->signParams($postData, 'my_secret_key', 'MD5');
    $postData['sign'] = $sign;
    $postData['sign_type'] = 'MD5';

    $adapter->processTransaction($apiAdmin, 500, ['post' => $postData], 1);

    expect($adapter->getOutput())->toBe('success');
});

test('AlipayGlobal processTransaction rejects signature mismatch with exception', function (): void {
    $adapter = new Payment_Adapter_AlipayGlobal([
        'return_url' => 'http://localhost/return',
        'cancel_url' => 'http://localhost/cancel',
        'notify_url' => 'http://localhost/notify',
        'redirect_url' => 'http://localhost/redirect',
        'partner' => '2088123456789012',
        'security_key' => 'my_secret_key',
    ]);

    $apiAdmin = Mockery::mock();

    $postData = [
        'trade_no' => 'ALI202611170002',
        'out_trade_no' => '77_1700000000',
        'trade_status' => 'TRADE_SUCCESS',
        'total_fee' => '30.00',
        'sign' => 'invalid_tampered_signature',
        'sign_type' => 'MD5',
    ];

    expect(fn () => $adapter->processTransaction($apiAdmin, 501, ['post' => $postData], 1))
        ->toThrow(Payment_Exception::class, 'Alipay Global callback signature verification failed.')
        ->and($adapter->getOutput())->toBe('fail');
});
