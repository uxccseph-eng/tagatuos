<?php

use App\Models\Company;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;

return [
    'enabled' => env('CLIENT_DATABASES_ENABLED', true),
    'tenant_model' => Company::class,
    'id_generator' => null,
    'database' => [
        'central_connection' => env('DB_CONNECTION', 'sqlite'),
        'template_tenant_connection' => 'client_template',
        'prefix' => 'client_',
        'suffix' => '',
        'managers' => [
            'sqlite' => SQLiteDatabaseManager::class,
            'mysql' => MySQLDatabaseManager::class,
            'mariadb' => MySQLDatabaseManager::class,
        ],
    ],
    'provisioner_connection' => 'client_provisioner',
    'runtime_user_host' => env('CLIENT_DB_USER_HOST', 'localhost'),
    'bootstrappers' => [DatabaseTenancyBootstrapper::class],
    'features' => [],
    'routes' => false,
];
