<?php

namespace App\Actions\AssemblyGroups;

use App\Models\AssemblyGroup;
use App\Models\Project;

class DeleteAssemblyGroup
{
    public function handle(Project $project, AssemblyGroup $assemblyGroup, bool $deleteContents): void
    {
        if ($deleteContents) {
            $groupIds = $this->descendantIds($project, $assemblyGroup);
            $project->partInstances()->whereIn('assembly_group_id', $groupIds)->delete();
        } else {
            $assemblyGroup->children()->update(['parent_id' => $assemblyGroup->parent_id]);
            $assemblyGroup->instances()->update(['assembly_group_id' => $assemblyGroup->parent_id]);
        }

        $assemblyGroup->delete();
    }

    /**
     * @return array<int, int>
     */
    private function descendantIds(Project $project, AssemblyGroup $root): array
    {
        $childrenByParent = $project->assemblyGroups()
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');
        $groupIds = [];
        $pending = [$root->id];

        while ($pending !== []) {
            $groupId = array_pop($pending);
            $groupIds[] = $groupId;

            foreach ($childrenByParent->get($groupId, collect()) as $child) {
                $pending[] = $child->id;
            }
        }

        return $groupIds;
    }
}
