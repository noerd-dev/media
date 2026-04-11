@foreach($nodes as $node)
    <div wire:key="folder-pick-{{ $node['id'] }}" class="text-sm">
        <button type="button"
                wire:click="selectFolder({{ $node['id'] }})"
                style="padding-left: {{ ($depth * 1.25) + 0.5 }}rem;"
                @class([
                    'w-full text-left py-1 pr-2 rounded',
                    'bg-blue-50 font-semibold' => $selectedFolderId === $node['id'],
                    'hover:bg-gray-50' => $selectedFolderId !== $node['id'],
                ])>
            <span class="text-yellow-600 mr-1">▸</span>{{ $node['name'] }}
        </button>
        @if(count($node['children']) > 0)
            @include('media::components.partials.folder-tree-node', ['nodes' => $node['children'], 'depth' => $depth + 1])
        @endif
    </div>
@endforeach
