<?php

namespace App\Providers;

use App\Models\User;
use App\Models\Member;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Crypt;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Boot the authentication services for the application.
     *
     * @return void
     */
    public function boot()
    {
        // Here you may define how you wish users to be authenticated for your Lumen
        // application. The callback which receives the incoming request instance
        // should return either a User instance or null. You're free to obtain
        // the User instance via an API token or any other method necessary.

        $this->app['auth']->viaRequest('api', function ($request) {

            // $api_token = $request->input('api_token'); // ? api_token on url params

            if ($request->header('Authorization')) {
                $idToken = $request->header('Authorization'); // ? api_token on header
                $idToken = explode(' ', $idToken);

                if (count($idToken) == 2) {
                    # $idToken[0] = Bearer
                    # $idToken[1] = api_token = encrypt([member_id][space][api_token])
                    $api_token = $idToken[1];

                    $idToken[1] = Crypt::decrypt($idToken[1]);
                    $idToken[1] = explode(' ', $idToken[1]);
                    $member_id = $idToken[1][0];

                    $member = Member::where(['id' => $member_id, 'api_token' => $api_token])->first();
                    if ($member) {
                        # Save member data on "request object"
                        return $request->member = $member;
                    }
                    // return Member::where(['id' => $idToken[1], 'api_token' => $idToken[2]])->first();
                }
            }
        });
    }
}
