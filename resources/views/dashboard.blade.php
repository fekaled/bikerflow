<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - BikerFlow</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 min-h-screen">
    <nav class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
            <span class="text-xl font-bold">BikerFlow</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm text-gray-600 hover:text-gray-900">
                    Sair
                </button>
            </form>
        </div>
    </nav>
    <div class="max-w-7xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold">Dashboard</h1>
        <p class="mt-2 text-gray-600">Bem-vindo(a), {{ auth()->user()->name }}!</p>
    </div>
</body>
</html>
