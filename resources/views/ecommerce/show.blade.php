@extends('layouts.storefront')

@section('title', ($ecomPreviewData['title'] ?? 'Product') . ' — Shop')

@section('content')
    <div data-rfm-ecom-store class="store-product-page">
        @include('retail-products.partials.family-ecommerce-preview', [
            'ecomPreviewData' => $ecomPreviewData,
            'asPage' => true,
        ])
    </div>
@endsection
