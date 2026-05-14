@extends('layouts.app')

@section('title', 'Novo Turno')

@section('content')
    <h1 class="text-2xl font-bold mb-6">Novo Turno</h1>

    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST" action="{{ route('shifts.store') }}">
            @csrf

            <div class="mb-4">
                <label for="restaurant_id" class="block text-sm font-medium text-gray-700 mb-1">Restaurante</label>
                <select name="restaurant_id" id="restaurant_id" class="w-full border rounded px-3 py-2">
                    <option value="">Selecione um restaurante</option>
                    @foreach($restaurants as $restaurant)
                        <option value="{{ $restaurant->id }}" {{ old('restaurant_id') == $restaurant->id ? 'selected' : '' }}>
                            {{ $restaurant->name }}
                        </option>
                    @endforeach
                </select>
                @error('restaurant_id')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label for="workflow_type" class="block text-sm font-medium text-gray-700 mb-1">Método de Rastreamento</label>
                <select name="workflow_type" id="workflow_type" class="w-full border rounded px-3 py-2">
                    <option value="live_tick" {{ old('workflow_type', 'live_tick') === 'live_tick' ? 'selected' : '' }}>Contagem em Tempo Real</option>
                    <option value="manual_entry" {{ old('workflow_type') === 'manual_entry' ? 'selected' : '' }}>Entrada Manual</option>
                </select>
                @error('workflow_type')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label for="restaurant_rate" class="block text-sm font-medium text-gray-700 mb-1">Taxa do Restaurante (R$)</label>
                <input type="text" name="restaurant_rate" id="restaurant_rate"
                    value="{{ old('restaurant_rate') }}"
                    class="w-full border rounded px-3 py-2" step="0.01">
                @error('restaurant_rate')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex gap-4">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    Criar Turno
                </button>
                <a href="{{ route('shifts.index') }}" class="text-gray-600 hover:text-gray-900 px-4 py-2">
                    Cancelar
                </a>
            </div>
        </form>
    </div>
@endsection
