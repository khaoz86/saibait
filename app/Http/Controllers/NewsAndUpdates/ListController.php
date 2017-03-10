<?php

namespace App\Http\Controllers\NewsAndUpdates;

use App\Updates;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ListController extends Controller
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
        $permission['is_admin'] = auth()->user()->isAdmin();
        $permission['update_account'] = auth()->user()->can('update-account');
        $permission['manage_user'] = auth()->user()->can('manage-user');

        $data = Updates::orderBy('pinned', 'desc')->orderBy('id', 'desc')->paginate(10);

        $columns = [
            'title', 'pinned', 'created_at',
        ];

        return response()->json([
            'profile' => auth()->user(),
            'permission' => $permission,
            'model' => $data,
            'columns' => $columns,
        ], 200);
    }
    
    public function deletePost(Request $request)
    {
        if (!auth()->user()->isAdmin()) {
            return response()->json([
                'message' => 'Action not allowed.',
            ], 403);
        }
        $this->validate($request, [
            'id' => 'bail|required|array',
        ]);

        $posts = Updates::whereIn('id', $request->id);
        $posts->delete();

        $data = Updates::orderBy('pinned', 'desc')->orderBy('id', 'desc')->paginate(10);

        $columns = [
            'title', 'pinned', 'created_at',
        ];

        return response()->json([
            'message' => 'Selected posts deleted',
            'model' => $data,
            'columns' => $columns,
        ], 200);
    }

    public function pinPost(Request $request)
    {
        if (!auth()->user()->isAdmin()) {
            return response()->json([
                'message' => 'Action not allowed.',
            ], 403);
        }
        $this->validate($request, [
            'id' => 'bail|required|array',
        ]);

        $posts = Updates::whereIn('id', $request->id);
        $posts->update(['pinned' => 1]);

        $data = Updates::orderBy('pinned', 'desc')->orderBy('id', 'desc')->paginate(10);

        $columns = [
            'title', 'pinned', 'created_at',
        ];

        return response()->json([
            'message' => 'Selected posts pinned',
            'model' => $data,
            'columns' => $columns,
        ], 200);
    }

    public function unPinPost(Request $request)
    {
        if (!auth()->user()->isAdmin()) {
            return response()->json([
                'message' => 'Action not allowed.',
            ], 403);
        }
        $this->validate($request, [
            'id' => 'bail|required|array',
        ]);

        $posts = Updates::whereIn('id', $request->id);
        $posts->update(['pinned' => 0]);

        $data = Updates::orderBy('pinned', 'desc')->orderBy('id', 'desc')->paginate(10);

        $columns = [
            'title', 'pinned', 'created_at',
        ];

        return response()->json([
            'message' => 'Selected posts unpinned',
            'model' => $data,
            'columns' => $columns,
        ], 200);
    }
}