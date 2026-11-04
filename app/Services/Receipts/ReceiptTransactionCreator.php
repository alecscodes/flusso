<?php

namespace App\Services\Receipts;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FileUploadService;
use App\Services\TransactionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Books what the owner confirmed from a scanned receipt: one expense per entry,
 * through the TransactionService (so balances stay right), each with its own copy
 * of the receipt image attached. All or nothing.
 */
class ReceiptTransactionCreator
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly FileUploadService $files,
        private readonly ReceiptFileStore $store,
    ) {}

    /**
     * @param  array{account_id: int, date: string, token: string, transactions: list<array{amount: numeric, category_id?: int|null, description?: string|null}>}  $data
     * @return Collection<int, Transaction>
     */
    public function create(User $user, array $data): Collection
    {
        $source = $this->store->path($user, $data['token']);

        if ($source === null) {
            throw ValidationException::withMessages(['token' => 'The receipt is no longer available. Scan it again.']);
        }

        $copies = [];

        try {
            $created = DB::transaction(function () use ($user, $data, $source, &$copies) {
                return collect($data['transactions'])->map(function (array $entry) use ($user, $data, $source, &$copies) {
                    $transaction = $this->transactions->createTransaction($user, [
                        'account_id' => $data['account_id'],
                        'type' => TransactionType::Expense,
                        'amount' => $entry['amount'],
                        'date' => $data['date'],
                        'description' => $entry['description'] ?? null,
                        'category_id' => $entry['category_id'] ?? null,
                    ]);

                    $copies[] = $this->files->attachStoredCopy($transaction, $source, 'receipt.'.pathinfo($source, PATHINFO_EXTENSION))->path;

                    return $transaction->load('files');
                });
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($copies);

            throw $exception;
        }

        $this->store->discard($user, $data['token']);

        return $created;
    }
}
