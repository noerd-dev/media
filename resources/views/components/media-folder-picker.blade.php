<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Noerd\Media\Models\MediaFolder;

new class extends Component {
    public bool $disableModal = false;

    /** @var array<int, int> */
    public array $mediaIds = [];

    public ?int $selectedFolderId = null;

    public function mount(array $mediaIds = []): void
    {
        $this->mediaIds = array_values(array_filter(array_map('intval', $mediaIds)));
    }

    public function selectFolder(?int $folderId): void
    {
        $this->selectedFolderId = $folderId;
    }

    public function confirm(): void
    {
        $this->dispatch('mediaFolderPicked', mediaIds: $this->mediaIds, folderId: $this->selectedFolderId);
        $this->dispatch('closeTopModal');
    }

    public function with(): array
    {
        $folders = MediaFolder::where('tenant_id', Auth::user()->selected_tenant_id)
            ->orderBy('name')
            ->get();

        $tree = [];
        $byParent = [];
        foreach ($folders as $folder) {
            $byParent[$folder->parent_id ?? 0][] = $folder;
        }

        $build = function (?int $parentId) use (&$build, $byParent): array {
            $nodes = [];
            foreach ($byParent[$parentId ?? 0] ?? [] as $folder) {
                $nodes[] = [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'children' => $build($folder->id),
                ];
            }
            return $nodes;
        };

        $tree = $build(null);

        return [
            'tree' => $tree,
        ];
    }
} ?>

<x-noerd::page :disableModal="$disableModal">
    <x-slot:header>
        <x-noerd::modal-title>{{ __('media_select_target_folder') }}</x-noerd::modal-title>
    </x-slot:header>

    <div class="mt-4 space-y-2">
        <button type="button"
                wire:click="selectFolder(null)"
                @class([
                    'w-full text-left px-3 py-2 rounded border',
                    'bg-blue-50 border-blue-500' => $selectedFolderId === null,
                    'bg-white border-gray-200 hover:bg-gray-50' => $selectedFolderId !== null,
                ])>
            {{ __('media_label_root') }}
        </button>

        @if(count($tree) > 0)
            <div class="border rounded p-2">
                @include('media::components.partials.folder-tree-node', ['nodes' => $tree, 'depth' => 0])
            </div>
        @endif
    </div>

    <div class="mt-6 flex items-center justify-end gap-2">
        <button type="button"
                wire:click="$dispatch('closeTopModal')"
                class="text-sm border px-3 py-1 rounded bg-white hover:bg-gray-50">
            {{ __('Cancel') }}
        </button>
        <button type="button"
                wire:click="confirm"
                @class([
                    'text-sm border px-3 py-1 rounded bg-brand-primary text-white hover:opacity-90',
                    'opacity-50 cursor-not-allowed' => count($mediaIds) === 0,
                ])
                @disabled(count($mediaIds) === 0)>
            {{ __('media_move_to_folder') }}
        </button>
    </div>
</x-noerd::page>
