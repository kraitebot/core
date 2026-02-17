# Database Backups

This directory contains full MySQL database dumps for disaster recovery and data restoration purposes.

## Current Backups

- `martingalian_backup_20251123_114815.sql` - Full database backup created on 2025-11-23
  - Size: 141MB
  - Tables: All Kraite tables including production data
  - Records:
    - symbols: 618
    - base_asset_mappers: 30
    - exchange_symbols: 1,182
    - candles: 74,843
    - Plus all other application tables

## Creating a New Backup

To create a new backup of the main database:

```bash
mysqldump -u root -p martingalian > packages/kraitebot/core/database/backups/martingalian_backup_$(date +%Y%m%d_%H%M%S).sql
```

## Restoring from Backup

### Full Database Restore

To restore the entire database from a backup:

```bash
mysql -u root -p martingalian < packages/kraitebot/core/database/backups/martingalian_backup_TIMESTAMP.sql
```

### Restore to Test Database

To restore to the test database (martingalian_new):

```bash
mysql -u root -p martingalian_new < packages/kraitebot/core/database/backups/martingalian_backup_TIMESTAMP.sql
```

## Important Notes

- **DO NOT commit these backups to Git** - They are excluded via .gitignore
- Backups contain sensitive production data and should be stored securely
- Always test restoration on a test database first before applying to production
- Keep multiple backup versions for disaster recovery
- Consider compressing large backups: `gzip martingalian_backup_TIMESTAMP.sql`

## Data Seeders

As of 2025-11-23, the core data tables (symbols, base_asset_mappers, exchange_symbols, candles) are automatically seeded via migration `2025_11_23_114703_seed_core_data.php` which calls:

- `SymbolsSeeder` (618 records)
- `BaseAssetMappersSeeder` (30 records)
- `ExchangeSymbolsSeeder` (1,182 records)
- `CandlesSeeder` (74,843 records)

These seeders eliminate the need to rebuild data from external APIs (saves ~5 hours of processing time).
