<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local immutable metadata-only lineage registry.
 *
 * Source claims are not legal approvals. Restrictive capabilities are
 * intersected across ALL parents and can never be widened by transforms.
 * No raw prices, API keys or provider response bodies are persisted here.
 */
final class LocalLineageRegistry
{
    public function registerSource(
        int $userId,
        string $referenceType,
        string $referenceId,
        string $evidenceSha256,
        array $source
    ): array {
        $this->validateIdentity($userId, $referenceType, $referenceId, $evidenceSha256);
        $restricted = app(ByokSnapshotLineage::class)->isRestricted($source);
        $host = $this->host($source['source_url'] ?? null);
        $metadata = [
            'name' => mb_substr((string) ($source['source_name'] ?? ''), 0, 160),
            'publisher' => mb_substr((string) ($source['publisher'] ?? ''), 0, 160),
            'source_host' => $host,
            'terms_host' => $this->host($source['terms_url'] ?? null),
            'license_status' => mb_substr((string) ($source['license_status'] ?? 'unknown'), 0, 48),
            'usage_rights' => mb_substr((string) ($source['usage_rights'] ?? 'unknown'), 0, 48),
        ];
        $rights = $restricted
            ? ['RESTRICTED_BYOK', 'MARKETDATA_BYOK_IN_APP_ONLY', true, false]
            : ['USER_ATTESTED', 'SOURCE_RIGHTS_UNVERIFIED', true, true];

        return $this->put(
            $userId, 'SOURCE', $referenceType, $referenceId, 'ACQUIRED_SOURCE',
            $evidenceSha256, $metadata, $rights[1], $rights[0],
            ['display' => $rights[2], 'export' => false,
                'cloud' => false, 'persist' => $rights[3]], []
        );
    }

    /**
     * @param list<string> $parentIds UUIDs belonging to the SAME local owner.
     */
    public function registerTransformation(
        int $userId,
        string $referenceType,
        string $referenceId,
        string $operation,
        string $outputSha256,
        array $parentIds
    ): array {
        $this->validateIdentity($userId, $referenceType, $referenceId, $outputSha256);
        if (! preg_match('/^[A-Z][A-Z0-9_]{2,71}$/D', $operation)
            || count($parentIds) < 1 || count($parentIds) > 16
            || count(array_unique($parentIds)) !== count($parentIds)) {
            throw new \InvalidArgumentException('II_LINEAGE_INVALID_TRANSFORMATION');
        }
        foreach ($parentIds as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new \InvalidArgumentException('II_LINEAGE_PARENT_INVALID');
            }
        }

        return DB::transaction(function () use (
            $userId, $referenceType, $referenceId, $operation, $outputSha256, $parentIds
        ): array {
            $parents = DB::table('ii_lineage_nodes')->where('user_id', $userId)
                ->whereIn('id', $parentIds)->get();
            if ($parents->count() !== count($parentIds)) {
                throw new \InvalidArgumentException('II_LINEAGE_PARENT_NOT_OWNED_OR_MISSING');
            }
            $rightsProfiles = $parents->pluck('rights_profile')->unique()->values()->all();
            $basis = $parents->contains(fn ($row) => $row->rights_basis === 'RESTRICTED_BYOK')
                ? 'RESTRICTED_BYOK'
                : 'INHERITED_UNVERIFIED';
            $rights = $basis === 'RESTRICTED_BYOK'
                ? 'MARKETDATA_BYOK_IN_APP_ONLY'
                : (count($rightsProfiles) === 1 ? $rightsProfiles[0] : 'MIXED_RIGHTS_UNVERIFIED');

            $capabilities = [
                'display' => $parents->every(fn ($row) => (bool) $row->can_display),
                'export' => $parents->every(fn ($row) => (bool) $row->can_export),
                'cloud' => $parents->every(fn ($row) => (bool) $row->can_cloud_transfer),
                'persist' => $parents->every(fn ($row) => (bool) $row->can_persist_payload),
            ];
            // This stage does not authorize exporting any source material.
            $capabilities['export'] = false;
            $capabilities['cloud'] = false;

            return $this->put(
                $userId, 'TRANSFORMATION', $referenceType, $referenceId, $operation,
                $outputSha256,
                ['input_count' => count($parentIds), 'algorithm' => 'ii.lineage.registry.v1'],
                $rights, $basis, $capabilities, $parentIds
            );
        }, 1);
    }

    public function find(int $userId, string $referenceType, string $referenceId, string $operation): ?array
    {
        $record = DB::table('ii_lineage_nodes')->where('user_id', $userId)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('operation', $operation)->first();

        return $record === null ? null : $this->view($record);
    }

    /**
     * Read a bounded owner-only ancestry graph for audit/review.
     * No numeric payload or provider API key is returned.
     */
    public function trace(int $userId, string $nodeId): array
    {
        if ($userId < 1 || ! Str::isUuid($nodeId)) {
            throw new \InvalidArgumentException('II_LINEAGE_TRACE_INVALID');
        }
        $pending = [$nodeId];
        $visited = [];
        $nodes = [];
        $edges = [];
        while ($pending !== []) {
            if (count($visited) > 64) {
                throw new \RuntimeException('II_LINEAGE_TRACE_LIMIT_EXCEEDED');
            }
            $id = array_shift($pending);
            if (isset($visited[$id])) {
                continue;
            }
            $row = DB::table('ii_lineage_nodes')
                ->where('user_id', $userId)->where('id', $id)->first();
            if ($row === null) {
                throw new \InvalidArgumentException('II_LINEAGE_TRACE_NOT_OWNED_OR_MISSING');
            }
            $visited[$id] = true;
            $nodes[] = $this->view($row);
            $parents = DB::table('ii_lineage_edges')
                ->where('user_id', $userId)->where('child_node_id', $id)
                ->orderBy('parent_node_id')->pluck('parent_node_id')->all();
            foreach ($parents as $parent) {
                $edges[] = ['parent_node_id' => $parent, 'child_node_id' => $id];
                if (! isset($visited[$parent])) {
                    $pending[] = $parent;
                }
            }
        }

        return ['root_node_id' => $nodeId, 'nodes' => $nodes, 'edges' => $edges];
    }

    /** Effective permission is never inferred from a UI badge. */
    public function allows(int $userId, string $nodeId, string $capability): bool
    {
        $column = match ($capability) {
            'display' => 'can_display',
            'export' => 'can_export',
            'cloud' => 'can_cloud_transfer',
            'persist' => 'can_persist_payload',
            default => null,
        };
        if ($column === null) {
            return false;
        }
        $node = DB::table('ii_lineage_nodes')
            ->where('user_id', $userId)->where('id', $nodeId)->first();

        return $node !== null && (bool) $node->{$column};
    }

    private function put(
        int $userId, string $kind, string $referenceType, string $referenceId,
        string $operation, string $evidenceSha256, array $metadata,
        string $rights, string $basis, array $caps, array $parentIds
    ): array {
        $parentIds = array_values($parentIds);
        sort($parentIds, SORT_STRING);
        // Canonical, deterministic identity. Neither API keys nor raw payload
        // bytes are included in the fingerprint or source metadata.
        $claim = [$kind, $referenceType, $referenceId, $operation,
            strtolower($evidenceSha256), $metadata, $rights, $basis, $caps, $parentIds];
        $fingerprint = hash('sha256', json_encode($claim, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use (
            $userId, $kind, $referenceType, $referenceId, $operation,
            $evidenceSha256, $metadata, $rights, $basis, $caps, $parentIds, $fingerprint
        ): array {
            $existing = DB::table('ii_lineage_nodes')->where('user_id', $userId)
                ->where('kind', $kind)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('operation', $operation)->first();
            if ($existing !== null) {
                if ($existing->fingerprint !== $fingerprint) {
                    throw new \RuntimeException('II_LINEAGE_IMMUTABLE_CONFLICT');
                }

                return $this->view($existing);
            }

            $id = (string) Str::uuid();
            DB::table('ii_lineage_nodes')->insert([
                'id' => $id, 'user_id' => $userId,
                'kind' => $kind, 'reference_type' => $referenceType,
                'reference_id' => $referenceId, 'operation' => $operation,
                'fingerprint' => $fingerprint, 'evidence_sha256' => strtolower($evidenceSha256),
                'rights_profile' => $rights, 'rights_basis' => $basis,
                'can_display' => $caps['display'],
                'can_export' => $caps['export'],
                'can_cloud_transfer' => $caps['cloud'],
                'can_persist_payload' => $caps['persist'],
                'source_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            foreach ($parentIds as $parent) {
                DB::table('ii_lineage_edges')->insert([
                    'user_id' => $userId,
                    'parent_node_id' => $parent,
                    'child_node_id' => $id,
                ]);
            }

            return $this->view(DB::table('ii_lineage_nodes')->where('id', $id)->first());
        }, 1);
    }

    private function validateIdentity(int $userId, string $type, string $ref, string $sha): void
    {
        if ($userId < 1
            || ! preg_match('/^[a-z][a-z0-9_]{2,71}$/D', $type)
            || $ref === '' || strlen($ref) > 128
            || ! preg_match('/^[a-f0-9]{64}$/Di', $sha)) {
            throw new \InvalidArgumentException('II_LINEAGE_INVALID_IDENTITY');
        }
    }

    private function host(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        return strtolower((string) parse_url($url, PHP_URL_HOST)) ?: null;
    }

    private function view(object $row): array
    {
        return [
            'id' => $row->id, 'kind' => $row->kind,
            'reference_type' => $row->reference_type,
            'reference_id' => $row->reference_id,
            'operation' => $row->operation,
            'fingerprint' => $row->fingerprint,
            'evidence_sha256' => $row->evidence_sha256,
            'rights_profile' => $row->rights_profile,
            'rights_basis' => $row->rights_basis,
            'can_display' => (bool) $row->can_display,
            'can_export' => (bool) $row->can_export,
            'can_cloud_transfer' => (bool) $row->can_cloud_transfer,
            'can_persist_payload' => (bool) $row->can_persist_payload,
        ];
    }
}
