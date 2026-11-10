<?php

use App\Http\Controllers\Api\V1\WebsiteSeoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/robots.txt', [WebsiteSeoController::class, 'robots']);
Route::get('/sitemap.xml', [WebsiteSeoController::class, 'sitemap']);
Route::get('/{organizationSlug}/{path?}', [WebsiteSeoController::class, 'redirect'])
    ->where('organizationSlug', '(?!storage(?:/|$))[^/]+')
    ->where('path', '.*');
