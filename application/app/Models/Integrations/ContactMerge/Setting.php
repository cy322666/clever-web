<?php

namespace App\Models\Integrations\ContactMerge;

use App\Filament\Resources\Integrations\ContactMergeResource;
use App\Helpers\Traits\SettingRelation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'contact_merge_settings';

    public static string $resource = ContactMergeResource::class;

    public static string $description = 'Поиск и склейка дублей контактов в amoCRM с гибкими правилами объединения.';

    public static array $cost = [
        '6_month'  => 'бесплатно',
        '12_month' => 'бесплатно',
    ];

    public const RULE_MERGE = 'merge';
    public const RULE_KEEP_OLD = 'keep_old';
    public const RULE_KEEP_NEW = 'keep_new';
    public const RULE_SKIP = 'skip';

    public const STRATEGY_OLDEST = 'oldest';
    public const STRATEGY_NEWEST = 'newest';

    protected $fillable = [
        'active',
        'match_fields',
        'merge_rules',
        'tag',
        'auto_merge',
        'master_strategy',
        'user_id',
    ];

    protected $casts = [
        'active' => 'boolean',
        'auto_merge' => 'boolean',
        'match_fields' => 'array',
        'merge_rules' => 'array',
    ];
}
