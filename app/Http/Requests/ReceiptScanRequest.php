<?php

namespace App\Http\Requests;

use App\Enums\AiFeature;
use App\Enums\ReceiptAmountMode;
use App\Enums\ReceiptScanMode;
use App\Models\Transaction;
use App\Services\Ai\AiManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A receipt photo to read. Only images whose content is JPEG, PNG or WebP are taken
 * (HEIC is not supported: phones can share the photo as JPEG).
 */
class ReceiptScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Transaction::class)
            && app(AiManager::class)->isEnabled(AiFeature::Receipts);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
            'mode' => ['required', Rule::enum(ReceiptScanMode::class)],
            'amounts' => ['nullable', Rule::enum(ReceiptAmountMode::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'image.mimetypes' => 'Use a JPEG, PNG or WebP image.',
            'image.max' => 'The image must not exceed 10MB.',
        ];
    }

    public function scanMode(): ReceiptScanMode
    {
        return ReceiptScanMode::from($this->string('mode')->toString());
    }

    public function amountMode(): ReceiptAmountMode
    {
        return ReceiptAmountMode::tryFrom($this->string('amounts')->toString()) ?? ReceiptAmountMode::Exact;
    }
}
