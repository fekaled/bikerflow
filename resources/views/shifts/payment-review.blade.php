@extends('layouts.app')

@section('title', 'Revisão de Pagamentos — Turno #' . $shift->id)

@php
    $statusLabels = [
        'pending' => 'Pendente',
        'processing' => 'Processando',
        'paid' => 'Pago',
        'failed' => 'Falhou',
    ];
    $statusColors = [
        'pending' => 'bg-yellow-100 text-yellow-800',
        'processing' => 'bg-blue-100 text-blue-800',
        'paid' => 'bg-green-100 text-green-800',
        'failed' => 'bg-red-100 text-red-800',
    ];
@endphp

@section('content')
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Revisão de Pagamentos — Turno #{{ $shift->id }}</h1>
        <a href="{{ route('shifts.show', $shift) }}" class="text-sm text-gray-600 hover:text-gray-900">
            &larr; Voltar para o turno
        </a>
    </div>

    @if($shift->status->value === 'approved')
        <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-6">
            <p class="font-semibold text-green-800">Turno Aprovado</p>
            <p class="text-green-700">Todos os pagamentos foram liberados.</p>
        </div>
    @endif

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="grid grid-cols-3 gap-4">
            <div>
                <p class="text-sm text-gray-500">Restaurante</p>
                <p class="font-medium">{{ $shift->restaurant->name }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Total Pendente</p>
                <p class="font-medium">R$ {{ $totalPending }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Total em Processamento</p>
                <p class="font-medium">R$ {{ $totalProcessing }}</p>
            </div>
        </div>
    </div>

    @if(empty($paymentItems))
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <p class="text-gray-500 italic">Nenhum pagamento encontrado para este turno. <span class="lowercase">nenhum pagador</span> associado.</p>
        </div>
    @else
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold mb-4">Pagamentos</h2>
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b">
                        <th class="py-2 px-3">Entregador</th>
                        <th class="py-2 px-3">Valor</th>
                        <th class="py-2 px-3">Receita</th>
                        <th class="py-2 px-3">PIX</th>
                        <th class="py-2 px-3">Conta</th>
                        <th class="py-2 px-3">Status</th>
                        <th class="py-2 px-3">Motivos de Bloqueio</th>
                        <th class="py-2 px-3">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($paymentItems as $item)
                        <tr class="border-b">
                            <td class="py-2 px-3">{{ $item['biker']->name }}</td>
                            <td class="py-2 px-3">R$ {{ $item['payment']->amount }}</td>
                            <td class="py-2 px-3">R$ {{ $item['payment']->revenue }}</td>
                            <td class="py-2 px-3">
                                @if($item['hasVerifiedPixKey'])
                                    <span class="text-green-600 text-sm">PIX verificada ✓</span>
                                @else
                                    <span class="text-red-600 text-sm">PIX não verificada ✗</span>
                                @endif
                            </td>
                            <td class="py-2 px-3">
                                @if($item['hasUser'])
                                    <span class="text-green-600 text-sm">conta vinculada ✓</span>
                                @else
                                    <span class="text-red-600 text-sm">Sem conta ✗</span>
                                @endif
                            </td>
                            <td class="py-2 px-3">
                                <span class="inline-block {{ $statusColors[$item['payment']->status->value] ?? 'bg-gray-100 text-gray-800' }} text-xs px-2 py-1 rounded">
                                    {{ $statusLabels[$item['payment']->status->value] ?? $item['payment']->status->value }}
                                    <span class="sr-only">{{ $item['payment']->status->value }}</span>
                                </span>
                            </td>
                            <td class="py-2 px-3">
                                @foreach($item['blockReasons'] as $reason)
                                    <span class="inline-block bg-red-100 text-red-800 text-xs px-2 py-1 rounded mr-1 mb-1">
                                        {{ $reason }}
                                    </span>
                                @endforeach
                            </td>
                            <td class="py-2 px-3">
                                @if($item['isEligible'])
                                    <form method="POST" action="{{ route('shifts.payments.release', [$shift, $item['payment']]) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="bg-blue-600 text-white text-xs px-3 py-1 rounded hover:bg-blue-700">
                                            Liberar
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($eligibleCount > 0 && $shift->status->value !== 'approved')
            <div class="bg-white rounded-lg shadow p-6">
                <form method="POST" action="{{ route('shifts.payments.release-all', $shift) }}">
                    @csrf
                    <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded hover:bg-green-700">
                        Liberar Todos Elegíveis
                    </button>
                </form>
            </div>
        @endif
    @endif
@endsection
