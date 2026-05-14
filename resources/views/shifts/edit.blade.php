@extends('layouts.app')

@section('title', 'Editar Turno')

@section('content')
    <h1 class="text-2xl font-bold mb-6">Editar Turno #{{ $shift->id }}</h1>

    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST" action="{{ route('shifts.update', $shift) }}">
            @csrf
            @method('PUT')

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Método de Rastreamento</label>
                @if($shift->status->value === 'draft')
                    <select name="workflow_type" id="workflow_type" class="w-full border rounded px-3 py-2">
                        <option value="live_tick" {{ old('workflow_type', $shift->workflow_type->value) === 'live_tick' ? 'selected' : '' }}>Contagem em Tempo Real</option>
                        <option value="manual_entry" {{ old('workflow_type', $shift->workflow_type->value) === 'manual_entry' ? 'selected' : '' }}>Entrada Manual</option>
                    </select>
                @else
                    <input type="text" value="{{ $shift->workflow_type->value === 'live_tick' ? 'Contagem em Tempo Real' : 'Entrada Manual' }}"
                        class="w-full border rounded px-3 py-2 bg-gray-100" disabled>
                    <input type="hidden" name="workflow_type" value="{{ $shift->workflow_type->value }}">
                @endif
                @error('workflow_type')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label for="restaurant_rate" class="block text-sm font-medium text-gray-700 mb-1">Taxa do Restaurante (R$)</label>
                <input type="text" name="restaurant_rate" id="restaurant_rate"
                    value="{{ old('restaurant_rate', $shift->restaurant_rate) }}"
                    class="w-full border rounded px-3 py-2" step="0.01">
                @error('restaurant_rate')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex gap-4">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    Salvar
                </button>
                <a href="{{ route('shifts.show', $shift) }}" class="text-gray-600 hover:text-gray-900 px-4 py-2">
                    Cancelar
                </a>
            </div>
        </form>
    </div>
@endsection
