<?php

namespace App\Services;

class BoardCuttingPlan
{
    private const float Tolerance = 0.000001;

    /**
     * @param  array<int, array<string, mixed>>  $strips
     * @return array<int, array<string, mixed>>
     */
    public function pack(array $strips, float $length, float $width, float $kerf): array
    {
        usort($strips, fn (array $a, array $b): int => $b['width'] <=> $a['width']);
        $boards = [];

        foreach ($strips as $strip) {
            $boardIndex = null;
            foreach ($boards as $index => $board) {
                if ($width + self::Tolerance >= $board['next_offset'] + $strip['width']) {
                    $boardIndex = $index;
                    break;
                }
            }
            if ($boardIndex === null) {
                $boards[] = ['number' => count($boards) + 1, 'next_offset' => 0.0, 'strips' => [], 'rip_cuts' => [], 'offcuts' => [], 'cuts' => []];
                $boardIndex = array_key_last($boards);
            }

            $board = &$boards[$boardIndex];
            $offset = $board['next_offset'];
            $stripNumber = count($board['strips']) + 1;
            $edge = $offset + $strip['width'];
            $board['next_offset'] = min($width, $edge + $kerf);
            if ($edge < $width - self::Tolerance) {
                $board['rip_cuts'][] = ['position' => round($edge, 4), 'strip' => $stripNumber];
            }

            $strip['number'] = $stripNumber;
            $strip['offset'] = round($offset, 4);
            $board['strips'][] = $strip;
            foreach ($strip['cuts'] as $cut) {
                $board['cuts'][] = [...$cut, 'strip' => $stripNumber, 'strip_width' => $strip['width'], 'strip_offset' => $strip['offset']];
            }
            if ($strip['waste_length'] > self::Tolerance) {
                $board['offcuts'][] = ['start' => $strip['used_length'], 'offset' => $strip['offset'], 'length' => $strip['waste_length'], 'width' => $strip['width']];
            }
            unset($board);
        }

        foreach ($boards as &$board) {
            $remainingWidth = $width - $board['next_offset'];
            if ($remainingWidth > self::Tolerance) {
                $board['offcuts'][] = ['start' => 0, 'offset' => round($board['next_offset'], 4), 'length' => $length, 'width' => round($remainingWidth, 4)];
            }
            $reusableArea = array_sum(array_map(fn (array $offcut): float => $offcut['length'] * $offcut['width'], $board['offcuts']));
            $board['reusable_area'] = round($reusableArea, 2);
            $board['waste_length'] = round($reusableArea / $width, 2);
            $board['used_length'] = round($length - $reusableArea / $width, 2);
            unset($board['next_offset']);
        }
        unset($board);

        return $boards;
    }
}
