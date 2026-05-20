# cPanel Deployment Guide

This project should be deployed as three separate parts:

1. Code through GitHub.
2. MySQL database through export/import.
3. Uploaded media through `storage/app/public`.

Do not put `.env`, `vendor`, `node_modules`, raw shop photos, temporary scrape files, or uploaded product media in GitHub.

## Server Target

Subdomain:

```text
vhc.habeshaequb.com
```

Recommended cPanel document root:

```text
/home/habeshjv/vhc/public
```

Laravel project root:

```text
/home/habeshjv/vhc
```

## Server Requirements

- PHP 8.2+
- MySQL/MariaDB
- Composer
- PHP extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `curl`, `openssl`, `dom`, `xml`, `xmlreader`, `xmlwriter`, `tokenizer`, `ctype`, `json`, `session`
- Upload limit: 20MB+ minimum, 64MB is better
- Memory limit: 256MB+ minimum
- HTTPS enabled

Current cPanel note:

```bash
php -v
php ~/bin/composer --version
```

Both should show PHP 8.2. If not, run Laravel/Composer commands through:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php
```

## First Deploy

After pushing the private GitHub repo:

```bash
cd ~
git clone https://github.com/YOUR_GITHUB_USERNAME/lhc-data.git vhc
cd ~/vhc
php ~/bin/composer install --no-dev --optimize-autoloader
cp .env.cpanel.example .env
php artisan key:generate
```

Edit `.env` on the server and set:

```text
APP_URL=https://vhc.habeshaequb.com
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
AI keys if needed
```

Then run:

```bash
php artisan storage:link
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Local Database Export

From Windows local machine:

```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root lhc_catalogue_staging > C:\xampp\htdocs\LHC_Data\lhc_catalogue_staging.sql
```

Import that SQL file into the cPanel MySQL database using phpMyAdmin or terminal.

If terminal import is available:

```bash
mysql -u CPANEL_DATABASE_USER -p CPANEL_DATABASE_NAME < lhc_catalogue_staging.sql
```

## Uploaded Media Migration

The important media folder is:

```text
storage/app/public
```

Zip this folder locally, upload it to the server, and extract it into:

```text
/home/habeshjv/vhc/storage/app/public
```

Then run:

```bash
cd ~/vhc
php artisan storage:link
```

## Updating Later

Local:

```bash
npm run build
git add .
git commit -m "Describe change"
git push
```

Server:

```bash
cd ~/vhc
git pull
php ~/bin/composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Security Before Live Use

Do not expose this app publicly without access control. This app contains product management, intake, media upload, invoices, source data, and AI tools. Add authentication or restrict the subdomain before real public deployment.
