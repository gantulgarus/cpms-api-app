<?php

namespace App\Services;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Гүйцэтгэлийн акт — гүйцэтгэгч × хугацааны хооронд БАТЛАГДСАН ажил.
 *
 * ЯАГААД ШАЛГАЛТААС ГАРНА, АЖЛААС БИШ: акт нь «энэ сард юу хүлээж авав»
 * гэсэн баримт. Ажлын мөр дээрх хуримтлагдсан `accepted_qty` нь хэзээ
 * батлагдсаныг хэлдэггүй тул хугацаагаар таслах боломжгүй. Шалгалтын
 * бичлэг бүр огноо, шалгасан хүнтэй — актад яг тэр хэрэгтэй.
 *
 * НЭГЖ ХОЛИХГҮЙ: м², м³, ш-ийг нэг «нийт» болгож нэмэх нь утгагүй. Дүнг
 * НЭГЖ ТУС БҮРЭЭР гаргана. Барилгын тооцоонд энэ нь албан ёсны шаардлага.
 */
class AcceptanceReport
{
    public function __construct(private readonly WorkScope $scope) {}

    /**
     * @return array{
     *   contractor: array{id: string, name: string}|null,
     *   period: array{from: string, to: string},
     *   stage: string,
     *   groups: array<int, array>,
     *   totals: array<int, array{unit: string, qty: float}>,
     *   workItemCount: int,
     *   inspectionCount: int,
     * }
     */
    public function build(
        Project $project,
        ?User $user,
        ?string $contractorId,
        string $from,
        string $to,
        string $stage = 'client',
        ?string $blockId = null,
    ): array {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->endOfDay();

        $query = DB::table('inspections as i')
            ->join('work_items as wi', 'wi.id', '=', 'i.work_item_id')
            ->join('blocks as b', 'b.id', '=', 'wi.block_id')
            ->join('locations as l', 'l.id', '=', 'wi.location_id')
            ->join('work_types as wt', 'wt.id', '=', 'wi.work_type_id')
            ->leftJoin('work_type_groups as g', 'g.id', '=', 'wt.work_type_group_id')
            ->leftJoin('users as u', 'u.id', '=', 'i.inspector_id')
            ->where('b.project_id', $project->id)
            ->where('i.stage', $stage)
            // Батлагдаагүй шалгалт актад орохгүй — татгалзал нь акт биш.
            ->where('i.accepted_qty', '>', 0)
            ->whereBetween('i.inspected_at', [$fromDate, $toDate])
            ->when($contractorId, fn ($q) => $q->where('wi.contractor_id', $contractorId))
            ->when($blockId, fn ($q) => $q->where('wi.block_id', $blockId));

        // Хамрах хүрээ — гүйцэтгэгч зөвхөн ӨӨРИЙН актыг гаргана.
        $this->scope->rawWorkItems($query, $user);

        $rows = $query
            ->select([
                'i.id as inspection_id',
                'i.accepted_qty',
                'i.inspected_at',
                'b.name as block_name',
                'l.path as location_path',
                'wt.name as work_type_name',
                'wi.unit',
                'wi.id as work_item_id',
                'u.name as inspector_name',
                'g.name as group_name',
                'g.build_order',
            ])
            ->orderBy('g.build_order')
            ->orderBy('g.name')
            ->orderBy('wi.unit')
            ->orderBy('b.name')
            ->orderBy('l.path_key')
            ->orderBy('i.inspected_at')
            ->get();

        $groups = [];
        $totals = [];
        $workItems = [];

        foreach ($rows as $r) {
            $groupName = $r->group_name ?: 'Бусад';
            $qty = round((float) $r->accepted_qty, 3);

            $groups[$groupName]['name'] = $groupName;
            $groups[$groupName]['rows'][] = [
                'inspectionId' => $r->inspection_id,
                'workItemId' => $r->work_item_id,
                'blockName' => $r->block_name,
                'locationPath' => $r->location_path,
                'workTypeName' => $r->work_type_name,
                'unit' => $r->unit,
                'acceptedQty' => $qty,
                'inspectedAt' => Carbon::parse($r->inspected_at)->toIso8601String(),
                'inspectorName' => $r->inspector_name,
            ];

            $groups[$groupName]['totals'][$r->unit] = round(
                ($groups[$groupName]['totals'][$r->unit] ?? 0) + $qty,
                3
            );
            $totals[$r->unit] = round(($totals[$r->unit] ?? 0) + $qty, 3);
            $workItems[$r->work_item_id] = true;
        }

        $contractor = $contractorId ? Contractor::find($contractorId) : null;

        return [
            'contractor' => $contractor ? ['id' => $contractor->id, 'name' => $contractor->name] : null,
            'project' => ['id' => $project->id, 'name' => $project->name],
            'period' => ['from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()],
            'stage' => $stage,
            'groups' => array_values(array_map(
                fn ($g) => [
                    'name' => $g['name'],
                    'rows' => $g['rows'],
                    'totals' => self::unitTotals($g['totals']),
                ],
                $groups,
            )),
            'totals' => self::unitTotals($totals),
            'workItemCount' => count($workItems),
            'inspectionCount' => $rows->count(),
        ];
    }

    /** @param  array<string, float>  $map */
    private static function unitTotals(array $map): array
    {
        ksort($map);

        return array_map(fn ($unit, $qty) => ['unit' => $unit, 'qty' => $qty], array_keys($map), $map);
    }
}
