<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Noerd\Media\Models\MediaFolder;
use Noerd\Media\Services\MediaMover;
use Noerd\Media\Services\MediaPathService;

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

        // The parent id comes from the client: an app folder the user may not
        // see is no valid parent (the scopes on MediaFolder hide it).
        if ($this->parentFolderId !== null && ! MediaFolder::whereKey($this->parentFolderId)->exists()) {
            return;
        }

        $name = trim($this->name);

        // The name becomes a directory on disk. A name that sanitizes to
        // nothing (only dots, slashes or control characters) would silently
        // land in a generic "folder" directory — reject it instead.
        if (app(MediaPathService::class)->segmentFor($name) === 'folder' && $name !== 'folder') {
            $this->addError('name', __('Please choose a name that can be used as a folder name.'));

            return;
        }

        $folder = MediaFolder::create([
            'tenant_id' => Auth::user()->selected_tenant_id,
            'parent_id' => $this->parentFolderId,
            'name' => $name,
        ]);

        // An empty folder is visible on disk too — the storage mirrors the library.
        app(MediaMover::class)->ensureDirectory((int) $folder->tenant_id, $folder);

        $this->dispatch('mediaFolderCreated');
        $this->dispatch('closeTopModal');
    }
} ?>

<x-noerd::page>
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
