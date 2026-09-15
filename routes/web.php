<?php

use Illuminate\Support\Facades\Route;

// Всё, что не API, отдаёт SPA: страницами управляет Vue Router.
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|up).*$');
