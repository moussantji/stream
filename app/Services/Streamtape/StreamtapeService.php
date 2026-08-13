<?php

namespace App\Services\Streamtape;

use App\Models\StreamtapeFolder;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Streamtape API. Used by the admin "send to Streamtape"
 * feature: start a remote upload of a resolved CDN URL (with the browser-like
 * headers the media CDN requires), track its progress and resolve the final
 * streamtape.com/v/{fileId} link.
 */
class StreamtapeService
{
    protected string $login;

    protected string $key;

    protected string $folder;

    protected string $apiHost;

    protected int $timeout;

    public function __construct()
    {
        $this->login = (string) config('streamtape.login', '');
        $this->key = (string) config('streamtape.key', '');
        $this->folder = (string) config('streamtape.folder', '');
        $this->apiHost = rtrim((string) config('streamtape.api_host', 'https://api.streamtape.com'), '/');
        $this->timeout = (int) config('streamtape.timeout', 30);
    }

    public function isConfigured(): bool
    {
        return $this->login !== '' && $this->key !== '';
    }

    /**
     * Start a remote upload. $headers is a list of "Name: value" lines the
     * API forwards when it fetches the source URL (so the media CDN sees a
     * real browser context, e.g. the videodownloader.site referer).
     *
     * @param  string[]  $headers
     * @return array{id:string,folderid:string}
     *
     * @throws \RuntimeException
     */
    public function remoteAdd(string $url, string $name, array $headers = [], ?string $folder = null): array
    {
        $params = [
            'login' => $this->login,
            'key' => $this->key,
            'url' => $url,
            'name' => $name,
        ];
        if ($folder !== null && $folder !== '') {
            $params['folder'] = $folder;
        } elseif ($this->folder !== '') {
            $params['folder'] = $this->folder;
        }
        if ($headers !== []) {
            $params['headers'] = implode("\n", $headers);
        }

        $data = $this->call('remotedl/add', $params);
        $result = $data['result'] ?? [];
        $id = (string) ($result['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Streamtape a refusé l\'upload : '.($data['msg'] ?? 'réponse vide'));
        }

        return ['id' => $id, 'folderid' => (string) ($result['folderid'] ?? '')];
    }

    /**
     * Current status of a remote upload (the API keys the result by the id).
     * When the upload is finished the entry also carries the final
     * streamtape.com/v/{linkid} URL directly.
     *
     * @return array{status:string,bytes_loaded:?int,bytes_total:?int,file_id:?string,streamtape_url:?string}
     *
     * @throws \RuntimeException
     */
    public function remoteStatus(string $id): array
    {
        $data = $this->call('remotedl/status', [
            'login' => $this->login,
            'key' => $this->key,
            'id' => $id,
        ]);

        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        $entry = is_array($result[$id] ?? null) ? $result[$id] : [];

        return [
            'status' => (string) ($entry['status'] ?? 'unknown'),
            'bytes_loaded' => isset($entry['bytes_loaded']) ? (int) $entry['bytes_loaded'] : null,
            'bytes_total' => isset($entry['bytes_total']) ? (int) $entry['bytes_total'] : null,
            'file_id' => (string) ($entry['linkid'] ?? $entry['file']['id'] ?? $entry['fileid'] ?? ''),
            'streamtape_url' => (string) ($entry['url'] ?? '') ?: null,
        ];
    }

    /** Delete a file from the account (by its streamtape link id). */
    public function deleteFile(string $fileId): bool
    {
        $data = $this->call('file/delete', [
            'login' => $this->login,
            'key' => $this->key,
            'file' => $fileId,
        ]);

        return (bool) ($data['result'] ?? false);
    }

    /**
     * Rename a file already on the account (by its streamtape link id).
     * The `name` param of remotedl/add is ignored by Streamtape whenever the
     * source CDN supplies its own filename (Content-Disposition), so the file
     * must be renamed once the transfer is finished.
     */
    public function renameFile(string $fileId, string $name): bool
    {
        $data = $this->call('file/rename', [
            'login' => $this->login,
            'key' => $this->key,
            'file' => $fileId,
            'name' => $name,
        ]);

        return (bool) ($data['result'] ?? false);
    }

    /**
     * Detailed info for one file (by its streamtape link id). A file is only
     * actually playable once it carries a thumbnail: Streamtape converts
     * files *after* marking the remote transfer finished, and converted files
     * without a splash image are typically corrupt/truncated (they exist but
     * never play).
     *
     * @return array{status:int,converted:bool,thumb:string,size:int,name:string} entries defaulted to absent
     */
    public function fileInfo(string $linkId): array
    {
        $data = $this->call('file/info', [
            'login' => $this->login,
            'key' => $this->key,
            'file' => $linkId,
        ]);

        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        $info = is_array($result[$linkId] ?? null) ? $result[$linkId] : [];

        return [
            'status' => (int) ($info['status'] ?? 0),
            'converted' => (bool) ($info['converted'] ?? false),
            'thumb' => (string) ($info['thumb'] ?? '') ?: null,
            'size' => isset($info['size']) ? (int) $info['size'] : null,
        ];
    }

    /** Conversion queue entries (jobs not yet converted, incl. stuck ones). */
    public function runningConverts(): array
    {
        $jobs = \Illuminate\Support\Facades\Cache::remember(
            'streamtape.running_converts',
            45,
            fn (): array => $this->call('file/runningconverts', [
                'login' => $this->login,
                'key' => $this->key,
            ])['result'] ?? []
        );

        return is_array($jobs) ? $jobs : [];
    }

    /** Conversion failures reported by Streamtape. */
    public function failedConverts(): array
    {
        $jobs = \Illuminate\Support\Facades\Cache::remember(
            'streamtape.failed_converts',
            45,
            fn (): array => $this->call('file/failedconverts', [
                'login' => $this->login,
                'key' => $this->key,
            ])['result'] ?? []
        );

        return is_array($jobs) ? $jobs : [];
    }

    /** True while Streamtape still lists the link id among its convert jobs. */
    public function isConverting(string $linkId): bool
    {
        foreach ($this->runningConverts() as $job) {
            if ((string) ($job['linkid'] ?? '') === $linkId) {
                return true;
            }
        }

        return false;
    }

    /** True when Streamtape definitively lists the link id as a failed convert. */
    public function isFailed(string $linkId): bool
    {
        foreach ($this->failedConverts() as $job) {
            if ((string) ($job['linkextid'] ?? $job['linkid'] ?? '') === $linkId) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the thumbnail URL served by file/info actually loads an
     * image. Streamtape sometimes reports converted files whose thumbnail
     * never renders — those never play. The splash fetch is cheap (tens of
     * KB) and cached briefly.
     */
    public function hasThumb(string $thumbUrl): bool
    {
        if ($thumbUrl === '') {
            return false;
        }

        return (bool) \Illuminate\Support\Facades\Cache::remember(
            'streamtape.thumb.'.md5($thumbUrl),
            180,
            function () use ($thumbUrl) {
                try {
                    return \Illuminate\Support\Facades\Http::timeout(8)
                        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36'])
                        ->get($thumbUrl)
                        ->successful();
                } catch (\Throwable) {
                    return false;
                }
            }
        );
    }

    /**
     * Size of a stored file (by its streamtape link id), looked up in the
     * given folder path (resolved from local records only, never creating).
     * Used to detect remote downloads that Streamtape marks "finished" even
     * though the transfer was cut mid-way (truncated, unplayable files).
     * Returns null when the folder/file cannot be found.
     */
    public function storedSize(string $linkId, string $folderPath = ''): ?int
    {
        $folderId = '';
        if ($folderPath !== '') {
            $segments = array_values(array_filter(array_map('trim', explode('/', $folderPath)), fn ($s) => $s !== ''));
            $parent = '';
            foreach ($segments as $segment) {
                $known = StreamtapeFolder::where('name', $segment)->where('parent', $parent)->first();
                if ($known === null) {
                    return null;
                }
                $parent = $known->folder_id;
            }
            $folderId = $parent;
        }

        try {
            $data = $this->call('file/listfolder', [
                'login' => $this->login,
                'key' => $this->key,
            ] + ($folderId !== '' ? ['folder' => $folderId] : []));
        } catch (\Throwable $e) {
            return null;
        }

        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        foreach (is_array($result['files'] ?? null) ? $result['files'] : [] as $file) {
            if ((string) ($file['linkid'] ?? '') === $linkId) {
                return (int) ($file['size'] ?? 0);
            }
        }

        return null;
    }

    /** Move a file into a folder (by streamtape file/link id + folder id). */
    public function moveFile(string $fileId, string $folderId = ''): bool
    {
        $params = [
            'login' => $this->login,
            'key' => $this->key,
            'file' => $fileId,
        ];
        if ($folderId !== '') {
            $params['folder'] = $folderId;
        }

        $data = $this->call('file/move', $params);

        return (bool) ($data['result'] ?? false);
    }

    /** Delete a folder (and everything in it). May fail on some accounts. */
    public function deleteFolder(string $folderId): bool
    {
        $data = $this->call('file/deletefolder', [
            'login' => $this->login,
            'key' => $this->key,
            'folder' => $folderId,
        ]);

        return (bool) ($data['result'] ?? false);
    }

    /**
     * Resolve the Streamtape folder id for a named folder (child of the
     * configured default folder, or of the root when none). The file-list
     * endpoints return null on some accounts, so ids are memorized locally:
     * the folder is created on first use, then reused.
     *
     * @throws \RuntimeException
     */
    public function resolveFolderId(string $name, ?string $parent = null): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $parent = $parent ?? $this->folder;

        $known = StreamtapeFolder::where('name', $name)->where('parent', $parent)->first();
        if ($known !== null) {
            return $known->folder_id;
        }

        try {
            $folderId = $this->createFolder($name, $parent);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Impossible de créer le dossier « {$name} » sur Streamtape (il existe peut-être déjà, crée-le ou supprime-le dans ton compte) : ".$e->getMessage()
            );
        }

        StreamtapeFolder::updateOrCreate(
            ['name' => $name, 'parent' => $parent],
            ['folder_id' => $folderId]
        );

        return $folderId;
    }

    /**
     * Resolve (creating on demand) a nested path of folders like
     * "Séries/From/S1", one segment per level. Used by the "auto" folder
     * behaviour: films go into a folder named after them, series into
     * "Séries/{title}/{S{season}}".
     *
     * @return array{path:string,id:string} final segment path + folder id
     */
    public function resolveFolderPath(string $path): array
    {
        $segments = array_values(array_filter(array_map('trim', explode('/', $path)), fn ($s) => $s !== ''));
        if ($segments === []) {
            return ['path' => '', 'id' => ''];
        }

        $resolved = [];
        $parent = '';
        foreach ($segments as $index => $segment) {
            $resolved[] = $segment;
            $parent = $index === 0
                ? $this->resolveFolderId($segment)
                : $this->resolveFolderId($segment, $parent);
        }

        return ['path' => implode('/', $resolved), 'id' => $parent];
    }

    /**
     * Total storage used on the account: walks every folder with
     * file/listfolder and sums the file sizes. Cached for a few minutes
     * (the panel polls it and the walk hits one API call per folder).
     *
     * @return array{used_bytes:int,files:int,folders:int}
     */
    public function storageUsage(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('streamtape.storage_usage', 300, fn () => $this->walkFolder(''));
    }

    /**
     * @return array{used_bytes:int,files:int,folders:int}
     */
    protected function walkFolder(string $folderId): array
    {
        $data = $this->call('file/listfolder', [
            'login' => $this->login,
            'key' => $this->key,
        ] + ($folderId !== '' ? ['folder' => $folderId] : []));

        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        $folders = is_array($result['folders'] ?? null) ? $result['folders'] : [];
        $files = is_array($result['files'] ?? null) ? $result['files'] : [];

        $used = 0;
        foreach ($files as $file) {
            $used += (int) ($file['size'] ?? 0);
        }

        $total = ['used_bytes' => $used, 'files' => count($files), 'folders' => 0];
        foreach ($folders as $folder) {
            $child = $this->walkFolder((string) ($folder['id'] ?? ''));
            $total['used_bytes'] += $child['used_bytes'];
            $total['files'] += $child['files'];
            $total['folders'] += $child['folders'] + 1;
        }

        return $total;
    }

    /** Create a folder (children of $parent, or root when empty). */
    protected function createFolder(string $name, string $parent = ''): string
    {
        $params = [
            'login' => $this->login,
            'key' => $this->key,
            'name' => $name,
        ];
        if ($parent !== '') {
            $params['pid'] = $parent;
        }

        $data = $this->call('file/createfolder', $params);
        $folderId = (string) ($data['result']['folderid'] ?? '');
        if ($folderId === '') {
            throw new \RuntimeException('réponse vide de l\'API');
        }

        return $folderId;
    }

    /**
     * Upload a local file directly (POST multipart /file/uploadfile).
     *
     * @return array{id:string,link:?string}
     *
     * @throws \RuntimeException
     */
    public function uploadLocal(string $path, string $name, ?string $folder = null): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('Fichier local introuvable : '.$path);
        }

        $params = [
            'login' => $this->login,
            'key' => $this->key,
        ];
        if ($folder !== null && $folder !== '') {
            $params['folder'] = $folder;
        } elseif ($this->folder !== '') {
            $params['folder'] = $this->folder;
        }

        $response = Http::timeout($this->timeout * 4)
            ->asMultipart()
            ->attach('file', fopen($path, 'r'), $name)
            ->post($this->apiHost.'/file/uploadfile', $params);

        if ($response->failed()) {
            throw new \RuntimeException('Streamtape uploadfile a répondu HTTP '.$response->status());
        }

        $json = $response->json();
        $status = (int) ($json['status'] ?? 0);
        if ($status !== 200) {
            throw new \RuntimeException('Streamtape uploadfile : '.($json['msg'] ?? 'erreur '.$status));
        }

        $result = is_array($json['result'] ?? null) ? $json['result'] : [];

        return [
            'id' => (string) ($result['id'] ?? ''),
            'link' => isset($result['link']) ? (string) $result['link'] : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     *
     * @throws \RuntimeException
     */
    protected function call(string $path, array $params): array
    {
        $response = Http::timeout($this->timeout)->get($this->apiHost.'/'.$path, $params);

        if ($response->failed()) {
            throw new \RuntimeException('Streamtape '.$path.' a répondu HTTP '.$response->status());
        }

        $json = $response->json();
        $status = (int) ($json['status'] ?? 0);
        if ($status !== 200) {
            throw new \RuntimeException(
                'Streamtape '.$path.' : '.($json['msg'] ?? 'erreur '.$status)
            );
        }

        return is_array($json) ? $json : [];
    }
}
