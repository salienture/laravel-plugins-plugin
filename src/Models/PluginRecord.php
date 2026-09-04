<?php

namespace Salienture\Plugins\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Persisted state for a discovered plugin (lifecycle + update channel).
 *
 * The filesystem remains the source of truth for what exists; this table
 * only records choices made by admins and the update checker. Trashed
 * plugins keep their row (soft-deleted) until trash is emptied.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $version
 * @property string|null $latest_version
 * @property string|null $changelog
 * @property string|null $download_url
 * @property bool $update_available
 * @property Carbon|null $last_checked_at
 * @property bool $is_active
 * @property bool|null $auto_update
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class PluginRecord extends Model
{
    use SoftDeletes;

    /** The database table used by the model. */
    protected $table = 'plugins';

    /**
     * Attributes mass-assignable via activation/update flows.
     *
     * @var list<int|string>
     */
    protected $fillable = [
        'slug',
        'name',
        'version',
        'latest_version',
        'changelog',
        'download_url',
        'update_available',
        'is_active',
        'auto_update',
        'last_checked_at',
    ];

    /**
     * Attribute casting definitions.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'update_available' => 'boolean',
            'is_active' => 'boolean',
            'auto_update' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }
}
