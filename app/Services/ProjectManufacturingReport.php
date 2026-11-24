<?php

namespace App\Services;

use App\Models\CatalogMaterial;
use App\Models\PartDefinition;
use App\Models\Project;
use Illuminate\Support\Collection;

class ProjectManufacturingReport
{
    public function __construct(private BoardCuttingPlan $boardCuttingPlan) {}

    private const float DefaultKerf = 3.2;

    private const float DefaultStockLength = 6000.0;

    private const float DefaultSheetLength = 2440.0;

    private const float DefaultSheetWidth = 1220.0;

    private const float DefaultMargin = 10.0;

    /**
     * @return array<string, mixed>
     */
    public function build(Project $project, array $options = []): array
    {
        $settings = $this->settings($options);
        $project->loadMissing('partDefinitions.instances');
        $usedParts = $project->partDefinitions->filter(fn (PartDefinition $part): bool => $part->instances->isNotEmpty());
        $billOfMaterials = $usedParts
            ->map(fn (PartDefinition $part): array => $this->billOfMaterialsRow($part))
            ->sortBy([['material', 'asc'], ['name', 'asc']])
            ->values()
            ->all();
        $materialPlan = $this->materialPlan($project, $usedParts, $settings);

        return [
            'summary' => [
                'unique_part_count' => count($billOfMaterials),
                'instance_count' => $usedParts->sum(fn (PartDefinition $part): int => $part->instances->count()),
                'material_volume_mm3' => round($usedParts->sum(
                    fn (PartDefinition $part): float => $part->length * $part->width * $part->thickness * $part->instances->count(),
                ), 2),
            ],
            'bill_of_materials' => $billOfMaterials,
            'linear_cutting' => $project->material_plan === null
                ? $this->linearCutting($usedParts->reject($this->isSheetPart(...))->values(), $settings)
                : $materialPlan['boards'],
            'sheet_cutting' => $project->material_plan === null
                ? $this->sheetCutting($usedParts->filter($this->isSheetPart(...))->values(), $settings)
                : $materialPlan['sheets'],
            'costing' => $materialPlan['costing'],
            'assumptions' => [
                'kerf_mm' => $settings['kerf'],
                'linear_stock_length_mm' => $settings['stock_length'],
                'sheet_size_mm' => ['length' => $settings['sheet_length'], 'width' => $settings['sheet_width']],
                'edge_margin_mm' => $settings['margin'],
                'note' => 'The cutting map is a preliminary first-fit layout. Confirm stock sizes, grain direction, defects, and machining allowances before production.',
            ],
        ];
    }

    private function materialPlan(Project $project, Collection $parts, array $settings): array
    {
        $plan = $project->material_plan ?? [];
        $assignments = collect($plan['assignments'] ?? [])->pluck('material_id', 'part_id');
        $supplies = collect($plan['supplies'] ?? []);
        $sceneFasteners = $project->projectFasteners()->selectRaw('catalog_material_id, COUNT(*) as quantity')
            ->groupBy('catalog_material_id')->pluck('quantity', 'catalog_material_id');
        $catalogIds = $assignments->values()->merge($supplies->pluck('material_id'))->push($plan['default_material_id'] ?? null)
            ->merge($sceneFasteners->keys())
            ->filter()->unique();
        $catalog = CatalogMaterial::query()->where('user_id', $project->user_id)->whereIn('id', $catalogIds)->get()->keyBy('id');

        $groups = [];
        $issues = [];
        $items = [];
        $boards = [];
        $sheets = [];

        foreach ($parts as $part) {
            $material = $catalog->get($assignments->get($part->id, $plan['default_material_id'] ?? null));
            if ($material === null) {
                $issues[] = $this->partIssue($part, 'unassigned');

                continue;
            }
            if (! $this->matchesStock($part, $material)) {
                $issues[] = $this->partIssue($part, 'incompatible', $material);

                continue;
            }
            $groups[$material->id][] = $part;
        }

        foreach ($groups as $id => $group) {
            $material = $catalog->get($id);
            if ($material->kind === 'board') {
                $stockSettings = array_replace($settings, ['stock_length' => (float) $material->length_mm]);
                $map = $this->boardMap(collect($group), $stockSettings, $material);
                $map['catalog_material_id'] = $id;
                $boards[] = $map;
                $packs = count($map['bars']);
                $used = $packs - array_sum(array_column($map['bars'], 'reusable_area'))
                    / ($stockSettings['stock_length'] * (float) $material->width_mm);
                $unit = 'доска';
            } else {
                $stockSettings = array_replace($settings, ['sheet_length' => (float) $material->length_mm, 'sheet_width' => (float) $material->width_mm]);
                $map = $this->sheetMap(collect($group), $stockSettings, $material->name);
                $map['catalog_material_id'] = $id;
                $sheets[] = $map;
                $packs = count($map['sheets']);
                $used = $packs - array_sum(array_column($map['sheets'], 'reusable_area')) / ($stockSettings['sheet_length'] * $stockSettings['sheet_width']);
                $unit = 'лист';
            }
            if ($packs > 0) {
                $items[] = $this->costItem($material, $unit, $used, $packs, 1);
            }
            if ($map['oversized'] !== []) {
                $oversizedIds = array_column($map['oversized'], 'part_id');
                foreach ($group as $part) {
                    if (in_array($part->id, $oversizedIds, true)) {
                        $issues[] = $this->partIssue($part, 'oversized', $material);
                    }
                }
            }
        }

        $manual = $supplies->pluck('quantity', 'material_id');
        foreach ($manual->keys()->merge($sceneFasteners->keys())->unique() as $id) {
            $material = $catalog->get($id);
            if ($material === null) {
                continue;
            }
            $manualQuantity = (float) $manual->get($id, 0);
            $sceneQuantity = (int) $sceneFasteners->get($id, 0);
            $quantity = $manualQuantity + $sceneQuantity;
            $packSize = (float) $material->pack_quantity;
            $items[] = [
                ...$this->costItem($material, $material->unit, $quantity, (int) ceil(round($quantity / $packSize, 10)), $packSize),
                'manual_quantity' => $manualQuantity,
                'scene_quantity' => $sceneQuantity,
            ];
        }

        return [
            'boards' => $boards,
            'sheets' => $sheets,
            'costing' => [
                'currency' => 'UAH',
                'configured' => $project->material_plan !== null,
                'complete' => $issues === [] && collect($items)->every(fn (array $item): bool => $item['pack_price_minor'] !== null),
                'consumed_minor' => array_sum(array_column($items, 'consumed_minor')),
                'purchase_minor' => array_sum(array_column($items, 'purchase_minor')),
                'items' => $items,
                'issues' => $issues,
            ],
        ];
    }

    private function matchesStock(PartDefinition $part, CatalogMaterial $material): bool
    {
        return (float) $part->thickness === (float) $material->thickness_mm
            && ($material->kind === 'sheet' || (
                $material->kind === 'board' && (float) $part->width <= (float) $material->width_mm
                && $part->grain_axis !== 'width'
            ));
    }

    private function partIssue(PartDefinition $part, string $reason, ?CatalogMaterial $material = null): array
    {
        return ['part_id' => $part->id, 'name' => $part->name, 'quantity' => $part->instances->count(), 'reason' => $reason, 'material' => $material?->name];
    }

    private function costItem(CatalogMaterial $material, string $unit, float $quantity, int $packs, float $packSize): array
    {
        $priceMinor = $material->price === null ? null : (int) round((float) $material->price * 100);

        $consumedMinor = $priceMinor === null ? null : (int) round($quantity / $packSize * $priceMinor);
        $purchaseMinor = $priceMinor === null ? null : $packs * $priceMinor;

        return [
            'material_id' => $material->id,
            'name' => $material->name,
            'kind' => $material->kind,
            'unit' => $unit,
            'quantity' => round($quantity, 3),
            'packs' => $packs,
            'pack_size' => $packSize,
            'pack_price_minor' => $priceMinor,
            'remaining_quantity' => round(max(0, $packs * $packSize - $quantity), 3),
            'consumed_minor' => $consumedMinor,
            'purchase_minor' => $purchaseMinor,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{kerf: float, margin: float, stock_length: float, sheet_length: float, sheet_width: float}
     */
    private function settings(array $options): array
    {
        return [
            'kerf' => (float) ($options['kerf_mm'] ?? self::DefaultKerf),
            'margin' => (float) ($options['edge_margin_mm'] ?? self::DefaultMargin),
            'stock_length' => (float) ($options['linear_stock_length_mm'] ?? self::DefaultStockLength),
            'sheet_length' => (float) ($options['sheet_length_mm'] ?? self::DefaultSheetLength),
            'sheet_width' => (float) ($options['sheet_width_mm'] ?? self::DefaultSheetWidth),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function billOfMaterialsRow(PartDefinition $part): array
    {
        $quantity = $part->instances->count();

        return [
            'part_id' => $part->id,
            'name' => $part->name,
            'material' => $part->material ?: 'Не указан',
            'quantity' => $quantity,
            'dimensions' => [
                'length' => $part->length,
                'width' => $part->width,
                'thickness' => $part->thickness,
            ],
            'grain_axis' => $part->grain_axis,
            'operation_count' => count($part->operations ?? []),
            'total_volume_mm3' => round($part->length * $part->width * $part->thickness * $quantity, 2),
        ];
    }

    private function isSheetPart(PartDefinition $part): bool
    {
        return $part->thickness <= 30 && $part->width >= 150;
    }

    /**
     * @param  Collection<int, PartDefinition>  $parts
     * @return array<int, array<string, mixed>>
     */
    private function linearCutting(Collection $parts, array $settings): array
    {
        return $parts
            ->groupBy(fn (PartDefinition $part): string => implode('|', [
                $part->material ?: 'Не указан',
                $part->width,
                $part->thickness,
            ]))
            ->map(fn (Collection $group): array => $this->linearMap($group, $settings, $group->first()->material ?: 'Не указан'))
            ->values()
            ->all();
    }

    private function linearMap(Collection $group, array $settings, string $material): array
    {
        $firstPart = $group->first();
        [$bars, $oversized] = $this->packLinearPieces($this->linearPieces($group), $settings);

        return [
            'material' => $material,
            'section' => ['width' => $firstPart->width, 'thickness' => $firstPart->thickness],
            'stock_length' => $settings['stock_length'],
            'bars' => $bars,
            'oversized' => $oversized,
        ];
    }

    private function boardMap(Collection $group, array $settings, CatalogMaterial $material): array
    {
        $strips = [];
        $oversized = [];
        foreach ($group->groupBy(fn (PartDefinition $part): string => (string) $part->width)->sortKeys(SORT_NUMERIC) as $width => $parts) {
            [$packed, $tooLong] = $this->packLinearPieces($this->linearPieces($parts), $settings);
            foreach ($packed as $strip) {
                $strips[] = [...$strip, 'width' => (float) $width];
            }
            array_push($oversized, ...$tooLong);
        }

        return [
            'material' => $material->name,
            'section' => ['width' => (float) $material->width_mm, 'thickness' => (float) $material->thickness_mm],
            'stock_length' => $settings['stock_length'],
            'bars' => $this->boardCuttingPlan->pack($strips, $settings['stock_length'], (float) $material->width_mm, $settings['kerf']),
            'oversized' => $oversized,
        ];
    }

    private function linearPieces(Collection $group): array
    {
        $pieces = $group->flatMap(fn (PartDefinition $part): array => collect(range(1, $part->instances->count()))
            ->map(fn (int $piece): array => ['part_id' => $part->id, 'name' => $part->name, 'piece' => $piece, 'length' => $part->length])
            ->all())->sortByDesc('length')->values();

        return $pieces->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $pieces
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function packLinearPieces(array $pieces, array $settings): array
    {
        $bars = [];
        $oversized = [];

        foreach ($pieces as $piece) {
            $required = $piece['length'] + $settings['kerf'];

            if ($required + $settings['margin'] * 2 > $settings['stock_length']) {
                $oversized[] = $piece;

                continue;
            }

            $barIndex = collect($bars)->search(fn (array $bar): bool => $settings['stock_length'] >= $bar['used_length'] + $required + $settings['margin']);

            if ($barIndex === false) {
                $bars[] = ['number' => count($bars) + 1, 'used_length' => $settings['margin'], 'cuts' => []];
                $barIndex = array_key_last($bars);
            }

            $piece['start'] = round($bars[$barIndex]['used_length'], 2);
            $bars[$barIndex]['cuts'][] = $piece;
            $bars[$barIndex]['used_length'] += $required;
        }

        foreach ($bars as &$bar) {
            $bar['used_length'] = round($bar['used_length'] + $settings['margin'], 2);
            $bar['waste_length'] = round($settings['stock_length'] - $bar['used_length'], 2);
        }
        unset($bar);

        return [$bars, $oversized];
    }

    /**
     * @param  Collection<int, PartDefinition>  $parts
     * @return array<int, array<string, mixed>>
     */
    private function sheetCutting(Collection $parts, array $settings): array
    {
        return $parts
            ->groupBy(fn (PartDefinition $part): string => implode('|', [$part->material ?: 'Не указан', $part->thickness]))
            ->map(fn (Collection $group): array => $this->sheetMap($group, $settings, $group->first()->material ?: 'Не указан'))
            ->values()
            ->all();
    }

    private function sheetMap(Collection $group, array $settings, string $material): array
    {
        $pieces = $group->flatMap(fn (PartDefinition $part): array => collect(range(1, $part->instances->count()))
            ->map(fn (int $piece): array => [
                'part_id' => $part->id,
                'name' => $part->name,
                'piece' => $piece,
                'length' => $part->grain_axis === 'width' ? $part->width : $part->length,
                'width' => $part->grain_axis === 'width' ? $part->length : $part->width,
                'rotated' => $part->grain_axis === 'width',
            ])->all())->sortByDesc(fn (array $piece): float => $piece['length'] * $piece['width'])->values();
        [$sheets, $oversized] = $this->packSheetPieces($pieces->all(), $settings);

        return [
            'material' => $material,
            'thickness' => $group->first()->thickness,
            'stock' => ['length' => $settings['sheet_length'], 'width' => $settings['sheet_width']],
            'sheets' => $sheets,
            'oversized' => $oversized,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pieces
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function packSheetPieces(array $pieces, array $settings): array
    {
        $sheets = [];
        $oversized = [];

        foreach ($pieces as $piece) {
            if ($settings['sheet_length'] < $piece['length'] + $settings['margin'] * 2 || $settings['sheet_width'] < $piece['width'] + $settings['margin'] * 2) {
                $oversized[] = $piece;

                continue;
            }

            $placement = null;
            foreach ($sheets as $sheetIndex => &$sheet) {
                $placement = $this->placeOnSheet($sheet, $piece, $settings);

                if ($placement !== null) {
                    $sheets[$sheetIndex]['placements'][] = $placement;
                    break;
                }
            }
            unset($sheet);

            if ($placement === null) {
                $sheets[] = [
                    'number' => count($sheets) + 1,
                    'shelves' => [],
                    'placements' => [],
                ];
                $sheetIndex = array_key_last($sheets);
                $placement = $this->placeOnSheet($sheets[$sheetIndex], $piece, $settings);
                $sheets[$sheetIndex]['placements'][] = $placement;
            }
        }

        foreach ($sheets as &$sheet) {
            $usedArea = collect($sheet['placements'])->sum(fn (array $piece): float => $piece['length'] * $piece['width']);
            $right = $settings['sheet_length'] - $settings['margin'];
            $bottom = $settings['sheet_width'] - $settings['margin'];
            $lastShelf = end($sheet['shelves']);
            $below = max(0, $bottom - $lastShelf['y'] - $lastShelf['height'] - $settings['kerf']);
            $sheet['reusable_area'] = round($below * ($right - $settings['margin']) + collect($sheet['shelves'])->sum(
                fn (array $shelf): float => max(0, $right - $shelf['x']) * $shelf['height'],
            ), 2);
            unset($sheet['shelves']);
            $sheet['used_area'] = round($usedArea, 2);
            $sheet['waste_area'] = round($settings['sheet_length'] * $settings['sheet_width'] - $usedArea, 2);
        }
        unset($sheet);

        return [$sheets, $oversized];
    }

    /**
     * @param  array<string, mixed>  $sheet
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    private function placeOnSheet(array &$sheet, array $piece, array $settings): ?array
    {
        foreach ($sheet['shelves'] as &$shelf) {
            if ($piece['width'] <= $shelf['height'] && $settings['sheet_length'] >= $shelf['x'] + $piece['length'] + $settings['margin']) {
                $placement = [...$piece, 'x' => round($shelf['x'], 2), 'y' => round($shelf['y'], 2)];
                $shelf['x'] += $piece['length'] + $settings['kerf'];

                return $placement;
            }
        }
        unset($shelf);

        $shelfY = $sheet['shelves'] === []
            ? $settings['margin']
            : collect($sheet['shelves'])->max(fn (array $shelf): float => $shelf['y'] + $shelf['height'] + $settings['kerf']);

        if ($shelfY + $piece['width'] + $settings['margin'] > $settings['sheet_width']) {
            return null;
        }

        $sheet['shelves'][] = [
            'x' => $settings['margin'] + $piece['length'] + $settings['kerf'],
            'y' => $shelfY,
            'height' => $piece['width'],
        ];

        return [...$piece, 'x' => $settings['margin'], 'y' => round($shelfY, 2)];
    }
}
