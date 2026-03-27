<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaTag;
use Noerd\Media\Services\MediaUploadService;
use Noerd\Traits\NoerdList;

new class extends Component {
    use NoerdList;

    public array $files = [];
    public ?Media $selected = null;
    public array $filterTagIds = [];
    public bool $hideDetail = false;
    public bool $selectMode = false;
    public ?string $selectContext = null;
    public ?string $selectToken = null;

    public function mount(): void
    {
        $this->mountList();

        // Support selectAction from input-relation component
        if ($this->listActionMethod === 'selectAction') {
            $this->selectMode = true;
        }
    }

    public function with(): array
    {
        $baseQuery = Media::where('tenant_id', Auth::user()->selected_tenant_id)
            ->when($this->search, fn($query) => $query->where('name', 'like', '%' . $this->search . '%'))
            ->when(count($this->filterTagIds) > 0, function ($query): void {
                foreach ($this->filterTagIds as $tagId) {
                    $query->whereHas('tags', fn($q) => $q->where('media_tags.id', $tagId));
                }
            });

        $rows = (clone $baseQuery)->limit($this->perPage)->get();

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

    public function clearFilters(): void
    {
        $this->filterTagIds = [];
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

            {{-- Tag Filter --}}
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
                    @if(count($filterTagIds) > 0)
                        <button type="button" wire:click="clearFilters" class="text-sm ml-auto text-gray-600 hover:text-black">
                            {{ __('Clear filters') }}
                        </button>
                    @endif
                </div>
            </div>

            {{-- Media Items --}}
            <div class="grid grid-cols-2 md:grid-cols-6 2xl:grid-cols-6 gap-4 p-4">
                @foreach($listConfig['rows'] as $row)
                    <a wire:click="{{ $selectMode ? 'chooseMedia' : 'selectMedia' }}({{ $row->id }})"
                       @class([
                           'relative cursor-pointer w-full aspect-square p-4',
                           'border-2 border-blue-500 ring-2 ring-blue-200' => $selected?->id === $row->id,
                           'border border-b-gray-400 hover:bg-gray-100' => $selected?->id !== $row->id,
                       ])>
                        <img src="{{ Storage::disk($row->disk)->url($row->thumbnail ?? $row->path) }}"
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

