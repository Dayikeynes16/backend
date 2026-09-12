<?php

namespace App\Http\Requests\Api;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del latido. La misma para la Scale API y para el hub: la forma
 * del payload es una sola, cambia quién lo firma.
 */
class DeviceHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'kind' => ['required', Rule::in(Device::KINDS)],
            'name' => ['sometimes', 'string', 'max:100'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
            'os' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'battery' => ['sometimes', 'nullable', 'array'],
            'battery.level' => ['required_with:battery', 'integer', 'between:0,100'],
            'battery.charging' => ['required_with:battery', 'boolean'],
            'connection' => ['sometimes', 'nullable', Rule::in(['cloud', 'hub'])],
            'local_ip' => ['sometimes', 'nullable', 'ip'],
        ];
    }
}
