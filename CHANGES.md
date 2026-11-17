# 改进记录 CHANGES

本项目基于 FOSSBilling 二次开发，旨在增强跨境支付能力、修复订单开通失败时的状态流转缺陷、并提供完备的订单自动化开通失败恢复与批量重试机制。

## 改进计划与实质改进点

### 1. 新增跨境支付通道：Alipay Global (支付宝跨境/国际支付适配器)
- **背景与问题**：原项目默认仅内置 PayPal、Stripe 与本地余额/离线转账，缺乏面向亚太及全球跨境电商的核心支付通道。
- **改动内容**：
  - 新增 `Payment_Adapter_AlipayGlobal` (`src/library/Payment/Adapter/AlipayGlobal.php`)，实现 `Payment_AdapterAbstract` 与 `FOSSBilling\Core\Container\InjectionAwareInterface`。
  - 支持多币种跨境结算（USD, EUR, GBP, HKD, JPY, CAD, AUD, SGD, CNY 等），提供一键切换沙箱与生产网关地址。
  - 提供安全的签名与验签算法（支持 MD5 及 HMAC-SHA256 防篡改），通过 `getSecretFields()` 保护商户密钥安全脱敏。
  - 支持自动生成标准跳转表单 (`getHtml`)，并在接收异步通知（IPN/Webhook）时校验商户交易流水号、账单金额与货币匹配性，实现幂等处理并自动核销账单。

### 2. 修复网关异步通知 (IPN) 原生响应机制与自定义应答输出
- **背景与问题**：`src/ipn.php` 原实现统一将所有网关的回调输出包装成 API JSON（如 `{"result": 1, "error": null}`），而许多跨境支付网关（如 Alipay、Paddle、Adyen 等）严格要求回调返回纯文本状态（如 `success` 或 `OK`），导致网关误判通知失败而反复触发重发与报警。
- **改动内容**：
  - 在 `src/modules/Invoice/ServiceTransaction.php` 中增强事务处理逻辑：当支付适配器通过 `setOutput()` 登记自定义响应内容时，自动持久化至 `Transaction` 实体的 `output` 字段。
  - 改造 `src/ipn.php`：优先检测事务中是否有自定义输出，若有则以 `text/plain` 格式输出原始网关应答，无自定义应答时平滑回退至原有 JSON 响应格式，确保主流网关回调无缝兼容。

### 3. 修复订单开通服务创建阶段未捕获异常导致状态僵死缺陷
- **背景与问题**：`src/modules/Order/Service.php` 中 `createServiceFromOrder()` 在执行 `_callOnService($order, Order::ACTION_CREATE)` 时未做异常捕获保护。当第三方服务端（如主机面板、域名注册接口）发生超时、凭据失效或网络瞬断时，异常向外溢出，导致订单停留在 `pending_setup` 状态且无错误原因记录，使得后续自动化任务无法识别其为失败订单。
- **改动内容**：
  - 在 `createServiceFromOrder()` 中将 `ACTION_CREATE` 纳入统一异常捕获处理流程。
  - 发生异常时，原子性地将订单状态更新为 `STATUS_FAILED_SETUP`，并将服务创建失败详情记录至订单状态历史表 (`client_order_status`)，同时安全向外抛出异常以维持外层事务一致性。

### 4. 实现订单自动化开通失败恢复机制与批量重试调度 (Automated Failure Recovery)
- **背景与问题**：原项目在订单开通失败后仅能依靠管理员手动逐单查找并重新激活，缺少自动化重试与冷却重试策略。
- **改动内容**：
  - 在 `src/modules/Order/Service.php` 中新增 `batchRetryFailedSetup()` 失败恢复核心逻辑：
    - 支持按最大重试次数（`maxRetries`，默认 3 次）与冷却时间（`retryDelayMinutes`，默认 5 分钟）筛选可恢复订单。
    - 使用持久化元数据记录重试次数与失败原因，成功开通后自动恢复为 `active` 并在状态历史留下追踪记录；重试耗尽时记录告警。
    - 新增 `retryFailedOrder()` 与 `resetFailedOrderRetries()` 支持单订单重试与重试计数重置。
  - 在 `src/modules/Order/Api/Admin.php` 中暴露管理端 API：`batch_retry_failed_setup`、`retry_setup`、`reset_setup_retries`。
  - 将 `order_batch_retry_failed_setup` 任务集成至系统 `Cron\Service` 的定时执行列表中，实现定时无缝自动恢复因临时网络或接口抖动导致的开通失败。

### 5. 增强多币种汇率转换边界处理与同币种免请求保护
- **背景与问题**：`src/modules/Currency/Service.php` 在处理汇率时，若源货币与目标货币相同仍会发起远程外部 API 请求；若接口异常或未在汇率表包含源币种自身，将导致异常中断。
- **改动内容**：
  - 在 `Currency\Service::getRate()` 中增加同币种前置判断：当 `$fromCurrencyCode === $toCurrencyCode` 时直接返回 `1.0`，避免无谓的网络开销并杜绝误抛异常。

---

### 6. 补记两处小改（随本次增强一并提交）

- `src/di.php`：会话处理器锁模式按驱动回退——SQLite 不支持 advisory lock 时改用 LOCK_NONE，避免 SQLite 环境会话初始化失败。
- `src/cron.php`：CLI 输出加输出缓冲（ob_start/ob_end_flush）并补齐换行，避免定时任务日志输出错位。

## 自测记录

自测环境：Linux x86_64, PHP 8.3.6, Node.js v24.22.1, npm 10.9.4.

1. **依赖安装与前端资源编译**
   - 执行命令：`COMPOSER_ALLOW_SUPERUSER=1 composer install`
     - 结果：通过。PHP 依赖完整加载，autoload 文件成功生成。
   - 执行命令：`npm install && npm run build`
     - 结果：通过。esbuild 成功完成全量打包：`src/public/assets` 核心前端 bundle、`src/themes/default/admin` (admin_default) 后台主题及 `src/themes/default/client` (huraga) 前台主题构建完毕。

2. **代码规范检查 (PSR-12)**
   - 执行命令：`composer cs:check`
     - 结果：通过。全部 1090 个 PHP 源代码文件均符合 PSR-12 代码规范，0 coding standards violations。

3. **单元测试与业务逻辑验证**
   - 针对本次新增与改动模块（AlipayGlobal 跨境网关、IPN 原始应答、订单失败自动恢复机制、定时任务集成、汇率优化）：
     - 执行命令：`APP_ENV=test COMPOSER_ALLOW_SUPERUSER=1 ./src/vendor/bin/pest --test-directory ../tests tests/Unit/Payment/ tests/Unit/Currency/ src/modules/Order/tests/Unit/FailureRecoveryTest.php src/modules/Cron/tests/Unit/ServiceTest.php`
     - 结果：通过（282 tests passed, 829 assertions, 0 errors）。
   - 全局 Unit 测试套件全量回归：
     - 执行命令：`APP_ENV=test COMPOSER_ALLOW_SUPERUSER=1 ./src/vendor/bin/pest --test-directory ../tests --testsuite=Unit`
     - 结果：通过（1615 tests passed, 4918 assertions, 3 skipped 由外部真实数据库跳过, 0 failures）。

4. **CLI 控制台与后台自动化 Cron 测试**
   - 执行命令：`php src/console.php system:version`
     - 结果：通过。正常输出版本信息，控制台命令与依赖注入容器装配正常。
   - 执行命令：`php src/cron.php`
     - 结果：通过。全量 14 个后台定时任务批量调度完成，新增的 `order_batch_retry_failed_setup` 自动化恢复任务成功被 Cron 调度器无锁执行并安全退出。

5. **Web 基础服务与 Guest API 连通性测试**
   - 执行命令：`php -S 127.0.0.1:8088 -t src &` 与 `curl -s -i http://127.0.0.1:8088/api/guest/system/company`
     - 结果：通过。HTTP 200 OK，返回有效 JSON 数据：
       `{"result":{"title":"FOSSBilling","name":"FOSSBilling","email":null,"signature":null,"logo_url":null,"url":null},"error":null}`。
