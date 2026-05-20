@extends('layouts.app')

@section('title', 'Hair Extension Intake Wizard')
@section('section', 'Hair Extensions')
@section('heading', 'Intake Wizard')

@section('content')
    <section
        class="hew-page"
        data-hew-root
        data-session='@json($session)'
        data-reference='@json($reference)'
        data-routes='@json($routes)'
    >
        <div class="hew-shell" data-hew-shell></div>
        <div class="hew-toast" data-hew-toast hidden></div>
    </section>
@endsection
