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

<x-noerd::page>
    <x-slot:header>
        <x-noerd::modal-title>{{ __('Select target folder') }}</x-noerd::modal-title>
    </x-slot:header>

    <div class="py-6 space-y-2">
        <button type="button"
                wire:click="selectFolder(null)"
                @class([
                    'w-full text-left px-3 py-2 rounded border',
                    'bg-blue-50 border-blue-500' => $selectedFolderId === null,
                    'bg-white border-gray-200 hover:bg-gray-50' => $selectedFolderId !== null,
                ])>
            {{ __('Media Library') }}
        </button>

        @if(count($tree) > 0)
            <div class="border rounded p-2">
                @include('media::components.partials.folder-tree-node', ['nodes' => $tree, 'depth' => 0])
            </div>
        @endif
    </div>

    <x-slot name="footer">
        @php($isDisabled = count($mediaIds) === 0)
        <div class="ml-auto flex gap-2">
            <x-noerd::button variant="ghost" wire:click="$dispatch('closeTopModal')">
                {{ __('Cancel') }}
            </x-noerd::button>

            <x-noerd::button
                wire:click="confirm"
                :class="$isDisabled ? 'opacity-50 cursor-not-allowed pointer-events-none' : ''">
                {{ __('Move to folder') }}
            </x-noerd::button>
        </div>
    </x-slot>
</x-noerd::page>
