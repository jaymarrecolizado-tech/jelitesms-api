<?php

declare(strict_types=1);

use Jelite\Config;

section('DB_NAME override');

// Real env vars win over .env in Config, so putenv drives this test.
putenv('DB_NAME=');
Config::load(dirname(__DIR__) . '/.env');
same('jelite_sms_api_test', Config::dbName(), 'derived name when DB_NAME empty');

putenv('APP_ENV=prod');
putenv('DB_NAME=');
Config::load(dirname(__DIR__) . '/.env');
same('jelite_sms_api_prod', Config::dbName(), 'derived name uses APP_ENV');

putenv('DB_NAME=u123456_jelite_sms_api_prod');
Config::load(dirname(__DIR__) . '/.env');
same('u123456_jelite_sms_api_prod', Config::dbName(), 'explicit DB_NAME wins (Hostinger prefix)');

// Restore for later test sections.
putenv('APP_ENV=test');
putenv('DB_NAME=');
Config::load(dirname(__DIR__) . '/.env');
same('jelite_sms_api_test', Config::dbName(), 'restored after override checks');
