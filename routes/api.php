<?php

use App\OnlineUser;
use App\SiteSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

//Route::get('/wew', 'PublicServerStatusController@test');

Route::get('/wew', function() {
    $user = \App\User::find(1);
    return $user->user_port;
    //$dt = Carbon::parse('2017-05-03T16:00:00.000Z');
    //return $dt->timezone('Asia/Manila');
//    $log = \App\User::with(['credit_logs.user_related'])->find(1);
//    return $log->credit_logs;
});

Route::get('/', function() {
    //$dt = Carbon::parse('2017-05-03T16:00:00.000Z');
    //return $dt->timezone('Asia/Manila');
//    $log = \App\User::with(['credit_logs.user_related'])->find(1);
//    return $log->credit_logs;
});

Route::get('/account', function () {
    $permission['is_admin'] = auth()->user()->isAdmin();
    $permission['update_account'] = auth()->user()->can('update-account');

    $db_settings = SiteSettings::findorfail(1);
    $site_options['enable_panel_login'] = $db_settings->settings['enable_panel_login'];

    $user = \App\User::with('status')->findorfail(auth()->user()->id);
    
    return response()->json([
        'site_options' => $site_options,
        'profile'=> ['status' => $user->status],
        'permission' => $permission,
    ], 200);
})->middleware('auth:api');

Route::get('/vpn_auth', function (Request $request) {
    try {
        $username = $request->username;
        $password = $request->password;
        $server_key = $request->server_key;

        if (!preg_match("/^[a-z0-9_]+$/",$username)) {
            Log::info('AUTH_FAILED CAPS: ' . $username);
            return '0';
        }

        $server = \App\VpnServer::where('server_key', $server_key)->firstorfail();

        $account = \App\User::where('username', $username)->firstorfail();

        if(!$server->users()->where('username', $account->username)->exists() && Hash::check($password, $account->password)) {
            return '1';
        }

        Log::info('AUTH_FAILED: ' . $username);
        return '0';
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $ex) {
        return '0';
    }
});

Route::get('/vpn_auth_v2', function (Request $request) {
    try {
        $username = $request->username;
        $password = $request->password;
        $server_key = $request->server_key;

        if (!preg_match("/^[a-z0-9_]+$/",$username)) {
            Log::info('AUTH_FAILED CAPS: ' . $username);
            return '0';
        }

        $server = \App\VpnServer::with(['user_packages'])->where('server_key', $server_key)->firstorfail();

        $account = \App\User::with('user_package')->where('username', $username)->firstorfail();

        if(!$server->users()->where('username', $account->username)->exists() && Hash::check($password, $account->password)) {
            $dl_speed = '0kbit';
            $up_speed = '0kbit';
            $dl_speed = $account->user_package->dl_speed;
            $up_speed = $account->user_package->up_speed;
            if($server->dl_speed != '0kbit' || $server->up_speed != '0kbit') {
                $dl_speed = $server->dl_speed;
                $up_speed = $server->up_speed;
            }
            if($account->dl_speed != '0kbit' || $account->up_speed != '0kbit') {
                $dl_speed = $account->dl_speed;
                $up_speed = $account->up_speed;
            }
            return '1;' . $dl_speed . ';' . $up_speed . '';
        }

        Log::info('AUTH_FAILED: ' . $username);
        return '0';
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $ex) {
        return '0';
    }
});

Route::get('/vpn_auth_connect', function (Request $request) {
    try {
        $username = trim($request->username);
        $server_key = trim($request->server_key);

        if($username == '' || $server_key == '') return '0';

        $server = \App\VpnServer::with(['user_packages', 'server_access', 'user_access'])->where('server_key', $server_key)->firstorfail();
        if(!$server->is_active) {
            Log::info('Server is currently down: ' . $username);
            return 'Server is currently down.';
        }

        if(!$server->server_access->is_active) {
            Log::info('Server access is inactive: ' . $username);
            return 'Server access is inactive.';
        }

        $user = \App\User::with('status', 'user_package')->where('username', $username)->firstorfail();

        if($server->users()->where('username', $user->username)->exists()) {
            Log::info('You have active device on this server: ' . $username);
            return 'You have active device on this server.';
        }

        if(!$user->vpn_f_login && !$user->user_package->vpn_login) {
            Log::info('VPN Login is disabled: ' . $username);
            return 'VPN Login is disabled.';
        }

        $current = Carbon::now();
        $dt = Carbon::parse($user->getOriginal('expired_at'));

        if(!$user->isAdmin()) {
            if(!$user->user_package->is_active) {
                Log::info('User package is not active: ' . $username);
                return 'User package is not active.';
            }
            if(!in_array($user->user_package->id, json_decode($server->user_packages->pluck('id')))) {
                Log::info('User package is not allowed in this server: ' . $username);
                return 'User package is not allowed in this server.';
            }
            if(!$user->isActive()) {
                Log::info('Account is not activated: ' . $username);
                return 'Account is not activated.';
            }
            if($user->vpn->count() >= intval($user->user_package->user_package['device'])) {
                Log::info('Max paid device reached: ' . $username);
                return 'Max paid device reached.';
            }
            if($server->server_access->config['paid'] && $current->gte($dt)) {
                Log::info('Your account is already expired: ' . $username);
                return 'Your account is already expired.';
            }
            if($server->limit_bandwidth && $user->consumable_data < 1) {
                Log::info('You used all data allocated: ' . $username);
                return 'You used all data allocated.';
            }
            if(!$server->server_access->config['paid']) {
                if($current->lt($dt)) {
                    Log::info('Paid user cannot enter free server: ' . $username);
                    return 'Paid user cannot enter free server.';
                }
                $free_sessions = \App\VpnServer::where('server_access_id', 1)->get();
                $free_ctr = 0;
                foreach ($free_sessions as $free) {
                    if($free->users()->where('id', $user->id)->count() > 0) {
                        $free_ctr += 1;
                    }
                }
                if(!$server->server_access->config['multi_device'] && $free_ctr > 0) {
                    Log::info('Only one device allowed for free user: ' . $username);
                    return 'Only one device allowed for free user.';
                }
                if($free_ctr >= $server->server_access->config['max_device']) {
                    Log::info('Max free device reached: ' . $username);
                    return 'Max free device reached.';
                }
            }

            if(!$server->server_access->config['multi_device']) {
                $vip_sessions = \App\VpnServer::where('server_access_id', $server->server_access->id)->get();
                $vip_ctr = 0;
                foreach ($vip_sessions as $vip) {
                    if($vip->users()->where('id', $user->id)->count() > 0) {
                        $vip_ctr += 1;
                    }
                }
                if(!$server->server_access->config['multi_device'] && $vip_ctr > 0) {
                    Log::info('Only one device allowed on ' . strtolower($server->server_access->name) . ' Server: ' . $username);
                    return 'Only one device allowed on ' . strtolower($server->server_access->name) . ' Server.';
                }
                if($vip_ctr >= $server->server_access->config['max_device']) {
                    Log::info('Max device reached  on ' . strtolower($server->server_access->name) . ' Server: ' . $username);
                    return 'Max device reached  on ' . strtolower($server->server_access->name) . ' Server.';
                }
            }

            if($server->server_access->config['private']) {
                if(!in_array($user->id, json_decode($server->user_access->pluck('id')))) {
                    Log::info('Your account is not allowed to login to ' . strtolower($server->server_access->name) . ' server: ' . $username);
                    return 'Your account is not allowed to login to ' . strtolower($server->server_access->name) . ' server.';
                }
            }
        }

        $vpn = new OnlineUser;
        $vpn->user_id = $user->id;
        $vpn->user_ip = $request->trusted_ip ? $request->trusted_ip : '0.0.0.0';
        $vpn->user_port = $request->trusted_port ? $request->trusted_port : '0';
        $vpn->vpn_server_id = $server->id;
        $vpn->byte_sent = 0;
        $vpn->byte_received = 0;
        $vpn->data_available = $server->limit_bandwidth ? $user->getOriginal('consumable_data') : 0;
        if($vpn->save()) {
            return '1';
        }

        return 'Server Error.';
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $ex) {
        return '0';
    }
});

Route::get('/vpn_auth_disconnect', function (Request $request) {
    try {
        $username = trim($request->username);
        $server_key = trim($request->server_key);
        $bytes_sent = trim($request->bytes_sent);
        $bytes_received = trim($request->bytes_received);
        $server = \App\VpnServer::where('server_key', $server_key)->firstorfail();
        $user_delete = $server->users()->where('username', $username)->firstorfail();

        $current = \Carbon\Carbon::now();
        $dt = \Carbon\Carbon::parse($user_delete->getOriginal('expired_at'));

        $vpn = $user_delete->vpn()->where('vpn_server_id', $server->id)->firstorfail();
        $user_delete->timestamps = false;
        if(!$user_delete->isAdmin() && $server->limit_bandwidth && $vpn->data_available > 0) {
            $data = doubleval($vpn->data_available) - doubleval($bytes_sent);
            $user_delete->consumable_data = ($data >= 0) ? $data : 0;
            $user_delete->save();
        }
        $user_delete->lifetime_bandwidth = doubleval($user_delete->lifetime_bandwidth) + doubleval($bytes_sent);
        $user_delete->save();
        $vpn_history = new \App\VpnHistory;
        $vpn_history->user_id = $user_delete->id;
        $vpn_history->user_ip = $vpn->user_ip;
        $vpn_history->user_port = $vpn->user_port;
        $vpn_history->server_name = $server->server_name;
        $vpn_history->server_ip = $server->server_ip;
        $vpn_history->server_domain = $server->server_domain;
        $vpn_history->byte_sent = floatval($bytes_sent);
        $vpn_history->byte_received = floatval($bytes_received);
        $vpn_history->session_start = \Carbon\Carbon::parse($vpn->getOriginal('created_at'));
        $vpn_history->session_end = \Carbon\Carbon::now();
        $vpn_history->save();
        $user_delete->vpn()->where('vpn_server_id', $server->id)->delete();
        return '1';
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $ex) {
        return '0';
    }
});

Route::get('/login_init', 'LoginController@index');

Route::get('/account/profile', 'Account\AccountController@index');
Route::post('/account/profile', 'Account\AccountController@update');

Route::get('/account/security', 'Account\SecurityController@index');
Route::post('/account/security', 'Account\SecurityController@update');

Route::get('/account/extend-duration', 'Account\ExtendDurationController@index');
Route::post('/account/extend-duration', 'Account\ExtendDurationController@extend');

Route::get('/account/transfer-credits', 'Account\TransferCreditsController@index');
Route::post('/account/transfer-credits', 'Account\TransferCreditsController@transfer');

Route::get('/account/credit-transfer-logs', 'Account\UserCreditLogController@index');

Route::get('/account/vpn_status', 'Account\VpnStatusController@index');
Route::post('/account/vpn_disconnect', 'Account\VpnStatusController@disconnect');

Route::get('/voucher/generate', 'VoucherController@generateVoucherIndex');
Route::post('/voucher/generate', 'VoucherController@generate');

Route::get('/voucher/apply', 'VoucherController@applyVoucherIndex');
Route::post('/voucher/apply', 'VoucherController@applyVoucher');
Route::post('/voucher/delete-voucher', 'VoucherController@deleteVoucher');

Route::get('/manage-user/all', 'ManageUser\ListUserAllController@index');
Route::get('/manage-user/ultimate', 'ManageUser\ListUserUltimateController@index');
Route::get('/manage-user/premium', 'ManageUser\ListUserPremiumController@index');
Route::get('/manage-user/reseller', 'ManageUser\ListUserResellerController@index');
Route::get('/manage-user/client', 'ManageUser\ListUserClientController@index');
Route::get('/manage-user/trash', 'ManageUser\ListUserTrashController@index');

Route::get('/manage-user/profile/{id}', 'ManageUser\UserProfileController@index');
Route::post('/manage-user/profile/{id}', 'ManageUser\UserProfileController@updateProfile');

Route::get('/manage-user/security/{id}', 'ManageUser\UserSecurityController@index');
Route::post('/manage-user/security/{id}', 'ManageUser\UserSecurityController@updateSecurity');

Route::get('/manage-user/usergroup/{id}', 'ManageUser\UserGroupController@index');
Route::post('/manage-user/usergroup/{id}/client', 'ManageUser\UserGroupController@user_group_client');
Route::post('/manage-user/usergroup/{id}/reseller', 'ManageUser\UserGroupController@user_group_reseller');
Route::post('/manage-user/usergroup/{id}/premium', 'ManageUser\UserGroupController@user_group_premium');
Route::post('/manage-user/usergroup/{id}/ultimate', 'ManageUser\UserGroupController@user_group_ultimate');
Route::post('/manage-user/usergroup/{id}/all', 'ManageUser\UserGroupController@user_group_all');

Route::get('/manage-user/permission/{id}', 'ManageUser\UserPermissionController@index');
Route::post('/manage-user/permission/{id}', 'ManageUser\UserPermissionController@updatePermission');

Route::get('/manage-user/duration/{id}', 'ManageUser\UserDurationController@index');
Route::post('/manage-user/duration/{id}', 'ManageUser\UserDurationController@updateDuration');

Route::get('/manage-user/credits/{id}', 'ManageUser\UserCreditController@index');
Route::post('/manage-user/credits/{id}', 'ManageUser\UserCreditController@updateCredits');

Route::get('/manage-user/user-package/{id}', 'ManageUser\UserPackageController@index');
Route::post('/manage-user/user-package/{id}', 'ManageUser\UserPackageController@user_package');

Route::get('/manage-user/voucher/{id}', 'ManageUser\UserVoucherController@index');
Route::post('/manage-user/voucher/{id}', 'ManageUser\UserVoucherController@applyVoucher');
Route::post('/manage-user/voucher/{id}/delete', 'ManageUser\UserVoucherController@deleteVoucher');

Route::post('/manage-user/vpn-session/{id}', 'ManageUser\UserDisconnectVpn@index');

Route::post('/distributor', 'DistributorController@index');

Route::get('/online-users', 'OnlineUsersController@index');
Route::post('/online-users', 'OnlineUsersController@searchOnlineUser');
Route::post('/online-users/disconnect-user', 'OnlineUsersController@disconnectVpn');

Route::get('/news-and-updates', 'NewsAndUpdates\ListController@index');
Route::post('/news-and-updates/delete', 'NewsAndUpdates\ListController@deletePost');
Route::post('/news-and-updates/pin-post', 'NewsAndUpdates\ListController@pinPost');
Route::post('/news-and-updates/unpin-post', 'NewsAndUpdates\ListController@unPinPost');
Route::post('/news-and-updates/item', 'NewsAndUpdates\ItemController@index');
Route::get('/news-and-updates/edit/{id}', 'NewsAndUpdates\EditItemController@index');
Route::post('/news-and-updates/edit/{id}', 'NewsAndUpdates\EditItemController@update');
Route::get('/news-and-updates-create', 'NewsAndUpdates\CreateController@index');
Route::post('/news-and-updates-create', 'NewsAndUpdates\CreateController@create');

Route::get('/manage-user/create', 'ManageUser\CreateUserController@index');
Route::post('/manage-user/create', 'ManageUser\CreateUserController@create');

Route::post('/manage-user/delete-client', 'ManageUser\ListUserClientController@deleteUsers');
Route::post('/manage-user/delete-reseller', 'ManageUser\ListUserResellerController@deleteUsers');
Route::post('/manage-user/delete-premium', 'ManageUser\ListUserPremiumController@deleteUsers');
Route::post('/manage-user/delete-ultimate', 'ManageUser\ListUserUltimateController@deleteUsers');
Route::post('/manage-user/delete-all', 'ManageUser\ListUserAllController@deleteUsers');

Route::post('/manage-user/client-update-userpackage', 'ManageUser\ListUserClientController@updateUserPackage');
Route::post('/manage-user/reseller-update-userpackage', 'ManageUser\ListUserResellerController@updateUserPackage');
Route::post('/manage-user/premium-update-userpackage', 'ManageUser\ListUserPremiumController@updateUserPackage');
Route::post('/manage-user/ultimate-update-userpackage', 'ManageUser\ListUserUltimateController@updateUserPackage');
Route::post('/manage-user/all-update-userpackage', 'ManageUser\ListUserAllController@updateUserPackage');

Route::post('/manage-user/client-update-usergroup', 'ManageUser\ListUserClientController@updateUserGroup');
Route::post('/manage-user/reseller-update-usergroup', 'ManageUser\ListUserResellerController@updateUserGroup');
Route::post('/manage-user/premium-update-usergroup', 'ManageUser\ListUserPremiumController@updateUserGroup');
Route::post('/manage-user/ultimate-update-usergroup', 'ManageUser\ListUserUltimateController@updateUserGroup');
Route::post('/manage-user/all-update-usergroup', 'ManageUser\ListUserAllController@updateUserGroup');

Route::post('/manage-user/client-update-status', 'ManageUser\ListUserClientController@updateUserStatus');
Route::post('/manage-user/reseller-update-status', 'ManageUser\ListUserResellerController@updateUserStatus');
Route::post('/manage-user/premium-update-status', 'ManageUser\ListUserPremiumController@updateUserStatus');
Route::post('/manage-user/ultimate-update-status', 'ManageUser\ListUserUltimateController@updateUserStatus');
Route::post('/manage-user/all-update-status', 'ManageUser\ListUserAllController@updateUserStatus');

Route::post('/manage-user/user-restore', 'ManageUser\ListUserTrashController@restoreUser');
Route::post('/manage-user/user-force-delete', 'ManageUser\ListUserTrashController@forceDeleteUser');

Route::get('/vpn-server/add', 'VpnServer\AddServerController@index');
Route::post('/vpn-server/add', 'VpnServer\AddServerController@addServer');
Route::get('/vpn-server/list', 'VpnServer\ListServerController@index');
Route::post('/vpn-server/delete-server', 'VpnServer\ListServerController@deleteServer');
Route::post('/vpn-server/quick/server-status', 'VpnServer\ListServerController@server_status');
Route::post('/vpn-server/quick/server-access', 'VpnServer\ListServerController@server_access');
Route::get('/vpn-server/server-info/{id}', 'VpnServer\ServerInfoController@index');
Route::post('/vpn-server/server-info/{id}', 'VpnServer\ServerInfoController@updateServer');
Route::get('/vpn-server/generatekey', 'VpnServer\ServerInfoController@generatekey');
Route::get('/vpn-server/server-status', 'VpnServer\ListServerController@serverstatus');

Route::get('/admin/site-settings', 'Admin\SiteSettings@index');
Route::post('/admin/site-settings', 'Admin\SiteSettings@updateSettings');

Route::get('/admin/credit-transfer-logs', 'Admin\CreditTransferLogController@index');
Route::post('/admin/credit-transfer-logs', 'Admin\CreditTransferLogController@index');

Route::get('/admin/vpn-history-logs', 'Admin\VpnHistoryLogController@index');
Route::post('/admin/vpn-history-logs', 'Admin\VpnHistoryLogController@index');

Route::get('/admin/app-update-android', 'Admin\AppUpdatesController@app_android_index');
Route::post('/admin/app-update-android', 'Admin\AppUpdatesController@app_android_update');

Route::get('/admin/app-update-gui', 'Admin\AppUpdatesController@app_gui_index');
Route::post('/admin/app-update-gui', 'Admin\AppUpdatesController@app_gui_update');

Route::get('/admin/app-update-android-ss', 'Admin\AppUpdatesController@ss_app_android_index');
Route::post('/admin/app-update-android-ss', 'Admin\AppUpdatesController@ss_app_android_update');

Route::get('/admin/app-update-gui-ss', 'Admin\AppUpdatesController@ss_app_gui_index');
Route::post('/admin/app-update-gui-ss', 'Admin\AppUpdatesController@ss_app_gui_update');

Route::get('/ss-port/get-new/{id}', 'ManageUser\UserPackageController@get_port');

Route::post('/public/online-users', 'PublicOnlineUsersController@index');
Route::post('/public/distributors', 'PublicDistributorController@index');
Route::get('/public/server-status', 'PublicServerStatusController@index');

Route::get('/public/news-and-updates-list', 'NewsAndUpdates\PublicNewsAndUpdatesController@index');
Route::post('/public/news-and-updates-item', 'NewsAndUpdates\PublicNewsAndUpdatesController@item');
