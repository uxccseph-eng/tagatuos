<?php

namespace App\Http\Requests;

use App\Services\WorkspacePresence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HeartbeatPresenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasApprovedAccess() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tab_id' => ['required', 'uuid'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'section' => ['required', Rule::in(array_keys(WorkspacePresence::SECTIONS))],
            'idle' => ['required', 'boolean'],
            'visible' => ['required', 'boolean'],
        ];
    }
}
