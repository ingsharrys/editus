<?php

namespace App\Policies;

use App\Models\User;
use App\Models\MetaPost;
use Illuminate\Support\Facades\DB;

class MetaPostPolicy
{
    public function view(User $user, MetaPost $post): bool
    {
        // ¿El usuario está vinculado (activo) a la página del post?
        return DB::table('meta_page_user')
            ->where('user_id', $user->id)
            ->where('meta_page_id', $post->meta_page_id)
            ->where('is_active', true)
            ->exists();
    }
}
