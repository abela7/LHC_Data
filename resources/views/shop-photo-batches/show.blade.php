@extends('layouts.app')

@section('title', $batch->name.' Photo Review')
@section('section', 'Hair Extensions')
@section('heading', $batch->name)

@section('content')
    <section class="max-w-[1400px] mx-auto px-4 md:px-6 pb-12" data-spb-root>

        {{-- Sticky header --}}
        <div class="bg-white border border-gray-200 rounded-2xl px-6 py-5 mb-6 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <a href="{{ route('shop-photo-batches.index') }}" class="flex-shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-200 bg-white text-gray-500 hover:text-gray-900 hover:border-gray-400 transition-colors" title="All batches">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </a>
                    <div class="min-w-0">
                        <p class="text-xs font-bold text-blue-600 uppercase tracking-widest">Batch Review</p>
                        <h1 class="text-xl md:text-2xl font-extrabold text-gray-900 tracking-tight truncate">{{ $batch->name }}</h1>
                    </div>
                </div>

                {{-- Stats pills --}}
                <div class="flex items-center gap-2 flex-wrap text-sm">
                    <span class="px-3 py-1 rounded-full bg-gray-100 text-gray-700 font-bold">{{ number_format($stats['total']) }} photos</span>
                    <span class="px-3 py-1 rounded-full bg-green-50 text-green-700 font-bold">{{ number_format($stats['identified']) }} identified</span>
                    <span class="px-3 py-1 rounded-full bg-amber-50 text-amber-700 font-bold">{{ number_format($stats['pending']) }} pending</span>
                    <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-700 font-bold">{{ number_format($stats['support']) }} support</span>
                    @if($stats['needs_revisit'] > 0)
                        <span class="px-3 py-1 rounded-full bg-red-50 text-red-700 font-bold">{{ number_format($stats['needs_revisit']) }} revisit</span>
                    @endif
                </div>
            </div>

            {{-- Search & filter --}}
            <div class="mt-3 flex gap-3">
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                    </div>
                    <input type="search" placeholder="Search photo, brand, style, variant..." data-spb-search class="block w-full pl-9 pr-3 py-2 border border-gray-200 rounded-lg bg-gray-50 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                </div>
                <select data-spb-status-filter aria-label="Filter by status" class="w-44 border border-gray-200 rounded-lg bg-gray-50 text-sm font-semibold text-gray-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                    <option value="">All statuses</option>
                    <option value="pending_review">Pending review</option>
                    <option value="identified">Identified</option>
                    <option value="support_photo">Support photo</option>
                    <option value="not_hair_extension">Not hair extension</option>
                    <option value="needs_revisit">Needs revisit</option>
                    <option value="duplicate">Duplicate</option>
                </select>
            </div>
        </div>

        {{-- Datalists (hidden from view, used by inputs) --}}
        <datalist id="spb-product-types">
            <option value="Bulk Hair">
            <option value="Braid">
            <option value="Crochet Braid">
            <option value="Weave">
            <option value="Wig">
            <option value="Ponytail">
            <option value="Clip On">
        </datalist>
        <datalist id="spb-main-variants">
            <option value="Length">
            <option value="Size">
            <option value="Bundle count">
            <option value="Pack count">
        </datalist>
        <datalist id="spb-sub-variants">
            <option value="Colour">
            <option value="Texture">
            <option value="Shade">
        </datalist>

        {{-- Photo cards --}}
        <div class="space-y-8">
            @foreach($items as $item)
                @php
                    $searchText = strtolower(collect([
                        $item->filename,
                        $item->original_filename,
                        $item->brand_name,
                        collect($item->grouping_path ?? [])->implode(' '),
                        $item->product_type_name,
                        $item->style_name,
                        $item->main_variant,
                        $item->sub_variant,
                        $item->common_variant,
                    ])->filter()->implode(' '));

                    $statusColors = [
                        'pending_review'     => 'bg-amber-100 text-amber-800 border-amber-200',
                        'identified'         => 'bg-green-100 text-green-800 border-green-200',
                        'support_photo'      => 'bg-blue-100 text-blue-800 border-blue-200',
                        'not_hair_extension' => 'bg-gray-100 text-gray-600 border-gray-200',
                        'needs_revisit'      => 'bg-red-100 text-red-800 border-red-200',
                        'duplicate'          => 'bg-purple-100 text-purple-800 border-purple-200',
                    ];
                    $statusClass = $statusColors[$item->status] ?? 'bg-amber-100 text-amber-800 border-amber-200';
                @endphp
                <article
                    class="bg-white rounded-[2rem] border border-gray-200 shadow-sm overflow-hidden lg:h-[calc(100vh-120px)] flex flex-col lg:flex-row transition-all hover:shadow-xl"
                    data-spb-card
                    data-status="{{ $item->status }}"
                    data-search="{{ e($searchText) }}"
                    id="photo-{{ $item->sequence }}"
                >
                    {{-- Photo panel --}}
                    <div class="relative flex-1 bg-gradient-to-br from-gray-50 to-gray-200 lg:h-full lg:overflow-hidden border-b lg:border-b-0 lg:border-r border-gray-200 group">
                        <a href="{{ route('shop-photo-batches.items.image', [$batch, $item]) }}" target="_blank" rel="noopener" class="absolute inset-0 flex items-center justify-center p-4 md:p-8">
                            <img
                                src="{{ route('shop-photo-batches.items.image', [$batch, $item]) }}"
                                alt="Photo {{ $item->sequence }}"
                                loading="lazy"
                                class="w-full h-full object-contain filter drop-shadow-2xl transition-transform duration-500 group-hover:scale-105"
                            >
                        </a>
                        
                        <div class="absolute top-5 left-5 flex flex-col items-start gap-2">
                            <span class="inline-flex items-center px-3 py-1.5 rounded-xl bg-white/90 text-gray-900 text-xs font-black shadow-sm backdrop-blur-md border border-white">
                                #{{ str_pad((string) $item->sequence, 3, '0', STR_PAD_LEFT) }}
                            </span>
                            <span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-black shadow-sm backdrop-blur-md border {{ $statusClass }}" data-spb-status-badge>
                                {{ str_replace('_', ' ', $item->status ?: 'pending review') }}
                            </span>
                        </div>
                    </div>

                    {{-- Form panel --}}
                    <div class="flex flex-col w-full lg:w-[420px] xl:w-[480px] bg-white h-full lg:overflow-y-auto">
                        <div class="p-6 md:p-8 flex-1 flex flex-col">
                            <div class="flex items-center justify-between gap-3 mb-6 pb-4 border-b border-gray-100">
                                <h2 class="text-xl font-extrabold text-gray-900 truncate" title="{{ $item->filename }}">{{ $item->filename }}</h2>
                                <span class="flex-shrink-0 text-sm font-bold uppercase tracking-wider" data-spb-save-state>
                                    <span class="text-gray-400">Not saved</span>
                                </span>
                            </div>

                            <form data-spb-form action="{{ route('shop-photo-batches.items.update', [$batch, $item]) }}" class="flex-1 flex flex-col">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4">
                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-1.5">Brand</label>
                                        <input name="brand_name" value="{{ $item->brand_name }}" placeholder="e.g. Cherish" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 text-sm font-bold text-gray-900 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-1.5">Grouping Path</label>
                                        <input name="grouping_path" value="{{ collect($item->grouping_path ?? [])->implode(' > ') }}" placeholder="e.g. Sleek > Style Icon, Kuknus > Bulk > Fusion" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 text-sm font-bold text-gray-900 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                                        <p class="mt-1 text-[11px] font-bold text-gray-400">Optional hierarchy under the brand. Do not put product type or colour here.</p>
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-1.5">Style / Family</label>
                                        <input name="style_name" value="{{ $item->style_name }}" placeholder="e.g. Spiral French Curl" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 text-sm font-bold text-gray-900 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-1.5">Product Type</label>
                                        <input name="product_type_name" value="{{ $item->product_type_name }}" list="spb-product-types" placeholder="Bulk Hair, Braid..." class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 text-sm font-bold text-gray-900 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-1.5">Main Variant</label>
                                        <input name="main_variant" value="{{ $item->main_variant }}" list="spb-main-variants" placeholder="e.g. 22 inch" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 text-sm font-bold text-gray-900 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-1.5">Sub Variant</label>
                                        <input name="sub_variant" value="{{ $item->sub_variant }}" list="spb-sub-variants" placeholder="e.g. Colour 1B" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 text-sm font-bold text-gray-900 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-1.5">Common Variant</label>
                                        <input name="common_variant" value="{{ $item->common_variant }}" placeholder="Only sellable pack traits, e.g. 3X, 4X, 2 pack" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 text-sm font-bold text-gray-900 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                                        <p class="mt-1 text-[11px] font-bold text-gray-400">No features here. Texture, fibre, anti-itch, soft feel go in notes only.</p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-1.5">Confidence</label>
                                        <select name="confidence" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 text-sm font-bold text-gray-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all appearance-none cursor-pointer">
                                            <option value="">- Select -</option>
                                            @foreach(['A', 'B', 'C', 'D'] as $confidence)
                                                <option value="{{ $confidence }}" @selected($item->confidence === $confidence)>{{ $confidence }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-1.5">Status</label>
                                        <select name="status" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl bg-gray-50/50 text-sm font-bold text-gray-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all appearance-none cursor-pointer">
                                            <option value="pending_review" @selected($item->status === 'pending_review')>Pending</option>
                                            <option value="identified" @selected($item->status === 'identified')>Identified</option>
                                            <option value="support_photo" @selected($item->status === 'support_photo')>Support</option>
                                            <option value="not_hair_extension" @selected($item->status === 'not_hair_extension')>Not ext.</option>
                                            <option value="needs_revisit" @selected($item->status === 'needs_revisit')>Revisit</option>
                                            <option value="duplicate" @selected($item->status === 'duplicate')>Duplicate</option>
                                        </select>
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-1.5">Ecommerce Note</label>
                                        <textarea name="ecommerce_note" rows="2" placeholder="Texture, pack count, material, length, colour group..." class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-gray-50/50 text-sm font-medium text-gray-900 placeholder-gray-400 resize-y focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">{{ $item->ecommerce_note }}</textarea>
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-1.5">Analysis Note</label>
                                        <textarea name="analysis_notes" rows="2" placeholder="Why identified, what is uncertain, what photo this supports..." class="w-full px-4 py-3 border border-gray-200 rounded-xl bg-gray-50/50 text-sm font-medium text-gray-900 placeholder-gray-400 resize-y focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">{{ $item->analysis_notes }}</textarea>
                                    </div>
                                </div>

                                {{-- Action row --}}
                                <div class="mt-auto pt-6">
                                    @if($item->hair_extension_intake_id)
                                        <a class="mb-3 w-full inline-flex items-center justify-center px-5 py-3 border-2 border-green-200 bg-green-50 hover:bg-green-100 text-green-800 text-sm font-extrabold rounded-xl transition-all" href="{{ route('hair-extension-intake.v2', ['edit_intake' => $item->hair_extension_intake_id]) }}">
                                            Open V2 intake #{{ $item->hair_extension_intake_id }}
                                        </a>
                                    @else
                                        <button
                                            type="button"
                                            class="mb-3 w-full inline-flex items-center justify-center px-5 py-3 border-2 border-blue-200 bg-blue-50 hover:bg-blue-100 text-blue-800 text-sm font-extrabold rounded-xl transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                                            data-spb-create-intake
                                            data-create-url="{{ route('shop-photo-batches.items.v2-intake', [$batch, $item]) }}"
                                        >
                                            Save + Create V2 intake
                                        </button>
                                    @endif
                                    <div class="flex items-center gap-3">
                                        <button class="flex-1 inline-flex items-center justify-center px-6 py-3.5 bg-gray-900 hover:bg-black text-white text-sm font-extrabold rounded-xl shadow-lg shadow-gray-900/20 transition-all hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-900" type="submit">
                                            Save Review
                                        </button>
                                        <a class="inline-flex items-center justify-center px-5 py-3.5 border-2 border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-sm font-extrabold rounded-xl transition-all focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-200" href="#photo-{{ $item->sequence + 1 }}">
                                            Next
                                            <svg class="w-4 h-4 ml-1.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <script>
            (() => {
                const root = document.querySelector('[data-spb-root]');
                if (!root) return;

                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const search = root.querySelector('[data-spb-search]');
                const statusFilter = root.querySelector('[data-spb-status-filter]');
                const cards = Array.from(root.querySelectorAll('[data-spb-card]'));

                const applyFilters = () => {
                    const query = (search?.value || '').trim().toLowerCase();
                    const status = statusFilter?.value || '';

                    cards.forEach((card) => {
                        const matchesQuery = !query || (card.dataset.search || '').includes(query);
                        const matchesStatus = !status || card.dataset.status === status;
                        card.style.display = (matchesQuery && matchesStatus) ? '' : 'none';
                    });
                };

                search?.addEventListener('input', applyFilters);
                statusFilter?.addEventListener('change', applyFilters);

                const collectPayload = (form) => {
                    const payload = {};
                    new FormData(form).forEach((value, key) => {
                        payload[key] = value;
                    });
                    return payload;
                };

                const applySavedItem = (card, item, payload = {}) => {
                    card.dataset.status = item.status || payload.status || 'pending_review';
                    card.dataset.search = [
                        item.filename,
                        item.original_filename,
                        item.brand_name,
                        Array.isArray(item.grouping_path) ? item.grouping_path.join(' ') : item.grouping_path,
                        item.product_type_name,
                        item.style_name,
                        item.main_variant,
                        item.sub_variant,
                        item.common_variant,
                    ].filter(Boolean).join(' ').toLowerCase();

                    const badge = card.querySelector('[data-spb-status-badge]');
                    if (badge) {
                        const colorMap = {
                            'pending_review':     'bg-amber-100 text-amber-800 border-amber-200',
                            'identified':         'bg-green-100 text-green-800 border-green-200',
                            'support_photo':      'bg-blue-100 text-blue-800 border-blue-200',
                            'not_hair_extension': 'bg-gray-100 text-gray-600 border-gray-200',
                            'needs_revisit':      'bg-red-100 text-red-800 border-red-200',
                            'duplicate':          'bg-purple-100 text-purple-800 border-purple-200',
                        };
                        badge.className = 'inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-black shadow-sm backdrop-blur-md border ' + (colorMap[card.dataset.status] || 'bg-amber-100 text-amber-800 border-amber-200');
                        badge.textContent = (card.dataset.status || '').replaceAll('_', ' ');
                    }

                    applyFilters();
                };

                const saveReview = async (form) => {
                    const card = form.closest('[data-spb-card]');
                    const payload = collectPayload(form);
                    const response = await fetch(form.action, {
                        method: 'PATCH',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify(payload),
                    });

                    const result = await response.json();
                    if (!response.ok || !result.ok) {
                        throw new Error(result.message || 'Save failed');
                    }

                    applySavedItem(card, result.item || {}, payload);
                    return { item: result.item || {}, payload };
                };

                root.addEventListener('submit', async (event) => {
                    const form = event.target.closest('[data-spb-form]');
                    if (!form) return;

                    event.preventDefault();
                    const card = form.closest('[data-spb-card]');
                    const saveState = form.querySelector('[data-spb-save-state]') || card.querySelector('[data-spb-save-state]');
                    const submit = form.querySelector('button[type="submit"]');

                    if (saveState) saveState.innerHTML = '<span class="text-blue-600">Saving\u2026</span>';
                    if (submit) submit.disabled = true;

                    try {
                        await saveReview(form);
                        if (saveState) saveState.innerHTML = '<span class="text-green-600">Saved</span>';
                    } catch (error) {
                        if (saveState) saveState.innerHTML = '<span class="text-red-600">' + (error.message || 'Error') + '</span>';
                    } finally {
                        if (submit) submit.disabled = false;
                    }
                });

                root.addEventListener('click', async (event) => {
                    const button = event.target.closest('[data-spb-create-intake]');
                    if (!button) return;

                    const form = button.closest('form');
                    const card = form?.closest('[data-spb-card]');
                    const saveState = form?.querySelector('[data-spb-save-state]') || card?.querySelector('[data-spb-save-state]');
                    if (!form || !card) return;

                    button.disabled = true;
                    if (saveState) saveState.innerHTML = '<span class="text-blue-600">Creating V2\u2026</span>';

                    try {
                        const { payload } = await saveReview(form);
                        const response = await fetch(button.dataset.createUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                            },
                            body: JSON.stringify(payload),
                        });
                        const result = await response.json();
                        if (!response.ok || !result.ok) {
                            throw new Error(result.message || 'V2 create failed');
                        }

                        button.outerHTML = `<a class="mb-3 w-full inline-flex items-center justify-center px-5 py-3 border-2 border-green-200 bg-green-50 hover:bg-green-100 text-green-800 text-sm font-extrabold rounded-xl transition-all" href="${result.edit_url}">Open V2 intake #${result.intake_id}</a>`;
                        if (saveState) saveState.innerHTML = '<span class="text-green-600">V2 created</span>';
                    } catch (error) {
                        if (saveState) saveState.innerHTML = '<span class="text-red-600">' + (error.message || 'Error') + '</span>';
                        button.disabled = false;
                    }
                });
            })();
        </script>
    </section>
@endsection
