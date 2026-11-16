<x-mail::message>

Thank you for your order **#{{ $order->id }}**.

**Status:** {{ $order->status }}

We will notify you when the status changes.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>