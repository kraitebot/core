<p align="center">
  <img src="https://kraite.com/logo.png" alt="Kraite" width="200">
</p>

<h1 align="center">Kraite Core</h1>

<p align="center">
  The core trading engine for Kraite — an algorithmic cryptocurrency trading bot.
</p>

---

## About

Kraite Core is the foundational package powering the Kraite trading system. It provides:

- **Eloquent models** for exchanges, symbols, positions, orders, accounts, and market data
- **Trading algorithms** — direction detection, indicator analysis, account-scoped token selection, and ladder-based position management
- **Exchange integrations** — Binance, Bitget, KuCoin, and Bybit through typed `Exchange::for...` operation boundaries
- **Step Dispatcher orchestration** — async job pipelines with parent-child relationships
- **WebSocket streams** — real-time mark price and user data processing
- **Market regime detection** — account-scoped `Bscs::forAccount(...)` policies for opening, position caps, leverage, margin, and dashboard state
- **Artisan commands** — cooldown/warmup, dispatch daemon, kline fetching, symbol discovery
- **Shared onboarding infrastructure** — private-beta notifications, priority-worker exchange inspection, Resend/ZeptoMail config sync, and cross-app URL configuration

## Requirements

- PHP 8.4+
- Laravel 12
- MySQL 8+
- Redis

## Shared Configuration

Core loads the shared `.env.kraite` file when present and synchronizes transactional email credentials into Laravel's service config. Apps can use `config('kraite.website_url')` for public website links; admin hosts derive this from `APP_URL` by mapping `admin.kraite.*` back to `kraite.*`, with `KRAITE_WEBSITE_URL` available as an explicit override.

## Disclaimer

> **This software is provided for educational and informational purposes only.**
>
> Cryptocurrency trading involves substantial risk of financial loss. Algorithmic trading amplifies this risk through automated execution at speeds that prevent human intervention. Past performance does not guarantee future results.
>
> **By using, forking, or referencing this code, you acknowledge that:**
> - You may lose some or all of your invested capital
> - The authors accept no responsibility for financial losses
> - This software is not financial advice
> - You are solely responsible for your trading decisions
> - Bugs, network failures, exchange outages, or market conditions can cause unexpected losses
>
> **Do not trade with money you cannot afford to lose.**

## License

Proprietary. All rights reserved.
