<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\EggProfileRegistry;
use PHPUnit\Framework\TestCase;

class EggProfileRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mirrors the real profile set's most important safety property
        // (Stage 8 依頼 3): Paper and Folia share an identical variable
        // signature - only the name disambiguates them.
        EggProfileRegistry::seed([
            [
                'id' => 'paper',
                'match' => [
                    'uuid' => ['paper-uuid-new', 'paper-uuid-old'],
                    'update_url_contains' => ['java/paper/egg-paper.'],
                    'name_aliases' => ['paper'],
                    'variable_signatures' => [['BUILD_NUMBER', 'DL_PATH', 'MINECRAFT_VERSION', 'SERVER_JARFILE']],
                ],
                'status' => 'resolved',
                'project_type' => 'plugin',
                'loader' => 'paper',
                'is_proxy' => false,
                'minecraft_version_variables' => ['MINECRAFT_VERSION'],
            ],
            [
                'id' => 'folia',
                'match' => [
                    'uuid' => ['folia-uuid'],
                    'update_url_contains' => ['java/folia/egg-folia.'],
                    'name_aliases' => ['folia'],
                    // Deliberately the same signature as paper above.
                    'variable_signatures' => [['BUILD_NUMBER', 'DL_PATH', 'MINECRAFT_VERSION', 'SERVER_JARFILE']],
                ],
                'status' => 'resolved',
                'project_type' => 'plugin',
                'loader' => 'folia',
                'is_proxy' => false,
                'minecraft_version_variables' => ['MINECRAFT_VERSION'],
            ],
            [
                'id' => 'spigot',
                'match' => [
                    'uuid' => ['spigot-uuid'],
                    'update_url_contains' => ['java/spigot/egg-spigot.'],
                    'name_aliases' => ['spigot'],
                    // Unique - never collides with anything else here.
                    'variable_signatures' => [['DL_PATH', 'DL_VERSION', 'SERVER_JARFILE']],
                ],
                'status' => 'resolved',
                'project_type' => 'plugin',
                'loader' => 'spigot',
                'is_proxy' => false,
                'minecraft_version_variables' => ['DL_VERSION'],
            ],
            [
                'id' => 'vanilla-bedrock',
                'match' => [
                    'uuid' => ['vanilla-bedrock-uuid'],
                    'name_aliases' => ['vanillabedrock'],
                    'variable_signatures' => [],
                ],
                'status' => 'none',
                'project_type' => null,
                'loader' => null,
                'is_proxy' => false,
                'minecraft_version_variables' => [],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        EggProfileRegistry::clear();

        parent::tearDown();
    }

    public function test_finds_by_uuid_including_a_legacy_uuid(): void
    {
        self::assertSame('paper', EggProfileRegistry::findByUuid('paper-uuid-new')->id);
        self::assertSame('paper', EggProfileRegistry::findByUuid('paper-uuid-old')->id);
        self::assertNull(EggProfileRegistry::findByUuid('unknown-uuid'));
        self::assertNull(EggProfileRegistry::findByUuid(null));
    }

    public function test_finds_by_update_url_substring(): void
    {
        $profile = EggProfileRegistry::findByUpdateUrl('https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/paper/egg-paper.yaml');

        self::assertSame('paper', $profile->id);
        self::assertNull(EggProfileRegistry::findByUpdateUrl('https://example.com/nothing'));
        self::assertNull(EggProfileRegistry::findByUpdateUrl(null));
    }

    /**
     * The core safety property from Stage 8's variable-signature research:
     * name+signature must resolve Folia to folia, not paper, even though
     * both share an identical Pterodactyl-ecosystem variable signature.
     */
    public function test_name_and_signature_disambiguates_a_colliding_signature(): void
    {
        $sig = ['BUILD_NUMBER', 'DL_PATH', 'MINECRAFT_VERSION', 'SERVER_JARFILE'];

        self::assertSame('folia', EggProfileRegistry::findByNameAndSignature('folia', $sig)->id);
        self::assertSame('paper', EggProfileRegistry::findByNameAndSignature('paper', $sig)->id);
    }

    public function test_name_and_signature_returns_null_when_the_name_does_not_match_that_profile(): void
    {
        $sig = ['BUILD_NUMBER', 'DL_PATH', 'MINECRAFT_VERSION', 'SERVER_JARFILE'];

        self::assertNull(EggProfileRegistry::findByNameAndSignature('spigot', $sig));
    }

    public function test_name_unique_resolves_a_single_matching_profile(): void
    {
        self::assertSame('spigot', EggProfileRegistry::findByNameUnique('spigot')->id);
        self::assertNull(EggProfileRegistry::findByNameUnique('unknown-name'));
    }

    public function test_non_colliding_signature_resolves_spigot(): void
    {
        $profile = EggProfileRegistry::findByNonCollidingSignature(['DL_PATH', 'DL_VERSION', 'SERVER_JARFILE']);

        self::assertSame('spigot', $profile->id);
    }

    /**
     * The signature-alone tier must refuse to answer for a signature two
     * profiles share, even when it happens to be looked up in a way that
     * would only ever "find" one of them by iteration order - collision
     * status is a property of the whole profile set, not of one lookup.
     */
    public function test_non_colliding_signature_refuses_a_colliding_signature(): void
    {
        $collidingSig = ['BUILD_NUMBER', 'DL_PATH', 'MINECRAFT_VERSION', 'SERVER_JARFILE'];

        self::assertNull(EggProfileRegistry::findByNonCollidingSignature($collidingSig));
        self::assertTrue(EggProfileRegistry::isCollidingSignature($collidingSig));
        self::assertFalse(EggProfileRegistry::isCollidingSignature(['DL_PATH', 'DL_VERSION', 'SERVER_JARFILE']));
    }

    public function test_normalize_name_strips_punctuation_and_lowercases(): void
    {
        self::assertSame('purpurgeyserfloodgate', EggProfileRegistry::normalizeName('Purpur-Geyser-Floodgate'));
    }

    public function test_normalize_signature_dedupes_uppercases_and_sorts(): void
    {
        self::assertSame(
            ['DL_VERSION', 'SERVER_JARFILE'],
            EggProfileRegistry::normalizeSignature(['server_jarfile', 'DL_VERSION', 'dl_version']),
        );
    }
}
