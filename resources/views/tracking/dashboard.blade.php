@extends('layouts.app')

@section('title', 'Acompanhamento')

@section('content')
    <h1 class="text-2xl font-bold mb-6">Acompanhamento ao Vivo</h1>

    @if($shifts->isEmpty())
        <p class="text-gray-500">Nenhum turno aberto no momento.</p>
    @else
        @foreach($shifts as $shift)
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-lg font-semibold mb-4">
                    Turno #{{ $shift->id }} — {{ $shift->restaurant->name }}
                    <span class="ml-2 text-sm text-gray-500">
                        Iniciado em {{ $shift->started_at->format('d/m/Y H:i') }}
                    </span>
                </h2>

                @if($shift->shiftBikers->isEmpty())
                    <p class="text-gray-400 italic">Nenhum entregador atribuído.</p>
                @else
                    <table class="w-full">
                        <thead>
                            <tr class="border-b text-left text-sm text-gray-600">
                                <th class="py-2 pr-4">Entregador</th>
                                <th class="py-2 pr-4">Viagens</th>
                                <th class="py-2">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($shift->shiftBikers as $shiftBiker)
                                <tr class="border-b">
                                    <td class="py-2 pr-4">{{ $shiftBiker->biker->name }}</td>
                                    <td class="py-2 pr-4">{{ $shiftBiker->trips_count }}</td>
                                    <td class="py-2">
                                        @if($shift->workflow_type->value === 'live_tick')
                                            <form method="POST" action="{{ route('tracking.tick', $shift) }}" style="display:inline;">
                                                @csrf
                                                <input type="hidden" name="biker_id" value="{{ $shiftBiker->biker_id }}">
                                                <button type="submit" class="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700">
                                                    +1 Viagem
                                                </button>
                                            </form>
                                        @else
                                            <span class="text-gray-400 text-sm">Contagem manual</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endforeach
    @endif
@endsection
