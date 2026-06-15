@extends('upsell::storefront.layout', ['title' => config('app.name')])

{{-- No active flow matched this purchase: render nothing visible (an empty,
     unobtrusive container) so the thank-you page is undisturbed. --}}
@section('widget')
@endsection
