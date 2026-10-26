<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name', 'Robetting'))</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container-xl">
            <a class="navbar-brand fw-bold" href="{{ route('home') }}">{{ config('app.name', 'Robetting') }}</a>
        </div>
    </nav>
    {{-- Rough test navigation (testing phase only): competition pages + quick match links. --}}
    <div class="container-xl mb-3" data-test-nav>
        <div class="d-flex flex-wrap align-items-center gap-2 small">
            <a href="{{ route('home') }}">Home</a>
            @foreach([
                'serie-a'               => 'Serie A',
                'premier-league'        => 'Premier League',
                'la-liga'               => 'La Liga',
                'bundesliga'            => 'Bundesliga',
                'ligue-1'               => 'Ligue 1',
                'uefa-champions-league' => 'Champions League',
                'uefa-europa-league'    => 'Europa League',
                'uefa-conference-league' => 'Conference League',
            ] as $navSlug => $navLabel)
            <span class="text-muted">·</span>
            <a href="{{ route('competitions.show', ['competition' => $navSlug]) }}">{{ $navLabel }}</a>
            @endforeach
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 small mt-1">
            <span class="text-muted">Test rapidi:</span>
            <a href="{{ route('matches.show', 10692) }}">Napoli-Bodø/Glimt</a>
            <span class="text-muted">·</span>
            <a href="{{ route('matches.show', 5370) }}">Monza-Napoli</a>
        </div>
    </div>
    <main class="container-xl pb-5">
        @yield('content')
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
