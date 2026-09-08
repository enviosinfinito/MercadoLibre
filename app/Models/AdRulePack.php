<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'preset_key',
    'name',
    'enabled',
    'meta',
])]
class AdRulePack extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(AdRule::class, 'ad_rule_pack_id');
    }
}
