@extends('emails.layout')

{{--
  Wrapper for MERCHANT-EDITED email HTML.

  SECURITY: $renderedHtml has ALREADY been substituted by TemplateRenderer via
  strtr() (NEVER Blade). This view does NOTHING but echo that pre-rendered string
  with {!! !!}. It MUST NOT pass merchant input through @php, Blade::render(),
  Str::of()->markdown(), or any compiler — doing so would re-introduce the RCE the
  strtr rule exists to prevent. The string is inert HTML at this point.

  Inline CSS inside the merchant body is allowed (email exception). Preview of
  this same string happens in an isolated iframe srcdoc + htmlspecialchars
  (EmailPreviewRenderer) — never executed.
--}}
@section('body')
    {!! $renderedHtml !!}
@endsection
