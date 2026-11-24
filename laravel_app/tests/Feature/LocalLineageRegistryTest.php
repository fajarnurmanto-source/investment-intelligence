<?php

namespace Tests\Feature;

use App\Models\IssuerDocument;
use App\Models\User;
use App\Services\LocalLineageRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class LocalLineageRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $name = 'Independent user source'): array
    {
        return [
            'source_name' => $name,
            'publisher' => 'Fixture',
            'source_url' => 'https://example.test/document',
            'terms_url' => 'https://example.test/terms',
            'license_status' => 'user_provided',
            'usage_rights' => 'internal_research',
            'redistribution_allowed' => false,
        ];
    }

    public function test_source_transformation_edges_are_owner_scoped_immutable_and_idempotent(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $registry = app(LocalLineageRegistry::class);
        $source = $registry->registerSource(
            $a->id, 'issuer_document', 'fixture-A', hash('sha256', 'bytes-A'), $this->source()
        );
        $same = $registry->registerSource(
            $a->id, 'issuer_document', 'fixture-A', hash('sha256', 'bytes-A'), $this->source()
        );
        $this->assertSame($source['id'], $same['id']);
        $this->assertSame('USER_ATTESTED', $source['rights_basis']);
        $this->assertFalse($source['can_export']);
        $this->assertTrue($source['can_persist_payload']);

        $child = $registry->registerTransformation(
            $a->id, 'issuer_document', 'fixture-A', 'NORMALIZE_ISSUER_PERIODS_V1',
            hash('sha256', 'normalized-A'), [$source['id']]
        );
        $this->assertSame($child['id'], $registry->registerTransformation(
            $a->id, 'issuer_document', 'fixture-A', 'NORMALIZE_ISSUER_PERIODS_V1',
            hash('sha256', 'normalized-A'), [$source['id']]
        )['id']);
        $this->assertSame(1, DB::table('ii_lineage_edges')->where('user_id', $a->id)->count());
        $this->assertSame($child['id'], $registry->find(
            $a->id, 'issuer_document', 'fixture-A', 'NORMALIZE_ISSUER_PERIODS_V1'
        )['id']);
        $this->assertNull($registry->find(
            $b->id, 'issuer_document', 'fixture-A', 'NORMALIZE_ISSUER_PERIODS_V1'
        ));
        $this->assertFalse($registry->allows($a->id, $child['id'], 'export'));
        $this->assertFalse($registry->allows($a->id, $child['id'], 'cloud'));
        $this->assertFalse($registry->allows($b->id, $child['id'], 'display'));

        try {
            $registry->registerSource(
                $a->id, 'issuer_document', 'fixture-A', hash('sha256', 'changed'), $this->source()
            );
            $this->fail('Changing source digest must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertSame('II_LINEAGE_IMMUTABLE_CONFLICT', $e->getMessage());
        }
        $this->assertDatabaseCount('ii_lineage_nodes', 2);
    }

    public function test_restricted_source_rights_cannot_be_widened_by_mixed_transforms(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $registry = app(LocalLineageRegistry::class);
        $independent = $registry->registerSource(
            $a->id, 'issuer_document', 'independent', hash('sha256', 'independent'), $this->source()
        );
        $restricted = $registry->registerSource(
            $a->id, 'legacy_source', 'vendor-metadata', hash('sha256', 'synthetic-only'),
            $this->source('MarketData.app BYOK')
        );
        $foreign = $registry->registerSource(
            $b->id, 'issuer_document', 'other-owner', hash('sha256', 'other-owner'), $this->source()
        );
        $this->assertSame('RESTRICTED_BYOK', $restricted['rights_basis']);
        $this->assertFalse($restricted['can_persist_payload']);

        try {
            $registry->registerTransformation(
                $a->id, 'issuer_document', 'cross-account', 'COMBINE_SOURCES_V1',
                hash('sha256', 'out'), [$restricted['id'], $foreign['id']]
            );
            $this->fail('Cross-owner parent must be blocked.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('II_LINEAGE_PARENT_NOT_OWNED_OR_MISSING', $e->getMessage());
        }
        $mixed = $registry->registerTransformation(
            $a->id, 'issuer_document', 'combined', 'COMBINE_SOURCES_V1',
            hash('sha256', 'combined'), [$independent['id'], $restricted['id']]
        );
        $this->assertSame('RESTRICTED_BYOK', $mixed['rights_basis']);
        $this->assertSame('MARKETDATA_BYOK_IN_APP_ONLY', $mixed['rights_profile']);
        $this->assertFalse($mixed['can_persist_payload']);
        $this->assertFalse($mixed['can_export']);
        $this->assertFalse($mixed['can_cloud_transfer']);
        $grandchild = $registry->registerTransformation(
            $a->id, 'issuer_document', 'grandchild', 'DERIVE_RATIOS_V1',
            hash('sha256', 'ratios'), [$mixed['id']]
        );
        $this->assertSame('RESTRICTED_BYOK', $grandchild['rights_basis']);
        $this->assertFalse($grandchild['can_export']);
        $this->assertFalse($grandchild['can_persist_payload']);
        $this->assertDatabaseCount('ii_lineage_edges', 3);
        $trace = $registry->trace($a->id, $grandchild['id']);
        $this->assertSame($grandchild['id'], $trace['root_node_id']);
        $this->assertCount(4, $trace['nodes']);
        $this->assertCount(3, $trace['edges']);
        $this->assertFalse($registry->allows($a->id, $grandchild['id'], 'export'));
        try {
            $registry->trace($b->id, $grandchild['id']);
            $this->fail('Cross-owner trace must be blocked.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('II_LINEAGE_TRACE_NOT_OWNED_OR_MISSING', $e->getMessage());
        }


        $stored = DB::table('ii_lineage_nodes')->where('id', $restricted['id'])->first();
        $this->assertStringNotContainsString('synthetic-only', $stored->source_metadata);
        $this->assertStringNotContainsString('/document', $stored->source_metadata);
    }

    public function test_registry_restricted_rights_block_csv_even_after_mutable_source_labels_change(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['ii_research_role' => 'analyst'])->save();
        $other = User::factory()->create();
        $other->forceFill(['ii_research_role' => 'analyst'])->save();
        $registry = app(LocalLineageRegistry::class);
        $legacy = $registry->registerSource(
            $owner->id, 'legacy_source', 'vendor-source-1',
            hash('sha256', 'synthetic-vendor-source'),
            $this->source('MarketData.app BYOK')
        );
        $this->assertFalse($legacy['can_export']);
        $this->actingAs($owner)
            ->get('/ii/fundamentals/coverage/export.csv')->assertForbidden();
        $this->get('/ii/screener/export.csv')->assertForbidden();

        $this->actingAs($other)
            ->get('/ii/fundamentals/coverage/export.csv')->assertOk();
    }

    public function test_actual_issuer_import_registers_source_and_normalization_atomically(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $owner->forceFill(['ii_research_role' => 'admin'])->save();
        $csv = "fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,total_equity,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price,market_price_as_of\n"
            ."2024,2024-12-31,false,100,10,500,300,250,50,1000,100,20,40,,\n";
        $this->actingAs($owner)->post('/ii/fundamentals/import', [
            'ticker' => 'AAPL', 'issuer_name' => 'Fixture',
            'sector' => 'Technology', 'kind' => 'user_supplied',
            'report_period' => 'FY2024', 'fiscal_period_type' => 'annual',
            'report_date' => '2025-03-01', 'currency' => 'USD',
            'multiplier' => '1', 'consolidated' => '1',
            'source_name' => 'Independent filings', 'publisher' => 'Fixture',
            'source_url' => 'https://example.test/filing',
            'license_status' => 'user_provided', 'usage_rights' => 'internal_research',
            'source_authenticity_status' => 'user_attested',
            'numeric_reconciliation_status' => 'unreconciled',
            'provenance_confidence' => 'low', 'rights_confirmed' => '1',
            'fundamental_file' => UploadedFile::fake()->createWithContent('issuer.csv', $csv),
        ])->assertRedirect('/ii/fundamentals')->assertSessionHasNoErrors();
        $doc = IssuerDocument::query()->where('user_id', $owner->id)->firstOrFail();
        $registry = app(LocalLineageRegistry::class);
        $src = $registry->find($owner->id, 'issuer_document', $doc->id, 'ACQUIRED_SOURCE');
        $derived = $registry->find(
            $owner->id, 'issuer_document', $doc->id, 'NORMALIZE_ISSUER_PERIODS_V1'
        );
        $this->assertNotNull($src);
        $this->assertNotNull($derived);
        $this->assertSame($doc->sha256, $src['evidence_sha256']);
        $this->assertDatabaseCount('ii_lineage_edges', 1);
        $this->assertDatabaseCount('ii_lineage_nodes', 2);
        $this->assertFalse($registry->allows($owner->id, $derived['id'], 'export'));
        $snapshot = app(\App\Services\FundamentalSnapshotService::class)
            ->latestByTicker($owner->id)['AAPL'];
        $this->assertSame('REGISTERED', $snapshot['lineage_status']);
        $this->assertSame($derived['id'], $snapshot['lineage_node_id']);
        $this->assertSame('SOURCE_RIGHTS_UNVERIFIED', $snapshot['lineage_rights_profile']);
        $this->assertFalse($snapshot['lineage_effective_export']);
    }
}
