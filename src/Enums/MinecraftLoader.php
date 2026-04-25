<?php

namespace Boy132\MinecraftModrinth\Enums;

use App\Models\Server;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum MinecraftLoader: string implements HasIcon, HasLabel
{
    case NeoForge = 'neoforge';
    case Forge = 'forge';
    case Fabric = 'fabric';
    case Quilt = 'quilt';
    case Folia = 'folia';
    case Purpur = 'purpur';
    case Paper = 'paper';
    case Spigot = 'spigot';
    case Bukkit = 'bukkit';
    case Sponge = 'sponge';
    case Velocity = 'velocity';
    case Waterfall = 'waterfall';
    case Bungeecord = 'bungeecord';

    public function getLabel(): string
    {
        return str($this->name)->title();
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::NeoForge   => 'mcloader-neoforge',
            self::Forge      => 'mcloader-forge',
            self::Fabric     => 'mcloader-fabric',
            self::Quilt      => 'mcloader-quilt',
            self::Folia      => 'mcloader-folia',
            self::Purpur     => 'mcloader-purpur',
            self::Paper      => 'mcloader-paper',
            self::Spigot     => 'mcloader-spigot',
            self::Bukkit     => 'mcloader-bukkit',
            self::Sponge     => 'mcloader-sponge',
            self::Velocity   => 'mcloader-velocity',
            self::Waterfall  => 'mcloader-waterfall',
            self::Bungeecord => 'mcloader-bungeecord',
        };
    }

    public static function fromServer(Server $server): ?MinecraftLoader
    {
        $server->loadMissing('egg');

        $tags = $server->egg->tags ?? [];

        return self::fromTags($tags);
    }

    /** @param string[] $tags */
    public static function fromTags(array $tags): ?MinecraftLoader
    {
        if (in_array('minecraft', $tags)) {
            if (in_array('neoforge', $tags) || in_array('neoforged', $tags)) {
                return self::NeoForge;
            }

            if (in_array('forge', $tags)) {
                return self::Forge;
            }

            if (in_array('fabric', $tags)) {
                return self::Fabric;
            }

            if (in_array('quilt', $tags)) {
                return self::Quilt;
            }

            if (in_array('folia', $tags)) {
                return self::Folia;
            }

            if (in_array('purpur', $tags)) {
                return self::Purpur;
            }

            if (in_array('paper', $tags) || in_array('leaf', $tags)) {
                return self::Paper;
            }

            if (in_array('bukkit', $tags)) {
                return self::Bukkit;
            }

            if (in_array('spigot', $tags)) {
                return self::Spigot;
            }

            if (in_array('sponge', $tags)) {
                return self::Sponge;
            }

            if (in_array('velocity', $tags)) {
                return self::Velocity;
            }

            if (in_array('waterfall', $tags)) {
                return self::Waterfall;
            }

            if (in_array('bungeecord', $tags)) {
                return self::Bungeecord;
            }

        }

        return null;
    }
}
