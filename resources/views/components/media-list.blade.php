<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaTag;
use Noerd\Media\Services\MediaUploadService;
use Noerd\Traits\NoerdList;
use Noerd\Traits\ShowFromFilterTrait;

new class extends Component {
    use NoerdList;
    use ShowFromFilterTrait;

    public array $files = [];
    public ?Media $selected = null;
    public array $filterTagIds = [];
    public bool $hideDetail = false;
    public bool $selectMode = false;
    public ?string $selectContext = null;
    public ?string $selectToken = null;
    public bool $bulkSelectMode = false;
    public array $selectedMediaIds = [];

    public function mount(): void
    {
        $this->mountList();

        // Support selectAction from input-relation component
        if ($this->listActionMethod === 'selectAction') {
            $this->selectMode = true;
        }
    }

    protected function getShowFromListFilter(): array
    {
        return [
            'label' => __('media_label_uploaded_from'),
            'column' => 'show_from',
            'type' => 'ShowFrom',
            'options' => $this->getDateFilterOptions(),
        ];
    }

    protected function getShowUntilListFilter(): array
    {
        return [
            'label' => __('media_label_uploaded_until'),
            'column' => 'show_until',
            'type' => 'ShowUntil',
            'options' => $this->getDateFilterOptions(),
        ];
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

        $options = [null => __('media_all_types')];
        foreach ($extensions as $extension) {
            $options[$extension] = '.' . mb_strtolower($extension);
        }

        return [
            'label' => __('media_label_type'),
            'column' => 'extension',
            'type' => 'Picklist',
            'options' => $options,
        ];
    }

    public function with(): array
    {
        $baseQuery = Media::where('tenant_id', Auth::user()->selected_tenant_id)
            ->when($this->search, function ($query): void {
                $search = '%' . $this->search . '%';
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', $search)
                        ->orWhere('ocr_text', 'like', $search);
                });
            })
            ->when(count($this->filterTagIds) > 0, function ($query): void {
                foreach ($this->filterTagIds as $tagId) {
                    $query->whereHas('tags', fn($q) => $q->where('media_tags.id', $tagId));
                }
            })
            ->tap(fn($query) => $this->applyListFilters($query));

        $rows = (clone $baseQuery)->latest()->limit($this->perPage)->get();

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
        ];
    }

    public function listAction(mixed $modelId = null, array $relations = []): void
    {
        $this->dispatch(
            event: 'noerdModal',
            modalComponent: 'media-detail',
            source: $this->getComponentName(),
            arguments: ['modelId' => $modelId, 'relations' => $relations],
        );
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
            $mediaUploadService->storeFromArray($file);
        }

        $this->files = [];
    }

    public function selectMedia(int $id): void
    {
        $this->selected = Media::with('tags')->find($id);
    }

    public function deleteMedia(int $id): void
    {
        $media = Media::find($id);
        if ($media) {
            Storage::disk($media->disk)->delete($media->path);
            $media->delete();
            $this->selected = null;
        }
    }

    public function enterBulkSelectMode(): void
    {
        $this->bulkSelectMode = true;
        $this->selectedMediaIds = [];
    }

    public function exitBulkSelectMode(): void
    {
        $this->bulkSelectMode = false;
        $this->selectedMediaIds = [];
    }

    public function toggleMediaSelection(int $id): void
    {
        if (in_array($id, $this->selectedMediaIds, true)) {
            $this->selectedMediaIds = array_values(
                array_diff($this->selectedMediaIds, [$id])
            );
        } else {
            $this->selectedMediaIds[] = $id;
        }
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

        $this->exitBulkSelectMode();
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
        $name = trim($tagName);
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
} ?>

<x-noerd::page :disableModal="$disableModal">
    <div class="grid grid-cols-6 gap-4">
        {{-- Media Grid --}}
        <div class="{{ $hideDetail ? 'col-span-6' : 'col-span-4' }}">
            <div class="pt-8">
                <livewire:dropzone
                    wire:model.live="files"
                    :rules="['mimes:png,jpg,jpeg,pdf,txt,webp,svg','max:10420']"
                    :key="'files'"
                    :multiple="true"
                />
            </div>

            {{-- Search + Filters Row --}}
            @php
                $tableFilters = $this->tableFilters();
                $hasActiveFilters = $search !== ''
                    || count($filterTagIds) > 0
                    || collect($listFilters)->filter()->isNotEmpty();
            @endphp
            <div class="px-4 pt-4 flex flex-wrap items-center gap-3">
                <div class="relative">
                    <x-noerd::text-input
                        wire:model.live.debounce.300ms="search"
                        type="text"
                        placeholder="{{ __('Search') }}"
                        class="!mt-0 h-[30px] min-w-[200px]"/>
                </div>
                @foreach($tableFilters as $tableFilter)
                    @if(in_array($tableFilter['type'] ?? 'Picklist', ['ShowFrom', 'ShowUntil']))
                        <x-noerd::filters.date-dropdown
                            :filter="$tableFilter"
                            :value="$listFilters[$tableFilter['column']] ?? ''"/>
                    @else
                        <x-noerd::filters.picklist
                            :filter="$tableFilter"
                            :value="$listFilters[$tableFilter['column']] ?? ''"/>
                    @endif
                @endforeach
                @if($hasActiveFilters)
                    <button type="button" wire:click="clearAllFilters"
                            class="text-xs text-gray-400 hover:text-gray-600 transition-colors whitespace-nowrap">
                        {{ __('noerd_clear_filters') }}
                    </button>
                @endif
            </div>

            {{-- Bulk Select Toolbar --}}
            <div class="px-4 pt-4 flex items-center gap-2">
                @if(! $bulkSelectMode)
                    <button type="button"
                            wire:click="enterBulkSelectMode"
                            class="text-sm border px-3 py-1 rounded bg-white hover:bg-gray-50">
                        {{ __('media_select') }}
                    </button>
                @else
                    <button type="button"
                            wire:click="exitBulkSelectMode"
                            class="text-sm border px-3 py-1 rounded bg-white hover:bg-gray-50">
                        {{ __('Cancel') }}
                    </button>
                    <span class="text-sm text-gray-600">
                        {{ __('media_selected_count', ['count' => count($selectedMediaIds)]) }}
                    </span>
                    <x-noerd::buttons.delete wire:confirm="{{ __('Really delete selected?') }}"
                                             wire:click="deleteSelectedMedia"
                                             :disabled="count($selectedMediaIds) === 0">
                        {{ __('Delete selected') }}
                    </x-noerd::buttons.delete>
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
                @foreach($listConfig['rows'] as $row)
                    @php
                        $isMultiSelected = in_array($row->id, $selectedMediaIds, true);
                        $clickAction = $bulkSelectMode
                            ? "toggleMediaSelection({$row->id})"
                            : ($selectMode ? "chooseMedia({$row->id})" : "selectMedia({$row->id})");
                    @endphp
                    <a wire:click="{{ $clickAction }}"
                       wire:key="media-tile-{{ $row->id }}"
                       @class([
                           'relative cursor-pointer w-full aspect-square p-4',
                           'border-2 border-blue-500 ring-2 ring-blue-200' => $bulkSelectMode
                               ? $isMultiSelected
                               : $selected?->id === $row->id,
                           'border border-b-gray-400 hover:bg-gray-100' => $bulkSelectMode
                               ? ! $isMultiSelected
                               : $selected?->id !== $row->id,
                       ])>
                        <img src="{{ Storage::disk($row->disk)->url($row->thumbnail ?? $row->path) }}"
                             alt="{{ $row->name }}"
                             class="absolute inset-0 w-full h-full p-4 object-cover rounded-lg"/>
                        @if($bulkSelectMode)
                            <div @class([
                                'absolute top-2 left-2 z-10 w-6 h-6 rounded border-2 flex items-center justify-center pointer-events-none',
                                'bg-blue-500 border-blue-500' => $isMultiSelected,
                                'bg-white border-gray-400' => ! $isMultiSelected,
                            ])>
                                @if($isMultiSelected)
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-white" fill="none"
                                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                @endif
                            </div>
                        @endif
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
                    @if($selected)
                        <img alt="{{ $selected->name }}"
                             src="{{ Storage::disk($selected->disk)->url($selected->thumbnail ?? $selected->path) }}"
                             class="w-full"/>
                        <div class="pt-4">{{ $selected->name }}</div>
                        <div class="text-gray-500">{{ $selected->size }}</div>
                        <div class="text-gray-500">{{ $selected->created_at }}</div>

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

                        {{-- Delete Button --}}
                        <div class="pt-4">
                            <x-noerd::buttons.delete wire:confirm="{{ __('Really delete?') }}"
                                                     wire:click="deleteMedia({{ $selected->id }})">
                                {{ __('Delete') }}
                            </x-noerd::buttons.delete>
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

