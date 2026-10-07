<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDownload;
use App\Models\Download;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class DownloaderController extends Controller
{
    /**
     * Inspect video information using yt-dlp.
     */
    public function inspect(Request $request)
    {
        try {
            $validated = $request->validate([
                'url' => ['required', 'url'],
            ]);

            $url = trim($validated['url']);

            $this->guardUrl($url);

            $ytDlp = env(
                'DOWNSYNC_YTDLP',
                'C:/Users/Lenovo/Downloads/yt-dlp.exe'
            );

            if (!is_file($ytDlp)) {
                return response()->json([
                    'error' => 'yt-dlp executable not found.',
                    'path' => $ytDlp,
                ], 500);
            }

            $tempDir = storage_path('app/downsync/temp');

            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }

            $environment = $this->windowsEnvironment();

            $command = [
                $ytDlp,
                '--dump-single-json',
                '--no-playlist',
                '--no-warnings',
                '--skip-download',
                '--force-ipv4',
                '--cache-dir',
                $tempDir,
                $url,
            ];

            $process = new Process(
                $command,
                base_path(),
                $environment
            );

            $process->setTimeout(180);
            $process->setIdleTimeout(120);

            $process->run();

            $stdout = trim($process->getOutput());
            $stderr = trim($process->getErrorOutput());

            if (!$process->isSuccessful()) {
                return response()->json([
                    'error' => 'yt-dlp failed.',
                    'exit_code' => $process->getExitCode(),
                    'stderr' => $stderr,
                    'stdout' => $stdout,
                ], 500, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            if ($stdout === '') {
                return response()->json([
                    'error' => 'yt-dlp returned an empty response.',
                    'stderr' => $stderr,
                ], 500, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            $data = json_decode(
                $stdout,
                true,
                512,
                JSON_INVALID_UTF8_SUBSTITUTE
            );

            if (!is_array($data)) {
                return response()->json([
                    'error' => 'Invalid yt-dlp JSON response.',
                    'json_error' => json_last_error_msg(),
                    'stdout' => mb_substr($stdout, 0, 5000),
                    'stderr' => $stderr,
                ], 500, [], JSON_INVALID_UTF8_SUBSTITUTE);
            }

            $formats = [];

            foreach (($data['formats'] ?? []) as $format) {
                if (empty($format['format_id'])) {
                    continue;
                }

                $formats[] = [
                    'format_id' => (string) $format['format_id'],
                    'ext' => $format['ext'] ?? null,
                    'resolution' => $format['resolution'] ?? null,
                    'width' => $format['width'] ?? null,
                    'height' => $format['height'] ?? null,
                    'fps' => $format['fps'] ?? null,
                    'filesize' => $format['filesize'] ?? null,
                    'filesize_approx' => $format['filesize_approx'] ?? null,
                    'vcodec' => $format['vcodec'] ?? null,
                    'acodec' => $format['acodec'] ?? null,
                    'format_note' => $format['format_note'] ?? null,
                    'tbr' => $format['tbr'] ?? null,
                ];
            }

            return response()->json([
                'success' => true,
                'title' => $data['title'] ?? 'Untitled',
                'thumbnail' => $data['thumbnail'] ?? null,
                'duration' => $data['duration'] ?? null,
                'uploader' => $data['uploader'] ?? null,
                'webpage_url' => $data['webpage_url'] ?? $url,
                'formats' => $formats,
            ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Unable to inspect video.',
                'message' => $e->getMessage(),
            ], 500, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }

    /**
     * Create a download record and dispatch the download job.
     */
    public function download(Request $request)
    {
        try {
            $validated = $request->validate([
                'url' => ['required', 'url'],
                'format_id' => ['nullable', 'string', 'max:100'],
                'format_label' => ['nullable', 'string', 'max:255'],
                'title' => ['nullable', 'string', 'max:500'],
                'thumbnail' => ['nullable', 'string'],
            ]);

            $url = trim($validated['url']);

            $this->guardUrl($url);

            $download = Download::create([
                'token' => Str::random(40),
                'url' => $url,
                'title' => $validated['title'] ?? 'Untitled',
                'thumbnail' => $validated['thumbnail'] ?? null,
                'status' => 'queued',
                'progress' => 0,
                'format_id' => $validated['format_id'] ?? null,
                'format_label' => $validated['format_label'] ?? null,
                'meta' => null,
            ]);

            ProcessDownload::dispatch($download->id);

            return response()->json([
                'success' => true,
                'download' => [
                    'id' => $download->id,
                    'token' => $download->token,
                    'title' => $download->title,
                    'thumbnail' => $download->thumbnail,
                    'status' => $download->status,
                    'progress' => $download->progress,
                    'format_id' => $download->format_id,
                    'format_label' => $download->format_label,
                ],
            ], 202, [], JSON_INVALID_UTF8_SUBSTITUTE);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Unable to create download.',
                'message' => $e->getMessage(),
            ], 500, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }

    /**
     * Return current download status.
     */
    public function status(string $token)
    {
        $download = Download::where('token', $token)->first();

        if (!$download) {
            return response()->json([
                'error' => 'Download not found.',
            ], 404);
        }

        /*
         * meta may already be an array because of the
         * Download model cast, or it may still be JSON.
         */
        $meta = $download->meta;

        if (is_string($meta)) {
            $decodedMeta = json_decode($meta, true);

            $meta = is_array($decodedMeta)
                ? $decodedMeta
                : [];
        }

        if (!is_array($meta)) {
            $meta = [];
        }

        return response()->json([
            'id' => $download->id,
            'token' => $download->token,
            'title' => $download->title,
            'thumbnail' => $download->thumbnail,
            'status' => $download->status,
            'progress' => (int) $download->progress,

            'file_name' => $download->file_name,
            'file_size' => $download->file_size,
            'mime_type' => $download->mime_type,

            'format_id' => $download->format_id,
            'format_label' => $download->format_label,

            /*
             * Downloaded video's actual saved metadata.
             */
            'details' => [
                'format_id' => $meta['format_id'] ?? $download->format_id,
                'ext' => $meta['ext'] ?? null,
                'resolution' => $meta['resolution'] ?? null,
                'width' => $meta['width'] ?? null,
                'height' => $meta['height'] ?? null,
                'fps' => $meta['fps'] ?? null,
                'vcodec' => $meta['vcodec'] ?? null,
                'acodec' => $meta['acodec'] ?? null,
                'tbr' => $meta['tbr'] ?? null,
                'format_note' => $meta['format_note'] ?? null,
                'filesize' => $meta['filesize'] ?? $download->file_size,
                'media' => $meta['media'] ?? null,
                'duration' => $meta['duration'] ?? null,
            ],

            'error' => $download->error,

            'download_url' => $download->status === 'ready'
                ? url('/api/downloads/' . $download->token . '/file')
                : null,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Download finished file.
     */
    public function file(string $token)
    {
        $download = Download::where('token', $token)->first();

        if (!$download) {
            return response()->json([
                'error' => 'Download not found.',
            ], 404);
        }

        if ($download->status !== 'ready') {
            return response()->json([
                'error' => 'File is not ready yet.',
                'status' => $download->status,
            ], 409);
        }

        if (
            $download->expires_at &&
            now()->greaterThan($download->expires_at)
        ) {
            return response()->json([
                'error' => 'This download has expired.',
            ], 410);
        }

        if (
            empty($download->file_path) ||
            !is_file($download->file_path)
        ) {
            return response()->json([
                'error' => 'Downloaded file no longer exists.',
            ], 404);
        }

        return response()->download(
            $download->file_path,
            $download->file_name ?: basename($download->file_path),
            [
                'Content-Type' =>
                    $download->mime_type ?: 'application/octet-stream',
            ]
        );
    }

    /**
     * Block invalid/private URLs.
     */
    private function guardUrl(string $url): void
    {
        $parts = parse_url($url);

        if (
            !$parts ||
            empty($parts['scheme']) ||
            empty($parts['host'])
        ) {
            abort(422, 'Invalid URL.');
        }

        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            abort(422, 'Only HTTP and HTTPS URLs are allowed.');
        }

        if (filter_var($parts['host'], FILTER_VALIDATE_IP)) {
            $ip = $parts['host'];

            if (
                !filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                )
            ) {
                abort(422, 'Private or reserved IP addresses are not allowed.');
            }
        }
    }

    /**
     * Windows environment required by yt-dlp/PyInstaller.
     */
    private function windowsEnvironment(): array
    {
        $environment = getenv();

        $temp = sys_get_temp_dir();

        $environment['TEMP'] = $temp;
        $environment['TMP'] = $temp;
        $environment['TMPDIR'] = $temp;

        $environment['SystemRoot'] =
            $environment['SystemRoot'] ?? 'C:\\Windows';

        $environment['SYSTEMROOT'] =
            $environment['SYSTEMROOT'] ?? 'C:\\Windows';

        if (empty($environment['PATH'])) {
            $environment['PATH'] =
                'C:\\Windows\\System32;' .
                'C:\\Windows;' .
                'C:\\Windows\\System32\\Wbem';
        }

        return $environment;
    }
}