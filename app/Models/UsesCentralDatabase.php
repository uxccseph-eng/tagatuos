<?php

namespace App\Models;

trait UsesCentralDatabase
{
    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('tenancy.database.central_connection');
    }
}
