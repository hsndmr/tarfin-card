<?php

namespace App\Http\Requests;

use App\Models\TarfinCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TarfinCardCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', TarfinCard::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'type' => [
                'required', 'string', Rule::in(['Visa', 'MasterCard', 'American Express', 'Discover Card', 'Visa Retired', 'JCB']),
            ],
        ];
    }
}
