# Minecraft Mod Manager

*[English](README.md)*

[Pelican Panel](https://pelican.dev) 用のプラグインです。**Modrinth、CurseForge、Hangar、GitHub
Releases** のMod・Plugin・Datapackを、サーバーパネル上で検索・インストール・更新・管理できます。

![カタログタブ](docs/images/catalog.png)
![インストール済みタブ](docs/images/installed.png)

## 対応ソース

| ソース | APIキー | 検索 | ハッシュ照合 | 対応プロジェクト種別 |
|---|---|---|---|---|
| [Modrinth](https://modrinth.com) | 不要 | ✅ | ✅(`sha512`) | Mod, Plugin, Datapack |
| [CurseForge](https://www.curseforge.com/minecraft) | **必須** | ✅ | ✅(`murmur2`) | Mod, Plugin |
| [Hangar](https://hangar.papermc.io) | 不要 | ✅ | ✅(`sha256`) | Plugin |
| [GitHub Releases](https://github.com) | 任意(推奨) | ❌(`owner/repo`を1件ずつ追跡) | ❌ | Mod, Plugin |

Modrinthは常に有効です。他の3つはegg単位のオプトインです([Egg設定](#egg設定)を参照)。GitHub
Releasesはトークンなしでも動作しますが、未認証時のレート制限(60リクエスト/時)は乏しいため、
トークンが設定されるまでバックグラウンドのキャッシュウォーミングからは除外されます。

## 要件

- Pelican Panel(`main`、Filament 5.6以上)
- PHP 8.3〜8.5
- **非同期キューワーカー(必須)。** インストール済みファイルのスキャン、一括更新、キャッシュの
  ウォーミングはすべてキュージョブとして実行され、Livewireリクエストの応答性を保ちます。実際の
  ドライバ(例: `QUEUE_CONNECTION=database`)を設定し、ワーカーを起動しておいてください。

  ```sh
  php artisan queue:work
  ```

  スキャン・一括更新については`sync`・`null`ドライバは意図的に拒否されます。この場合ブラウザの
  リクエストをブロックする代わりに、キュー未設定の警告が表示されます。

## インストール

**方法1: 直接URL** - Pelican Panelのプラグインインストーラーに以下を貼り付けてください。

```txt
https://github.com/kazaminosuke/pelican-minecraft-modrinth/releases/latest/download/pelican-minecraft-modrinth.zip
```

**方法2: ZIPアップロード** - [Releases](https://github.com/kazaminosuke/pelican-minecraft-modrinth/releases)
ページから最新のZIPをダウンロードし、プラグインインストーラーからアップロードしてください。

## Egg設定

プラグインが何を管理すべきか判別できるよう、eggの**feature**に以下のいずれかを追加してください。

- `mod_manager` - `mods/` を管理
- `plugin_manager` - `plugins/` を管理
- `datapack_manager` - `world/datapacks/` を管理(上記いずれかと併用可能)

さらに`minecraft`**タグ**と、バージョン/ローダー別のフィルタリングを機能させるためのローダー
タグ(`paper`、`purpur`、`folia`、`spigot`、`bukkit`、`fabric`、`quilt`、`forge`、`neoforge`、
`sponge`、`velocity`、`waterfall`、`bungeecord`のいずれか)を追加してください。

追加のカタログソースを有効にするには、それぞれのfeatureフラグも追加します。

```json
{ "features": ["mod_manager", "curseforge", "hangar"], "tags": ["minecraft", "fabric"] }
```

`curseforge`・`hangar`・`github_releases`は、それぞれ対応するタブを有効にします(上表の対応
プロジェクト種別の範囲内)。このフラグがないeggでは、該当ソースのAPIキーを設定していても
そのタブ自体が表示されません。

**eggの自動認識**([内部の仕組み](#内部の仕組み)参照)により、公式のMinecraft eggの大半は上記を
手動設定する必要がありません(明示的な`features`/`tags`が設定されていれば常にそちらが優先されます)。
これに伴う変更点として、認識されたJava系egg(mod/plugin/hybrid/vanilla/modpack)ではdatapack管理が
`datapack_manager` featureなしでも**既定で有効**になります。無効化するにはeggのfeatureに
`datapack_manager_disabled`を追加するか、`MOD_MANAGER_EGG_AUTODETECT=false`を設定して
自動認識導入前の挙動(`datapack_manager`の明示指定が必須)に完全に戻してください。

## 設定

プラグイン設定画面(パネル管理者 → Plugins)には以下の項目があり、特記のない限りそれぞれ
グローバルな`.env`キーに対応しています。

| 項目 | `.env`キー |
|---|---|
| 最新Minecraftバージョン | `LATEST_MINECRAFT_VERSION` |
| ナビゲーションの並び順 | `MINECRAFT_MODRINTH_NAV_SORT` |
| CurseForge APIキー | `CURSEFORGE_API_KEY` |
| GitHubトークン | `GITHUB_TOKEN` |
| 一般ユーザーにもegg プロファイルの編集を許可 | `MOD_MANAGER_ALLOW_USER_EGG_PROFILE_EDIT`(既定OFF) |

「最新Minecraftバージョン」は、サーバー自身に`MINECRAFT_VERSION`/`MC_VERSION`起動時変数が
設定されていない場合のフォールバック値です。「一般ユーザーにもeggプロファイルの編集を許可」は、
そのサーバーを既に管理できるユーザー(所有者・管理者・`startup.update`権限を持つサブユーザー)に
編集を広げるだけで、全ユーザーに開放するものではありません。判定ロジックの詳細は
[`docs/architecture.md`](docs/architecture.md)(英語)を参照してください。**注意:** この権限判定
ロジックには現時点で自動テストが整備されておらず、手動確認のみで検証されています。変更する際は
手動での再確認をお願いします。

同じ画面には、自動認識で解決できなかったeggに対して対応種別・ローダー・MCバージョン・
datapack対応を手動設定する**Egg profiles**アクションもあります。

同じ画面には**キャッシュをクリア**アクションもあり、対象範囲によって挙動が異なります。

- **全サーバー** - 全サーバーの追跡済みMetadataと共有キャッシュをクリアしますが、即座には
  再スキャンしません。各サーバーは次回Installedタブを開いたときに自動的に再スキャンされます。
- **単一サーバー** - そのサーバーのMetadataをクリアし、即座に強制再スキャンをキューに投入します
  (稼働中のキューが必要です。[要件](#要件)を参照)。

## 内部の仕組み

- **ローカルMetadataインデックス**(各サーバー上の`.pelican-mod-manager.json`。旧ファイル名
  `.modrinth-metadata.json`からの自動移行に対応)が、インストール済みファイルとアップストリーム
  プロジェクトの対応関係を追跡します。
- **インクリメンタルなハッシュスキャン**により、サイズ/更新日時のシグネチャに変化があった
  ファイルのみを再ハッシュ化します(毎回全ファイルを再ハッシュ化しません)。
- **バックグラウンドジョブとステータスバッジ**により、スキャンと一括更新がUIをブロックせずに
  実行されます。
- すべてのアップストリームAPI呼び出しの前段に**stale-while-revalidateキャッシュ**があり、
  データ種別ごとの鮮度ポリシーに加え、実際に使われている(ローダー / Minecraftバージョン /
  プロジェクト種別)の組み合わせを事前にキャッシュへ埋める**スケジュール実行のウォームジョブ**
  (ユーザー操作とは別枠でレート制限)を備えています。
- **条件付きの遅延読み込み**により、表示するデータが既にキャッシュ済みの場合は追加の読み込み
  往復自体を省略します。
- **eggの自動認識**により、パネル公式のMinecraft egg(およびPterodactylエコシステム側の
  対応するegg)を、eggを手で編集することなく認識します。明示的な`features`/`tags`は常に
  自動認識より優先され、自動では判定しきれないeggには(何も表示しない代わりに)簡単な設定案内が
  表示されます。`MOD_MANAGER_EGG_AUTODETECT=false`でこの機能を無効化できます。

各項目の詳細な設計(検出順序や、eggを手動設定する方法を含む)については
[`docs/architecture.md`](docs/architecture.md)(英語)を参照してください。

## トラブルシューティング

- **「非同期キューワーカーが必要です」という警告が出る** - [要件](#要件)を参照してください。
- **行が「未追跡」と表示される** - mod/plugin/datapackフォルダにファイルは存在するものの、
  まだMetadataインデックスに記録されていない状態です。再スキャンアクションを使用してください。
- **ソースタブに`!`バッジが表示される** - そのソースはeggで有効化されているものの、未設定の
  状態です(例: APIキー未設定のCurseForgeなど)。[設定](#設定)を参照してください。
- **カタログのデータが古いと感じる** - 設定画面のキャッシュをクリアアクションを使用してください。
  全サーバー/単一サーバーでの挙動の違いは上記の通りです。

## リポジトリ

<https://github.com/kazaminosuke/pelican-minecraft-modrinth>

## フォークの系譜とライセンス

このリポジトリは
[H1ghSyst3m/plugins](https://github.com/H1ghSyst3m/plugins/tree/featcomplete-mod-plugin-management)
のフォークであり、同リポジトリは[pelican-dev/plugins](https://github.com/pelican-dev/plugins)の
フォークです。

GNU General Public License v3.0(GPL-3.0)の下でライセンスされています。詳細は
[`LICENSE`](LICENSE)を参照してください。

## 開発者向け

- [`docs/architecture.md`](docs/architecture.md)(英語) - キャッシュ層、Metadataフォーマット、
  新しいソースの追加方法。
- [Issues](https://github.com/kazaminosuke/pelican-minecraft-modrinth/issues) /
  [Pull requests](https://github.com/kazaminosuke/pelican-minecraft-modrinth/pulls)
