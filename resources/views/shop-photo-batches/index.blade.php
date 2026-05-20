@extends('layouts.app')

@section('title', 'Shop Photo Batches')
@section('section', 'Hair Extensions')
@section('heading', 'Shop Photo Batches')

@section('content')
    <section class="spb-page">
        <style>
            .spb-page {
                max-width: 1120px;
                margin: 0 auto;
            }

            .spb-hero {
                border: 1px solid rgba(19, 43, 37, .12);
                border-radius: 28px;
                padding: clamp(1.25rem, 4vw, 2rem);
                background: linear-gradient(135deg, #fcfaf4 0%, #e8f0e7 100%);
                box-shadow: 0 20px 60px rgba(16, 36, 31, .08);
                margin-bottom: 1rem;
            }

            .spb-eyebrow {
                margin: 0 0 .45rem;
                color: #0d5c4e;
                font-weight: 900;
                letter-spacing: .14em;
                text-transform: uppercase;
                font-size: .78rem;
            }

            .spb-hero h1 {
                margin: 0;
                color: #10241f;
                font-size: clamp(2rem, 7vw, 4.4rem);
                line-height: .96;
                letter-spacing: -.05em;
            }

            .spb-hero p {
                max-width: 720px;
                margin: .85rem 0 0;
                color: #52635d;
                font-size: 1rem;
                line-height: 1.55;
            }

            .spb-grid {
                display: grid;
                gap: 1rem;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            }

            .spb-card {
                display: block;
                border: 1px solid rgba(16, 36, 31, .12);
                border-radius: 22px;
                padding: 1rem;
                background: #fffdf8;
                color: inherit;
                text-decoration: none;
                box-shadow: 0 12px 35px rgba(16, 36, 31, .06);
            }

            .spb-card strong {
                display: block;
                color: #0d5c4e;
                font-size: 1.15rem;
                margin-bottom: .35rem;
            }

            .spb-card span {
                color: #52635d;
                font-weight: 800;
            }
        </style>

        <div class="spb-hero">
            <p class="spb-eyebrow">Shelf photo review</p>
            <h1>Photo batches</h1>
            <p>Use this page to review raw shop photos before turning them into proper hair-extension family/product records.</p>
        </div>

        <div class="spb-grid">
            @forelse($batches as $batch)
                <a class="spb-card" href="{{ route('shop-photo-batches.show', $batch) }}">
                    <strong>{{ $batch->name }}</strong>
                    <span>{{ number_format($batch->items_count) }} photos</span>
                </a>
            @empty
                <div class="spb-card">
                    <strong>No photo batches yet</strong>
                    <span>Import a folder to start reviewing.</span>
                </div>
            @endforelse
        </div>
    </section>
@endsection
