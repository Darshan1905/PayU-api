@extends('payment.layout')

@section('title', 'Payment Failed')

@php
  $pageBg = '#fef2f2';
  $iconBg = '#fee2e2';
  $iconColor = '#dc2626';
@endphp

@section('content')
<div class="card" style="--page-bg: {{ $pageBg }}; --icon-bg: {{ $iconBg }}; --icon-color: {{ $iconColor }}; --btn-bg: #dc2626;">
  <div class="icon">✕</div>
  <h1>Payment Failed</h1>
  <p class="lead">Your payment could not be completed. No amount has been charged, or the transaction was cancelled.</p>

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

  <a class="btn" href="{{ $shopUrl }}">Try Again</a>
  <p class="footer">Pavokart · Secure payment via PayU</p>
</div>
@endsection
