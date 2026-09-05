<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P03;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * The tenant's ideal-customer profile — a DECLARATION, not an inference.
 *
 * Every dimension here is something the tenant said about who they want to sell to: industries,
 * geographies, a size band, buyer role CLASSES, and segments they have declared out of scope.
 * Nothing in this module infers a profile from behaviour, and nothing derives one from data about
 * people.
 *
 * BUYER ROLE CLASSES ARE CLASSES. "Finance decision maker" is a role; a named individual is not,
 * and no field here holds one. The distinction is the privacy line the product is built around: the
 * system may reason about what kind of person typically buys a thing, and may not build a dossier
 * on the person who does.
 *
 * Profiles are VERSIONED and append-only. A tenant changing their profile creates a new version;
 * the old one stays readable, because a fit hypothesis derived last month was derived against the
 * profile as it was then, and re-reading it against today's would misdescribe what happened.
 *
 * Every version must state its UNCERTAINTY. A profile is a hypothesis about a market, and one that
 * declared no limitations would be claiming a certainty no market research supports.
 */
final class Icp
{
    /** @var list<string> */
    private const SIZE_BANDS = [
        'MICRO_1_9', 'SMALL_10_49', 'MID_50_249', 'LARGE_250_999', 'ENTERPRISE_1000_PLUS',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Declare a new profile version.
     *
     * Creating one for an existing profile SUPERSEDES nothing — the profile row keeps its identity
     * and gains a version. Only the LATEST version is scored against, and the earlier ones stay
     * readable so a hypothesis can be reconciled with the profile it was actually derived under.
     *
     * @param array{
     *     industries?: list<string>, geographies?: list<string>, company_size_band?: ?string,
     *     buyer_role_classes?: list<string>, excluded_segments?: list<string>,
     *     uncertainty_notes_safe: string
     * } $version
     */
    public function declareVersion(
        string $tenantId,
        string $workspaceId,
        string $displayName,
        array $version,
        ?string $icpProfileId = null,
    ): Outcome {
        if (trim($version['uncertainty_notes_safe']) === '') {
            // A profile is a hypothesis about a market. One that declared no limitations would be
            // claiming a certainty nothing supports — so the field is required, not merely present.
            return Outcome::refuse('ICP_UNCERTAINTY_NOTES_REQUIRED', [], 'POLICY');
        }
        $sizeBand = $version['company_size_band'] ?? null;
        if ($sizeBand !== null && !in_array($sizeBand, self::SIZE_BANDS, true)) {
            return Outcome::refuse('ICP_COMPANY_SIZE_BAND_NOT_RECOGNIZED', [
                'company_size_band' => $sizeBand,
            ], 'POLICY');
        }

        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $tenantId, $workspaceId, $displayName, $version, $icpProfileId, $sizeBand, $now
        ): Outcome {
            $profileId = $icpProfileId;
            if ($profileId === null) {
                $profileId = Ids::new();
                $tx->insert('icp_profiles', [
                    'icp_profile_id' => $profileId,
                    'schema_version' => '1',
                    'tenant_id' => $tenantId,
                    'workspace_id' => $workspaceId,
                    'display_name' => $displayName,
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $existing = $tx->one(
                    'SELECT tenant_id, workspace_id, status FROM icp_profiles WHERE icp_profile_id = ?',
                    [$profileId]
                );
                if ($existing === null) {
                    return Outcome::refuse('ICP_PROFILE_NOT_FOUND', [], 'PROVENANCE');
                }
                if ((string) $existing['tenant_id'] !== $tenantId
                    || (string) $existing['workspace_id'] !== $workspaceId
                ) {
                    return Outcome::refuse('ICP_PROFILE_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
                }
                if ((string) $existing['status'] !== 'ACTIVE') {
                    return Outcome::refuse('ICP_PROFILE_NOT_ACTIVE', [], 'POLICY');
                }
                $tx->run('UPDATE icp_profiles SET updated_at = ? WHERE icp_profile_id = ?', [$now, $profileId]);
            }

            $versionNumber = 1 + (int) $tx->scalar(
                'SELECT COALESCE(MAX(version_number), 0) FROM icp_profile_versions WHERE icp_profile_id = ?',
                [$profileId]
            );
            $versionId = Ids::new();
            $tx->insert('icp_profile_versions', [
                'icp_profile_version_id' => $versionId,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'icp_profile_id' => $profileId,
                'version_number' => $versionNumber,
                'industries_json' => json_encode(array_values($version['industries'] ?? []), JSON_THROW_ON_ERROR),
                'geographies_json' => json_encode(array_values($version['geographies'] ?? []), JSON_THROW_ON_ERROR),
                'company_size_band' => $sizeBand,
                // ROLE CLASSES, never individuals. No field here can hold a named person, and the
                // scorer only ever matches these as terms.
                'buyer_role_classes_json' => json_encode(
                    array_values($version['buyer_role_classes'] ?? []), JSON_THROW_ON_ERROR
                ),
                'excluded_segments_json' => json_encode(
                    array_values($version['excluded_segments'] ?? []), JSON_THROW_ON_ERROR
                ),
                'uncertainty_notes_safe' => $version['uncertainty_notes_safe'],
                'created_at' => $now,
            ]);

            return Outcome::ok([
                'icp_profile_id' => $profileId,
                'icp_profile_version_id' => $versionId,
                'version_number' => $versionNumber,
            ]);
        });
    }

    /**
     * The LATEST version of the tenant's active profile in this workspace, or null.
     *
     * Null is a real answer: a tenant that has declared no profile gets no ICP component on their
     * hypotheses, rather than a default profile they never wrote.
     *
     * @return array<string, mixed>|null
     */
    public function activeProfileVersion(string $tenantId, string $workspaceId): ?array
    {
        $row = $this->db->one(
            "SELECT v.icp_profile_version_id, v.industries_json, v.geographies_json,
                    v.company_size_band, v.buyer_role_classes_json, v.excluded_segments_json
             FROM icp_profile_versions v
             JOIN icp_profiles p ON p.icp_profile_id = v.icp_profile_id AND p.status = 'ACTIVE'
             WHERE p.tenant_id = ? AND p.workspace_id = ?
             ORDER BY v.version_number DESC LIMIT 1",
            [$tenantId, $workspaceId]
        );
        if ($row === null) {
            return null;
        }

        return [
            'icp_profile_version_id' => (string) $row['icp_profile_version_id'],
            'industries' => json_decode((string) $row['industries_json'], true, 512, JSON_THROW_ON_ERROR),
            'geographies' => json_decode((string) $row['geographies_json'], true, 512, JSON_THROW_ON_ERROR),
            'company_size_band' => $row['company_size_band'],
            'buyer_role_classes' => json_decode((string) $row['buyer_role_classes_json'], true, 512, JSON_THROW_ON_ERROR),
            'excluded_segments' => json_decode((string) $row['excluded_segments_json'], true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
