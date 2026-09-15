<?php

namespace App\Http\Requests;

use App\Services\YandexMaps\Exceptions\InvalidOrganizationLink;
use App\Services\YandexMaps\OrganizationLink;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
{
    /**
     * Приводим ссылку к хранимому виду до проверок, а не после: иначе `max` считает длину
     * без дописанной схемы, и ссылка на границе лимита проходит валидацию, но не влезает
     * в колонку. Дальше по коду ссылка уже нормализованная, второй раз её разбирать не нужно.
     */
    protected function prepareForValidation(): void
    {
        $url = $this->input('url');

        if (is_string($url)) {
            $this->merge(['url' => OrganizationLink::normalize($url)]);
        }
    }

    public function rules(): array
    {
        return [
            'url' => [
                'bail',
                'required',
                'string',
                'max:2048',
                // Формат проверяем без сети. Короткую ссылку разворачивает контроллер.
                function (string $attribute, mixed $value, Closure $fail) {
                    try {
                        OrganizationLink::parse((string) $value);
                    } catch (InvalidOrganizationLink $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
        ];
    }

    public function attributes(): array
    {
        return ['url' => 'ссылка'];
    }
}
