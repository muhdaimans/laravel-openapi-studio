<?php

use Illuminate\Support\Facades\Route;
use OpenApiStudio\Http\Controllers\EditorController;
use OpenApiStudio\Http\Controllers\SpecController;

$name = '[A-Za-z0-9][A-Za-z0-9._-]*';

Route::get('/', [EditorController::class, 'index'])->name('index');
Route::get('assets/{file}', [EditorController::class, 'asset'])->name('asset');

Route::prefix('api')->name('api.')->group(function () use ($name) {
    Route::get('specs', [SpecController::class, 'index'])->name('specs.index');
    Route::post('specs', [SpecController::class, 'store'])->name('specs.store');
    Route::get('specs/{name}', [SpecController::class, 'show'])->where('name', $name)->name('specs.show');
    Route::put('specs/{name}', [SpecController::class, 'update'])->where('name', $name)->name('specs.update');
    Route::delete('specs/{name}', [SpecController::class, 'destroy'])->where('name', $name)->name('specs.destroy');
    Route::get('specs/{name}/export', [SpecController::class, 'export'])->where('name', $name)->name('specs.export');
    Route::post('import', [SpecController::class, 'import'])->name('import');
    Route::post('convert/parse', [SpecController::class, 'parse'])->name('convert.parse');
    Route::post('convert/dump', [SpecController::class, 'dump'])->name('convert.dump');
});
