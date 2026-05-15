@extends('layouts.app')

@section('title', 'Registrar Viagens')

@section('content')
    <h1 class="text-2xl font-bold mb-6">
        Turno #{{ $shift->id }} — {{ $shift->restaurant->name }}
    </h1>

    <p class="text-gray-600 mb-4">
        <span class="font-medium">Workflow:</span> Entrada Manual
        <span class="mx-2">|</span>
        <span class="font-medium">Status:</span> {{ $shift->status->value === 'open' ? 'Aberto' : $shift->status->value }}
    </p>

    <form method="POST" action="{{ route('entry.store', $shift) }}">
        @csrf

        @if($shift->shiftBikers->isEmpty())
            <p class="text-gray-400 italic mb-4">Nenhum entregador atribuído.</p>
        @else
            <table class="w-full bg-white rounded-lg shadow mb-6">
                <thead>
                    <tr class="border-b text-left text-sm text-gray-600">
                        <th class="py-3 px-4">Entregador</th>
                        <th class="py-3 px-4">Viagens</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shift->shiftBikers as $loopIndex => $shiftBiker)
                        <tr class="border-b">
                            <td class="py-2 px-4">
                                {{ $shiftBiker->biker->name }}
                                <input type="hidden"
                                       name="bikers[{{ $loopIndex }}][biker_id]"
                                       value="{{ $shiftBiker->biker_id }}">
                            </td>
                            <td class="py-2 px-4">
                                <input type="number"
                                       name="bikers[{{ $loopIndex }}][trips_count]"
                                       value="{{ $shiftBiker->trips_count }}"
                                       min="0"
                                       required
                                       class="border rounded px-3 py-1 w-24 text-right">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="flex items-center gap-4">
            <button type="submit"
                    class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                Registrar Viagens
            </button>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="close_shift" value="1">
                Encerrar turno
            </label>
        </div>
    </form>
@endsection
