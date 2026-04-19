<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Noerd\Media\Models\MediaFolder;

new class extends Component {
    public bool $disableModal = false;

    public bool $showSuccessIndicator = false;

    public ?int $parentFolderId = null;

    public string $name = '';

    public function mount(?int $parentFolderId = null): void
    {
        $this->parentFolderId = $parentFolderId;
    }

    public function store(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
        ]);

        MediaFolder::create([
            'tenant_id' => Auth::user()->selected_tenant_id,
            'parent_id' => $this->parentFolderId,
            'name' => trim($this->name),
        ]);

        $this->dispatch('mediaFolderCreated');
        $this->dispatch('closeTopModal');
    }
} ?>

<x-noerd::page :disableModal="$disableModal">
    <x-slot:header>
        <x-noerd::modal-title>{{ __('New folder') }}</x-noerd::modal-title>
    </x-slot:header>

    <div class="py-6">
        <x-noerd::text-input
            wire:model="name"
            wire:keydown.enter="store"
            type="text"
            placeholder="{{ __('Folder name') }}"
            autofocus
        />
    </div>

    <x-slot:footer>
        <x-noerd::delete-save-bar :showDelete="false"/>
    </x-slot:footer>
</x-noerd::page>
