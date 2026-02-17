# Core Symbol Data Dumps

This directory contains SQL dumps of the core symbol data tables used to seed fresh installations without running the 3-hour `refresh-core-data` discovery process.

## Files

- **symbols.sql** - All cryptocurrency symbols with CMC IDs and metadata
- **exchange_symbols.sql** - All trading pairs on Binance and Bybit (with active/inactive flags)
- **base_asset_mappers.sql** - Exchange-specific token name mappings (e.g., VELODROME → VELO)

## Usage

These dumps are automatically used by the `CoreSymbolDataSeeder` when you run:

```bash
php artisan migrate
```

The migration `2025_11_16_165043_seed_core_symbol_data.php` will call the seeder automatically.

## Regenerating Dumps

To regenerate these dumps with fresh data:

```bash
mysqldump -u root -ppassword martingalian symbols --no-create-info --skip-extended-insert --complete-insert --compact --skip-add-locks --skip-comments > packages/kraitebot/core/database/dumps/symbols.sql

mysqldump -u root -ppassword martingalian exchange_symbols --no-create-info --skip-extended-insert --complete-insert --compact --skip-add-locks --skip-comments > packages/kraitebot/core/database/dumps/exchange_symbols.sql

mysqldump -u root -ppassword martingalian base_asset_mappers --no-create-info --skip-extended-insert --complete-insert --compact --skip-add-locks --skip-comments > packages/kraitebot/core/database/dumps/base_asset_mappers.sql
```

**Important**: The `--complete-insert` flag includes column names in the INSERT statements, making dumps resilient to schema changes.

## Data Statistics

- **615 symbols** (578 active, 37 inactive)
- **1,185 exchange_symbols** (951 active, 234 inactive)
- **42 base_asset_mappers** (17 Binance, 25 Bybit)

Last updated: 2025-11-16
