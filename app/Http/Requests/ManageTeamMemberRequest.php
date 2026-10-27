<?php

namespace App\Http\Requests;

use App\TeamPermissions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManageTeamMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->companies()
            ->where('companies.id', $this->route('companyId'))->wherePivot('role', 'owner')->wherePivot('is_suspended', false)->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->routeIs('team.remove')) {
            return ['confirmation' => ['accepted']];
        }
        $rules = [
            'role' => ['required', Rule::in(TeamPermissions::MEMBER_ROLES)],
            'tabs' => ['required', 'array', 'list', 'min:1', 'max:8'],
            'tabs.*' => ['required', 'string', 'distinct', Rule::in(array_keys(TeamPermissions::TABS))],
            'is_suspended' => ['sometimes', 'boolean'],
        ];
        if ($this->routeIs('team.store')) {
            $rules += [
                'mode' => ['required', Rule::in(['new', 'existing'])],
                'name' => ['required_if:mode,new', 'nullable', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:254'],
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        foreach (['email', 'name'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => $key === 'email' ? mb_strtolower(trim($this->input($key))) : trim($this->input($key))]);
            }
        }
    }
}
