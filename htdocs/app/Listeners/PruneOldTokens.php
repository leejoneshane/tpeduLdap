<?php

namespace App\Listeners;

use DB;
use Laravel\Passport\Events\RefreshTokenCreated;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class PruneOldTokens
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  RefreshTokenCreated  $event
     * @return void
     */
    public function handle(RefreshTokenCreated $event)
    {
        try {
            DB::table('oauth_refresh_tokens')
                ->where('id', '<>', $event->refreshTokenId)
                ->where('access_token_id', '<>', $event->accessTokenId)
                ->where('revoked', true)
                ->save();
        } catch (\Exception $e) {}
    }
}
