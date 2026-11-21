<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileShareLink;
use Exceedone\Exment\Model\FileWorkspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * File server, phase 3 (ISS00377): links for people outside.
 *
 * - share: a folder or file can be seen by anyone with the link (and, when it
 *   is allowed, downloaded). A folder is seen with all that is in it.
 * - request: anyone with the link can upload files into a folder (a "file
 *   request" of Box); they see nothing of what is in it.
 *
 * A link can have an end date, a password and a count of opens, and can be
 * taken back. Those who can edit the folder or file make "share" links;
 * those who can upload into the folder make "request" links. The site can
 * stop the links (exment.file_server_external_share = false) or make an end
 * date needed (exment.file_server_share_max_days).
 */
class ShareLinks
{
    public const TOKEN_LENGTH = 40;
    public const SESSION_KEY = 'exment_file_share_unlocked';
    public const COUNTED_KEY = 'exment_file_share_unlocked_counted';

    /**
     * The shortest password of a link.
     */
    public static function passwordMinLength(): int
    {
        return max(1, intval(config('exment.file_server_share_password_min', 8)));
    }

    /**
     * Whether an open of a link by someone outside is written in the audit
     * log: once in 10 minutes for a link and an address (not for each page).
     */
    public static function auditOpen(FileShareLink $link, ?string $ip): bool
    {
        try {
            return \Illuminate\Support\Facades\Cache::add('exment_file_share_open_' . $link->id . '_' . md5(strval($ip)), 1, now()->addMinutes(10));
        } catch (\Throwable $e) {
            return true;
        }
    }

    /** The wrong passwords of a link before it is stopped for a while (ISS00410). */
    public const PASSWORD_TRIES = 10;
    public const PASSWORD_LOCK_MINUTES = 15;

    /** The types a request link does not take, unless the site sets them (ISS00410). */
    public const DEFAULT_DENIED_EXTENSIONS = 'exe,com,bat,cmd,scr,pif,msi,msp,msix,msixbundle,appx,appxbundle,dll,cpl,sys,drv,ocx,'
        . 'js,jse,mjs,vbs,vbe,wsf,wsh,ps1,psm1,psd1,hta,jar,lnk,url,scf,reg,inf,sh,bash,py,pyw,pl,rb,'
        . 'php,phtml,phar,asp,aspx,jsp,cgi,html,htm,xhtml,shtml,svg,svgz,xml,'
        . 'iso,img,vhd,vhdx,dmg,docm,dotm,xlsm,xltm,xlam,pptm,potm,ppam,ppsm,'
        . 'mht,mhtml,chm,hlp,iqy,slk,xll,settingcontent-ms,library-ms,search-ms,application,gadget,msc,cab,appref-ms,website,xbap';

    protected Library $library;

    public function __construct(Library $library)
    {
        $this->library = $library;
    }

    public static function enabled(): bool
    {
        return boolval(config('exment.file_server_external_share', true));
    }

    public static function maxDays(): int
    {
        return max(0, intval(config('exment.file_server_share_max_days', 0)));
    }

    public static function basePath(): string
    {
        $path = trim(strval(config('exment.file_server_share_path', 'share')), '/');
        return $path === '' ? 'share' : $path;
    }

    /**
     * Make a link.
     *
     * @param array<string, mixed> $options expires_at (Y-m-d or a date), password, allow_download, max_access, message
     */
    public function create(FileWorkspace $workspace, FileNode $node, string $kind, array $options = []): FileShareLink
    {
        if (!static::enabled()) {
            throw new FileServerException(exmtrans('file_server.error_share_disabled'), 403);
        }
        if ($node->workspace_id !== $workspace->id || $node->isTrashed()) {
            throw FileServerException::notFound();
        }
        $access = $this->library->access();
        if ($kind === FileShareLink::KIND_REQUEST) {
            if (!$node->isFolder()) {
                throw new FileServerException(exmtrans('file_server.error_request_folder_only'));
            }
            // a link that lets anyone outside put files in: those who may edit the folder (ISS00433)
            $access->authorize($workspace, $node, Level::EDIT);
        } elseif ($kind === FileShareLink::KIND_SHARE) {
            $access->authorize($workspace, $node, Level::EDIT);
            if ($node->isDraft()) {
                throw new FileServerException(exmtrans('file_server.error_share_draft'));
            }
            // who may not download may not give a link to download (ISS00379)
            if (boolval(array_get($options, 'allow_download', true)) && !Governance::canDownload($access, $workspace, $node)) {
                throw new FileServerException(exmtrans('file_server.error_download_not_allowed'), 403);
            }
        } else {
            throw new FileServerException(exmtrans('file_server.error_upload'));
        }

        $expires = $this->expiresAt(array_get($options, 'expires_at'));
        $password = strval(array_get($options, 'password', ''));
        if ($password !== '' && mb_strlen($password) < static::passwordMinLength()) {
            throw new FileServerException(exmtrans('file_server.error_share_password_short', ['min' => static::passwordMinLength()]));
        }
        $max = array_get($options, 'max_access');
        $max = ($max === null || $max === '' || intval($max) <= 0) ? null : min(1000000, intval($max));
        $message = trim(strval(array_get($options, 'message', '')));

        $link = new FileShareLink([
            'token' => Str::random(static::TOKEN_LENGTH),
            'workspace_id' => $workspace->id,
            'node_id' => $node->id,
            'created_user_id' => $access->userId(),
            'kind' => $kind,
            'password_hash' => $password !== '' ? Hash::make($password) : null,
            'expires_at' => $expires,
            'allow_download' => $kind === FileShareLink::KIND_SHARE ? boolval(array_get($options, 'allow_download', true)) : false,
            'max_access' => $max,
            'message' => $message !== '' ? mb_substr($message, 0, 1000) : null,
        ]);
        $link->save();
        Audit::log(Audit::SHARE_CREATE, $workspace, $node, $access->userId(), ['kind' => $kind, 'expires_at' => isset($expires) ? $expires->format('Y-m-d') : null, 'password' => $password !== '', 'download' => $link->allow_download], $link->id);
        return $link;
    }

    /**
     * The end of a link: the end of the day given. Needed (and not later than
     * the days of the site) when the site sets the days.
     */
    protected function expiresAt(mixed $value): ?\Carbon\Carbon
    {
        $max = static::maxDays();
        if ($value === null || $value === '') {
            if ($max > 0) {
                throw new FileServerException(exmtrans('file_server.error_share_expiry_needed', ['days' => $max]));
            }
            return null;
        }
        try {
            $date = \Carbon\Carbon::parse(strval($value))->endOfDay();
        } catch (\Throwable $e) {
            throw new FileServerException(exmtrans('file_server.error_share_expiry'));
        }
        if ($date->isPast()) {
            throw new FileServerException(exmtrans('file_server.error_share_expiry'));
        }
        if ($max > 0 && $date->gt(now()->addDays($max)->endOfDay())) {
            throw new FileServerException(exmtrans('file_server.error_share_expiry_needed', ['days' => $max]));
        }
        return $date;
    }

    /**
     * The links of a folder or file (those the user may see: they can edit it,
     * or upload into it for the request links).
     *
     * @return \Illuminate\Support\Collection<int, FileShareLink>
     */
    public function linksOf(FileWorkspace $workspace, FileNode $node)
    {
        $access = $this->library->access();
        $level = $access->level($workspace, $node);
        return FileShareLink::query()->where('node_id', $node->id)->orderByDesc('id')->get()
            ->filter(function (FileShareLink $link) use ($level) {
                return $level >= Level::EDIT;
            })->values();
    }

    /**
     * Take a link back: the user who made it, or one who manages the folder or file.
     */
    public function revoke(FileWorkspace $workspace, FileShareLink $link): void
    {
        if ($link->workspace_id !== $workspace->id) {
            throw FileServerException::notFound();
        }
        $access = $this->library->access();
        $node = FileNode::find($link->node_id);
        $mine = isset($link->created_user_id) && $link->created_user_id === $access->userId();
        if (!$mine && (!isset($node) || !$access->can($workspace, $node, Level::MANAGE))) {
            throw FileServerException::forbidden();
        }
        $link->revoked_at = now();
        $link->revoked_user_id = $access->userId();
        $link->save();
        Audit::log(Audit::SHARE_REVOKE, $workspace, $node, $access->userId(), [], $link->id);
    }

    // ---------------------------------------------------------------- the side of the people outside

    /**
     * The link of a token, when it can be opened (null: not found, taken back,
     * past its end, used up, its folder or file removed, or the links stopped).
     */
    public static function resolve(string $token): ?FileShareLink
    {
        // the file server not used (Feature management): no link opens
        if (!Library::featureEnabled()) {
            return null;
        }
        if (!static::enabled() || strlen($token) !== static::TOKEN_LENGTH || preg_match('/\A[A-Za-z0-9]+\z/', $token) !== 1) {
            return null;
        }
        $link = FileShareLink::query()->where('token', $token)->first();
        if (!isset($link) || isset($link->revoked_at) || $link->isExpired()) {
            return null;
        }
        // used up: only for the sessions that did not open it yet (ISS00410)
        if ($link->isUsedUp() && !static::isCounted($link)) {
            return null;
        }
        $node = $link->node;
        $workspace = $link->workspace;
        if (!isset($node) || !isset($workspace) || $node->isTrashed()) {
            return null;
        }
        // the maker of the link must still be allowed to make it: a folder
        // closed later (its own permissions, or one above it), a maker taken
        // out of the workspace or removed, close the link too (ISS00410)
        if (!static::makerMayGive($link, $workspace, $node)) {
            return null;
        }
        return $link;
    }

    protected static function makerMayGive(FileShareLink $link, FileWorkspace $workspace, FileNode $node): bool
    {
        $makerId = $link->created_user_id;
        if (!isset($makerId)) {
            return false;
        }
        $isAdmin = false;
        try {
            $login = \Exceedone\Exment\Model\LoginUser::query()->where('base_user_id', $makerId)->first();
            if (!isset($login) && getModelName(\Exceedone\Exment\Enums\SystemTableName::USER)::find($makerId) === null) {
                return false;
            }
            $isAdmin = isset($login) && boolval($login->hasPermission(\Exceedone\Exment\Enums\Permission::SYSTEM));
        } catch (\Throwable $e) {
            $isAdmin = false;
        }
        // a request link as a share link: its maker must still edit the folder
        // (a link made before by who may only upload closes too, ISS00433)
        return (new Access(intval($makerId), $isAdmin))->can($workspace, $node, Level::EDIT);
    }

    public static function isUnlocked(FileShareLink $link): bool
    {
        if (!$link->hasPassword()) {
            return true;
        }
        return in_array($link->token, (array)session(static::SESSION_KEY, []), true);
    }

    /**
     * Whether the link takes no password now: too many wrong ones were sent
     * to it lately, from any place (ISS00410).
     */
    public static function isLocked(FileShareLink $link): bool
    {
        return intval(Cache::get(static::lockKey($link), 0)) >= static::PASSWORD_TRIES;
    }

    protected static function lockKey(FileShareLink $link): string
    {
        return 'exment_file_share_fail_' . $link->id;
    }

    public static function unlock(FileShareLink $link, string $password): bool
    {
        if (!$link->hasPassword()) {
            return true;
        }
        // each try is counted first, in one step of the cache, so tries at the
        // same time from many places cannot pass the limit. Anyone who has the
        // URL can stop the link for a while this way; that is the price of it
        // (the maker can make a new link).
        $key = static::lockKey($link);
        Cache::add($key, 0, now()->addMinutes(static::PASSWORD_LOCK_MINUTES));
        $tries = intval(Cache::increment($key));
        if ($tries > static::PASSWORD_TRIES) {
            return false;
        }
        if (!Hash::check($password, strval($link->password_hash))) {
            return false;
        }
        Cache::forget($key);
        $list = (array)session(static::SESSION_KEY, []);
        $list[] = $link->token;
        session([static::SESSION_KEY => array_values(array_unique($list))]);
        return true;
    }

    public static function isCounted(FileShareLink $link): bool
    {
        return in_array($link->token, (array)session(static::COUNTED_KEY, []), true);
    }

    /**
     * Count a use of the link: the first time a session opens, downloads or
     * uploads with it. The count is taken in one UPDATE that also checks the
     * limit, so two at the same time cannot both take the last one; a session
     * that was counted goes on using the link (ISS00410).
     *
     * @return bool false: the link is used up
     */
    public static function countAccess(FileShareLink $link): bool
    {
        if (static::isCounted($link)) {
            return true;
        }
        $query = FileShareLink::query()->where('id', $link->id);
        if (isset($link->max_access)) {
            $query->whereColumn('access_count', '<', 'max_access');
        }
        $updated = $query->update([
            'access_count' => \DB::raw('access_count + 1'),
            'last_accessed_at' => now(),
        ]);
        if ($updated === 0) {
            return false;
        }
        $link->access_count = $link->access_count + 1;
        $counted = (array)session(static::COUNTED_KEY, []);
        $counted[] = $link->token;
        session([static::COUNTED_KEY => array_values(array_unique($counted))]);
        return true;
    }

    /**
     * A folder or file under the node of a "share" link (or the node itself):
     * what the people outside may see. Null when it is not under it.
     */
    public static function nodeInLink(FileShareLink $link, ?int $nodeId): ?FileNode
    {
        $root = $link->node;
        if (!isset($root)) {
            return null;
        }
        if ($nodeId === null || $nodeId === $root->id) {
            return $root;
        }
        if (!$root->isFolder()) {
            return null;
        }
        $node = FileNode::query()->where('workspace_id', $link->workspace_id)->whereNull('trashed_at')->find($nodeId);
        if (!isset($node) || $node->isDraft()) {
            return null;
        }
        // from the node up to the node of the link: none in the trash, and none
        // with its own permissions (those the maker of the link may not give,
        // and that may be closed later: ISS00410)
        $chain = array_reverse($node->ancestorsAndSelf());
        foreach ($chain as $ancestor) {
            if ($ancestor->id === $root->id) {
                return $node;
            }
            if ($ancestor->isTrashed() || $ancestor->has_unique_permissions) {
                return null;
            }
        }
        return null;
    }

    /**
     * The folders and files in a folder of a "share" link that the people
     * outside may see: not in the trash, without their own permissions.
     *
     * @return \Illuminate\Support\Collection<int, FileNode>
     */
    public static function childrenInLink(FileNode $folder)
    {
        return FileNode::query()->where('parent_id', $folder->id)->whereNull('trashed_at')
            ->where('has_unique_permissions', false)
            ->where(function ($query) {
                // not the files that wait for their approval (ISS00379)
                $query->whereNull('approval_status')->orWhere('approval_status', FileNode::APPROVAL_APPROVED);
            })
            ->orderByRaw("CASE WHEN node_type = 'folder' THEN 0 ELSE 1 END")->orderBy('name')->get();
    }

    /**
     * The library that acts for the people outside: it may do all, so the
     * link decides what is allowed (see the controller of the links).
     */
    public static function systemLibrary(): Library
    {
        return new Library(new Access(null, true, []));
    }

    /**
     * The library of a link: it may do all, but it leaves out the folders and
     * files with their own permissions (in a zip), and holds the previews of
     * what it takes in until someone inside opens it (ISS00410).
     */
    public static function linkLibrary(): Library
    {
        $library = static::systemLibrary();
        $library->forLink();
        return $library;
    }

    // ---------------------------------------------------------------- the limits of the request links (ISS00410)

    public static function requestMaxFiles(): int
    {
        return max(0, intval(config('exment.file_server_request_max_files', 100)));
    }

    public static function requestMaxBytes(): int
    {
        return max(0, intval(config('exment.file_server_request_max_bytes', 1073741824)));
    }

    /**
     * The largest file from a request link: the smaller of its own limit and that of the site.
     */
    public static function requestMaxFileSize(): int
    {
        $own = max(0, intval(config('exment.file_server_request_max_file_size', 104857600)));
        $site = Uploader::maxFileSize();
        if ($own === 0) {
            return $site;
        }
        return $site > 0 ? min($own, $site) : $own;
    }

    /**
     * @return string[]
     */
    public static function requestDeniedExtensions(): array
    {
        $value = config('exment.file_server_request_deny_extensions', '');
        return static::extensionList(is_array($value) || strval($value) !== '' ? $value : static::DEFAULT_DENIED_EXTENSIONS);
    }

    /**
     * Only these types, when the site sets them (empty: all but the denied ones).
     *
     * @return string[]
     */
    public static function requestAllowedExtensions(): array
    {
        return static::extensionList(config('exment.file_server_request_allow_extensions', ''));
    }

    /**
     * @return string[]
     */
    protected static function extensionList(mixed $value): array
    {
        $list = is_array($value) ? $value : explode(',', strval($value));
        return array_values(array_filter(array_map(function ($ext) {
            return strtolower(trim(strval($ext), " .\t"));
        }, $list)));
    }

    /**
     * Put a file from the people outside into the folder of a "request" link.
     * A file with the same name is not overwritten: the new one gets another name.
     */
    public static function receive(FileShareLink $link, string $name, string $localPath): FileNode
    {
        if ($link->kind !== FileShareLink::KIND_REQUEST) {
            throw FileServerException::forbidden();
        }
        $workspace = $link->workspace;
        $folder = $link->node;
        if (!isset($workspace) || !isset($folder) || !$folder->isFolder()) {
            throw FileServerException::notFound();
        }
        $library = static::linkLibrary();
        $size = intval(filesize($localPath));
        $max = static::requestMaxFileSize();
        if ($max > 0 && $size > $max) {
            throw new FileServerException(exmtrans('file_server.error_too_large'), 413);
        }
        // "run.exe." and "run.exe　" are run.exe on Windows: no dots or spaces
        // (of any kind) at the end (ISS00410)
        $name = preg_replace('/[\s\p{Z}\x{FEFF}.]+\z/u', '', $name) ?? '';
        $name = $library->validName($name);
        // again: "run.exe.\u{202E}" is "run.exe." once the character that does not show is taken away
        $name = $library->validName(preg_replace('/[\s\p{Z}\x{FEFF}.]+\z/u', '', $name) ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = static::requestAllowedExtensions();
        if (count($allowed) > 0 ? !in_array($ext, $allowed, true) : ($ext !== '' && in_array($ext, static::requestDeniedExtensions(), true))) {
            throw new FileServerException(exmtrans('file_server.error_request_type', ['ext' => $ext]), 415);
        }

        // take a place in the limits of the link in one UPDATE (two at the same time cannot pass it)
        $query = FileShareLink::query()->where('id', $link->id);
        $maxFiles = static::requestMaxFiles();
        if ($maxFiles > 0) {
            $query->where('received_count', '<', $maxFiles);
        }
        $maxBytes = static::requestMaxBytes();
        if ($maxBytes > 0) {
            $query->where('received_bytes', '<=', $maxBytes - $size);
        }
        $updated = $query->update([
            'received_count' => \DB::raw('received_count + 1'),
            'received_bytes' => \DB::raw('received_bytes + ' . $size),
        ]);
        if ($updated === 0) {
            throw new FileServerException(exmtrans('file_server.error_request_full'), 413);
        }

        try {
            // a new file each time: never a new version of a file that is there
            // (the name is taken again right before it is kept)
            $name = $library->freeName($workspace, $folder, $name);
            $node = $library->storeNewFile($workspace, $folder, $name, $localPath, exmtrans('file_server.received_comment'));
            Audit::log(Audit::LINK_UPLOAD, $workspace, $node, null, ['size' => $size], $link->id);
            return $node;
        } catch (\Throwable $e) {
            FileShareLink::query()->where('id', $link->id)->update([
                'received_count' => \DB::raw('CASE WHEN received_count > 0 THEN received_count - 1 ELSE 0 END'),
                'received_bytes' => \DB::raw('CASE WHEN received_bytes >= ' . $size . ' THEN received_bytes - ' . $size . ' ELSE 0 END'),
            ]);
            throw $e;
        }
    }
}
