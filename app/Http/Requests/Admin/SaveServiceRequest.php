<?php

namespace App\Http\Requests\Admin;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && ! $this->filled('slug') && ! $this->route('service')) {
            $this->merge([
                'slug' => str($this->input('name'))
                    ->lower()
                    ->replaceMatches('/[^a-z0-9]+/', '_')
                    ->trim('_')
                    ->limit(80, '')
                    ->toString(),
            ]);
        }
    }

    public function rules(): array
    {
        /** @var Service|null $service */
        $service = $this->route('service');
        $creating = $service === null;

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'max:80',
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
                Rule::unique('services', 'slug')->ignore($service?->id),
            ],
            'description' => ['required', 'string', 'max:255'],
            'icon' => ['required', 'string', Rule::in(Service::ICONS)],
            'is_active' => [$creating ? 'sometimes' : 'required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, numbers, and underscores only.',
            'slug.unique' => 'That service key is already in use.',
            'icon.in' => 'Choose a valid icon.',
        ];
    }
}
