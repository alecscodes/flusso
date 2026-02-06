<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Setting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Get a setting value by key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting !== null ? $setting->value : $default;
    }

    /**
     * Set a setting value by key.
     */
    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Check if registration is allowed.
     * Registration is allowed if:
     * 1. The registration_enabled setting is true, OR
     * 2. No users exist in the system (initial setup).
     */
    public static function isRegistrationAllowed(): bool
    {
        // Allow registration if no users exist (initial setup)
        if (User::count() === 0) {
            return true;
        }

        // Otherwise, check the setting (defaults to false)
        return filter_var(
            static::get('registration_enabled', false),
            FILTER_VALIDATE_BOOLEAN
        );
    }
}
