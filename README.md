# fossbilling-plus

> 面向跨境电商与托管服务商的开源账单与自动化开通管理系统（增强版）。  
> Enhanced open-source billing, client management, and automated provisioning system for cross-border e-commerce and hosting providers.

---

## 中文文档

### 简介
**fossbilling-plus** 是基于 FOSSBilling 二次开发的高性能客户管理与自动化账单系统。针对全球跨境电商与云主机/域名托管业务场景，系统深度增强了多币种跨境支付能力（新增 Alipay Global 国际支付宝通道）、修复了异步通知原生纯文本应答机制、完善了开通异常捕获与状态保护，并引入了生产级的订单自动化开通失败恢复（Failure Recovery）与周期性后台重试调度，全面提升数字产品交付的稳定性与可靠性。

### 功能与改进点
本项目在完整保留上游模块化架构与现代化特性的同时，实施了 5 项核心实质改进（逐项对应 `CHANGES.md`）：

1. **跨境支付通道：Alipay Global (支付宝跨境/国际支付网关)**
   - 新增 `Payment_Adapter_AlipayGlobal` 适配器，支持美元 (USD)、欧元 (EUR)、英镑 (GBP)、港币 (HKD)、日元 (JPY)、人民币 (CNY) 等主流国际结算币种。
   - 支持沙箱测试环境与生产网关一键切换；提供敏感密钥自动脱敏保护机制 (`getSecretFields`)。
   - 具备防篡改签名与验签算法（支持 MD5 与 HMAC-SHA256），实现异步回调幂等防重与发票自动核销入账。
2. **网关异步通知 (IPN) 原生响应机制与自定义应答输出**
   - 解决跨境支付通道（如 Alipay、Paddle、Adyen 等）回调严格要求返回纯文本状态（如 `success` 或 `OK`）的兼容性问题。
   - 在事务层持久化适配器输出，`src/ipn.php` 优先返回纯文本原始响应，无自定义应答时平滑回退至 JSON 格式，彻底消除网关因误判而反复重试告警的问题。
3. **订单开通服务异常捕获与状态保护**
   - 修复在调用第三方服务（如主机控制面板、域名注册接口）发生网络超时或凭据错误时，未捕获异常导致订单停滞在 `pending_setup` 且无错误原因记录的问题。
   - 在 `createServiceFromOrder` 阶段实现原子性异常保护：出现故障时将订单状态置为 `failed_setup` 并将详细错误写入订单状态历史表，维护外层事务一致性。
4. **订单自动化开通失败恢复机制与批量重试调度 (Automated Failure Recovery)**
   - 在订单核心服务层实现 `batchRetryFailedSetup()`，支持按最大重试次数（默认 3 次）与冷却时间（默认 5 分钟）智能批量筛选并安全重试失败订单。
   - 针对成功恢复的订单自动转换状态至 `active` 并写入追踪审计记录；重试耗尽时记录告警。
   - 提供管理端 API (`batch_retry_failed_setup`, `retry_setup`, `reset_setup_retries`) 并无缝集成至 `src/cron.php` 周期性任务，实现故障全自动恢复。
5. **多币种汇率转换边界处理与同币种免请求保护**
   - 针对汇率计算服务增加同币种短路保护：源货币与目标货币相同时直接返回 `1.0`，避免无谓的外部 HTTP 汇率请求，防止因上游汇率接口抖动导致流程中断。

### 快速开始

#### 环境要求
- **PHP**：8.3 或更高版本（需安装 `curl`, `intl`, `mbstring`, `pdo`, `zlib`, `openssl` 扩展）
- **Composer**：2.x
- **Node.js & npm**：Node.js 18+，npm 9+
- **数据库**：MySQL 8.0+ / MariaDB 10.5+ / PostgreSQL 14+ / SQLite 3

#### 安装与启动
1. **获取代码**
   ```bash
   git clone <repository_url> fossbilling-plus
   cd fossbilling-plus
   ```

2. **安装后端依赖**
   ```bash
   COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
   ```

3. **编译前端静态资源**
   ```bash
   npm install
   npm run build
   ```

4. **配置数据库**
   复制配置文件模板并配置数据库连接信息：
   ```bash
   cp src/config-sample.php src/config.php
   ```
   编辑 `src/config.php`（仅需填写数据库连接，请勿填写真实生产密钥至版本控制）：
   ```php
   'db' => [
       'type' => 'pdo_mysql', // 可选: pdo_mysql, pdo_pgsql, pdo_sqlite
       'host' => '127.0.0.1',
       'port' => '3306',
       'name' => 'fossbilling',
       'user' => 'your_db_user',
       'password' => 'your_db_password',
   ],
   ```

5. **启动 Web 服务**
   - **本地测试**：
     ```bash
     php -S 127.0.0.1:8080 -t src
     ```
     浏览器访问 `http://127.0.0.1:8080` 即可开始使用。
   - **生产部署**：
     将 Web 服务器（Nginx / Apache）的 Document Root 指向 `src/` 目录。

6. **定时任务配置 (Cron)**
   在系统的 crontab 中配置后台调度器（每 5 分钟执行一次）：
   ```bash
   */5 * * * * php /path/to/fossbilling-plus/src/cron.php >/dev/null 2>&1
   ```

---

## English Documentation

### Introduction
**fossbilling-plus** is an enhanced, open-source client management and automated billing platform based on FOSSBilling. Engineered for global cross-border e-commerce, cloud hosting, and domain service providers, this project expands international payment capabilities (adding Alipay Global), fixes IPN raw notification response handling, hardens order provisioning exception safety, and introduces automated order failure recovery with periodic background retry scheduling.

### Features & Key Improvements
Retaining full compatibility with upstream modular architecture and modern PHP standards, this release introduces 5 core improvements (mapped to `CHANGES.md`):

1. **Cross-border Gateway: Alipay Global Payment Adapter**
   - Integrated `Payment_Adapter_AlipayGlobal`, supporting multi-currency global settlements (USD, EUR, GBP, HKD, JPY, CNY, etc.).
   - Built-in sandbox/production switching, automated redirect form rendering, and sensitive credential masking (`getSecretFields`).
   - Tamper-proof signature verification (MD5 & HMAC-SHA256) with idempotent IPN processing and automated invoice clearance.
2. **Native IPN Plaintext Response Support**
   - Resolves interoperability issues where modern gateways (Alipay, Paddle, Adyen) mandate raw plaintext notifications (e.g., `success` or `OK`) rather than JSON wrappers.
   - Preserves custom gateway outputs via `Transaction::$output` and outputs raw text in `src/ipn.php`, gracefully falling back to standard JSON when no custom output is defined.
3. **Provisioning Exception Handling & State Protection**
   - Fixes an issue where unhandled exceptions during external server provisioning left orders stuck in `pending_setup` with no audit note.
   - Implements atomic exception handling in `createServiceFromOrder`: marks failing orders as `failed_setup` and records error details into `client_order_status` history.
4. **Automated Order Failure Recovery & Batch Retry**
   - Implemented `batchRetryFailedSetup()` with configurable maximum retry attempts (default: 3) and retry delay cooldowns (default: 5 minutes).
   - Automatically promotes successfully recovered orders to `active` while recording full status audit logs; alerts when retry thresholds are reached.
   - Exposed Admin APIs (`batch_retry_failed_setup`, `retry_setup`, `reset_setup_retries`) and plugged into `src/cron.php` for automated hands-off self-healing.
5. **Currency Rate Optimization & Same-Currency Protection**
   - Short-circuits currency conversion when source and target currencies match, immediately returning `1.0` and preventing unnecessary remote API calls.

### Quick Start

#### Requirements
- **PHP**: 8.3+ (extensions: `curl`, `intl`, `mbstring`, `pdo`, `zlib`, `openssl`)
- **Composer**: 2.x
- **Node.js & npm**: Node.js 18+, npm 9+
- **Database**: MySQL 8.0+, MariaDB 10.5+, PostgreSQL 14+, or SQLite 3

#### Installation & Launch
1. **Clone repository**
   ```bash
   git clone <repository_url> fossbilling-plus
   cd fossbilling-plus
   ```

2. **Install PHP dependencies**
   ```bash
   COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
   ```

3. **Build frontend assets**
   ```bash
   npm install
   npm run build
   ```

4. **Database Configuration**
   Copy sample configuration and set your database credentials:
   ```bash
   cp src/config-sample.php src/config.php
   ```
   Edit `src/config.php` (placeholders only; do not commit live credentials):
   ```php
   'db' => [
       'type' => 'pdo_mysql',
       'host' => '127.0.0.1',
       'port' => '3306',
       'name' => 'fossbilling',
       'user' => 'your_db_user',
       'password' => 'your_db_password',
   ],
   ```

5. **Start Web Server**
   - **Local test**:
     ```bash
     php -S 127.0.0.1:8080 -t src
     ```
     Access `http://127.0.0.1:8080` in your web browser.
   - **Production**:
     Configure your web server (Nginx or Apache) with `src/` as Document Root.

6. **Cron Task Configuration**
   Configure system cron to run background processing and automated order recovery every 5 minutes:
   ```bash
   */5 * * * * php /path/to/fossbilling-plus/src/cron.php >/dev/null 2>&1
   ```

---

## 来源与致谢 (Sources & Acknowledgments)

本项目基于 FOSSBilling（https://github.com/FOSSBilling/FOSSBilling）二次开发，原项目由 FOSSBilling 以 Apache-2.0 许可证开源。

---

## 许可证 (License)

本项目沿用 Apache-2.0 许可证，全文详见根目录 [LICENSE](LICENSE)。  
Licensed under the Apache License, Version 2.0. See [LICENSE](LICENSE) for the full text.
