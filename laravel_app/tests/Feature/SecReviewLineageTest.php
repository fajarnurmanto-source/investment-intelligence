<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LocalLineageRegistry;
use App\Services\SecReviewLineageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecReviewLineageTest extends TestCase
{
    use RefreshDatabase;

    private function normalized(): array
    {
        return [
            'cik' => '0000320193',
            'source_url' => 'https://data.sec.gov/api/xbrl/companyfacts/CIK0000320193.json',
            'source_sha256' => hash('sha256', 'synthetic SEC test payload'),
            'requires_user_reconciliation' => true,
            'external_fetch_performed' => false,
            'periods' => [['fiscal_year' => 2024]],
        ];
    }

    public function test_same_review_csv_is_idempotent_and_changed_csv_gets_new_transform(): void
    {
        $owner = User::factory()->create();
        $service = app(SecReviewLineageService::class);
        $first = $service->attest($owner->id, $this->normalized(), "fiscal_year,revenue\n2024,100\n");
        $same = $service->attest($owner->id, $this->normalized(), "fiscal_year,revenue\n2024,100\n");
        $this->assertSame($first['node_id'], $same['node_id']);
        $this->assertSame('USER_UPLOAD_REVIEW_REQUIRED', $first['status']);
        $this->assertDatabaseCount('ii_lineage_nodes', 2);

        $revised = $service->attest($owner->id, $this->normalized(), "fiscal_year,revenue\n2024,101\n");
        $this->assertNotSame($first['node_id'], $revised['node_id']);
        $this->assertDatabaseCount('ii_lineage_nodes', 3);

        $registry = app(LocalLineageRegistry::class);
        $this->assertCount(2, $registry->trace($owner->id, $first['node_id'])['nodes']);
        $this->assertFalse($registry->allows($owner->id, $first['node_id'], 'export'));
        $other = User::factory()->create();
        $this->assertFalse($registry->verifyTrace($other->id, $first['node_id']));
        foreach (DB::table('ii_lineage_nodes')->get() as $node) {
            $this->assertStringNotContainsString('2024,100', $node->source_metadata);
            $this->assertStringNotContainsString('contact@example.com', $node->source_metadata);
        }
    }

    public function test_invalid_source_and_tampered_ancestor_fail_before_review_csv_is_certified(): void
    {
        $owner = User::factory()->create();
        $service = app(SecReviewLineageService::class);
        foreach ([
            ['source_url' => 'https://data.sec.gov.evil.example/CIK0000320193.json'],
            ['cik' => 'not-a-cik'],
            ['requires_user_reconciliation' => false],
            ['periods' => []],
        ] as $changes) {
            try {
                $service->attest($owner->id, array_replace($this->normalized(), $changes), 'csv');
                $this->fail('Bad SEC origin or reconciliation must fail.');
            } catch (\RuntimeException $e) {
                $this->assertSame('II_SEC_REVIEW_SOURCE_BOUNDARY_DENIED', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('ii_lineage_nodes', 0);

        $service->attest($owner->id, $this->normalized(), 'valid csv');
        $sourceId = DB::table('ii_lineage_nodes')->where('kind', 'SOURCE')->value('id');
        DB::table('ii_lineage_nodes')->where('id', $sourceId)->update(['can_export' => true]);
        try {
            $service->attest($owner->id, $this->normalized(), 'another report');
            $this->fail('Tampered SEC source must never produce a report node.');
        } catch (\RuntimeException $e) {
            $this->assertSame('II_SEC_REVIEW_SOURCE_INTEGRITY_FAILED', $e->getMessage());
        }
        $this->assertDatabaseCount('ii_lineage_nodes', 2);
    }
}
