<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LocalLineageRegistry;
use App\Services\SyntheticReportLineageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SyntheticReportLineageTest extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        return $user;
    }

    public function test_workspace_download_has_verified_owner_only_report_ancestry_and_content_hash(): void
    {
        $owner = $this->analyst();
        $this->actingAs($owner);
        $response = $this->get('/ii/workspace/report?ticker=DEMO2')->assertOk();
        $response->assertHeader('X-II-Lineage-Status', 'VERIFIED_SYNTHETIC_RESEARCH_ONLY');
        $nodeId = $response->headers->get('X-II-Lineage-Node');
        $this->assertNotEmpty($nodeId);
        $this->assertSame(hash('sha256', $response->getContent()), $response->headers->get('X-II-Content-SHA256'));
        $this->assertStringContainsString('SYNTHETIC DEMONSTRATION ONLY', $response->getContent());

        $registry = app(LocalLineageRegistry::class);
        $this->assertTrue($registry->verifyTrace($owner->id, $nodeId));
        $trace = $registry->trace($owner->id, $nodeId);
        $this->assertCount(2, $trace['nodes']);
        $this->assertCount(1, $trace['edges']);
        $this->assertFalse($registry->allows($owner->id, $nodeId, 'export'));
        $this->assertFalse($registry->allows($owner->id, $nodeId, 'cloud'));
        $this->assertDatabaseCount('ii_lineage_nodes', 2);

        $repeated = $this->get('/ii/workspace/report?ticker=DEMO2')->assertOk();
        $this->assertSame($nodeId, $repeated->headers->get('X-II-Lineage-Node'));
        $this->assertDatabaseCount('ii_lineage_nodes', 2);
        $other = $this->analyst();
        $this->assertFalse($registry->verifyTrace($other->id, $nodeId));
        $this->actingAs($other);
        $this->get('/ii/workspace/report?ticker=DEMO2')->assertOk();
        $this->assertDatabaseCount('ii_lineage_nodes', 4);
    }

    public function test_review_notes_create_new_report_version_without_changing_fixture_claim(): void
    {
        $owner = $this->analyst();
        $this->actingAs($owner);
        $original = $this->get('/ii/workspace/report?ticker=DEMO2')->assertOk();
        $firstId = $original->headers->get('X-II-Lineage-Node');
        $this->post('/ii/workspace/note', [
            'ticker' => 'DEMO2',
            'finding' => 'Synthetic fixture review note v2',
            'review_status' => 'OPEN',
        ])->assertRedirect();

        $new = $this->get('/ii/workspace/report?ticker=DEMO2')->assertOk();
        $this->assertNotSame($firstId, $new->headers->get('X-II-Lineage-Node'));
        $this->assertStringContainsString('Synthetic fixture review note v2', $new->getContent());
        $this->assertSame(hash('sha256', $new->getContent()), $new->headers->get('X-II-Content-SHA256'));
        $this->assertDatabaseCount('ii_lineage_nodes', 3);
        $newTrace = app(LocalLineageRegistry::class)->trace($owner->id, $new->headers->get('X-II-Lineage-Node'));
        $this->assertCount(2, $newTrace['nodes']);
    }

    public function test_case_download_has_distinct_report_node_and_rejects_other_account_access(): void
    {
        $owner = $this->analyst();
        $this->actingAs($owner);
        $this->post('/ii/cases', [
            'ticker' => 'DEMO3',
            'title' => 'Synthetic case lineage example',
        ])->assertRedirect();
        $id = (string) DB::table('ii_vs5_cases')->where('ii_created_by', $owner->id)->value('id');
        $this->assertNotSame('', $id);
        $response = $this->get('/ii/cases/'.$id.'/report')->assertOk();
        $response->assertHeader('X-II-Lineage-Status', 'VERIFIED_SYNTHETIC_RESEARCH_ONLY');
        $nodeId = $response->headers->get('X-II-Lineage-Node');
        $this->assertTrue(app(LocalLineageRegistry::class)->verifyTrace($owner->id, $nodeId));
        $this->assertSame(hash('sha256', $response->getContent()), $response->headers->get('X-II-Content-SHA256'));
        $other = $this->analyst();
        $this->actingAs($other)
            ->get('/ii/cases/'.$id.'/report')->assertNotFound();
        $this->assertFalse(app(LocalLineageRegistry::class)->verifyTrace($other->id, $nodeId));
    }

    public function test_external_or_restricted_research_cannot_be_disguised_as_synthetic_report(): void
    {
        $owner = $this->analyst();
        $service = app(SyntheticReportLineageService::class);
        $base = [
            'ticker' => 'DEMO2', 'history' => [],
            'admission' => 'NOT_ADMITTED', 'effective_decision' => 'HOLD',
            'authority' => 'NONE', 'valuation_authorized' => false,
            'trading_authorized' => false,
            'provenance' => ['source_type' => 'GENERATED_DEMO', 'source_id' => 'generated-fixture-demo'],
        ];
        foreach ([
            array_replace_recursive($base, ['provenance' => ['source_type' => 'LIVE_PROVIDER']]),
            array_merge($base, ['source_name' => 'MarketData.app BYOK']),
            array_merge($base, ['trading_authorized' => true]),
        ] as $invalid) {
            try {
                $service->attest($owner->id, 'WORKSPACE_VS4', $invalid, '# Report');
                $this->fail('Restricted or non-synthetic report must not be registered.');
            } catch (\RuntimeException $e) {
                $this->assertSame('II_REPORT_SYNTHETIC_BOUNDARY_DENIED', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('ii_lineage_nodes', 0);
    }

    public function test_tampered_synthetic_source_blocks_report_download_before_response(): void
    {
        $owner = $this->analyst();
        $this->actingAs($owner);
        $this->get('/ii/workspace/report?ticker=DEMO2')->assertOk();
        $sourceId = DB::table('ii_lineage_nodes')->where('user_id', $owner->id)
            ->where('kind', 'SOURCE')->value('id');
        DB::table('ii_lineage_nodes')->where('id', $sourceId)->update(['can_cloud_transfer' => true]);
        $this->get('/ii/workspace/report?ticker=DEMO2')->assertStatus(500);
        $this->assertDatabaseCount('ii_lineage_nodes', 2);
    }
}
