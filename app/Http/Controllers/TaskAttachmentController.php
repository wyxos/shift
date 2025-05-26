<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * @deprecated This controller is obsolete. Use AttachmentController instead.
 *
 * The TaskAttachmentController has been replaced by the AttachmentController.
 * This file is kept for backward compatibility but should not be used in new code.
 * All methods in this controller now delegate to the corresponding methods in AttachmentController.
 */
class TaskAttachmentController extends Controller
{
    /**
     * Get an instance of the AttachmentController.
     *
     * @return AttachmentController
     */
    protected function getAttachmentController()
    {
        return App::make(AttachmentController::class);
    }

    /**
     * Upload a file to temporary storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        return $this->getAttachmentController()->upload($request);
    }

    /**
     * List all files in a temporary directory.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function listTempFiles(Request $request)
    {
        return $this->getAttachmentController()->listTempFiles($request);
    }

    /**
     * Remove a file from temporary storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeTempFile(Request $request)
    {
        return $this->getAttachmentController()->removeTempFile($request);
    }

    /**
     * List all attachments for a task.
     *
     * @param Task $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function listTaskAttachments(Task $task)
    {
        return $this->getAttachmentController()->listTaskAttachments($task);
    }

    /**
     * Delete a task attachment.
     *
     * @param Attachment $attachment
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteAttachment(Attachment $attachment)
    {
        return $this->getAttachmentController()->deleteAttachment($attachment);
    }
}
