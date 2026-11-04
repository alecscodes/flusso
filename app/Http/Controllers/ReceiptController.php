<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReceiptScanRequest;
use App\Http\Requests\ReceiptStoreRequest;
use App\Services\Receipts\ReceiptFileStore;
use App\Services\Receipts\ReceiptScanException;
use App\Services\Receipts\ReceiptScanner;
use App\Services\Receipts\ReceiptTransactionCreator;
use App\Support\FlashToast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ReceiptController extends Controller
{
    public function __construct(
        private ReceiptScanner $scanner,
        private ReceiptFileStore $files,
        private ReceiptTransactionCreator $creator,
    ) {}

    /**
     * Read a receipt photo into a proposal. Nothing is saved: the owner reviews it and confirms with `store`.
     */
    public function scan(ReceiptScanRequest $request): JsonResponse
    {
        $user = $request->user();

        /** @var UploadedFile $image */
        $image = $request->file('image');

        $token = $this->files->stash($user, $image);
        $path = $this->files->path($user, $token);

        try {
            $proposal = $this->scanner->scan(
                $user,
                $this->files->absolutePath((string) $path),
                $request->scanMode(),
                $request->amountMode(),
            );
        } catch (ReceiptScanException $exception) {
            $this->files->discard($user, $token);

            throw ValidationException::withMessages(['image' => $exception->getMessage()]);
        }

        return response()->json([...$proposal, 'token' => $token]);
    }

    public function store(ReceiptStoreRequest $request): RedirectResponse
    {
        $created = $this->creator->create($request->user(), $request->validated());

        FlashToast::success($created->count() === 1 ? 'Transaction created from the receipt.' : "{$created->count()} transactions created from the receipt.");

        return redirect()->back(fallback: route('transactions.index'));
    }
}
