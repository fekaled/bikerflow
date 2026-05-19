@extends('layouts.app')

@section('title', 'Painel de Margens')

@section('content')
    <h1 class="text-2xl font-bold mb-6">Painel de Margens</h1>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">

        {{-- Card 1: Receita Total --}}
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500 mb-2">Receita Total</p>
            <p class="text-2xl font-semibold">{{ $brl_total_revenue }}</p>
        </div>

        {{-- Card 2: Pagamentos --}}
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500 mb-2">Pagamentos</p>
            <p class="text-2xl font-semibold">{{ $brl_total_payout }}</p>
        </div>

        {{-- Card 3: Margem Líquida --}}
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500 mb-2">Margem Líquida</p>
            <p class="text-2xl font-semibold {{ str_starts_with($brl_net_margin, '-') ? 'text-red-600' : 'text-green-600' }}">
                {{ $brl_net_margin }}
            </p>
        </div>

        {{-- Card 4: Turnos Fechados --}}
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500 mb-2">Turnos Fechados</p>
            <p class="text-2xl font-semibold">{{ $shift_count }}</p>
        </div>

        {{-- Card 5: Pagamentos (Pago/Pendente) --}}
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500 mb-2">Pagamentos (Pago/Pendente)</p>
            <p class="text-2xl font-semibold">
                <span class="text-green-600">{{ $paid_count }}</span> /
                <span class="text-yellow-500">{{ $unpaid_count }}</span>
            </p>
            <p class="text-xs text-gray-400 mt-1">
                Pago: {{ $payment_detail['paid'] ?? 0 }} |
                Pendente: {{ $payment_detail['pending'] ?? 0 }} |
                Falha: {{ $payment_detail['failed'] ?? 0 }} |
                Processando: {{ $payment_detail['processing'] ?? 0 }}
            </p>
        </div>
    </div>
@endsection
