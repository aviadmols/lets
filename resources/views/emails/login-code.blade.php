@extends('emails.layout')

{{-- Default sign-in code. Trusted platform view (Blade-safe).
     No link anywhere on purpose: a one-time code that also arrives as a clickable
     button is a phishing template, and the shopper is already sitting on the page
     that asked for it. --}}
@section('body')
    <div dir="rtl" style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#1f2937;background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;">
        <h1 style="font-size:20px;font-weight:700;margin:0 0 16px;color:#111827;">קוד הכניסה שלך</h1>
        <p style="font-size:15px;line-height:1.6;margin:0 0 14px;">הזינו את הקוד הבא באזור האישי של {{ $businessName }}:</p>
        <p style="direction:ltr;unicode-bidi:isolate;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:34px;font-weight:700;letter-spacing:8px;margin:8px 0 18px;color:#111827;">{{ $code }}</p>
        <p style="font-size:15px;line-height:1.6;margin:0 0 14px;">הקוד תקף ל-{{ $expires_minutes }} דקות.</p>
        <p style="font-size:12px;line-height:1.5;color:#6b7280;margin:18px 0 0;border-top:1px solid #e5e7eb;padding-top:14px;">לא ביקשתם קוד? אפשר להתעלם מההודעה — בלי הקוד אי אפשר להיכנס. · {{ $businessName }}</p>
    </div>
@endsection
