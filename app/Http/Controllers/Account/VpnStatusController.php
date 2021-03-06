<?php

namespace App\Http\Controllers\Account;

use App\Jobs\JobVpnDisconnectUser;
use App\Lang;
use App\SiteSettings;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class VpnStatusController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware(['auth:api']);
    }

    public function index()
    {
        $db_settings = \App\SiteSettings::findorfail(1);

        if (auth()->user()->cannot('update-account') || !auth()->user()->isAdmin() && !$db_settings->settings['enable_panel_login']) {
            return response()->json([
                'message' => 'Logged out.',
            ], 401);
        }
        
        $permission['is_admin'] = auth()->user()->isAdmin();
        $permission['manage_user'] = auth()->user()->can('manage-user');
        $permission['manage_vpn_server'] = auth()->user()->can('manage-vpn-server');
        $permission['manage_voucher'] = auth()->user()->can('manage-voucher');
        $permission['manage_update_json'] = auth()->user()->can('manage-update-json');

        $site_options['site_name'] = $db_settings->settings['site_name'];
        $site_options['sub_name'] = 'VPN Sessions';
        $site_options['enable_panel_login'] = $db_settings->settings['enable_panel_login'];

        $language = Lang::all()->pluck('name');

        return response()->json([
            'site_options' => $site_options,
            'profile' => ['username' => auth()->user()->username, 'distributor' => auth()->user()->distributor],
            'language' => $language,
            'permission' => $permission,
            'vpn_session' => \App\OnlineUser::with('vpnserver')->where('user_id', auth()->user()->id)->get(),
        ], 200);
    }

    public function disconnect(Request $request)
    {
        try {
            $db_settings = SiteSettings::find(1);
            $server_id = $request->server_id;
            $vpn_user = \App\OnlineUser::with('vpnserver')->where([['user_id', auth()->user()->id], ['vpn_server_id',$server_id]])->firstorfail();
            $job = (new JobVpnDisconnectUser(auth()->user()->username, $vpn_user->vpnserver->server_ip, $vpn_user->vpnserver->server_port))->onConnection($db_settings->settings['queue_driver'])->onQueue('disconnect_user');
            dispatch($job);
            return response()->json(['message' => 'Request sent to the server.'], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $ex) {
            return response()->json(['message' => 'Session not found.'], 404);
        }
    }
}
