@props([
    'media',
])

{{-- Renders the media thumbnail, or a file-type tile when the file has no
     displayable preview (e.g. a PDF on an installation without Ghostscript).
     The tile's icon and colour come from the extension, so a PDF is not just
     another grey document. --}}
@if($media->hasRenderableThumbnail())
    <img src="{{ $media->thumbnailUrl() }}"
         alt="{{ $media->name }}"
         {{ $attributes->merge(['class' => 'object-cover rounded-lg']) }}/>
@else
    @php
        $fileType = \Noerd\Media\Support\FileTypeIcon::for($media->normalizedExtension());
    @endphp
    <div {{ $attributes->merge(['class' => 'flex min-h-32 flex-col items-center justify-center gap-2 rounded-lg ' . $fileType['classes']]) }}>
        <x-dynamic-component :component="'heroicons::outline.' . $fileType['icon']" class="w-12 h-12" stroke-width="1.5"/>
        <span class="text-xs font-semibold uppercase tracking-wide">{{ $media->normalizedExtension() }}</span>
    </div>
@endif
