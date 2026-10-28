<?php

use App\Models\Company;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;

return [
    'tenant_model' => Company::class,
    'id_generator' => null,
    'bootstrappers' => [DatabaseTenancyBootstrapper::class],
    'database' => [
        'central_connection' => env('DB_CONNECTION', 'sqlite'),
        'template_tenant_connection' => null,
        'prefix' => 'client_',
        'suffix' => '',
        'managers' => [
            'sqlite' => SQLiteDatabaseManager::class,
            'mysql' => MySQLDatabaseManager::class,
            'mariadb' => MySQLDatabaseManager::class,
        ],
    ],
    'features' => [],
    'routes' => false,
    'migration_parameters' => ['--force' => true, '--path' => [database_path('migrations/clients')], '--realpath' => true],
];
