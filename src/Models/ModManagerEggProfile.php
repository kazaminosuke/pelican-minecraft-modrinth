<?php

namespace Kazaminosuke\ModManager\Models;

use App\Models\Egg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manually-configured egg profile: the GUI fallback EggProfileResolver
 * falls back to for an egg its automatic cascade could not place (status
 * MANUAL_REQUIRED). Saved either by an administrator (plugin settings
 * screen, always available) or - only when config('pelican-minecraft-
 * modrinth.allow_user_egg_profile_edit') is true - by a user holding
 * SubuserPermission::StartupUpdate on a server using that egg (see
 * ModManagerPage's manual-profile form).
 *
 * Deliberately keyed by egg, not by server: loader/project type/datapack
 * support are properties of the egg, not of any one server running it, so
 * one save here covers every server sharing that egg. See Stage 8's design
 * doc (6-3) for why a per-server table was rejected.
 *
 * @property int $id
 * @property int $egg_id
 * @property string|null $egg_uuid
 * @property string|null $project_type
 * @property string|null $loader
 * @property string|null $minecraft_version
 * @property bool $supports_datapacks
 */
class ModManagerEggProfile extends Model
{
    protected $table = 'mod_manager_egg_profiles';

    /** @var list<string> */
    protected $fillable = [
        'egg_id',
        'egg_uuid',
        'project_type',
        'loader',
        'minecraft_version',
        'supports_datapacks',
    ];

    protected function casts(): array
    {
        return [
            'egg_id' => 'integer',
            'supports_datapacks' => 'boolean',
        ];
    }

    public function egg(): BelongsTo
    {
        return $this->belongsTo(Egg::class);
    }
}
