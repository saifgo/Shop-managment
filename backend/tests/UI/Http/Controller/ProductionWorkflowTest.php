<?php

declare(strict_types=1);

namespace App\Tests\UI\Http\Controller;

use App\Infrastructure\Persistence\Entity\Inventory\StockLocation;
use App\Infrastructure\Persistence\Entity\Production\ProductionLossReason;
use App\Infrastructure\Persistence\Entity\Production\ProductionStage;
use App\Tests\Support\AuthenticatedApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Production workflow rules from the blueprint (§8, §19.2): configuration provisioning, stage
 * sequencing, pause/resume, loss reasons, stage flags and the "already in production" projection.
 */
final class ProductionWorkflowTest extends AuthenticatedApiTestCase
{
    public function testFreshCompanyGetsDefaultStagesLossReasonsAndStockLocationOnFirstUse(): void
    {
        // Simulate a deployment where only migrations ran: no stages, no loss reasons, no usable location.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM '.ProductionStage::class)->execute();
        $entityManager->createQuery('DELETE FROM '.ProductionLossReason::class)->execute();
        $entityManager->createQuery('UPDATE '.StockLocation::class." l SET l.isActive = false, l.isDefault = false, l.code = 'OLD'")->execute();

        $token = $this->login()['access_token'];
        $variantId = $this->variantId($token, 'VAS-M');

        $this->request($token, 'POST', '/api/inventory/adjustments', [
            'variant_id' => $variantId,
            'quantity_delta' => '3',
            'reason' => 'Opening count',
        ], 201);

        $production = $this->request($token, 'POST', '/api/productions', [
            'items' => [['variant_id' => $variantId, 'planned_quantity' => '2']],
        ], 201);
        $started = $this->request($token, 'POST', '/api/productions/'.$production['id'].'/start', [], 200);

        self::assertSame(
            ['Preparing Materials', 'Making the Product', 'Refining', 'First Oven', 'Decoration', 'Second Oven', 'Sorting'],
            array_column($started['stages'], 'name'),
        );
        self::assertCount(7, $this->request($token, 'GET', '/api/production/loss-reasons', null, 200)['items']);
        self::assertCount(7, $this->request($token, 'GET', '/api/production/stages', null, 200)['items']);
    }

    public function testStagesRunStrictlyInOrderAndPausedOrdersBlockStageWork(): void
    {
        $token = $this->login()['access_token'];
        $order = $this->startedProduction($token, 'TAG-M', '5');
        [$first, $second] = $order['stages'];
        $base = '/api/productions/'.$order['id'];

        $this->request($token, 'POST', $base.'/stages/'.$second['id'].'/start', [], 400);
        $this->request($token, 'POST', $base.'/stages/'.$first['id'].'/start', [], 200);
        $this->request($token, 'POST', $base.'/stages/'.$second['id'].'/start', [], 400);
        $this->request($token, 'POST', $base.'/stages/'.$first['id'].'/start', [], 400);

        $paused = $this->request($token, 'POST', $base.'/pause', null, 200);
        self::assertTrue($paused['can_resume']);
        $this->request($token, 'POST', $base.'/stages/'.$first['id'].'/complete', ['accepted_output_quantity' => '5'], 400);

        $resumed = $this->request($token, 'POST', $base.'/resume', null, 200);
        self::assertSame('IN_PROGRESS', $resumed['status']);
        $this->request($token, 'POST', $base.'/stages/'.$first['id'].'/complete', ['accepted_output_quantity' => '5'], 200);

        // A later stage cannot take in more than the previous one accepted.
        $itemId = $order['items'][0]['id'];
        $this->request($token, 'POST', $base.'/stages/'.$second['id'].'/start', [
            'items' => [['item_id' => $itemId, 'input_quantity' => '6']],
        ], 400);
        $this->request($token, 'POST', $base.'/stages/'.$second['id'].'/start', [], 200);
    }

    public function testLossesRequireAnActiveConfiguredReason(): void
    {
        $token = $this->login()['access_token'];
        $order = $this->startedProduction($token, 'TAG-M', '10');
        $stageId = $order['stages'][0]['id'];
        $base = '/api/productions/'.$order['id'].'/stages/'.$stageId;
        $this->request($token, 'POST', $base.'/start', [], 200);

        $this->request($token, 'POST', $base.'/complete', ['accepted_output_quantity' => '8', 'loss_quantity' => '2'], 400);
        $this->request($token, 'POST', $base.'/complete', [
            'accepted_output_quantity' => '8',
            'loss_quantity' => '2',
            'losses' => [['reason_code' => 'NOT_A_REASON', 'quantity' => '2']],
        ], 400);
        $this->request($token, 'POST', $base.'/complete', [
            'accepted_output_quantity' => '8',
            'loss_quantity' => '2',
            'losses' => [['reason_code' => 'CRACKS', 'quantity' => '1']],
        ], 400);

        $done = $this->request($token, 'POST', $base.'/complete', [
            'accepted_output_quantity' => '8',
            'loss_quantity' => '2',
            'losses' => [['reason_code' => 'CRACKS', 'quantity' => '1'], ['reason_code' => 'BROKEN', 'quantity' => '1']],
        ], 200);

        $line = $done['stages'][0]['lines'][0];
        self::assertSame(['Cracks', 'Broken / unusable'], array_column($line['losses'], 'reason_label'));
        self::assertNotNull($done['stages'][0]['performed_by_name']);
        self::assertSame('8.0000', $done['items'][0]['in_process_quantity']);
    }

    public function testStageFlagsAreEnforced(): void
    {
        $token = $this->login()['access_token'];
        $stages = $this->request($token, 'GET', '/api/production/stages', null, 200)['items'];
        $this->request($token, 'PATCH', '/api/production/stages/'.$stages[0]['id'], ['can_record_loss' => false], 200);
        $this->request($token, 'PATCH', '/api/production/stages/'.$stages[1]['id'], ['can_record_quantity' => false], 200);

        $order = $this->startedProduction($token, 'TAG-M', '4');
        $base = '/api/productions/'.$order['id'];
        $first = $order['stages'][0]['id'];
        $second = $order['stages'][1]['id'];

        $this->request($token, 'POST', $base.'/stages/'.$first.'/start', [], 200);
        $this->request($token, 'POST', $base.'/stages/'.$first.'/complete', [
            'accepted_output_quantity' => '3',
            'loss_quantity' => '1',
            'losses' => [['reason_code' => 'CRACKS', 'quantity' => '1']],
        ], 400);
        $this->request($token, 'POST', $base.'/stages/'.$first.'/complete', ['accepted_output_quantity' => '4'], 200);

        // A stage that does not record quantities passes everything through.
        $this->request($token, 'POST', $base.'/stages/'.$second.'/start', [], 200);
        $done = $this->request($token, 'POST', $base.'/stages/'.$second.'/complete', [], 200);
        self::assertSame('4.0000', $done['stages'][1]['accepted_output_quantity']);
        self::assertSame('0.0000', $done['stages'][1]['loss_quantity']);
    }

    public function testPlanTransitionAndWorkflowConfiguration(): void
    {
        $token = $this->login()['access_token'];
        $variantId = $this->variantId($token, 'BWL-4');

        $draft = $this->request($token, 'POST', '/api/productions', [
            'items' => [['variant_id' => $variantId, 'planned_quantity' => '3']],
        ], 201);
        self::assertTrue($draft['can_plan']);
        $planned = $this->request($token, 'POST', '/api/productions/'.$draft['id'].'/plan', null, 200);
        self::assertSame('PLANNED', $planned['status']);
        $this->request($token, 'POST', '/api/productions/'.$draft['id'].'/plan', null, 400);

        // Loss reasons: add, then deactivate so it can no longer be used.
        $reason = $this->request($token, 'POST', '/api/production/loss-reasons', ['label' => 'Glaze defect'], 201);
        self::assertSame('GLAZE_DEFECT', $reason['code']);
        $this->request($token, 'POST', '/api/production/loss-reasons', ['label' => 'Glaze defect'], 400);
        $this->request($token, 'PATCH', '/api/production/loss-reasons/'.$reason['id'], ['is_active' => false], 200);

        // Stages: add one, reorder, and never deactivate the last active stage.
        $added = $this->request($token, 'POST', '/api/production/stages', ['name' => 'Packing', 'reconciliation_mode' => 'FLEXIBLE'], 201);
        self::assertSame(8, $added['sequence']);
        $stages = $this->request($token, 'GET', '/api/production/stages', null, 200)['items'];
        $reversed = array_reverse(array_column($stages, 'id'));
        $reordered = $this->request($token, 'PUT', '/api/production/stages/order', ['stage_ids' => $reversed], 200)['items'];
        self::assertSame('Packing', $reordered[0]['name']);
        self::assertSame(1, $reordered[0]['sequence']);

        foreach (array_slice($reordered, 1) as $stage) {
            $this->request($token, 'PATCH', '/api/production/stages/'.$stage['id'], ['is_active' => false], 200);
        }
        $this->request($token, 'PATCH', '/api/production/stages/'.$reordered[0]['id'], ['is_active' => false], 400);

        $started = $this->request($token, 'POST', '/api/productions/'.$draft['id'].'/start', null, 200);
        self::assertSame(['Packing'], array_column($started['stages'], 'name'));

        $execution = $started['stages'][0];
        $base = '/api/productions/'.$draft['id'].'/stages/'.$execution['id'];
        $this->request($token, 'POST', $base.'/start', [], 200);
        $this->request($token, 'POST', $base.'/complete', [
            'accepted_output_quantity' => '2',
            'loss_quantity' => '1',
            'losses' => [['reason_code' => 'GLAZE_DEFECT', 'quantity' => '1']],
        ], 400);
    }

    public function testAlreadyInProductionFollowsStageLosses(): void
    {
        $token = $this->login()['access_token'];
        $variantId = $this->variantId($token, 'TAG-L');
        $order = $this->startedProduction($token, 'TAG-L', '10');
        self::assertSame('10.0000', $this->availability($token, $variantId)['already_in_production']);

        $base = '/api/productions/'.$order['id'].'/stages/'.$order['stages'][0]['id'];
        $this->request($token, 'POST', $base.'/start', [], 200);
        $this->request($token, 'POST', $base.'/complete', [
            'accepted_output_quantity' => '7',
            'loss_quantity' => '3',
            'losses' => [['reason_code' => 'BROKEN', 'quantity' => '3']],
        ], 200);

        self::assertSame('7.0000', $this->availability($token, $variantId)['already_in_production']);

        $this->request($token, 'POST', '/api/productions/'.$order['id'].'/cancel', ['reason' => 'Kiln down'], 200);
        self::assertSame('0.0000', $this->availability($token, $variantId)['already_in_production']);
    }

    /** @return array<string, mixed> */
    private function startedProduction(string $token, string $sku, string $quantity): array
    {
        $production = $this->request($token, 'POST', '/api/productions', [
            'items' => [['variant_id' => $this->variantId($token, $sku), 'planned_quantity' => $quantity]],
            'plan' => true,
        ], 201);

        return $this->request($token, 'POST', '/api/productions/'.$production['id'].'/start', null, 200);
    }

    /** @return array<string, mixed> */
    private function availability(string $token, string $variantId): array
    {
        return $this->request($token, 'GET', '/api/inventory/availability?variant_id='.$variantId, null, 200);
    }

    private function variantId(string $token, string $sku): string
    {
        $products = $this->request($token, 'GET', '/api/products?per_page=100', null, 200);

        foreach ($products['items'] as $product) {
            foreach ($this->request($token, 'GET', '/api/products/'.$product['id'].'/variants', null, 200)['items'] as $variant) {
                if ($variant['sku'] === $sku) {
                    return $variant['id'];
                }
            }
        }

        self::fail(sprintf('Variant with SKU %s not found.', $sku));
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $token, string $method, string $path, ?array $body, int $expectedStatus): array
    {
        $client = static::createClient();
        $client->request(
            $method,
            $path,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body === null ? null : json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame($expectedStatus, (string) $client->getResponse()->getContent());

        return json_decode($client->getResponse()->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR) ?? [];
    }
}
