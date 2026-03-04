<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'date' => $this->date,
            'type' => $this->type->value,
            'account_id' => $this->account_id,
            'category_id' => $this->category_id,
            'from_account_id' => $this->from_account_id,
            'to_account_id' => $this->to_account_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Relationships
            'account' => $this->when($this->account, fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'currency' => $this->account->currency,
            ]),
            'category' => $this->when($this->category, fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'color' => $this->category->color,
            ]),
            'from_account' => $this->when($this->from_account, fn () => [
                'id' => $this->from_account->id,
                'name' => $this->from_account->name,
                'currency' => $this->from_account->currency,
            ]),
            'to_account' => $this->when($this->to_account, fn () => [
                'id' => $this->to_account->id,
                'name' => $this->to_account->name,
                'currency' => $this->to_account->currency,
            ]),
        ];
    }
}
