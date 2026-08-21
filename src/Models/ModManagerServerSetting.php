<?php

namespace Kazaminosuke\ModManager\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-server Mod Manager settings.
 *
 * A missing row is intentionally equivalent to the pre-server-settings
 * behaviour: the manager is enabled and every nullable permission falls back
 * to the corresponding global plugin setting.
 */
class ModManagerServerSetting extends Model
{
    protected $table = 'mod_manager_server_settings';

    /** @var list<string> */
    protected $fillable = [
        'server_id',
        'enabled',
        'allow_user_egg_profile_edit',
        'allow_user_project_install',
        'allow_user_project_update',
        'allow_user_project_delete',
    ];

    protected function casts(): array
    {
        return [
            'server_id' => 'integer',
            'enabled' => 'boolean',
            'allow_user_egg_profile_edit' => 'boolean',
            'allow_user_project_install' => 'boolean',
            'allow_user_project_update' => 'boolean',
            'allow_user_project_delete' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
