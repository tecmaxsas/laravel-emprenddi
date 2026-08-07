<?php

namespace App\Models\OrderTaking;

use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    use BelongsToCompany;

    protected $table = 'order_taking_email_log';

    protected $fillable = [
        'company_id', 'order_id',
        'sent_at', 'to_address', 'cc_addresses',
        'subject', 'sent_by_user_id', 'status', 'error_message',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
