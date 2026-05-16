@php
    $isMutable = in_array($shift->status->value, ['draft', 'open']);
    $shiftBikers = $shift->shiftBikers;
    $isClosed = $shift->status->value === 'closed';
@endphp

<div class="mt-8 bg-white rounded-lg shadow p-6">
    <h2 class="text-lg font-semibold mb-4">Entregadores Atribuídos</h2>

    @if($shiftBikers->isEmpty())
        <p class="text-gray-500 italic">Nenhum entregador atribuído</p>
    @else
        <table class="w-full text-left">
            <thead>
                <tr class="border-b">
                    <th class="py-2 px-3">Nome</th>
                    <th class="py-2 px-3">Taxa por Viagem</th>
                    <th class="py-2 px-3">Taxa Base</th>
                    <th class="py-2 px-3">Viagens</th>
                    @if($isClosed)
                        <th class="py-2 px-3">Pagamento</th>
                        <th class="py-2 px-3">Receita</th>
                        <th class="py-2 px-3">Status</th>
                    @endif
                    @if($isMutable)
                        <th class="py-2 px-3">Ações</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($shiftBikers as $sb)
                    <tr class="border-b">
                        <td class="py-2 px-3">{{ $sb->biker->name }}</td>
                        <td class="py-2 px-3">{{ $sb->biker_rate }}</td>
                        <td class="py-2 px-3">{{ $sb->base_fee }}</td>
                        <td class="py-2 px-3">{{ $sb->trips_count }}</td>
                        @if($isClosed)
                            <td class="py-2 px-3">{{ $sb->payment ? 'R$ ' . $sb->payment->amount : '—' }}</td>
                            <td class="py-2 px-3">{{ $sb->payment ? 'R$ ' . $sb->payment->revenue : '—' }}</td>
                            <td class="py-2 px-3">{{ $sb->payment ? $sb->payment->status->value : '—' }}</td>
                        @endif
                        @if($isMutable)
                            <td class="py-2 px-3 flex gap-2">
                                <form method="POST" action="{{ route('shifts.bikers.update', [$shift, $sb]) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="text-blue-600 hover:underline text-sm">Editar</button>
                                </form>
                                <form method="POST" action="{{ route('shifts.bikers.destroy', [$shift, $sb]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:underline text-sm">Remover</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($isMutable)
        <div class="mt-6 border-t pt-4">
            <h3 class="text-md font-medium mb-2">Atribuir Entregador</h3>
            <form method="POST" action="{{ route('shifts.bikers.store', $shift) }}">
                @csrf
                <div class="flex gap-4 items-end">
                    <div>
                        <label class="block text-sm text-gray-600">Entregador</label>
                        <select name="biker_id" class="border rounded px-3 py-2">
                            @foreach(\App\Models\Biker::where('active', true)->orderBy('name')->get() as $biker)
                                <option value="{{ $biker->id }}">{{ $biker->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600">Taxa por Viagem</label>
                        <input type="text" name="biker_rate" class="border rounded px-3 py-2 w-28" />
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600">Taxa Base</label>
                        <input type="text" name="base_fee" class="border rounded px-3 py-2 w-28" />
                    </div>
                    <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                        Atribuir
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
