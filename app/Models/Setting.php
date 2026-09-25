<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    public static function get(string $key, $default = null)
    {
        return Cache::remember("setting:{$key}", 3600, function () use ($key, $default) {
            return static::query()->where('key', $key)->value('value') ?? $default;
        });
    }

    public static function put(string $key, $value, ?int $userId = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        Cache::forget("setting:{$key}");
    }
}
