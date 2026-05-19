@extends('layouts.app')

@section('title', 'Status de Pagamentos — Turno #' . $shift->id)

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
    $gatewayStatusLabels = [
        'processed' => 'Processado',
        'queued' => 'Na fila',
        'error' => 'Erro',
        'failed' => 'Falhou',
    ];
    $gatewayStatusColors = [
        'processed' => 'bg-green-100 text-green-800',
        'queued' => 'bg-blue-100 text-blue-800',
        'error' => 'bg-orange-100 text-orange-800',
        'failed' => 'bg-red-100 text-red-800',
    ];
@endphp

@section('content')
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Status de Pagamentos — Turno #{{ $shift->id }}</h1>
        <div class="flex gap-3">
            <a href="{{ route('shifts.show', $shift) }}" class="text-sm text-gray-600 hover:text-gray-900">
                &larr; Voltar para o turno
            </a>
        </div>
    </div>

    @if($shift->status->value === 'paid')
        <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-6">
            <p class="font-semibold text-green-800">Turno Pago</p>
            <p class="text-green-700">Todos os pagamentos foram concluídos com sucesso.</p>
        </div>
    @endif

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <p class="text-sm text-gray-500">Restaurante</p>
                <p class="font-medium">{{ $shift->restaurant->name }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Status do Turno</p>
                <p class="font-medium">{{ $shift->status->value === 'paid' ? 'Pago' : 'Aprovado' }}</p>
            </div>
        </div>
    </div>

    {{-- Totals Summary --}}
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-blue-50 rounded-lg p-4">
            <p class="text-sm text-blue-600 font-medium">Processando</p>
            <p class="text-xl font-bold text-blue-800">R$ {{ $totals['processing'] }}</p>
            <p class="text-xs text-blue-500">{{ count($groups['processing']) }} pagamento(s)</p>
        </div>
        <div class="bg-red-50 rounded-lg p-4">
            <p class="text-sm text-red-600 font-medium">Falharam</p>
            <p class="text-xl font-bold text-red-800">R$ {{ $totals['failed'] }}</p>
            <p class="text-xs text-red-500">{{ count($groups['failed']) }} pagamento(s)</p>
        </div>
        <div class="bg-green-50 rounded-lg p-4">
            <p class="text-sm text-green-600 font-medium">Pagos</p>
            <p class="text-xl font-bold text-green-800">R$ {{ $totals['paid'] }}</p>
            <p class="text-xs text-green-500">{{ count($groups['paid']) }} pagamento(s)</p>
        </div>
    </div>

    {{-- Processing Payments --}}
    @if(count($groups['processing']) > 0)
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold mb-4 text-blue-800">Em Processamento</h2>
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b">
                        <th class="py-2 px-3">Entregador</th>
                        <th class="py-2 px-3">Valor</th>
                        <th class="py-2 px-3">Status</th>
                        <th class="py-2 px-3">PIX Gateway / ID Transação</th>
                        <th class="py-2 px-3">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($groups['processing'] as $item)
                        <tr class="border-b">
                            <td class="py-2 px-3">{{ $item['biker']->name }}</td>
                            <td class="py-2 px-3">R$ {{ $item['payment']->amount }}</td>
                            <td class="py-2 px-3">
                                <span class="inline-block {{ $statusColors[$item['payment']->status->value] ?? 'bg-gray-100 text-gray-800' }} text-xs px-2 py-1 rounded">
                                    {{ $statusLabels[$item['payment']->status->value] ?? $item['payment']->status->value }}
                                </span>
                                @if($item['payment']->gateway_status)
                                    <span class="inline-block ml-1 {{ $gatewayStatusColors[$item['payment']->gateway_status] ?? 'bg-gray-100 text-gray-800' }} text-xs px-2 py-1 rounded">
                                        PIX: {{ $gatewayStatusLabels[$item['payment']->gateway_status] ?? $item['payment']->gateway_status }}
                                    </span>
                                @endif
                            </td>
                            <td class="py-2 px-3 text-sm">
                                @if($item['payment']->gateway_transaction_id)
                                    <span class="text-gray-500" title="ID Transação Gateway">
                                        {{ Str::limit($item['payment']->gateway_transaction_id, 20) }}
                                    </span>
                                @endif
                            </td>
                            <td class="py-2 px-3 flex gap-2">
                                <form method="POST" action="{{ route('shifts.payments.mark-paid', [$shift, $item['payment']]) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="bg-green-600 text-white text-xs px-3 py-1 rounded hover:bg-green-700">
                                        Marcar como Pago
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('shifts.payments.mark-failed', [$shift, $item['payment']]) }}" style="display:inline;">
                                    @csrf
                                    <button type="button" class="bg-red-600 text-white text-xs px-3 py-1 rounded hover:bg-red-700"
                                            onclick="this.closest('form').querySelector('.failure-reason').classList.toggle('hidden'); this.classList.toggle('hidden');">
                                        Marcar como Falha
                                    </button>
                                    <div class="hidden failure-reason mt-2">
                                        <input type="text" name="failure_reason" placeholder="Motivo da falha (mín. 3 caracteres)"
                                               class="border rounded px-2 py-1 text-xs w-64" required minlength="3" maxlength="500" />
                                        <button type="submit" class="bg-red-700 text-white text-xs px-2 py-1 rounded ml-1 hover:bg-red-800">
                                            Confirmar Falha
                                        </button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Failed Payments --}}
    @if(count($groups['failed']) > 0)
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold mb-4 text-red-800">Falharam</h2>
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b">
                        <th class="py-2 px-3">Entregador</th>
                        <th class="py-2 px-3">Valor</th>
                        <th class="py-2 px-3">Status</th>
                        <th class="py-2 px-3">Motivo</th>
                        <th class="py-2 px-3">Tentativas</th>
                        <th class="py-2 px-3">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($groups['failed'] as $item)
                        <tr class="border-b">
                            <td class="py-2 px-3">{{ $item['biker']->name }}</td>
                            <td class="py-2 px-3">R$ {{ $item['payment']->amount }}</td>
                            <td class="py-2 px-3">
                                <span class="inline-block {{ $statusColors[$item['payment']->status->value] ?? 'bg-gray-100 text-gray-800' }} text-xs px-2 py-1 rounded">
                                    {{ $statusLabels[$item['payment']->status->value] ?? $item['payment']->status->value }}
                                </span>
                            </td>
                            <td class="py-2 px-3 text-sm text-gray-700">
                                {{ $item['payment']->failure_reason ?? '—' }}
                            </td>
                            <td class="py-2 px-3 text-sm">
                                {{ $item['payment']->retry_count }}/3
                            </td>
                            <td class="py-2 px-3">
                                @if($item['payment']->retry_count >= 3)
                                    <div class="bg-orange-100 border-l-4 border-orange-400 p-2 rounded">
                                        <p class="text-orange-800 text-xs font-semibold">Intervenção manual necessária</p>
                                        <p class="text-orange-700 text-xs">Considerar transferência bancária manual ou contatar o entregador.</p>
                                    </div>
                                @elseif($item['isEligibleForRetry'])
                                    <form method="POST" action="{{ route('shifts.payments.retry', [$shift, $item['payment']]) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="bg-yellow-500 text-white text-xs px-3 py-1 rounded hover:bg-yellow-600">
                                            Tentar Novamente
                                        </button>
                                    </form>
                                @else
                                    <span class="text-gray-400 text-xs">Inelegível para retry</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Paid Payments --}}
    @if(count($groups['paid']) > 0)
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold mb-4 text-green-800">Pagos</h2>
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b">
                        <th class="py-2 px-3">Entregador</th>
                        <th class="py-2 px-3">Valor</th>
                        <th class="py-2 px-3">Status</th>
                        <th class="py-2 px-3">Pago em</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($groups['paid'] as $item)
                        <tr class="border-b">
                            <td class="py-2 px-3">{{ $item['biker']->name }}</td>
                            <td class="py-2 px-3">R$ {{ $item['payment']->amount }}</td>
                            <td class="py-2 px-3">
                                <span class="inline-block {{ $statusColors[$item['payment']->status->value] ?? 'bg-gray-100 text-gray-800' }} text-xs px-2 py-1 rounded">
                                    {{ $statusLabels[$item['payment']->status->value] ?? $item['payment']->status->value }}
                                </span>
                            </td>
                            <td class="py-2 px-3 text-sm">
                                {{ $item['payment']->paid_at ? $item['payment']->paid_at->format('d/m/Y H:i') : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(count($groups['processing']) === 0 && count($groups['failed']) === 0 && count($groups['paid']) === 0)
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <p class="text-gray-500 italic">Nenhum pagamento encontrado para este turno.</p>
        </div>
    @endif
@endsection
