@extends('layouts.app')

@section('title', 'Turnos')

@section('content')
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Turnos</h1>
        <a href="{{ route('shifts.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
            Novo Turno
        </a>
    </div>

    <div class="mb-4">
        <a href="{{ route('shifts.index') }}" class="text-sm text-blue-600 hover:underline {{ !request('status') ? 'font-bold' : '' }}">Todos</a>
        &middot;
        <a href="{{ route('shifts.index', ['status' => 'draft']) }}" class="text-sm text-blue-600 hover:underline {{ request('status') === 'draft' ? 'font-bold' : '' }}">Rascunho</a>
        &middot;
        <a href="{{ route('shifts.index', ['status' => 'open']) }}" class="text-sm text-blue-600 hover:underline {{ request('status') === 'open' ? 'font-bold' : '' }}">Aberto</a>
        &middot;
        <a href="{{ route('shifts.index', ['status' => 'closed']) }}" class="text-sm text-blue-600 hover:underline {{ request('status') === 'closed' ? 'font-bold' : '' }}">Encerrado</a>
    </div>

    @if($shifts->count() > 0)
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Restaurante</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rastreamento</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Taxa Restaurante</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Criado em</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($shifts as $shift)
                        <tr>
                            <td class="px-6 py-4 text-sm">{{ $shift->restaurant->name }}</td>
                            <td class="px-6 py-4 text-sm">{{ $shift->workflow_type->value }}</td>
                            <td class="px-6 py-4 text-sm">{{ $shift->status->value }}</td>
                            <td class="px-6 py-4 text-sm">{{ $shift->restaurant_rate }}</td>
                            <td class="px-6 py-4 text-sm">{{ $shift->created_at->format('d/m/Y H:i') }}</td>
                            <td class="px-6 py-4 text-sm">
                                <a href="{{ route('shifts.show', $shift) }}" class="text-blue-600 hover:underline">Ver</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $shifts->links() }}
        </div>
    @else
        <p class="text-gray-500">No shifts found</p>
    @endif
@endsection
