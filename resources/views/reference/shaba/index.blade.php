@extends('layouts.app')

@section('title', 'Shaba Reference')

@section('heading', 'Shaba Reference')

@section('content')
    <section class="p-6 md:p-8 max-w-[1400px] mx-auto space-y-8" data-cursor-element-id="cursor-el-1">
        
        <!-- Header -->
        <header class="flex flex-col md:flex-row md:items-start justify-between gap-4 bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-gray-200">
            <div class="space-y-2">
                <p class="text-sm font-bold text-blue-600 uppercase tracking-widest">Reference Datasource</p>
                <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 tracking-tight">Shaba Products</h1>
                <p class="text-gray-500 max-w-2xl text-base md:text-lg">Search the local Shaba Cosmetics export for product matching, image checks, and shop-floor intake support.</p>
            </div>
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-blue-50 text-blue-700 border border-blue-100" aria-hidden="true">
                <span class="flex h-2.5 w-2.5 rounded-full bg-blue-600 animate-pulse"></span>
                <strong class="font-bold text-sm tracking-wide">SHB</strong>
                <span class="text-sm font-medium">Local Source</span>
            </div>
        </header>

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200 flex flex-col justify-center items-center text-center transition-transform hover:scale-[1.02]">
                <strong class="text-3xl md:text-4xl font-extrabold text-gray-900">{{ number_format($stats['products']) }}</strong>
                <span class="text-sm font-semibold text-gray-500 uppercase tracking-widest mt-2">Products</span>
            </div>
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200 flex flex-col justify-center items-center text-center transition-transform hover:scale-[1.02]">
                <strong class="text-3xl md:text-4xl font-extrabold text-gray-900">{{ number_format($stats['brands']) }}</strong>
                <span class="text-sm font-semibold text-gray-500 uppercase tracking-widest mt-2">Brands</span>
            </div>
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200 flex flex-col justify-center items-center text-center transition-transform hover:scale-[1.02]">
                <strong class="text-3xl md:text-4xl font-extrabold text-gray-900">{{ number_format($stats['variants']) }}</strong>
                <span class="text-sm font-semibold text-gray-500 uppercase tracking-widest mt-2">Variants</span>
            </div>
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200 flex flex-col justify-center items-center text-center transition-transform hover:scale-[1.02]">
                <strong class="text-3xl md:text-4xl font-extrabold text-gray-900">{{ number_format($stats['media']) }}</strong>
                <span class="text-sm font-semibold text-gray-500 uppercase tracking-widest mt-2">Images</span>
            </div>
        </div>

        <!-- Departments -->
        @php
            $departmentUrl = function (?string $value) use ($search, $brand) {
                return route('reference.shaba.index', array_filter([
                    'search' => $search,
                    'brand' => $brand,
                    'department' => $value,
                ], fn ($queryValue) => $queryValue !== null && $queryValue !== ''));
            };
        @endphp
        <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <a href="{{ $departmentUrl(null) }}" class="group rounded-2xl border p-5 shadow-sm transition-all {{ $department === '' ? 'bg-gray-900 border-gray-900 text-white' : 'bg-white border-gray-200 text-gray-900 hover:border-gray-300 hover:shadow-md' }}">
                <span class="block text-xs font-extrabold uppercase tracking-widest {{ $department === '' ? 'text-gray-300' : 'text-gray-500' }}">Department</span>
                <strong class="mt-2 block text-2xl font-extrabold tracking-tight">All Shaba Products</strong>
                <span class="mt-3 inline-flex rounded-full px-3 py-1 text-sm font-bold {{ $department === '' ? 'bg-white/15 text-white' : 'bg-gray-100 text-gray-700' }}">{{ number_format($stats['products']) }} products</span>
            </a>
            @foreach ($departmentLabels as $departmentKey => $departmentLabel)
                @php
                    $isActiveDepartment = $department === $departmentKey;
                @endphp
                <a href="{{ $departmentUrl($departmentKey) }}" class="group rounded-2xl border p-5 shadow-sm transition-all {{ $isActiveDepartment ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-gray-200 text-gray-900 hover:border-blue-200 hover:shadow-md' }}">
                    <span class="block text-xs font-extrabold uppercase tracking-widest {{ $isActiveDepartment ? 'text-blue-100' : 'text-blue-600' }}">Department</span>
                    <strong class="mt-2 block text-2xl font-extrabold tracking-tight">{{ $departmentLabel }}</strong>
                    <span class="mt-3 inline-flex rounded-full px-3 py-1 text-sm font-bold {{ $isActiveDepartment ? 'bg-white/15 text-white' : 'bg-blue-50 text-blue-700' }}">{{ number_format($departmentStats[$departmentKey] ?? 0) }} products</span>
                </a>
            @endforeach
        </section>

        <!-- Search Form -->
        <form method="GET" action="{{ route('reference.shaba.index') }}" class="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-gray-200 space-y-4 md:space-y-0 md:flex md:items-end gap-6">
            @if ($department)
                <input type="hidden" name="department" value="{{ $department }}">
            @endif
            <div class="flex-1 space-y-2">
                <label for="search" class="block text-sm font-bold text-gray-700">Search Products</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <input type="search" id="search" name="search" value="{{ $search }}" placeholder="Product, style, shade, variant..." class="block w-full pl-10 pr-4 py-3 border border-gray-300 rounded-xl bg-gray-50 text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 sm:text-base transition-colors">
                </div>
            </div>
            <div class="flex-1 space-y-2">
                <label for="brand" class="block text-sm font-bold text-gray-700">Filter by Brand</label>
                <input type="search" id="brand" name="brand" value="{{ $brand }}" placeholder="Brand name" class="block w-full px-4 py-3 border border-gray-300 rounded-xl bg-gray-50 text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 sm:text-base transition-colors">
            </div>
            <div class="flex items-center gap-3 pt-2 md:pt-0">
                <button type="submit" class="inline-flex justify-center items-center px-6 py-3 border border-transparent text-base font-bold rounded-xl shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                    Filter Results
                </button>
                @if ($search || $brand || $department)
                    <a href="{{ route('reference.shaba.index') }}" class="inline-flex justify-center items-center px-6 py-3 border border-gray-300 shadow-sm text-base font-bold rounded-xl text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                        Clear
                    </a>
                @endif
            </div>
        </form>

        <!-- Product Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 md:gap-8">
            @forelse ($products as $product)
                @php
                    $priceLabel = null;
                    if ($product->min_price_pence !== null) {
                        $priceLabel = '&pound;' . number_format($product->min_price_pence / 100, 2);
                        if ($product->max_price_pence !== $product->min_price_pence) {
                            $priceLabel .= ' - &pound;' . number_format($product->max_price_pence / 100, 2);
                        }
                    }
                @endphp
                <article class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden flex flex-col hover:shadow-xl hover:-translate-y-1 transition-all duration-200 group">
                    <button type="button" class="block relative aspect-[4/3] bg-gray-50 border-b border-gray-100 overflow-hidden w-full cursor-zoom-in outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500 gallery-trigger" data-images="{{ $product->media->pluck('url') }}" data-title="{{ $product->title }}">
                        @if ($product->main_image_url)
                            <img src="{{ $product->main_image_url }}" alt="{{ $product->title }}" loading="lazy" class="w-full h-full object-contain p-6 group-hover:scale-105 transition-transform duration-300">
                            @if ($product->media_count > 1)
                                <div class="absolute bottom-3 right-3 bg-black/70 backdrop-blur-sm text-white text-xs font-bold px-2 py-1 rounded-lg flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    1/{{ $product->media_count }}
                                </div>
                            @endif
                        @else
                            <div class="absolute inset-0 flex flex-col items-center justify-center text-gray-400">
                                <svg class="h-12 w-12 opacity-50 mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                <span class="text-sm font-medium">No image</span>
                            </div>
                        @endif
                    </button>
                    <div class="p-6 flex flex-col flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-3">
                            <p class="text-xs font-bold text-blue-600 uppercase tracking-widest">{{ $product->brand ?: 'Unknown Brand' }}</p>
                            <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-extrabold uppercase tracking-wider text-blue-700">
                                {{ $departmentLabels[$product->department] ?? 'Body Care' }}
                            </span>
                        </div>
                        <h2 class="text-lg font-bold text-gray-900 line-clamp-2 leading-snug mb-4 group-hover:text-blue-600 transition-colors" title="{{ $product->title }}">{{ $product->title }}</h2>
                        
                        <div class="flex flex-wrap items-center gap-2 mb-4">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold bg-gray-100 text-gray-800">
                                {{ $product->variant_count }} variant{{ $product->variant_count !== 1 ? 's' : '' }}
                            </span>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold bg-gray-100 text-gray-800">
                                {{ $product->media_count }} image{{ $product->media_count !== 1 ? 's' : '' }}
                            </span>
                        </div>

                        @if ($priceLabel)
                            <p class="text-xl font-extrabold text-gray-900 mb-3">{!! $priceLabel !!}</p>
                        @endif
                        
                        @if ($product->description)
                            <p class="text-sm text-gray-500 line-clamp-3 mb-6 flex-1">{{ strip_tags($product->description) }}</p>
                        @endif
                        
                        <div class="mt-auto pt-4 border-t border-gray-100">
                            <a class="inline-flex items-center text-sm font-bold text-blue-600 hover:text-blue-800 transition-colors" href="{{ $product->canonical_url }}" target="_blank" rel="noopener">
                                Open Shaba Source
                                <svg class="ml-1.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                            </a>
                        </div>
                    </div>
                </article>
            @empty
                <div class="col-span-full py-16 px-4 text-center bg-white rounded-2xl border-2 border-gray-200 border-dashed">
                    <svg class="mx-auto h-16 w-16 text-gray-300 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <h3 class="text-lg font-bold text-gray-900">No products found</h3>
                    <p class="mt-2 text-base text-gray-500 max-w-sm mx-auto">We couldn't find any Shaba products matching your search. Try a broader term, shade, or brand.</p>
                </div>
            @endforelse
        </div>

        @if ($products->hasPages())
            <div class="mt-10 bg-white p-6 rounded-2xl shadow-sm border border-gray-200">
                {{ $products->links() }}
            </div>
        @endif
    </section>

    <!-- Gallery Modal -->
    <div id="gallery-modal" class="fixed inset-0 z-[100] hidden flex-col bg-black/90 backdrop-blur-sm transition-opacity duration-300 opacity-0" aria-modal="true" role="dialog">
        <div class="flex-1 w-full h-full flex flex-col relative">
            <!-- Header -->
            <div class="absolute top-0 inset-x-0 p-4 flex items-start justify-between z-10 pointer-events-none">
                <div class="bg-black/50 backdrop-blur-md text-white px-4 py-2 rounded-xl pointer-events-auto max-w-2xl">
                    <h3 id="gallery-title" class="font-bold text-lg leading-tight line-clamp-2"></h3>
                    <p id="gallery-counter" class="text-gray-300 text-sm font-medium mt-0.5"></p>
                </div>
                <button type="button" id="gallery-close" class="pointer-events-auto bg-black/50 hover:bg-black/70 backdrop-blur-md text-white p-2 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-white">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Image Area -->
            <div class="flex-1 w-full flex items-center justify-center p-4 pt-24 pb-24 relative overflow-hidden">
                <img id="gallery-image" src="" alt="" class="max-w-full max-h-full object-contain drop-shadow-2xl transition-opacity duration-200">
                
                <!-- Controls -->
                <button type="button" id="gallery-prev" class="absolute left-4 top-1/2 -translate-y-1/2 bg-black/50 hover:bg-black/70 backdrop-blur-md text-white p-3 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-white disabled:opacity-30 disabled:cursor-not-allowed">
                    <svg class="w-8 h-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                
                <button type="button" id="gallery-next" class="absolute right-4 top-1/2 -translate-y-1/2 bg-black/50 hover:bg-black/70 backdrop-blur-md text-white p-3 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-white disabled:opacity-30 disabled:cursor-not-allowed">
                    <svg class="w-8 h-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </div>

            <!-- Thumbnails -->
            <div id="gallery-thumbnails" class="absolute bottom-0 inset-x-0 p-4 flex items-center justify-center gap-2 overflow-x-auto bg-gradient-to-t from-black/80 to-transparent">
            </div>
        </div>
    </dialog>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('gallery-modal');
            const closeBtn = document.getElementById('gallery-close');
            const imgEl = document.getElementById('gallery-image');
            const titleEl = document.getElementById('gallery-title');
            const counterEl = document.getElementById('gallery-counter');
            const prevBtn = document.getElementById('gallery-prev');
            const nextBtn = document.getElementById('gallery-next');
            const thumbsContainer = document.getElementById('gallery-thumbnails');
            
            let currentImages = [];
            let currentIndex = 0;

            function openModal() {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                // Small delay to allow display:flex to apply before opacity transition
                setTimeout(() => {
                    modal.classList.remove('opacity-0');
                    modal.classList.add('opacity-100');
                }, 10);
                document.body.style.overflow = 'hidden'; // Prevent background scrolling
            }

            function closeModal() {
                modal.classList.remove('opacity-100');
                modal.classList.add('opacity-0');
                setTimeout(() => {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    imgEl.src = '';
                    currentImages = [];
                    document.body.style.overflow = '';
                }, 300); // Wait for transition
            }

            function renderImage() {
                if (!currentImages.length) return;
                
                // Fade effect
                imgEl.style.opacity = '0.5';
                setTimeout(() => {
                    imgEl.src = currentImages[currentIndex];
                    imgEl.style.opacity = '1';
                }, 50);

                counterEl.textContent = `Image ${currentIndex + 1} of ${currentImages.length}`;
                
                // Button states
                prevBtn.disabled = currentIndex === 0;
                nextBtn.disabled = currentIndex === currentImages.length - 1;

                // Update thumbnails
                Array.from(thumbsContainer.children).forEach((btn, idx) => {
                    if (idx === currentIndex) {
                        btn.classList.add('ring-2', 'ring-white', 'opacity-100');
                        btn.classList.remove('opacity-50');
                        btn.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                    } else {
                        btn.classList.remove('ring-2', 'ring-white', 'opacity-100');
                        btn.classList.add('opacity-50');
                    }
                });
            }

            // Bind triggers
            document.querySelectorAll('.gallery-trigger').forEach(trigger => {
                trigger.addEventListener('click', (e) => {
                    e.preventDefault();
                    
                    const imagesRaw = trigger.dataset.images;
                    if (!imagesRaw || imagesRaw === '[]') return;
                    
                    currentImages = JSON.parse(imagesRaw);
                    currentIndex = 0;
                    
                    titleEl.textContent = trigger.dataset.title || '';
                    
                    // Generate thumbnails
                    thumbsContainer.innerHTML = '';
                    if (currentImages.length > 1) {
                        currentImages.forEach((src, idx) => {
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = `w-16 h-16 rounded-lg overflow-hidden flex-shrink-0 bg-black transition-all ${idx === 0 ? 'ring-2 ring-white opacity-100' : 'opacity-50 hover:opacity-75'}`;
                            btn.innerHTML = `<img src="${src}" class="w-full h-full object-cover">`;
                            btn.onclick = () => {
                                currentIndex = idx;
                                renderImage();
                            };
                            thumbsContainer.appendChild(btn);
                        });
                    }

                    renderImage();
                    openModal();
                });
            });

            // Navigation
            prevBtn.addEventListener('click', () => {
                if (currentIndex > 0) {
                    currentIndex--;
                    renderImage();
                }
            });

            nextBtn.addEventListener('click', () => {
                if (currentIndex < currentImages.length - 1) {
                    currentIndex++;
                    renderImage();
                }
            });

            // Keyboard navigation
            document.addEventListener('keydown', (e) => {
                if (modal.classList.contains('hidden')) return;
                
                if (e.key === 'ArrowLeft' && !prevBtn.disabled) {
                    currentIndex--;
                    renderImage();
                } else if (e.key === 'ArrowRight' && !nextBtn.disabled) {
                    currentIndex++;
                    renderImage();
                } else if (e.key === 'Escape') {
                    closeModal();
                }
            });

            // Click backdrop to close
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    closeModal();
                }
            });
            
            // Close button click
            if(closeBtn) {
                closeBtn.addEventListener('click', closeModal);
            }
        });
    </script>
@endsection
