<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Noerd\Noerd\Traits\Noerd;
use Noerd\Media\Models\Media;
use Noerd\Noerd\Helpers\StaticConfigHelper;

new class extends Component {

    use Noerd;

    public const COMPONENT = 'media-select-modal';

    public $context; // e.g., field name to set in collection-component

    public function tableAction(mixed $modelId): void
    {
        $this->dispatch('mediaSelected', $modelId, $this->context);
        $this->dispatch('close-modal-' . self::COMPONENT);
    }

    public function with(): array
    {
        $rows = Media::where('tenant_id', Auth::user()->selected_tenant_id)
            ->orderBy($this->sortField, $this->sortAsc ? 'asc' : 'desc')
            ->when($this->search, function ($query): void {
                $query->where(function ($query): void {
                    $query->where('name', 'like', '%' . $this->search . '%');
                });
            })
            ->paginate(self::PAGINATION);

        $tableConfig = StaticConfigHelper::getTableConfig('media-select-modal');

        return [
            'rows' => $rows,
            'tableConfig' => $tableConfig,
        ];
    }
}; ?>

<x-noerd::page :disableModal="$disableModal">
    <x-slot:header>
        <x-noerd::modal-title>{{ __('Select Media') }}</x-noerd::modal-title>
    </x-slot:header>

    <div class="flex-1 overflow-y-auto p-4">
        <livewire:media-table :hideDetail="true" :selectMode="true" :selectContext="$context"/>
    </div>
</x-noerd::page>


