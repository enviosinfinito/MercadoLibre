<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

#[Fillable([
    'connection_id',
    'payload',
])]
class EncryptedCredential extends Model
{
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function setPlainPayload(array $data): void
    {
        $this->payload = Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function plainPayload(): array
    {
        return json_decode(Crypt::decryptString($this->payload), true, 512, JSON_THROW_ON_ERROR);
    }
}