<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Noerd\Facades\Noerd;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;
use Noerd\Media\Models\MediaTag;
use Noerd\Media\Services\MediaUploadService;
use Noerd\Traits\NoerdList;

new class () extends Component {
    use NoerdList;

    public $detailComponent = 'media::media-detail';

    public array $files = [];
    public ?Media $selected = null;
    public array $filterTagIds = [];
    public bool $hideDetail = false;
    public bool $selectMode = false;
    public ?string $selectContext = null;
    public ?string $selectToken = null;
    public array $selectedMediaIds = [];

    #[Url(as: 'folder', except: null)]
    public ?int $currentFolderId = null;

    public function mount(): void
    {
        $this->mountList();

        // Support selectAction from input-relation component
        if ($this->listActionMethod === 'selectAction') {
            $this->selectMode = true;
        }

        // Drop an invalid folder id coming from the URL (deleted folder or foreign tenant)
        if ($this->currentFolderId !== null) {
            $folderExists = MediaFolder::where('tenant_id', Auth::user()->selected_tenant_id)
                ->whereKey($this->currentFolderId)
                ->exists();

            if (! $folderExists) {
                $this->currentFolderId = null;
            }
        }
    }

    public function with(): array
    {
        $hasActiveFilters = $this->search !== ''
            || count($this->filterTagIds) > 0
            || collect($this->listFilters)->filter()->isNotEmpty();

        $baseQuery = Media::where('tenant_id', Auth::user()->selected_tenant_id)
            ->when($this->search, fn($query) => $query->where('name', 'like', '%' . $this->search . '%'))
            ->when(count($this->filterTagIds) > 0, function ($query): void {
                foreach ($this->filterTagIds as $tagId) {
                    $query->whereHas('tags', fn($q) => $q->where('media_tags.id', $tagId));
                }
            })
            ->tap(fn($query) => $this->applyListFilters($query))
            // Folder context only applies when no search/filter is active (global search per UX choice)
            ->when(! $hasActiveFilters, fn($query) => $query->where('folder_id', $this->currentFolderId));

        $rows = (clone $baseQuery)->latest()->limit($this->perPage)->get();

        $currentFolder = $this->currentFolderId
            ? MediaFolder::where('tenant_id', Auth::user()->selected_tenant_id)
                ->with('parent')
                ->find($this->currentFolderId)
            : null;

        $folders = $hasActiveFilters
            ? collect()
            : MediaFolder::where('tenant_id', Auth::user()->selected_tenant_id)
                ->where('parent_id', $this->currentFolderId)
                ->orderBy('name')
                ->get();

        $parentFolderId = $currentFolder?->parent_id;
        $parentFolderName = $currentFolder
            ? ($currentFolder->parent?->name ?? __('Media Library'))
            : null;

        $allTags = MediaTag::where('tenant_id', Auth::user()->selected_tenant_id)
            ->orderBy('name')
            ->withCount(['medias' => fn($q) => $q->where('tenant_id', Auth::user()->selected_tenant_id)])
            ->get()
            ->filter(fn($tag) => $tag->medias_count > 0)
            ->values();

        $attachedTagIds = $this->selected?->tags->pluck('id')->toArray() ?? [];
        $availableTags = $allTags
            ->reject(fn($tag) => in_array($tag->id, $attachedTagIds))
            ->sortByDesc('created_at')
            ->take(10)
            ->values()
            ->map(fn($tag) => ['id' => $tag->id, 'name' => $tag->name])
            ->toArray();

        return [
            'listConfig' => $this->buildList($rows),
            'tags' => $allTags,
            'availableTags' => $availableTags,
            'totalCount' => (clone $baseQuery)->count(),
            'folders' => $folders,
            'breadcrumb' => $currentFolder?->breadcrumb() ?? [],
            'hasActiveFilters' => $hasActiveFilters,
            'parentFolderId' => $parentFolderId,
            'parentFolderName' => $parentFolderName,
            'hasParentTile' => $currentFolder !== null,
        ];
    }

    public function updatedFiles(): void
    {
        $this->store();
    }

    public function rendering(): void
    {
        if ((int) request()->id) {
            $this->listAction(request()->id);
        }

        if (request()->create) {
            $this->listAction();
        }
    }

    public function store(): void
    {
        $mediaUploadService = app()->make(MediaUploadService::class);

        foreach ($this->files as $file) {
            $media = $mediaUploadService->storeFromArray($file);

            if ($this->currentFolderId !== null) {
                $media->update(['folder_id' => $this->currentFolderId]);
            }
        }

        $this->files = [];
    }

    /**
     * Build the dropzone validation rules from the configurable allowed extensions.
     *
     * @return array<int, string>
     */
    public function uploadRules(): array
    {
        $extensions = config('media.allowed_extensions', []);

        return [
            'mimes:' . implode(',', $extensions),
            'max:' . config('media.max_upload_size'),
        ];
    }

    public function deleteMedia(int $id): void
    {
        $media = Media::find($id);
        if ($media) {
            Storage::disk($media->disk)->delete($media->path);
            $media->delete();
            $this->selectedMediaIds = array_values(array_diff($this->selectedMediaIds, [$id]));
            $this->selected = null;
        }
    }

    public function toggleMediaSelection(int $id): void
    {
        if (in_array($id, $this->selectedMediaIds, true)) {
            $this->selectedMediaIds = array_values(
                array_diff($this->selectedMediaIds, [$id]),
            );
        } else {
            $this->selectedMediaIds[] = $id;
        }

        $this->selected = count($this->selectedMediaIds) === 1
            ? Media::with('tags')->find($this->selectedMediaIds[0])
            : null;
    }

    public function deleteSelectedMedia(): void
    {
        if ($this->selectedMediaIds === []) {
            return;
        }

        // $this->selected is a Livewire lazy proxy for an Eloquent model.
        // Touching its attributes after the underlying record is deleted
        // would cause Livewire's hydration closure to call firstOrFail() on
        // a missing record, throwing ModelNotFoundException (HTTP 404).
        // So we capture the id and clear the property BEFORE the deletion.
        $currentSelectedId = $this->selected?->id;
        if ($currentSelectedId !== null && in_array($currentSelectedId, $this->selectedMediaIds, true)) {
            $this->selected = null;
        }

        // The Media model has a TenantScope global scope, so this query
        // is automatically restricted to the current tenant.
        $items = Media::whereIn('id', $this->selectedMediaIds)->get();

        foreach ($items as $media) {
            Storage::disk($media->disk)->delete($media->path);
            $media->delete();
        }

        $this->selectedMediaIds = [];
        $this->selected = null;
    }

    public function chooseMedia(int $id): void
    {
        if (! $this->selectMode) {
            return;
        }
        $this->dispatch('mediaSelected', $id, $this->selectContext, $this->selectToken);
        $this->dispatch('closeTopModal');
    }

    public function addOrAttachTag(string $tagName): void
    {
        $name = mb_trim($tagName);
        if ($name === '' || ! $this->selected) {
            return;
        }

        $tag = MediaTag::firstOrCreate([
            'tenant_id' => Auth::user()->selected_tenant_id,
            'name' => $name,
        ]);

        $this->selected->tags()->syncWithoutDetaching([$tag->id]);
        $this->selected->load('tags');
    }

    public function attachTag(int $tagId): void
    {
        if (! $this->selected) {
            return;
        }
        $this->selected->tags()->syncWithoutDetaching([$tagId]);
        $this->selected->load('tags');
    }

    public function detachTag(int $tagId): void
    {
        if (! $this->selected) {
            return;
        }
        $this->selected->tags()->detach([$tagId]);
        $this->selected->load('tags');
    }

    public function toggleFilterTag(int $tagId): void
    {
        if (in_array($tagId, $this->filterTagIds, true)) {
            $this->filterTagIds = array_values(array_diff($this->filterTagIds, [$tagId]));
        } else {
            $this->filterTagIds[] = $tagId;
        }
    }

    public function clearAllFilters(): void
    {
        $this->search = '';
        $this->filterTagIds = [];
        $this->clearAllListFilters();
    }

    public function loadMore(): void
    {
        $this->perPage += $this->perPage;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openFolder(?int $folderId): void
    {
        $this->currentFolderId = $folderId;
        $this->selected = null;
        $this->selectedMediaIds = [];
        $this->resetPage();
    }

    public function openCreateFolderModal(): void
    {
        Noerd::modal('media::folder-create', ['parentFolderId' => $this->currentFolderId]);
    }

    #[On('mediaFolderCreated')]
    public function refreshAfterFolderCreated(): void
    {
        // Livewire re-renders on event handling; no other action needed.
    }

    public function deleteFolder(int $folderId): void
    {
        $folder = MediaFolder::where('tenant_id', Auth::user()->selected_tenant_id)->find($folderId);
        if (! $folder) {
            return;
        }

        // Cascade: move children folders + files up to the deleted folder's parent
        MediaFolder::where('tenant_id', Auth::user()->selected_tenant_id)
            ->where('parent_id', $folder->id)
            ->update(['parent_id' => $folder->parent_id]);

        Media::where('tenant_id', Auth::user()->selected_tenant_id)
            ->where('folder_id', $folder->id)
            ->update(['folder_id' => $folder->parent_id]);

        $folder->delete();
    }

    public function openMoveModal(?int $mediaId = null): void
    {
        $ids = $mediaId !== null ? [$mediaId] : $this->selectedMediaIds;
        if ($ids === []) {
            return;
        }

        Noerd::modal('media::folder-picker', ['mediaIds' => $ids]);
    }

    #[On('mediaFolderPicked')]
    public function moveMediaToFolder(array $mediaIds, ?int $folderId): void
    {
        if ($mediaIds === []) {
            return;
        }

        Media::whereIn('id', $mediaIds)
            ->where('tenant_id', Auth::user()->selected_tenant_id)
            ->update(['folder_id' => $folderId]);

        $this->selectedMediaIds = [];

        if ($this->selected && in_array($this->selected->id, $mediaIds, true)) {
            $this->selected = null;
        }
    }

    protected function getExtensionListFilter(): ?array
    {
        // Extensions are tenant-scoped automatically via Media's TenantScope.
        $extensions = Media::query()
            ->whereNotNull('extension')
            ->where('extension', '!=', '')
            ->distinct()
            ->orderBy('extension')
            ->pluck('extension')
            ->toArray();

        if ($extensions === []) {
            return null;
        }

        $options = [null => __('All types')];
        foreach ($extensions as $extension) {
            $options[$extension] = '.' . mb_strtolower($extension);
        }

        return [
            'label' => __('Type'),
            'column' => 'extension',
            'type' => 'Picklist',
            'options' => $options,
        ];
    }
} ?>

<x-noerd::page>
    <x-slot:header>
        {{-- Search and the YAML actions are injected generically by modal-title (NoerdList host). --}}
        <x-noerd::modal-title>
            <div class="pb-3 lg:pb-0">{{ __('Media') }}</div>
        </x-noerd::modal-title>
    </x-slot:header>

    <div class="grid grid-cols-6 gap-4">
        {{-- Media Grid --}}
        <div class="{{ $hideDetail ? 'col-span-6' : 'col-span-4' }}"
             x-data="{
                 draggedIds: [],
                 dragOverFolderId: null,
                 isMoveDrag(e) {
                     return e.dataTransfer && Array.from(e.dataTransfer.types).includes('application/x-media-move');
                 },
                 startDrag(e, id) {
                     const selected = Array.isArray($wire.selectedMediaIds) ? $wire.selectedMediaIds : [];
                     this.draggedIds = selected.includes(id) ? [...selected] : [id];
                     e.dataTransfer.effectAllowed = 'move';
                     e.dataTransfer.setData('application/x-media-move', JSON.stringify(this.draggedIds));
                 },
                 endDrag() {
                     this.draggedIds = [];
                     this.dragOverFolderId = null;
                 },
                 dropOn(folderId) {
                     if (this.draggedIds.length === 0) return;
                     $wire.moveMediaToFolder(this.draggedIds, folderId);
                     this.endDrag();
                 }
             }">
            <div class="pt-8"
                 x-data="{ uploadError: '' }"
                 @@livewire-upload-error="uploadError = @js(__("Upload failed. The file may be too large for the server's upload limit."))"
                 @@livewire-upload-start="uploadError = ''"
                 @@livewire-upload-finish="uploadError = ''">
                <livewire:dropzone
                    wire:model.live="files"
                    :rules="$this->uploadRules()"
                    :key="'files'"
                    :multiple="true"
                />
                <div x-show="uploadError"
                     x-cloak
                     x-transition
                     class="mt-2 p-3 rounded bg-red-50 border border-red-200 text-sm text-red-700 flex items-start justify-between gap-3">
                    <span x-text="uploadError"></span>
                    <x-noerd::button variant="icon"  icon="x-mark" type="button" @click="uploadError = ''" class="text-red-700!"/>
                </div>
            </div>

            {{-- Breadcrumb (hidden when filters are active) --}}
            @unless($hasActiveFilters)
                <div class="px-4 pt-4 flex flex-wrap items-center gap-3">
                    <nav class="flex items-center gap-2 text-sm">
                        <button type="button"
                                wire:click="openFolder(null)"
                                @if($currentFolderId !== null)
                                    x-on:dragover="if (isMoveDrag($event)) { $event.preventDefault(); dragOverFolderId = 'root'; }"
                                    x-on:dragleave="if (dragOverFolderId === 'root') dragOverFolderId = null"
                                    x-on:drop.prevent="dropOn(null)"
                                    :class="dragOverFolderId === 'root' ? 'bg-blue-100 ring-2 ring-blue-400 rounded px-1' : ''"
                                @endif
                                class="hover:underline {{ $currentFolderId === null ? 'font-semibold' : '' }}">
                            {{ __('Media Library') }}
                        </button>
                        @foreach($breadcrumb as $crumb)
                            <span class="text-gray-400">/</span>
                            <button type="button"
                                    wire:click="openFolder({{ $crumb['id'] }})"
                                    @unless($loop->last)
                                        x-on:dragover="if (isMoveDrag($event)) { $event.preventDefault(); dragOverFolderId = {{ $crumb['id'] }}; }"
                                        x-on:dragleave="if (dragOverFolderId === {{ $crumb['id'] }}) dragOverFolderId = null"
                                        x-on:drop.prevent="dropOn({{ $crumb['id'] }})"
                                        :class="dragOverFolderId === {{ $crumb['id'] }} ? 'bg-blue-100 ring-2 ring-blue-400 rounded px-1' : ''"
                                    @endunless
                                    class="hover:underline {{ $loop->last ? 'font-semibold' : '' }}">
                                {{ $crumb['name'] }}
                            </button>
                        @endforeach
                    </nav>
                </div>
            @endunless

            {{-- Filters Row --}}
            @php
                $tableFilters = $this->tableFilters();
            @endphp
            <div class="px-4 pt-4 flex flex-wrap items-center gap-3">
                @foreach($tableFilters as $tableFilter)
                    <x-noerd::filters.picklist
                        :filter="$tableFilter"
                        :value="$listFilters[$tableFilter['column']] ?? ''"/>
                @endforeach
                @if($hasActiveFilters)
                    <button type="button" wire:click="clearAllFilters"
                            class="text-xs text-gray-400 hover:text-gray-600 transition-colors whitespace-nowrap">
                        {{ __('Clear all filters') }}
                    </button>
                @endif
            </div>

            {{-- Tag Filter --}}
            @if($tags->isNotEmpty())
                <div class="p-4 pt-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm text-gray-600 mr-2">{{ __('Filter by tags:') }}</span>
                        @foreach($tags as $tag)
                            <button type="button"
                                    wire:click="toggleFilterTag({{ $tag->id }})"
                                    @class([
                                        'text-sm border px-2 py-1 rounded',
                                        'bg-gray-800 text-white border-gray-800' => in_array($tag->id, $filterTagIds, true),
                                        'bg-white hover:bg-gray-50' => ! in_array($tag->id, $filterTagIds, true),
                                    ])>
                                {{ $tag->name }} ({{ $tag->medias_count }})
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Media Items --}}
            <div class="grid grid-cols-2 md:grid-cols-6 2xl:grid-cols-6 gap-4 p-4">
                @if($hasParentTile && ! $hasActiveFilters)
                    @php
                        $parentDragKey = $parentFolderId === null ? "'root'" : $parentFolderId;
                        $parentDropArg = $parentFolderId === null ? 'null' : $parentFolderId;
                        $parentClickArg = $parentFolderId === null ? 'null' : $parentFolderId;
                    @endphp
                    <div wire:key="folder-tile-parent"
                         class="relative w-full aspect-square p-4 border border-b-gray-400 hover:bg-gray-100 transition-colors"
                         :class="dragOverFolderId === {{ $parentDragKey }} ? 'bg-blue-100 ring-2 ring-blue-400' : ''"
                         x-on:dragover="if (isMoveDrag($event)) { $event.preventDefault(); dragOverFolderId = {{ $parentDragKey }}; }"
                         x-on:dragleave="if (dragOverFolderId === {{ $parentDragKey }}) dragOverFolderId = null"
                         x-on:drop.prevent="dropOn({{ $parentDropArg }})">
                        <button type="button"
                                wire:click="openFolder({{ $parentClickArg }})"
                                class="absolute inset-0 flex flex-col items-center justify-center cursor-pointer text-gray-600 hover:text-gray-800">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="m15 15-3-3m0 0-3 3m3-3v6"/>
                            </svg>
                            <span class="mt-2 text-sm truncate w-full text-center px-2">.. {{ $parentFolderName }}</span>
                        </button>
                    </div>
                @endif
                @foreach($folders as $folder)
                    <div wire:key="folder-tile-{{ $folder->id }}"
                         class="relative w-full aspect-square p-4 border border-b-gray-400 hover:bg-gray-100 transition-colors"
                         :class="dragOverFolderId === {{ $folder->id }} ? 'bg-blue-100 ring-2 ring-blue-400' : ''"
                         x-on:dragover="if (isMoveDrag($event)) { $event.preventDefault(); dragOverFolderId = {{ $folder->id }}; }"
                         x-on:dragleave="if (dragOverFolderId === {{ $folder->id }}) dragOverFolderId = null"
                         x-on:drop.prevent="dropOn({{ $folder->id }})">
                        <button type="button"
                                wire:click="openFolder({{ $folder->id }})"
                                class="absolute inset-0 flex flex-col items-center justify-center cursor-pointer">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16 text-yellow-500" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M19.5 21a3 3 0 0 0 3-3v-9a3 3 0 0 0-3-3h-5.379a.75.75 0 0 1-.53-.22L11.47 3.66A2.25 2.25 0 0 0 9.879 3H4.5a3 3 0 0 0-3 3v12a3 3 0 0 0 3 3h15Z"/>
                            </svg>
                            <span class="mt-2 text-sm truncate w-full text-center px-2">{{ $folder->name }}</span>
                        </button>
                        <button type="button"
                                wire:click="deleteFolder({{ $folder->id }})"
                                wire:confirm="{{ __('Delete folder? Contents will move to the parent folder.') }}"
                                class="absolute top-2 right-2 z-10 text-red-600 hover:text-red-800 text-xl leading-none"
                                title="{{ __('Delete') }}">×</button>
                    </div>
                @endforeach
                @unless($hasActiveFilters)
                    <div wire:key="folder-tile-create"
                         class="relative w-full aspect-square p-4 border border-dashed border-gray-400 hover:bg-gray-100">
                        <button type="button"
                                wire:click="openCreateFolderModal"
                                class="absolute inset-0 flex flex-col items-center justify-center cursor-pointer text-gray-500 hover:text-gray-700">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                            </svg>
                            <span class="mt-2 text-sm truncate w-full text-center px-2">{{ __('New folder') }}</span>
                        </button>
                    </div>
                @endunless
                @foreach($listConfig['rows'] as $row)
                    @php
                        $isSelected = in_array($row->id, $selectedMediaIds, true);
                        $clickAction = $selectMode
                            ? "chooseMedia({$row->id})"
                            : "toggleMediaSelection({$row->id})";
                    @endphp
                    <a wire:click="{{ $clickAction }}"
                       wire:key="media-tile-{{ $row->id }}"
                       @if(! $selectMode)
                           draggable="true"
                           x-on:dragstart="startDrag($event, {{ $row->id }})"
                           x-on:dragend="endDrag()"
                           :class="draggedIds.includes({{ $row->id }}) ? 'opacity-50' : ''"
                       @endif
                       @class([
                           'relative cursor-pointer w-full aspect-square p-4',
                           'border-2 border-brand-primary bg-brand-primary/5' => $isSelected,
                           'border border-b-gray-400 hover:bg-gray-100' => ! $isSelected,
                       ])>
                        <img src="{{ $row->thumbnailUrl() }}"
                             alt="{{ $row->name }}"
                             class="absolute inset-0 w-full h-full p-4 object-cover rounded-lg"/>
                        @if($row->ai_error_count > 0)
                            <div class="absolute bg-red-300 text-red-800 p-2 px-4 rounded-full">
                                {{ $row->ai_error_count }}
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>

            {{-- Infinite Scroll --}}
            <div x-data x-intersect="$wire.loadMore()" class="h-8"></div>
        </div>

        {{-- Detail Panel --}}
        @unless($hideDetail)
            <div class="col-span-2 p-4 bg-gray-100">
                <div class="sticky top-[47px]">
                    @if(count($selectedMediaIds) > 1)
                        <div class="font-semibold mb-4">
                            {{ __(':count selected', ['count' => count($selectedMediaIds)]) }}
                        </div>
                        <div class="flex flex-col items-start gap-2">
                            <x-noerd::button variant="secondary"
                                             icon="folder"
                                             wire:click="openMoveModal">
                                {{ __('Move to folder') }}
                            </x-noerd::button>
                            <x-noerd::button variant="danger"
                                             wire:confirm="{{ __('Really delete selected media?') }}"
                                             wire:click="deleteSelectedMedia">
                                {{ __('Delete selected') }}
                            </x-noerd::button>
                        </div>
                    @elseif($selected)
                        @php
                            $fileUrl = $selected->url();
                        @endphp
                        <img alt="{{ $selected->name }}"
                             src="{{ $selected->thumbnailUrl() }}"
                             class="w-full"/>
                        <div class="pt-4 flex flex-wrap items-center gap-2">
                            <a href="{{ $fileUrl }}"
                               target="_blank"
                               rel="noopener"
                               class="inline-flex h-8 cursor-pointer items-center justify-center gap-2 rounded-sm border border-gray-300 !bg-brand-secondary px-4 py-1.5 text-sm text-brand-secondary-text shadow-xs transition hover:bg-brand-secondary/80">
                                {{ __('Open in new tab') }}
                            </a>
                            <a href="{{ $fileUrl }}"
                               download="{{ $selected->name }}"
                               class="inline-flex h-8 cursor-pointer items-center justify-center gap-2 rounded-sm border border-gray-300 !bg-brand-secondary px-4 py-1.5 text-sm text-brand-secondary-text shadow-xs transition hover:bg-brand-secondary/80">
                                {{ __('Download') }}
                            </a>
                        </div>
                        <div class="pt-4">{{ $selected->name }}</div>
                        <div class="text-gray-500">{{ $selected->size }}</div>
                        <div class="text-gray-500">
                            <span class="font-semibold">{{ __('Created') }}:</span>
                            {{ \Carbon\Carbon::parse($selected->created_at)->format('d.m.Y H:i') }}
                        </div>

                        @if($selected->ocr_text)
                            <div class="pt-4">{!! nl2br($selected->ocr_text) !!}</div>
                        @endif

                        {{-- Tags Section --}}
                        <div class="pt-6">
                            <div class="font-semibold mb-2">{{ __('Tags') }}</div>

                            {{-- Attached Tags --}}
                            <div class="flex flex-wrap gap-2 mb-3">
                                @forelse($selected->tags as $tag)
                                    <span class="inline-flex items-center gap-1 bg-white border px-2 py-1 rounded">
                                        <span>{{ $tag->name }}</span>
                                        <button type="button"
                                                wire:click="detachTag({{ $tag->id }})"
                                                class="text-red-600"
                                                title="Remove">×</button>
                                    </span>
                                @empty
                                    <span class="text-gray-500">{{ __('No tags') }}</span>
                                @endforelse
                            </div>

                            {{-- Tag Input with Dropdown --}}
                            <div x-data="tagSelector(@js($availableTags))" @click.outside="close()" class="relative">
                                <input type="text"
                                       x-ref="input"
                                       x-model="search"
                                       @focus="open = true"
                                       @keydown.escape.prevent="close()"
                                       @keydown.enter.prevent="selectOrCreate()"
                                       @keydown.arrow-down.prevent="moveDown()"
                                       @keydown.arrow-up.prevent="moveUp()"
                                       placeholder="{{ __('Add new tag…') }}"
                                       class="w-full border rounded px-3 py-2"/>

                                {{-- Dropdown --}}
                                <div x-show="open && filtered.length > 0"
                                     x-cloak
                                     x-transition
                                     class="absolute z-10 mt-1 w-full bg-white border border-gray-300 rounded-md shadow-lg max-h-60 overflow-auto">
                                    <template x-for="(tag, index) in filtered" :key="tag.id">
                                        <button type="button"
                                                @click="select(tag)"
                                                @mouseenter="selectedIndex = index"
                                                :class="index === selectedIndex ? 'bg-gray-100' : ''"
                                                class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                            <span x-text="tag.name"></span>
                                        </button>
                                    </template>
                                </div>

                                {{-- Create New Hint --}}
                                <div x-show="open && filtered.length === 0 && search.trim()"
                                     x-cloak
                                     x-transition
                                     class="absolute z-10 mt-1 w-full bg-white border border-gray-300 rounded-md shadow-lg">
                                    <div class="px-4 py-2 text-sm text-gray-500">
                                        {{ __('Press Enter to create new tag') }}
                                    </div>
                                </div>

                                <div class="text-xs text-gray-500 mt-1">{{ __('Type to search or press Enter to add') }}</div>
                            </div>
                        </div>

                        {{-- Move + Delete Buttons --}}
                        <div class="pt-4 flex flex-wrap items-center gap-2">
                            <x-noerd::button variant="secondary"
                                             icon="folder"
                                             wire:click="openMoveModal({{ $selected->id }})">
                                {{ __('Move to folder') }}
                            </x-noerd::button>
                            <x-noerd::button variant="danger"
                                             wire:confirm="{{ __('Really delete?') }}"
                                             wire:click="deleteMedia({{ $selected->id }})">
                                {{ __('Delete') }}
                            </x-noerd::button>
                        </div>
                    @endif
                </div>
            </div>
        @endunless
    </div>

    @script
    <script>
        Alpine.data('tagSelector', (initialTags) => ({
            open: false,
            search: '',
            selectedIndex: -1,
            tags: initialTags,

            get filtered() {
                if (!this.search.trim()) {
                    return this.tags;
                }
                const s = this.search.toLowerCase();
                return this.tags.filter(t => t.name.toLowerCase().includes(s));
            },

            close() {
                this.open = false;
                this.search = '';
                this.selectedIndex = -1;
            },

            moveDown() {
                this.open = true;
                this.selectedIndex = Math.min(this.selectedIndex + 1, this.filtered.length - 1);
            },

            moveUp() {
                this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
            },

            select(tag) {
                $wire.attachTag(tag.id);
                this.close();
            },

            selectOrCreate() {
                if (this.filtered.length > 0 && this.selectedIndex >= 0) {
                    this.select(this.filtered[this.selectedIndex]);
                } else if (this.search.trim()) {
                    $wire.addOrAttachTag(this.search.trim());
                    this.close();
                }
            }
        }));
    </script>
    @endscript
</x-noerd::page>

