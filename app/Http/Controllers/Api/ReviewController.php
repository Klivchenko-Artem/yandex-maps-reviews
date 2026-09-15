<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReviewController extends Controller
{
    public const PER_PAGE = 50;

    /**
     * Отзывы отдаются из нашей базы, а не из Яндекса: страница открывается за миллисекунды,
     * а пользователь, листающий 12 страниц, не превращается в 12 запросов к источнику.
     */
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        $reviews = $organization->reviews()
            ->visible()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return ReviewResource::collection($reviews);
    }
}
