<?php

use Livewire\Volt\Component;
use Nywerk\Media\Models\Media;
use Nywerk\Media\Models\MediaLabel;
use Nywerk\Media\Services\ImagePreviewService;
use Nywerk\Media\Services\MediaUploadService;
use Noerd\Noerd\Traits\Noerd;
use Nywerk\Uki\Models\TextDocument;
use Spatie\PdfToText\Pdf;
use Noerd\Noerd\Helpers\StaticConfigHelper;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

new class extends Component {

    use Noerd;

    public const COMPONENT = 'media-table';

    public array $files = [];
    public ?Media $selected = null;
    public string $labelName = '';
    public array $filterLabelIds = [];
    public int $perPage = 0;
    public bool $hideDetail = false;
    public bool $selectMode = false;
    public ?string $selectContext = null;

    public function tableAction(mixed $modelId = null, mixed $relationId = null): void
    {


        $this->dispatch(
            event: 'noerdModal',
            component: 'media-component',
            source: self::COMPONENT,
            arguments: ['modelId' => $modelId, 'relationId' => $relationId],
        );
    }

    public function with()
    {
        $baseQuery = Media::where('tenant_id', Auth::user()->selected_tenant_id)
            ->orderBy($this->sortField, $this->sortAsc ? 'asc' : 'desc')
            ->when($this->search, function ($query): void {
                $query->where(function ($query): void {
                    $query->where('name', 'like', '%' . $this->search . '%');
                });
            })
            ->when(count($this->filterLabelIds) > 0, function ($query): void {
                foreach ($this->filterLabelIds as $labelId) {
                    $query->whereHas('labels', function ($q) use ($labelId): void {
                        $q->where('media_labels.id', $labelId);
                    });
                }
            })
        ;

        $totalCount = (clone $baseQuery)->count();
        $rows = (clone $baseQuery)->limit($this->perPage)->get();

        $tableConfig = StaticConfigHelper::getTableConfig('media-table');

        $labels = MediaLabel::where('tenant_id', Auth::user()->selected_tenant_id)
            ->orderBy('name')
            ->withCount(['medias' => function ($q): void {
                $q->where('tenant_id', Auth::user()->selected_tenant_id);
            }])
            ->get();

        return [
            'rows' => $rows,
            'tableConfig' => $tableConfig,
            'labels' => $labels,
            'totalCount' => $totalCount,
        ];
    }

    public function mount(): void
    {
        $this->sortField = 'id';
        $this->sortAsc = false;
        $this->perPage = self::PAGINATION;
    }

    public function updatedFiles($value)
    {
        $this->store();
    }

    public function rendering()
    {
        if ((int)request()->orderConfrimationId) {
            $this->tableAction(request()->orderConfrimationId);
        }

        if (request()->create) {
            $this->tableAction();
        }
    }

    public function store()
    {
        $mediaUploadService = app()->make(MediaUploadService::class);

        foreach ($this->files as $file) {
            $mediaUploadService->storeFromArray($file);
        }

        $this->files = [];
    }

    public function selectMedia($id)
    {
        $media = Media::with('labels')->find($id);
        $this->selected = $media;
    }

    public function deleteMedia($id)
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
        if (!$this->selectMode) {
            return;
        }
        $this->dispatch('mediaSelected', $id, $this->selectContext);
        $this->dispatch('close-modal-media-select-modal');
    }

    public function pdfToText()
    {
        return;

        // Not working yet because of GS issues when running in a job
        $ocrSevice = app()->make(\Nywerk\Media\Services\OcrService::class);


        $path = Storage::disk($this->selected->disk)->path($this->selected->path);
        $ocrSevice->parseWithOCR($path);

        $storagePath = Storage::disk($this->selected->disk)->path($this->selected->path);
        $text = Pdf::getText($storagePath);

        $aiDocument = new TextDocument();
        $aiDocument->tenant_id = auth()->user()->selected_tenant_id;
        $aiDocument->name = $this->selected->name;
        $aiDocument->text = $text;
        $aiDocument->media_id = $this->selected->id;
        $aiDocument->save();
    }

    public function addLabel(): void
    {
        $name = trim($this->labelName);
        if ($name === '' || !$this->selected) {
            return;
        }

        $label = MediaLabel::firstOrCreate([
            'tenant_id' => Auth::user()->selected_tenant_id,
            'name' => $name,
        ]);

        $this->selected->labels()->syncWithoutDetaching([$label->id]);
        $this->selected->load('labels');
        $this->labelName = '';
    }

    public function attachLabel(int $labelId): void
    {
        if (!$this->selected) {
            return;
        }
        $this->selected->labels()->syncWithoutDetaching([$labelId]);
        $this->selected->load('labels');
    }

    public function detachLabel(int $labelId): void
    {
        if (!$this->selected) {
            return;
        }
        $this->selected->labels()->detach([$labelId]);
        $this->selected->load('labels');
    }

    public function toggleFilterLabel(int $labelId): void
    {
        if (in_array($labelId, $this->filterLabelIds, true)) {
            $this->filterLabelIds = array_values(array_diff($this->filterLabelIds, [$labelId]));
        } else {
            $this->filterLabelIds[] = $labelId;
        }
        // Reset page on filter change
        $this->perPage = self::PAGINATION;
    }

    public function clearFilters(): void
    {
        $this->filterLabelIds = [];
        $this->perPage = self::PAGINATION;
    }

    public function loadMore(): void
    {
        $this->perPage += self::PAGINATION;
    }

    public function updatedSearch(): void
    {
        $this->perPage = self::PAGINATION;
    }
} ?>

<x-noerd::page :disableModal="$disableModal">

    <div class="grid grid-cols-6 gap-4">

        <div class="{{ $hideDetail ? 'col-span-6' : 'col-span-4' }}">
            <div class="pt-8">
                <livewire:dropzone
                    wire:model.live="files"
                    :rules="['mimes:png,jpg,jpeg,pdf,txt','max:10420']"
                    :key="'files'"
                    :multiple="true"/>
            </div>

            <div class="p-4 pt-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-gray-600 mr-2">
                        Nach Labels filtern:
                    </span>
                    @if(isset($labels))
                        @foreach($labels as $label)
                            <button type="button"
                                    class="text-sm border px-2 py-1 rounded {{ in_array($label->id, $filterLabelIds, true) ? 'bg-gray-800 text-white border-gray-800' : 'bg-white hover:bg-gray-50' }}"
                                    wire:click="toggleFilterLabel({{$label->id}})">
                                {{$label->name}} ({{$label->medias_count}})
                            </button>
                        @endforeach
                    @endif

                    <button type="button"
                            class="text-sm ml-auto text-gray-600 hover:text-black"
                            wire:click="clearFilters">
                        Filter entfernen
                    </button>
                </div>
            </div>

            <div>
                <div class="grid grid-cols-2 md:grid-cols-2 2xl:grid-cols-6 gap-4 p-4">
                    @foreach($rows as $row)
                        <a @if($selectMode) wire:click="chooseMedia({{$row->id}})" @else wire:click="selectMedia({{$row->id}})" @endif
                           @class([
                               'relative cursor-pointer w-full aspect-square p-4',
                               'border-2 border-blue-500 ring-2 ring-blue-200' => ($selected?->id === $row->id),
                               'border border-b-gray-400 hover:bg-gray-100' => ($selected?->id !== $row->id),
                           ])>

                            <img src="{{ Storage::disk($row->disk)->url($row->thumbnail ?? $row->path) }}" alt="Bild 1"
                                 class="absolute inset-0 w-full h-full p-4 object-cover rounded-lg"/>

                            @if($row->ai_error_count > 0)
                                <div class="absolute bg-red-300 text-red-800 p-2 px-4 rounded-full">
                                    {{$row->ai_error_count}}
                                </div>
                            @endif
                        </a>
                    @endforeach
                </div>
                <div
                    x-data="{observe() { let observer = new IntersectionObserver((entries) => { entries.forEach(entry => { if (entry.isIntersecting) { $wire.loadMore() } }) }) ; observer.observe(this.$el) }}"
                    x-init="observe"
                    class="h-8"
                ></div>
            </div>
        </div>
        @unless($hideDetail)
        <div class="col-span-2 p-4 bg-gray-100">
            <div class="sticky top-[47px]">
            @if($selected)
                <img alt="" src="{{ Storage::disk($selected->disk)->url($selected->thumbnail ?? $selected->path) }}"
                     class="w-full"/>
                <div class="pt-4">
                    {{$selected->name}}
                </div>
                <div class="text-gray-500">{{$selected->size}}</div>
                <div class="text-gray-500">{{$selected->created_at}}</div>

                {{--
                <x-noerd::buttons.primary wire:click="pdfToText" class="mt-8">
                    Text aus PDF extrahieren und als Dokument speichern
                </x-noerd::buttons.primary>
                --}}

                @if($selected->ocr_text)
                    {!! nl2br( $selected->ocr_text) !!}
                @endif

                <div class="pt-6">
                    <div class="font-semibold mb-2">Labels</div>

                    <div class="flex flex-wrap gap-2 mb-3">
                        @foreach(($selected->labels ?? []) as $label)
                            <span class="inline-flex items-center gap-1 bg-white border px-2 py-1 rounded">
                                <span>{{$label->name}}</span>
                                <button type="button" class="text-red-600" title="Remove"
                                        wire:click="detachLabel({{$label->id}})">×</button>
                            </span>
                        @endforeach
                        @if(($selected->labels ?? collect())->isEmpty())
                            <span class="text-gray-500">Keine Labels</span>
                        @endif
                    </div>

                    <div>
                        <input type="text"
                               wire:model.defer="labelName"
                               wire:keydown.enter.prevent="addLabel"
                               placeholder="Neues Label hinzufügen…"
                               class="w-full border rounded px-3 py-2"/>
                        <div class="text-xs text-gray-500 mt-1">Drücke Enter zum Hinzufügen</div>
                    </div>

                    @if(isset($labels) && $labels->count() > 0)
                        <div class="mt-3">
                            <div class="text-sm text-gray-600 mb-1">Vorhandene Labels</div>
                            <div class="flex flex-wrap gap-2 max-h-32 overflow-auto">
                                @foreach($labels as $label)
                                    @if(!($selected->labels ?? collect())->pluck('id')->contains($label->id))
                                        <button type="button"
                                                class="text-sm bg-white border px-2 py-1 rounded hover:bg-gray-50"
                                                wire:click="attachLabel({{$label->id}})">
                                            {{$label->name}}
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <div class="pt-4">
                    <x-noerd::buttons.delete wire:confirm="Wirklich löschen?"
                                             wire:click="deleteMedia({{$selected->id}})">
                        Löschen
                    </x-noerd::buttons.delete>
                </div>

            @endif
            </div>
        </div>
        @endunless
    </div>
</x-noerd::page>
