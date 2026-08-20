@props([
    'media',
])

{{-- Renders the media thumbnail, or a file-type tile when the file has no
     displayable preview (e.g. a PDF on an installation without Ghostscript). --}}
@if($media->hasRenderableThumbnail())
    <img src="{{ $media->thumbnailUrl() }}"
         alt="{{ $media->name }}"
         {{ $attributes->merge(['class' => 'object-cover rounded-lg']) }}/>
@else
    <div {{ $attributes->merge(['class' => 'flex min-h-32 flex-col items-center justify-center gap-2 rounded-lg bg-gray-100 text-gray-500']) }}>
        <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M9 16.5v.75m3-3v3M15 12v5.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
        </svg>
        <span class="text-xs font-semibold uppercase tracking-wide">{{ $media->normalizedExtension() }}</span>
    </div>
@endif
