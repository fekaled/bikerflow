@extends('layouts.app')

@section('title', 'Chaves PIX — ' . $biker->name)

@section('content')
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold">Chaves PIX</h1>
            <p class="text-gray-600 mt-1">
                {{ $biker->name }} — {{ $biker->phone }}
            </p>
        </div>
        <a href="{{ url()->previous() }}" class="text-sm text-gray-600 hover:text-gray-900">
            &larr; Voltar
        </a>
    </div>

    @if(session('success'))
        <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-6">
            <p class="text-green-700">{{ session('success') }}</p>
        </div>
    @endif

    @if(session('error'))
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6">
            <p class="text-red-700">{{ session('error') }}</p>
        </div>
    @endif

    <div class="bg-white rounded-lg shadow p-6">
        @if($pixKeys->isEmpty())
            <p class="text-gray-500 italic">Nenhuma chave PIX cadastrada para este entregador.</p>
        @else
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b">
                        <th class="py-2 px-3">Tipo</th>
                        <th class="py-2 px-3">Chave</th>
                        <th class="py-2 px-3">Titular</th>
                        <th class="py-2 px-3">Status</th>
                        <th class="py-2 px-3">Verificado em</th>
                        <th class="py-2 px-3">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pixKeys as $pixKey)
                        <tr class="border-b">
                            <td class="py-2 px-3">{{ strtoupper($pixKey->key_type) }}</td>
                            <td class="py-2 px-3">{{ $pixKey->key_value }}</td>
                            <td class="py-2 px-3">{{ $pixKey->account_holder_name ?? '—' }}</td>
                            <td class="py-2 px-3">
                                @if($pixKey->is_verified)
                                    <span class="inline-block bg-green-100 text-green-800 text-xs px-2 py-1 rounded">
                                        Verificada
                                    </span>
                                @else
                                    <span class="inline-block bg-red-100 text-red-800 text-xs px-2 py-1 rounded">
                                        Não verificada
                                    </span>
                                @endif
                            </td>
                            <td class="py-2 px-3 text-sm">
                                {{ $pixKey->verified_at ? $pixKey->verified_at->format('d/m/Y H:i') : '—' }}
                            </td>
                            <td class="py-2 px-3">
                                @if($pixKey->is_verified)
                                    <form method="POST" action="{{ route('admin.pix-keys.unverify', $pixKey) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="bg-yellow-500 text-white text-xs px-3 py-1 rounded hover:bg-yellow-600">
                                            Desverificar
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.pix-keys.verify', $pixKey) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="bg-blue-600 text-white text-xs px-3 py-1 rounded hover:bg-blue-700">
                                            Verificar
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
