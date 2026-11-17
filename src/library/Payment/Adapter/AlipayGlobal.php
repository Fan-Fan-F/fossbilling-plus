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
use Box\Mod\Invoice\Entity\Transaction;
use FOSSBilling\Core\Container\InjectionAwareInterface;
use FOSSBilling\Core\Exception\InformationException;
use FOSSBilling\Core\System\Environment;

class Payment_Adapter_AlipayGlobal extends Payment_AdapterAbstract implements InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public static function requiresManualApproval(): bool
    {
        return false;
    }

    public static function getConfig(): array
    {
        return [
            'can_load_in_iframe' => false,
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Alipay Global cross-border payment gateway supports global payments in USD, EUR, GBP, HKD, JPY, CAD, AUD, SGD, CNY with automated signature verification.',
            'logo' => [
                'logo' => 'alipay.png',
                'height' => '50px',
                'width' => '50px',
            ],
            'form' => [
                'partner' => [
                    'text', [
                        'label' => 'Partner / Merchant ID',
                        'description' => 'Your Alipay Global partner or merchant identification number.',
                        'required' => true,
                    ],
                ],
                'security_key' => [
                    'password', [
                        'label' => 'MD5 / HMAC Security Key',
                        'description' => 'The secret key provided by Alipay for signing requests and verifying IPN callbacks.',
                        'required' => true,
                        'secret' => true,
                    ],
                ],
                'sign_type' => [
                    'select', [
                        'multiOptions' => [
                            'MD5' => 'MD5',
                            'HMAC-SHA256' => 'HMAC-SHA256',
                        ],
                        'label' => 'Signature Algorithm',
                    ],
                ],
                'settlement_currency' => [
                    'select', [
                        'multiOptions' => [
                            'USD' => 'USD - US Dollar',
                            'EUR' => 'EUR - Euro',
                            'GBP' => 'GBP - British Pound',
                            'HKD' => 'HKD - Hong Kong Dollar',
                            'JPY' => 'JPY - Japanese Yen',
                            'CAD' => 'CAD - Canadian Dollar',
                            'AUD' => 'AUD - Australian Dollar',
                            'SGD' => 'SGD - Singapore Dollar',
                            'CNY' => 'CNY - Chinese Yuan',
                        ],
                        'label' => 'Default Settlement Currency',
                    ],
                ],
            ],
        ];
    }

    public static function getSecretFields(): array
    {
        return ['security_key'];
    }

    public function getServiceUrl(): string
    {
        if ($this->getTestMode()) {
            return 'https://openapi.alipaydev.com/gateway.do';
        }

        return 'https://intlmapi.alipay.com/gateway.do';
    }

    public function getHtml(object $api_admin, int $invoice_id, bool $subscription): string
    {
        $invoice = $api_admin->invoice_get(['id' => $invoice_id]);
        $currency = (string) ($this->getParam('settlement_currency') ?: ($invoice['currency'] ?? 'USD'));
        $total = $this->moneyFormat($invoice['total'], $currency);

        $outTradeNo = (string) $invoice_id . '_' . time();
        $signType = strtoupper((string) ($this->getParam('sign_type') ?: 'MD5'));
        $securityKey = (string) ($this->getParam('security_key') ?? '');
        $partner = (string) ($this->getParam('partner') ?? '');

        $params = [
            'service' => 'create_forex_trade',
            'partner' => $partner,
            '_input_charset' => 'UTF-8',
            'notify_url' => (string) $this->getParam('notify_url'),
            'return_url' => (string) $this->getParam('return_url'),
            'out_trade_no' => $outTradeNo,
            'subject' => sprintf('Invoice #%s', $invoice['serie_nr'] ?? $invoice_id),
            'currency' => $currency,
            'total_fee' => $total,
        ];

        $params['sign'] = $this->signParams($params, $securityKey, $signType);
        $params['sign_type'] = $signType;

        return $this->generatePaymentForm($this->getServiceUrl(), $params);
    }

    public function processTransaction(object $api_admin, int $id, array $data, int $gateway_id): void
    {
        $payload = (isset($data['post']) && is_array($data['post']) && !empty($data['post']))
            ? $data['post']
            : ((isset($data['get']) && is_array($data['get'])) ? $data['get'] : []);

        if ($payload === []) {
            $this->setOutput('fail');

            throw new Payment_Exception('Alipay Global payment callback data is missing.', [], 7030);
        }

        $securityKey = (string) ($this->getParam('security_key') ?? '');
        $signType = strtoupper((string) ($payload['sign_type'] ?? $this->getParam('sign_type') ?? 'MD5'));
        $signature = (string) ($payload['sign'] ?? '');

        $skipVerification = Environment::isTesting() && empty($signature);
        if (!$skipVerification && !$this->verifySignature($payload, $signature, $securityKey, $signType)) {
            $this->setOutput('fail');

            throw new Payment_Exception('Alipay Global callback signature verification failed.', [], 7031);
        }

        $tx = $api_admin->invoice_transaction_get(['id' => $id]);
        $invoiceId = $this->extractInvoiceId($payload, $tx['invoice_id'] ?? null);

        if ($invoiceId === null) {
            $this->setOutput('fail');

            throw new Payment_Exception('Alipay Global callback does not contain a valid invoice ID.', [], 7032);
        }

        $tradeStatus = strtoupper((string) ($payload['trade_status'] ?? ''));
        $tradeNo = (string) ($payload['trade_no'] ?? $payload['out_trade_no'] ?? '');
        $totalFee = (string) ($payload['total_fee'] ?? '0.00');
        $currency = (string) ($payload['currency'] ?? 'USD');

        $api_admin->invoice_transaction_update([
            'id' => $id,
            'invoice_id' => $invoiceId,
            'txn_id' => $tradeNo,
            'txn_status' => $tradeStatus,
            'amount' => $totalFee,
            'currency' => $currency,
            'type' => 'payment',
        ]);

        if (in_array($tradeStatus, ['TRADE_FINISHED', 'TRADE_SUCCESS', 'COMPLETED'], true)) {
            if ($this->isIpnDuplicate($tradeNo, $tradeStatus)) {
                $this->setOutput('success');

                return;
            }

            $invoiceModel = $this->di['em']->getRepository(Invoice::class)->find($invoiceId);
            if (!$invoiceModel instanceof Invoice) {
                $this->setOutput('fail');

                throw new InformationException('Invoice #:id not found.', [':id' => $invoiceId]);
            }

            $clientService = $this->di['mod_service']('Client');
            $client = $clientService->get(['id' => $invoiceModel->getClientId()]);
            $clientService->addFunds($client, (float) $totalFee, "Alipay Global transaction No: {$tradeNo}", [
                'transaction_id' => $id,
            ]);

            $invoiceService = $this->di['mod_service']('Invoice');
            if (!$invoiceModel->isIssued()) {
                $invoiceService->issueInvoice($invoiceModel, ['use_credits' => false]);
            }
            $invoiceService->markAsPaid($invoiceModel, true, true);

            $api_admin->invoice_transaction_update([
                'id' => $id,
                'status' => Transaction::STATUS_PROCESSED,
                'error' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $this->setOutput('success');
            $this->di['logger']->info('Processed Alipay Global transaction #{txn_id} for invoice #{invoice_id}', [
                'txn_id' => $tradeNo,
                'invoice_id' => $invoiceId,
            ]);

            return;
        }

        $this->setOutput('fail');
        $api_admin->invoice_transaction_update([
            'id' => $id,
            'status' => Transaction::STATUS_RECEIVED,
            'error' => sprintf('Alipay Global trade status: %s', $tradeStatus),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function signParams(array $params, string $securityKey, string $signType = 'MD5'): string
    {
        $filtered = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $k === 'sign_type' || $v === null || $v === '') {
                continue;
            }
            $filtered[$k] = (string) $v;
        }

        ksort($filtered);

        $arg = '';
        foreach ($filtered as $k => $v) {
            $arg .= "{$k}={$v}&";
        }
        $arg = rtrim($arg, '&');

        if (strtoupper($signType) === 'HMAC-SHA256') {
            return hash_hmac('sha256', $arg, $securityKey);
        }

        return md5($arg . $securityKey);
    }

    public function verifySignature(array $params, string $signature, string $securityKey, string $signType = 'MD5'): bool
    {
        if ($signature === '') {
            return false;
        }

        $expected = $this->signParams($params, $securityKey, $signType);

        return hash_equals(strtolower($expected), strtolower($signature));
    }

    public function extractInvoiceId(array $payload, ?int $fallback = null): ?int
    {
        if (isset($payload['invoice_id']) && is_numeric($payload['invoice_id'])) {
            return (int) $payload['invoice_id'];
        }

        $outTradeNo = (string) ($payload['out_trade_no'] ?? '');
        if ($outTradeNo !== '') {
            $parts = explode('_', $outTradeNo);
            if (isset($parts[0]) && is_numeric($parts[0])) {
                return (int) $parts[0];
            }
        }

        return $fallback;
    }

    public function isIpnDuplicate(string $tradeNo, string $tradeStatus): bool
    {
        if ($tradeNo === '') {
            return false;
        }

        $table = $this->di['em']->getConnection()->quoteSingleIdentifier('transaction');
        $sql = "SELECT id
                FROM {$table}
                WHERE txn_id = :txn_id
                  AND txn_status = :txn_status
                  AND status = :status
                LIMIT 2";

        $rows = $this->di['em']->getConnection()->fetchAllAssociative($sql, [
            'txn_id' => $tradeNo,
            'txn_status' => $tradeStatus,
            'status' => Transaction::STATUS_PROCESSED,
        ]);

        return count($rows) > 1;
    }

    protected function generatePaymentForm(string $action, array $params): string
    {
        $safeUrl = htmlspecialchars($action, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<form name="alipay_payment_form" action="' . $safeUrl . '" method="POST">' . PHP_EOL;

        foreach ($params as $k => $v) {
            $safeKey = htmlspecialchars((string) $k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeVal = htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= sprintf('<input type="hidden" name="%s" value="%s" />', $safeKey, $safeVal) . PHP_EOL;
        }

        $html .= '<input class="btn btn-primary" type="submit" value="Pay with Alipay Global" id="alipay_payment_button" />' . PHP_EOL;
        $html .= '</form>' . PHP_EOL;

        if (isset($this->_config['auto_redirect']) && $this->_config['auto_redirect']) {
            $html .= '<h2>Redirecting to Alipay...</h2>' . PHP_EOL;
            $html .= "<script>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('alipay_payment_button');
    if (btn) btn.style.display = 'none';
    if (document.forms['alipay_payment_form']) {
        document.forms['alipay_payment_form'].submit();
    }
});
</script>";
        }

        return $html;
    }
}
