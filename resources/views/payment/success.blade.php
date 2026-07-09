@extends('payment.layout')

@section('title', 'Payment Successful')

@php
  $pageBg = '#f0fdf4';
  $iconBg = '#ccfbf1';
  $iconColor = '#0f766e';
@endphp

@section('content')
<div class="card" style="--page-bg: {{ $pageBg }}; --icon-bg: {{ $iconBg }}; --icon-color: {{ $iconColor }};">
  <div class="icon">✓</div>
  <h1>Payment Successful</h1>
  <p class="lead">Your payment was completed successfully. Thank you for your purchase.</p>

  @if ($txnId || $orderId || $paymentId || $amount)
  <div class="details">
    @if ($txnId)
    <div class="row"><span class="label">Transaction ID</span><span class="value">{{ $txnId }}</span></div>
    @endif
    @if ($orderId)
    <div class="row"><span class="label">Order ID</span><span class="value">{{ $orderId }}</span></div>
    @endif
    @if ($paymentId)
    <div class="row"><span class="label">Payment ID</span><span class="value">{{ $paymentId }}</span></div>
    @endif
    @if ($amount)
    <div class="row"><span class="label">Amount</span><span class="value">₹ {{ $amount }}</span></div>
    @endif
    @if ($status)
    <div class="row"><span class="label">Status</span><span class="value">{{ $status }}</span></div>
    @endif
  </div>
  @endif

  <a class="btn" href="{{ $shopUrl }}">Continue Shopping</a>
  <p class="footer">Pavokart · Secure payment via PayU</p>
</div>
@endsection
