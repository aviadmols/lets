@extends('emails.layout')

{{-- Default per-charge confirmation. Trusted platform view (Blade-safe). --}}
@section('body')
    <div dir="rtl" style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#1f2937;background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;">
        <h1 style="font-size:20px;font-weight:700;margin:0 0 16px;color:#111827;">שלום {{ $customer_name }},</h1>
        <p style="font-size:15px;line-height:1.6;margin:0 0 14px;">התשלום עבור <strong>{{ $product_title }}</strong> התקבל בהצלחה (תשלום {{ $installment_sequence }} מתוך {{ $installment_count }}).</p>
        <p style="font-size:15px;line-height:1.6;margin:0 0 14px;font-weight:700;">סכום: {{ $amount }} {{ $currency }}</p>
        @if (! empty($invoice_url))
            <a href="{{ $invoice_url }}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-size:15px;font-weight:600;margin:8px 0 16px;">צפייה בחשבונית</a>
        @endif
        @if (! empty($portal_url))
            <p style="font-size:15px;line-height:1.6;margin:0 0 14px;"><a href="{{ $portal_url }}" style="color:#2563eb;">ניהול התוכנית שלי</a></p>
        @endif
        <p style="font-size:12px;line-height:1.5;color:#6b7280;margin:18px 0 0;border-top:1px solid #e5e7eb;padding-top:14px;">מספר תוכנית #{{ $plan_id }} · {{ $businessName }}</p>
    </div>
@endsection
