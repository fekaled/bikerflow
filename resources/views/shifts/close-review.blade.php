@extends('layouts.app')

@section('title', 'Revisão de Encerramento — Turno #' . $shift->id)

@section('content')
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Revisão de Encerramento — Turno #{{ $shift->id }}</h1>
        <a href="{{ route('shifts.show', $shift) }}" class="text-sm text-gray-600 hover:text-gray-900">
            &larr; Voltar para o turno
        </a>
    </div>

    @if($hasWarnings)
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
            <p class="font-semibold text-yellow-800">Atenção</p>
            <p class="text-yellow-700">Um ou mais entregadores possuem alertas de elegibilidade.</p>
        </div>
    @endif

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <p class="text-sm text-gray-500">Restaurante</p>
                <p class="font-medium">{{ $shift->restaurant->name }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Taxa do Restaurante</p>
                <p class="font-medium">R$ {{ $shift->restaurant_rate }}</p>
            </div>
        </div>
    </div>

    @if(empty($reviewItems))
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <p class="text-gray-500 italic">Nenhum entregador atribuído a este turno.</p>
        </div>
    @else
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold mb-4">Resumo de Pagamentos</h2>
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b">
                        <th class="py-2 px-3">Entregador</th>
                        <th class="py-2 px-3">Viagens</th>
                        <th class="py-2 px-3">Taxa Base</th>
                        <th class="py-2 px-3">Taxa/Viagem</th>
                        <th class="py-2 px-3">Pagamento</th>
                        <th class="py-2 px-3">Receita</th>
                        <th class="py-2 px-3">Alertas</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reviewItems as $item)
                        <tr class="border-b">
                            <td class="py-2 px-3">{{ $item['biker']->name }}</td>
                            <td class="py-2 px-3">{{ $item['shiftBiker']->trips_count }}</td>
                            <td class="py-2 px-3">{{ $item['shiftBiker']->base_fee }}</td>
                            <td class="py-2 px-3">{{ $item['shiftBiker']->biker_rate }}</td>
                            <td class="py-2 px-3">R$ {{ $item['payout'] }}</td>
                            <td class="py-2 px-3">R$ {{ $item['revenue'] }}</td>
                            <td class="py-2 px-3">
                                @foreach($item['warnings'] as $warning)
                                    <span class="inline-block bg-yellow-100 text-yellow-800 text-xs px-2 py-1 rounded mr-1 mb-1">
                                        {{ $warning }}
                                    </span>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 font-semibold">
                        <td class="py-2 px-3" colspan="4">Total</td>
                        <td class="py-2 px-3">R$ {{ $totalPayout }}</td>
                        <td class="py-2 px-3">R$ {{ $totalRevenue }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST" action="{{ route('shifts.close', $shift) }}">
            @csrf
            <div class="mb-4">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="confirmed" id="confirmed" value="1"
                        class="rounded border-gray-300 text-blue-600 shadow-sm"
                        onchange="document.getElementById('btn-confirm').disabled = !this.checked" />
                    <span>Confirmo que não há viagens contestadas</span>
                </label>
            </div>
            <button type="submit" id="btn-confirm" disabled
                class="bg-red-600 text-white px-6 py-2 rounded hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed">
                Confirmar Encerramento
            </button>
        </form>
    </div>
@endsection
