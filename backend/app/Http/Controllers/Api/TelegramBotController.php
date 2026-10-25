<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Models\WasteRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class TelegramBotController
{
    public function webhook(Request $request)
    {
        $token = config('services.telegram.bot_token');
        $secret = config('services.telegram.webhook_secret');

        if (! $secret || ! $token) {
            return response()->json(['message' => 'Telegram webhook is not fully configured.'], 503);
        }

        if (! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $update = $request->all();
        if (! isset($update['update_id']) || ! is_numeric($update['update_id'])) {
            return response()->json(['message' => 'Invalid Telegram update.'], 422);
        }

        $result = DB::transaction(function () use ($update) {
            $now = now();
            $inserted = DB::table('telegram_updates')->insertOrIgnore([
                'update_id' => $update['update_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (! $inserted) {
                return ['duplicate' => true];
            }

            $message = $update['message'] ?? $update['edited_message'] ?? null;
            if (! $message) {
                return ['duplicate' => false];
            }

            $chatId = $message['chat']['id'] ?? null;
            $text = trim((string) ($message['text'] ?? ''));

            if (! $chatId || $text === '' || ($message['chat']['type'] ?? null) !== 'private') {
                return ['duplicate' => false];
            }

            return [
                'duplicate' => false,
                'chat_id' => $chatId,
                'reply' => $this->handleMessage($chatId, $text),
            ];
        });

        if (! empty($result['duplicate'])) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        if (! empty($result['chat_id']) && ! empty($result['reply'])) {
            $this->sendMessage($token, $result['chat_id'], $result['reply']);
        }

        return response()->json(['ok' => true]);
    }

    private function handleMessage(int|string $chatId, string $text): string
    {
        if (preg_match('/^\/start(?:@\w+)?\s+link_([A-Za-z0-9]{48})$/', $text, $matches)) {
            return "To finish connecting your KotTraLuy account, send this command:\n/link {$matches[1]}";
        }

        $user = User::where('telegram_chat_id', $chatId)
            ->where('status', 'active')
            ->where('must_change_password', false)
            ->lockForUpdate()
            ->first();

        if (preg_match('/^\/link(?:@\w+)?(?:\s+(.+))?$/i', $text, $matches)) {
            return $this->linkTelegramAccount($chatId, trim((string) ($matches[1] ?? '')), $user);
        }

        if (! $user) {
            return 'KotTraLuy Telegram is not linked yet. Log in to KotTraLuy, choose Connect Telegram, then send /link <token> to this bot.';
        }

        $trimmed = Str::lower($text);
        $reply = null;

        if (in_array($trimmed, ['help', '/help', '/start', 'menu'], true)) {
            $reply = "KotTraLuy commands:\n- sale <product> <qty> <price>\n- purchase <product> <qty> <cost>\n- waste <product> <qty> <reason>\n- start shift\n- close shift\n- today\n- stock\nExample: sale Milk 4 3.5";
        } elseif (preg_match('/^sale\s+(.+?)\s+([1-9]\d*)\s+([0-9]+(?:\.[0-9]+)?)$/i', $text, $matches)) {
            $productName = trim($matches[1]);
            $quantity = (int) $matches[2];
            $unitPrice = (float) $matches[3];

            $product = Product::where('business_id', $user->business_id)
                ->where(function ($query) use ($productName) {
                    $query->whereRaw('LOWER(name) = ?', [strtolower($productName)])
                        ->orWhereRaw('LOWER(sku) = ?', [strtolower($productName)]);
                })
                ->lockForUpdate()
                ->first();

            if (! $product) {
                $reply = "Product '{$productName}' was not found in your business stock.";
            } elseif ($product->stock_quantity < $quantity) {
                $reply = "Not enough stock for {$product->name}. Available: {$product->stock_quantity}.";
            } else {
                DB::transaction(function () use ($user, $product, $quantity, $unitPrice) {
                    $openShiftId = Shift::where('business_id', $user->business_id)
                        ->where('user_id', $user->id)
                        ->where('status', 'open')
                        ->value('id');

                    $sale = Sale::create([
                        'business_id' => $user->business_id,
                        'shift_id' => $openShiftId,
                        'user_id' => $user->id,
                        'sale_no' => 'TG-SALE-'.now()->timestamp,
                        'sold_at' => now(),
                        'total_amount' => 0,
                        'notes' => 'Telegram bot sale',
                    ]);

                    $lineTotal = $quantity * $unitPrice;
                    $sale->items()->create([
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'unit_cost_snapshot' => $product->default_cost ?? 0,
                        'line_total' => $lineTotal,
                    ]);

                    $product->stock_quantity = (int) $product->stock_quantity - $quantity;
                    $product->save();

                    $sale->total_amount = $lineTotal;
                    $sale->save();
                });

                $reply = "Sale recorded successfully for {$quantity} {$product->name}.";
            }
        } elseif (preg_match('/^purchase\s+(.+?)\s+([1-9]\d*)\s+([0-9]+(?:\.[0-9]+)?)$/i', $text, $matches)) {
            $productName = trim($matches[1]);
            $quantity = (int) $matches[2];
            $unitCost = (float) $matches[3];

            $product = Product::where('business_id', $user->business_id)
                ->where(function ($query) use ($productName) {
                    $query->whereRaw('LOWER(name) = ?', [strtolower($productName)])
                        ->orWhereRaw('LOWER(sku) = ?', [strtolower($productName)]);
                })
                ->lockForUpdate()
                ->first();

            if (! $product) {
                $reply = "Product '{$productName}' was not found in your business stock.";
            } else {
                DB::transaction(function () use ($user, $product, $quantity, $unitCost) {
                    $openShiftId = Shift::where('business_id', $user->business_id)
                        ->where('user_id', $user->id)
                        ->where('status', 'open')
                        ->value('id');

                    $purchase = Purchase::create([
                        'business_id' => $user->business_id,
                        'shift_id' => $openShiftId,
                        'user_id' => $user->id,
                        'supplier_name' => 'Telegram Bot',
                        'purchase_date' => now()->toDateString(),
                        'reference_no' => 'TG-PUR-'.now()->timestamp,
                        'notes' => 'Telegram bot purchase',
                        'total_amount' => 0,
                    ]);

                    $lineTotal = $quantity * $unitCost;
                    $purchase->items()->create([
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'line_total' => $lineTotal,
                    ]);

                    $product->stock_quantity = (int) $product->stock_quantity + $quantity;
                    $product->save();

                    $purchase->total_amount = $lineTotal;
                    $purchase->save();
                });

                $reply = "Purchase recorded successfully for {$quantity} {$product->name}.";
            }
        } elseif (preg_match('/^waste\s+(.+?)\s+([1-9]\d*)(?:\s+(.*))?$/i', $text, $matches)) {
            $productName = trim($matches[1]);
            $quantity = (int) $matches[2];
            $reason = trim($matches[3] ?? 'telegram');

            $product = Product::where('business_id', $user->business_id)
                ->where(function ($query) use ($productName) {
                    $query->whereRaw('LOWER(name) = ?', [strtolower($productName)])
                        ->orWhereRaw('LOWER(sku) = ?', [strtolower($productName)]);
                })
                ->lockForUpdate()
                ->first();

            if (! $product) {
                $reply = "Product '{$productName}' was not found in your business stock.";
            } elseif ($product->stock_quantity < $quantity) {
                $reply = "Waste quantity exceeds available stock for {$product->name}.";
            } else {
                DB::transaction(function () use ($user, $product, $quantity, $reason) {
                    $openShiftId = Shift::where('business_id', $user->business_id)
                        ->where('user_id', $user->id)
                        ->where('status', 'open')
                        ->value('id');

                    WasteRecord::create([
                        'business_id' => $user->business_id,
                        'shift_id' => $openShiftId,
                        'user_id' => $user->id,
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_cost_snapshot' => $product->default_cost ?? 0,
                        'reason' => $reason,
                        'notes' => 'Telegram bot waste',
                        'recorded_at' => now(),
                    ]);

                    $product->stock_quantity = (int) $product->stock_quantity - $quantity;
                    $product->save();
                });

                $reply = "Waste recorded successfully for {$quantity} {$product->name}.";
            }
        } elseif (strtolower($text) === 'start shift') {
            $existing = Shift::where('business_id', $user->business_id)
                ->where('user_id', $user->id)
                ->where('status', 'open')
                ->first();

            if ($existing) {
                $reply = 'You already have an open shift.';
            } else {
                $shift = Shift::create([
                    'business_id' => $user->business_id,
                    'user_id' => $user->id,
                    'shift_type' => $user->assigned_shift ?? 'A',
                    'started_at' => now(),
                    'opening_cash' => 0,
                    'notes' => 'Started from Telegram',
                    'status' => 'open',
                ]);

                $reply = "Shift started successfully. Shift #{$shift->id} is now open.";
            }
        } elseif (strtolower($text) === 'close shift' || strtolower($text) === 'end shift') {
            $shift = Shift::where('business_id', $user->business_id)
                ->where('user_id', $user->id)
                ->where('status', 'open')
                ->first();

            if (! $shift) {
                $reply = 'There is no open shift for your account.';
            } else {
                $shift->update([
                    'closing_cash' => $shift->opening_cash,
                    'ended_at' => now(),
                    'status' => 'closed',
                    'notes' => ($shift->notes ? $shift->notes.' | Closed from Telegram' : 'Closed from Telegram'),
                ]);

                $reply = 'Shift closed successfully.';
            }
        } elseif (strtolower($text) === 'today') {
            $salesTotal = Sale::where('business_id', $user->business_id)
                ->whereDate('sold_at', now()->toDateString())
                ->sum('total_amount');

            $reply = 'Today sales total: $'.number_format((float) $salesTotal, 2);
        } elseif (in_array($trimmed, ['stock', '/stock'], true)) {
            $products = Product::where('business_id', $user->business_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['name', 'stock_quantity', 'unit', 'minimum_stock']);

            $reply = $products->isEmpty()
                ? 'No active products found.'
                : "Current stock:\n".$products->map(function (Product $product) {
                    $lowStock = $product->stock_quantity <= $product->minimum_stock ? ' (low stock)' : '';

                    return "{$product->name}: {$product->stock_quantity} {$product->unit}{$lowStock}";
                })->implode("\n");
        } else {
            $reply = 'Unknown command. Use /help to see valid KotTraLuy Telegram commands.';
        }

        return $reply;
    }

    private function linkTelegramAccount(int|string $chatId, string $code, ?User $linkedUser): string
    {
        $rateKey = 'telegram-link:'.hash('sha256', (string) $chatId);

        if (RateLimiter::tooManyAttempts($rateKey, 10)) {
            return 'Too many linking attempts. Please wait a minute and try again.';
        }

        RateLimiter::hit($rateKey, 60);

        if (! preg_match('/^[A-Za-z0-9]{48}$/', $code)) {
            return 'That linking token is invalid or expired. Generate a new token in KotTraLuy and try again.';
        }

        $link = DB::table('telegram_link_codes')
            ->where('token_hash', hash('sha256', $code))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();

        if (! $link) {
            return 'That linking token is invalid or expired. Generate a new token in KotTraLuy and try again.';
        }

        if ($linkedUser && (int) $linkedUser->id !== (int) $link->user_id) {
            return 'This Telegram account is already connected to another KotTraLuy account.';
        }

        $linkOwner = User::whereKey($link->user_id)
            ->where('status', 'active')
            ->where('must_change_password', false)
            ->lockForUpdate()
            ->first();

        if (! $linkOwner) {
            return 'That linking token is invalid or expired. Generate a new token in KotTraLuy and try again.';
        }

        if ($linkOwner->telegram_chat_id !== null && (string) $linkOwner->telegram_chat_id !== (string) $chatId) {
            return 'This KotTraLuy account is already connected to a different Telegram account.';
        }

        try {
            $linkOwner->forceFill(['telegram_chat_id' => $chatId])->save();
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

            if (! in_array($sqlState, ['19', '23000', '23505'], true)) {
                throw $exception;
            }

            return 'This Telegram account is already connected to another KotTraLuy account.';
        }

        DB::table('telegram_link_codes')
            ->where('user_id', $linkOwner->id)
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

        RateLimiter::clear($rateKey);

        return 'Your Telegram account is now connected to KotTraLuy. Send /help to see available commands.';
    }

    private function sendMessage(string $token, int|string $chatId, string $reply): void
    {
        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $reply,
            ])->throw();
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Telegram sendMessage request failed.', [
                'chat_id' => $chatId,
                'status' => $exception instanceof RequestException
                    ? $exception->response->status()
                    : null,
            ]);
        }
    }
}
