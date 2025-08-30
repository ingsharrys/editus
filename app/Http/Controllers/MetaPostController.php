<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MetaPost;
use Illuminate\Support\Facades\DB;


class MetaPostController extends Controller
{
    public function index(Request $request)
    {
        $isAdmin = (int)($request->user()->role_id ?? 0) === 1;

        $groups = MetaPost::query()
            ->when(!$isAdmin, fn($q) => $q->where('user_id', $request->user()->id))
            ->whereNotNull('batch_uuid')
            ->select([
                'batch_uuid',
                'type',
                DB::raw('MIN(created_at) as first_at'),
                DB::raw('MIN(COALESCE(message,"")) as message'),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as ok"),
                DB::raw("SUM(CASE WHEN status='fail' THEN 1 ELSE 0 END) as fails"),
            ])
            ->groupBy('batch_uuid', 'type')
            ->orderByDesc(DB::raw('MIN(created_at)'))
            ->paginate(20);

        return view('meta_posts.index', compact('groups', 'isAdmin'));
    }

    public function show(Request $request, string $batch)
    {
        $isAdmin = (int)($request->user()->role_id ?? 0) === 1;

        $posts = MetaPost::with(['page:id,name,page_id', 'user:id,name'])
            ->when(!$isAdmin, fn($q) => $q->where('user_id', $request->user()->id))
            ->where('batch_uuid', $batch)
            ->orderByDesc('created_at')
            ->get();

        abort_if($posts->isEmpty(), 404);

        $head = $posts->first();
        $summary = [
            'batch'    => $batch,
            'type'     => $head->type,
            'message'  => $head->message,
            'user'     => $head->user,
            'first_at' => $posts->min('created_at'),
            'total'    => $posts->count(),
            'ok'       => $posts->where('status', 'success')->count(),
            'fails'    => $posts->where('status', 'fail')->count(),
        ];

        return view('meta_posts.show', compact('summary', 'posts', 'isAdmin'));
    }
}
