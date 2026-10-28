<?php

namespace App\Models;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query): void {
            $context = app(TenantContext::class);
            if ($context->centralQueries) {
                return;
            }
            $model = $query->getModel();
            if ($context->company !== null) {
                $query->where($model->qualifyColumn('company_id'), $context->company->id);
            } elseif ($context->previewWorkspace !== null && in_array('workspace_key', $model->getFillable(), true)) {
                $query->where($model->qualifyColumn('workspace_key'), $context->previewWorkspace)->whereNull($model->qualifyColumn('company_id'));
            } else {
                $query->whereRaw('1 = 0');
            }
        });
        static::creating(function (self $model): void {
            $context = app(TenantContext::class);
            if ($context->company !== null) {
                if ($model->company_id !== null && (int) $model->company_id !== $context->company->id) {
                    throw new LogicException('A record cannot be created for a different company.');
                }
                $model->company_id = $context->company->id;
                if (in_array('workspace_key', $model->getFillable(), true) && $model->workspace_key !== $context->company->workspaceKey()) {
                    throw new LogicException('The record workspace does not belong to the selected company.');
                }
            } elseif ($context->centralQueries && $model->company_id === null && in_array('workspace_key', $model->getFillable(), true)) {
                $model->company_id = Company::query()->where('workspace_key', $model->workspace_key)->value('id');
            } elseif (! $context->centralQueries && ! ($context->previewWorkspace !== null && $model->workspace_key === $context->previewWorkspace && $model->company_id === null)) {
                throw new LogicException('An authorized company context is required to create records.');
            }
        });
        static::updating(function (self $model): void {
            if ($model->isDirty('company_id') || $model->isDirty('workspace_key')) {
                throw new LogicException('Record ownership cannot be changed.');
            }
            $model->assertCompanyOwnership();
        });
        static::deleting(fn (self $model) => $model->assertCompanyOwnership());
    }

    protected function assertCompanyOwnership(): void
    {
        $context = app(TenantContext::class);
        if (! $context->centralQueries && ($context->company ? (int) $this->company_id !== $context->company->id : ! ($context->previewWorkspace !== null && $this->company_id === null && $this->workspace_key === $context->previewWorkspace))) {
            throw new LogicException('The record does not belong to the selected company.');
        }
    }

    /** @return array{company_id: ?int} */
    public static function companyAttributes(string $workspace): array
    {
        $context = app(TenantContext::class);
        if ($context->company !== null) {
            if ($workspace !== $context->company->workspaceKey()) {
                throw new LogicException('The workspace does not belong to the selected company.');
            }

            return ['company_id' => $context->company->id];
        }
        if (! $context->centralQueries && $context->previewWorkspace !== $workspace) {
            throw new LogicException('An authorized workspace is required.');
        }

        return ['company_id' => Company::query()->where('workspace_key', $workspace)->value('id')];
    }
}
