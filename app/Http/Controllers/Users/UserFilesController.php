<?php

namespace App\Http\Controllers\Users;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetFileRequest;
use App\Models\Actionlog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use enshrined\svgSanitize\Sanitizer;
use Illuminate\Support\Facades\Storage;

class UserFilesController extends Controller
{
    /**
     * Return JSON response with a list of user details for the getIndex() view.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v1.6]
     * @param AssetFileRequest $request
     * @param int $userId
     * @return string JSON
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function store(AssetFileRequest $request, $userId = null)
    {
        $user = User::find($userId);
        $destinationPath = config('app.private_uploads').'/users';

        if (isset($user->id)) {
            $this->authorize('update', $user);

            $logActions = [];
            $files = $request->file('file');

            if (is_null($files)) {
                return redirect()->back()->with('error', trans('admin/users/message.upload.nofiles'));
            }
            foreach ($files as $file) {
                
                $extension = $file->getClientOriginalExtension();
                $file_name = 'user-'.$user->id.'-'.str_random(8).'-'.str_slug(basename($file->getClientOriginalName(), '.'.$extension)).'.'.$extension;


                    // Check for SVG and sanitize it
                    if ($extension == 'svg') {
                        \Log::debug('This is an SVG');
                        \Log::debug($file_name);

                            $sanitizer = new Sanitizer();

                            $dirtySVG = file_get_contents($file->getRealPath());
                            $cleanSVG = $sanitizer->sanitize($dirtySVG);

                            try {
                                Storage::put('private_uploads/users/'.$file_name, $cleanSVG);
                            } catch (\Exception $e) {
                                \Log::debug('Upload no workie :( ');
                                \Log::debug($e);
                            }

                    } else {
                        Storage::put('private_uploads/users/'.$file_name, file_get_contents($file));
                }

                //Log the uploaded file to the log
                $logAction = new Actionlog();
                $logAction->item_id = $user->id;
                $logAction->item_type = User::class;
                $logAction->user_id = Auth::id();
                $logAction->note = $request->input('notes');
                $logAction->target_id = null;
                $logAction->created_at = date("Y-m-d H:i:s");
                $logAction->filename = $file_name;
                $logAction->action_type = 'uploaded';

                if (! $logAction->save()) {
                    return JsonResponse::create(['error' => 'Failed validation: '.print_r($logAction->getErrors(), true)], 500);
                }
                $logActions[] = $logAction;
            }
            // dd($logActions);
            return redirect()->back()->with('success', trans('admin/users/message.upload.success'));
        }
        return redirect()->back()->with('error', trans('admin/users/message.upload.nofiles'));


    }

    /**
     * Delete file
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v1.6]
     * @param  int $userId
     * @param  int $fileId
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function destroy($userId = null, $fileId = null)
    {
        $user = User::find($userId);
        $destinationPath = config('app.private_uploads').'/users';

        if (isset($user->id)) {
            $this->authorize('update', $user);
            $log = Actionlog::find($fileId);
            $full_filename = $destinationPath.'/'.$log->filename;
            if (file_exists($full_filename)) {
                unlink($destinationPath.'/'.$log->filename);
            }
            $log->delete();

            return redirect()->back()->with('success', trans('admin/users/message.deletefile.success'));
        }
        // Prepare the error message
        $error = trans('admin/users/message.user_not_found', ['id' => $userId]);
        // Redirect to the licence management page
        return redirect()->route('users.index')->with('error', $error);

    }

    /**
     * Display/download the uploaded file
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v1.6]
     * @param  int $userId
     * @param  int $fileId
     * @return mixed
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function show($userId = null, $fileId = null)
    {
        $user = User::find($userId);

        // the license is valid
        if (isset($user->id)) {

            $this->authorize('view', $user);

            if ($log = Actionlog::find($fileId)->whereNotNull('filename')->where('item_id', $user->id)->first()) {

                // Display the file inline
                if (request('inline') == 'true') {
                    $headers = [
                        'Content-Disposition' => 'inline',
                    ];
                    return Storage::download('private_uploads/users/'.$log->filename, $log->filename, $headers);
                }

                return Storage::download('private_uploads/users/'.$log->filename);
            }

            return redirect()->route('users.index')->with('error',  trans('admin/users/message.log_record_not_found'));
        }

        // Redirect to the user management page if the user doesn't exist
        return redirect()->route('users.index')->with('error',  trans('admin/users/message.user_not_found', ['id' => $userId]));
    }

}
