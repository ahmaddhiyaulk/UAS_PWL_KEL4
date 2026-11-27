<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Transaction extends Model
{
    protected $fillable = [
        'invoice_no', 'user_id', 'cashier_id', 'channel', 'customer_name', 'total',
        'paid_amount', 'change_amount', 'payment_method', 'payment_detail',
        'payment_status', 'order_status', 'pickup_method', 'note', 'snap_token', 'paid_at',
        'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public const ORDER_STATUSES = [
        'menunggu_pembayaran' => 'Menunggu pembayaran',
        'diproses'            => 'Diproses',
        'siap_diambil'        => 'Siap diambil',
        'selesai'             => 'Selesai',
        'dibatalkan'          => 'Dibatalkan',
    ];

    public const PAYMENT_BADGES = [
        'pending' => 'warning', 'paid' => 'success', 'failed' => 'danger', 'expired' => 'secondary',
    ];

    public function items()   { return $this->hasMany(TransactionItem::class); }
    public function buyer()   { return $this->belongsTo(User::class, 'user_id'); }
    public function cashier() { return $this->belongsTo(User::class, 'cashier_id'); }

    public function scopePaid($q) { return $q->where('payment_status', 'paid'); }

    public static function invoiceNo(): string
    {
        return 'INV-'.now()->format('ymdHis').random_int(100, 999);
    }

    public function getBadgeAttribute(): string
    {
        return self::PAYMENT_BADGES[$this->payment_status] ?? 'secondary';
    }

    public function markPaid(?string $detail = null): void
    {
        if ($this->payment_status === 'paid') {
            return;
        }
        $this->update([
            'payment_status' => 'paid',
            'payment_detail' => $detail ?? $this->payment_detail,
            'paid_amount'    => $this->paid_amount ?: $this->total,
            'paid_at'        => now(),
            'order_status'   => $this->channel === 'online' ? 'diproses' : 'selesai',
        ]);
    }

    /** Pembayaran gagal/kedaluwarsa: stok dikembalikan. */
    public function markFailed(string $status = 'failed'): void
    {
        if ($this->payment_status !== 'pending') {
            return;
        }
        DB::transaction(function () use ($status) {
            foreach ($this->items as $item) {
                if ($item->book_id) {
                    Book::where('id', $item->book_id)->increment('stock', $item->qty);
                }
            }
            $this->update(['payment_status' => $status, 'order_status' => 'dibatalkan']);
        });
    }

    /** Data grafik penjualan (hanya transaksi lunas). */
    public static function salesSeries(Carbon $from, Carbon $to, string $mode = 'daily'): array
    {
        $rows = static::paid()->whereBetween('paid_at', [$from, $to])->get(['total', 'paid_at']);
        $fmt = $mode === 'monthly' ? 'Y-m' : 'Y-m-d';
        $grouped = $rows->groupBy(fn ($r) => $r->paid_at->format($fmt))->map->sum('total');

        $labels = [];
        $data = [];
        $period = $mode === 'monthly'
            ? CarbonPeriod::create($from->copy()->startOfMonth(), '1 month', $to)
            : CarbonPeriod::create($from->copy()->startOfDay(), '1 day', $to);

        foreach ($period as $d) {
            $key = $d->format($fmt);
            $labels[] = $mode === 'monthly' ? $d->translatedFormat('M Y') : $d->format('d M');
            $data[] = (int) ($grouped[$key] ?? 0);
        }

        return ['labels' => $labels, 'data' => $data];
    }
}
