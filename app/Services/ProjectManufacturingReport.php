<?php

namespace App\Services;

use App\Models\PartDefinition;
use App\Models\Project;
use Illuminate\Support\Collection;

class ProjectManufacturingReport
{
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

        return [
            'summary' => [
                'unique_part_count' => count($billOfMaterials),
                'instance_count' => $usedParts->sum(fn (PartDefinition $part): int => $part->instances->count()),
                'material_volume_mm3' => round($usedParts->sum(
                    fn (PartDefinition $part): float => $part->length * $part->width * $part->thickness * $part->instances->count(),
                ), 2),
            ],
            'bill_of_materials' => $billOfMaterials,
            'linear_cutting' => $this->linearCutting($usedParts->reject($this->isSheetPart(...))->values(), $settings),
            'sheet_cutting' => $this->sheetCutting($usedParts->filter($this->isSheetPart(...))->values(), $settings),
            'assumptions' => [
                'kerf_mm' => $settings['kerf'],
                'linear_stock_length_mm' => $settings['stock_length'],
                'sheet_size_mm' => ['length' => $settings['sheet_length'], 'width' => $settings['sheet_width']],
                'edge_margin_mm' => $settings['margin'],
                'note' => 'The cutting map is a preliminary first-fit layout. Confirm stock sizes, grain direction, defects, and machining allowances before production.',
            ],
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
            ->map(function (Collection $group) use ($settings): array {
                $firstPart = $group->first();
                $pieces = $group->flatMap(function (PartDefinition $part): array {
                    return collect(range(1, $part->instances->count()))
                        ->map(fn (int $piece): array => [
                            'part_id' => $part->id,
                            'name' => $part->name,
                            'piece' => $piece,
                            'length' => $part->length,
                        ])
                        ->all();
                })->sortByDesc('length')->values();
                [$bars, $oversized] = $this->packLinearPieces($pieces->all(), $settings);

                return [
                    'material' => $firstPart->material ?: 'Не указан',
                    'section' => ['width' => $firstPart->width, 'thickness' => $firstPart->thickness],
                    'stock_length' => $settings['stock_length'],
                    'bars' => $bars,
                    'oversized' => $oversized,
                ];
            })
            ->values()
            ->all();
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
            ->map(function (Collection $group) use ($settings): array {
                $firstPart = $group->first();
                $pieces = $group->flatMap(function (PartDefinition $part): array {
                    return collect(range(1, $part->instances->count()))
                        ->map(fn (int $piece): array => [
                            'part_id' => $part->id,
                            'name' => $part->name,
                            'piece' => $piece,
                            'length' => $part->grain_axis === 'width' ? $part->width : $part->length,
                            'width' => $part->grain_axis === 'width' ? $part->length : $part->width,
                            'rotated' => $part->grain_axis === 'width',
                        ])
                        ->all();
                })->sortByDesc(fn (array $piece): float => $piece['length'] * $piece['width'])->values();
                [$sheets, $oversized] = $this->packSheetPieces($pieces->all(), $settings);

                return [
                    'material' => $firstPart->material ?: 'Не указан',
                    'thickness' => $firstPart->thickness,
                    'stock' => ['length' => $settings['sheet_length'], 'width' => $settings['sheet_width']],
                    'sheets' => $sheets,
                    'oversized' => $oversized,
                ];
            })
            ->values()
            ->all();
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
