@extends('layouts.app')

@section('title', 'Turno #' . $shift->id)

@php
    $statusLabels = [
        'draft' => 'Rascunho',
        'open' => 'Aberto',
        'closed' => 'Encerrado',
        'approved' => 'Aprovado',
        'paid' => 'Pago',
    ];
    $workflowLabels = [
        'live_tick' => 'Contagem em Tempo Real',
        'manual_entry' => 'Entrada Manual',
    ];
@endphp

@section('content')
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Turno #{{ $shift->id }}</h1>
        <a href="{{ route('shifts.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
            &larr; Voltar para lista
        </a>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <p class="text-sm text-gray-500">Restaurante</p>
                <p class="font-medium">{{ $shift->restaurant->name }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Método de Rastreamento</p>
                <p class="font-medium">{{ $workflowLabels[$shift->workflow_type->value] ?? $shift->workflow_type->value }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Status</p>
                <p class="font-medium">{{ $statusLabels[$shift->status->value] ?? $shift->status->value }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Taxa do Restaurante</p>
                <p class="font-medium">R$ {{ $shift->restaurant_rate }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Iniciado em</p>
                <p class="font-medium">{{ $shift->started_at ? $shift->started_at->format('d/m/Y H:i') : '—' }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Encerrado em</p>
                <p class="font-medium">{{ $shift->closed_at ? $shift->closed_at->format('d/m/Y H:i') : '—' }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Criado por</p>
                <p class="font-medium">{{ $shift->created_by ? App\Models\User::find($shift->created_by)?->name : '—' }}</p>
            </div>
        </div>

        <div class="mt-6 flex gap-4">
            @if($shift->status->value === 'draft')
                <a href="{{ route('shifts.edit', $shift) }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    Editar
                </a>
            @endif

            @if($shift->status->value === 'open')
                <a href="{{ route('shifts.close.review', $shift) }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">
                    Encerrar Turno
                </a>
            @endif

            @if(in_array($shift->status->value, ['closed', 'approved']))
                <a href="{{ route('shifts.payments.review', $shift) }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    Revisar Pagamentos
                </a>
            @endif

            @if(in_array($shift->status->value, ['approved', 'paid']))
                <a href="{{ route('shifts.payments.status', $shift) }}" class="bg-purple-600 text-white px-4 py-2 rounded hover:bg-purple-700">
                    Ver Status de Pagamentos
                </a>
            @endif
        </div>
    </div>

    @include('shifts.partials.biker-assignments')
@endsection
