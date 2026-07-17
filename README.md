# Minecraft Modrinth

A plugin for [Pelican Panel](https://pelican.dev) that lets you search, install, update, and manage Minecraft mods/plugins from [Modrinth](https://modrinth.com) directly in the server panel.

> This repository is a **fork** of
> [H1ghSyst3m/plugins](https://github.com/H1ghSyst3m/plugins/tree/featcomplete-mod-plugin-management), which forks [pelican-dev/plugins](https://github.com/pelican-dev/plugins).

## Features

- Browse and search Modrinth projects from inside the server panel
- Install compatible mod/plugin versions with one click
- Track installed files via `.modrinth-metadata.json`
- Detect available updates for installed entries
- Uninstall installed entries directly from the panel
- **Fork-specific additions:**
  - Scan existing `.jar` files and import found matches into metadata
  - Show "Not on Modrinth" state for unknown files
  - Rescan actions for mods/plugins update checks
  - Bulk update action for all updatable mods/plugins
  - Extended German/English notification and action texts
  - Install Datapacks from Modrinth

## Setup

Add `mod_manager` or `plugin_manager` to your egg **features**.
Add `datapack_manager` if you want to manage datapacks as well.
Also ensure the egg has the `minecraft` tag and a matching loader tag (for example `paper`, `fabric`, `forge`, or `neoforge`).

## Installation

### Option 1: Direct URL

Use this URL in the Pelican Panel plugin installer:

```txt
https://github.com/YONN2222/pelican-minecraft-modrinth/releases/latest/download/pelican-minecraft-modrinth.zip
```

### Option 2: Upload ZIP

1. Go to the [Releases](https://github.com/YONN2222/pelican-minecraft-modrinth/releases) page
2. Download the latest plugin ZIP
3. Open the Pelican Panel plugin installer
4. Upload the ZIP file

## Repository

https://github.com/YONN2222/pelican-minecraft-modrinth

## License

GNU General Public License v3.0 (GPL-3.0)
