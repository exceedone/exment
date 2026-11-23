<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileShareLink;
use Exceedone\Exment\Services\FileServer\FileServerException;
use Exceedone\Exment\Services\FileServer\Library;
use Exceedone\Exment\Services\FileServer\Preview;
use Exceedone\Exment\Services\FileServer\ShareLinks;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * File server, phase 3 (ISS00377): the pages for the people outside, who open
 * a share link or a request link. No sign-in; the link decides what is
 * allowed. A link that cannot be opened (not found, taken back, past its end,
 * used up) answers the same page, so a guess tells nothing.
 */
class FileShareController extends \App\Http\Controllers\Controller
{
    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function show(Request $request, string $token, ?string $node = null)
    {
        $link = ShareLinks::resolve($token);
        if (!isset($link)) {
            return $this->gone();
        }
        if (!ShareLinks::isUnlocked($link)) {
            return $this->page('password', $link, ['error' => session('file_share_error')]);
        }
        if (!ShareLinks::countAccess($link)) {
            return $this->gone();
        }

        if ($link->kind === FileShareLink::KIND_REQUEST) {
            return $this->page('request', $link, [
                'folder' => $link->node,
                'upload_url' => $this->url($link, 'upload'),
                'max_file_size' => ShareLinks::requestMaxFileSize(),
            ]);
        }

        $item = ShareLinks::nodeInLink($link, isset($node) ? intval($node) : null);
        if (!isset($item)) {
            return $this->gone();
        }
        if (ShareLinks::auditOpen($link, request()->ip())) {
            \Exceedone\Exment\Services\FileServer\Audit::log(\Exceedone\Exment\Services\FileServer\Audit::LINK_OPEN, $link->workspace, $item, null, [], $link->id);
        }
        $data = ['item' => $item, 'breadcrumbs' => $this->breadcrumbs($link, $item)];
        // the links to take away, while its maker may still download (ShareLinks::downloadAllowed)
        $canDownload = ShareLinks::downloadAllowed($link, $item);
        if ($item->isFolder()) {
            // not what has its own permissions (ISS00410)
            $children = ShareLinks::childrenInLink($item);
            $data['children'] = $children->map(function (FileNode $child) use ($link, $canDownload) {
                return [
                    'name' => $child->name,
                    'is_folder' => $child->isFolder(),
                    'size' => $child->size,
                    'updated_at' => $child->updated_at,
                    'url' => $this->url($link, 'item/' . $child->id),
                    'download_url' => $canDownload ? $this->url($link, 'download') . '?node=' . $child->id : null,
                ];
            })->all();
            $data['zip_url'] = $canDownload ? $this->url($link, 'download') . '?node=' . $item->id : null;
        } else {
            $version = $item->current_version;
            $kind = isset($version) ? Preview::kind($version->mime_type, $item->name) : Preview::KIND_NONE;
            // a link without download: what is made from the file, not the file (ViewOnly)
            if (!$canDownload) {
                $kind = \Exceedone\Exment\Services\FileServer\ViewOnly::kind($kind, $version);
            }
            $text = null;
            if ($kind === Preview::KIND_TEXT && isset($version) && $version->size <= Preview::TEXT_PREVIEW_BYTES) {
                $text = Library::disk()->get($version->path);
                if (is_string($text) && !mb_check_encoding($text, 'UTF-8')) {
                    $text = mb_convert_encoding($text, 'UTF-8', 'SJIS-win,EUC-JP,UTF-8');
                }
            }
            $data += [
                'kind' => $kind,
                'text' => $text,
                'content_url' => $this->url($link, 'content') . '?node=' . $item->id,
                'pdf_url' => isset($version) && isset($version->preview_path) ? $this->url($link, 'content') . '?node=' . $item->id . '&rendition=pdf' : null,
                'download_url' => $canDownload ? $this->url($link, 'download') . '?node=' . $item->id : null,
            ];
        }
        return $this->page('share', $link, $data);
    }

    /**
     * The password of a link.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function unlock(Request $request, string $token)
    {
        $link = ShareLinks::resolve($token);
        if (!isset($link)) {
            return $this->gone();
        }
        if (!ShareLinks::unlock($link, strval($request->input('password')))) {
            $message = ShareLinks::isLocked($link) ? exmtrans('file_server.share_password_locked', ['minutes' => ShareLinks::PASSWORD_LOCK_MINUTES]) : exmtrans('file_server.share_password_wrong');
            return redirect($this->url($link))->with('file_share_error', $message);
        }
        return redirect($this->url($link));
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function download(Request $request, string $token)
    {
        $link = $this->openLink($token);
        if (!$link->allow_download || $link->kind !== FileShareLink::KIND_SHARE) {
            abort(403);
        }
        $item = ShareLinks::nodeInLink($link, $request->filled('node') ? intval($request->get('node')) : null);
        if (!isset($item)) {
            abort(404);
        }
        if (!ShareLinks::downloadAllowed($link, $item)) {
            abort(403);
        }
        \Exceedone\Exment\Services\FileServer\Audit::log(\Exceedone\Exment\Services\FileServer\Audit::LINK_DOWNLOAD, $link->workspace, $item, null, [], $link->id);
        $library = ShareLinks::linkLibrary();
        if ($item->isFolder()) {
            $workspace = $link->workspace;
            if (!isset($workspace)) {
                abort(404);
            }
            try {
                $path = $library->zipForLink(intval($link->id))->zip($workspace, [$item]);
            } catch (FileServerException $e) {
                abort($e->status(), $e->getMessage());
            }
            return response()->download($path, $item->name . '.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
        }
        $version = $item->current_version;
        if (!isset($version)) {
            abort(404);
        }
        return $this->stream($version->path, $item->name, $version->mime_type ?: 'application/octet-stream', false);
    }

    /**
     * What the page shows (only the types that are safe to show as they are).
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function content(Request $request, string $token)
    {
        $link = $this->openLink($token);
        if ($link->kind !== FileShareLink::KIND_SHARE) {
            abort(403);
        }
        $item = ShareLinks::nodeInLink($link, $request->filled('node') ? intval($request->get('node')) : null);
        if (!isset($item) || !$item->isFile() || !isset($item->current_version)) {
            abort(404);
        }
        $version = $item->current_version;
        $canDownload = ShareLinks::downloadAllowed($link, $item);
        if ($request->get('rendition') === 'pdf') {
            if (!isset($version->preview_path)) {
                abort(404);
            }
            return $this->stream($version->preview_path, pathinfo($item->name, PATHINFO_FILENAME) . '.pdf', 'application/pdf', true);
        }
        // a link without download: never the bytes of the file (ViewOnly)
        if (!$canDownload) {
            \Exceedone\Exment\Services\FileServer\Audit::log(\Exceedone\Exment\Services\FileServer\Audit::VIEW, $link->workspace, $item, null, ['view_only' => 'content'], $link->id);
            return \Exceedone\Exment\Services\FileServer\ViewOnly::response($version, $item->name);
        }
        if (!Preview::isInlineSafe($version->mime_type)) {
            abort(404);
        }
        return $this->stream($version->path, $item->name, strval($version->mime_type), true);
    }

    /**
     * Files from the people outside, into the folder of a request link.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function upload(Request $request, string $token)
    {
        $link = ShareLinks::resolve($token);
        if (!isset($link) || !ShareLinks::isUnlocked($link) || !ShareLinks::countAccess($link)) {
            return response()->json(['result' => false, 'message' => exmtrans('file_server.share_gone')], 404);
        }
        if ($link->kind !== FileShareLink::KIND_REQUEST) {
            return response()->json(['result' => false, 'message' => exmtrans('file_server.error_forbidden')], 403);
        }
        $files = $request->file('files', []);
        $files = is_array($files) ? $files : [$files];
        if (count($files) === 0) {
            return response()->json(['result' => false, 'message' => exmtrans('file_server.error_upload')], 422);
        }
        $names = [];
        try {
            foreach ($files as $file) {
                if (!$file->isValid()) {
                    throw new FileServerException(exmtrans('file_server.error_upload'));
                }
                $names[] = ShareLinks::receive($link, $file->getClientOriginalName(), strval($file->getRealPath()))->name;
            }
        } catch (FileServerException $e) {
            return response()->json(['result' => false, 'message' => $e->getMessage(), 'received' => $names], $e->status());
        }
        return response()->json(['result' => true, 'message' => exmtrans('file_server.share_received', ['count' => count($names)]), 'received' => $names]);
    }

    // ---------------------------------------------------------------- helpers

    protected function openLink(string $token): FileShareLink
    {
        $link = ShareLinks::resolve($token);
        // a download counts as an open too (ISS00410)
        if (!isset($link) || !ShareLinks::isUnlocked($link) || !ShareLinks::countAccess($link)) {
            abort(404);
        }
        return $link;
    }

    protected function url(FileShareLink $link, ?string $path = null): string
    {
        return url()->to(url_join(ShareLinks::basePath(), $link->token, $path ?? ''));
    }

    /**
     * @return array<int, array{name: string, url: string}>
     */
    protected function breadcrumbs(FileShareLink $link, FileNode $item): array
    {
        $root = $link->node;
        $list = [];
        foreach ($item->ancestorsAndSelf() as $node) {
            if (count($list) === 0 && isset($root) && $node->id !== $root->id) {
                continue;
            }
            $list[] = ['name' => $node->name, 'url' => isset($root) && $node->id === $root->id ? $this->url($link) : $this->url($link, 'item/' . $node->id)];
        }
        return $list;
    }

    /**
     * @param array<string, mixed> $data
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function page(string $mode, FileShareLink $link, array $data = [])
    {
        $html = view('exment::file_server.share', $data + [
            'mode' => $mode,
            'link' => $link,
            'site_name' => \Exceedone\Exment\Model\System::site_name(),
            'unlock_url' => $this->url($link),
        ])->render();
        return response($html)->withHeaders($this->pageHeaders());
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function gone()
    {
        $html = view('exment::file_server.share', [
            'mode' => 'gone',
            'link' => null,
            'site_name' => \Exceedone\Exment\Model\System::site_name(),
        ])->render();
        return response($html, 404)->withHeaders($this->pageHeaders());
    }

    /**
     * @return array<string, string>
     */
    protected function pageHeaders(): array
    {
        return [
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'no-store',
        ];
    }

    /**
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function stream(string $path, string $name, string $mime, bool $inline)
    {
        $disk = Library::disk();
        if (!$disk->exists($path)) {
            abort(404);
        }
        $fallback = preg_replace('/[^\x20-\x7E]|[\\\\"%\/]/', '_', $name) ?: 'file';
        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition($inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store',
        ];
        if ($inline) {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'";
        }
        $size = $disk->size($path);
        if ($size > 0) {
            $headers['Content-Length'] = strval($size);
        }
        return response()->stream(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, $headers);
    }
}
