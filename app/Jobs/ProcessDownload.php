<?php

namespace App\Jobs;

use App\Models\Download;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;

class ProcessDownload implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(
        public int $downloadId
    ) {
    }

    public function handle(): void
    {
        $download = Download::find($this->downloadId);

        if (!$download) {
            return;
        }

        $download->update([
            'status' => 'processing',
            'progress' => 0,
            'error' => null,
        ]);

        $baseDir = storage_path('app/downsync');

        $downloadDir = $baseDir . DIRECTORY_SEPARATOR . 'downloads';
        $tempDir = $baseDir . DIRECTORY_SEPARATOR . 'temp';

        if (!is_dir($downloadDir)) {
            mkdir($downloadDir, 0777, true);
        }

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        /*
        |--------------------------------------------------------------------------
        | yt-dlp
        |--------------------------------------------------------------------------
        */

        $ytDlp = env(
            'DOWNSYNC_YTDLP',
            'C:/Users/Lenovo/Downloads/yt-dlp.exe'
        );

        if (!file_exists($ytDlp)) {
            $this->failDownload(
                $download,
                'yt-dlp not found: ' . $ytDlp
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | FFmpeg
        |--------------------------------------------------------------------------
        */

        $ffmpeg = $this->findFfmpeg();

        if (!$ffmpeg) {
            $this->failDownload(
                $download,
                'FFmpeg was not found. Please install FFmpeg or set DOWNSYNC_FFMPEG in .env.'
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Format Selection
        |--------------------------------------------------------------------------
        |
        | Selected video format + BEST AUDIO.
        |
        | Audio is intentionally mandatory here.
        |
        */

        $formatId = trim((string) $download->format_id);

        if ($formatId !== '') {
            $formatSelector =
                $formatId . '+bestaudio/bestvideo+bestaudio/best';
        } else {
            $formatSelector = 'bestvideo+bestaudio/best';
        }

        /*
        |--------------------------------------------------------------------------
        | Output
        |--------------------------------------------------------------------------
        */

        $outputTemplate =
            $downloadDir .
            DIRECTORY_SEPARATOR .
            $download->token .
            '.%(ext)s';

        /*
        |--------------------------------------------------------------------------
        | yt-dlp Command
        |--------------------------------------------------------------------------
        */

        $command = [
            $ytDlp,

            '--no-playlist',
            '--newline',
            '--no-warnings',
            '--force-ipv4',

            /*
            |--------------------------------------------------------------------------
            | Faster downloads
            |--------------------------------------------------------------------------
            */

            '--concurrent-fragments',
            '8',

            /*
            |--------------------------------------------------------------------------
            | Merge video + audio into MP4
            |--------------------------------------------------------------------------
            */

            '--merge-output-format',
            'mp4',

            '--ffmpeg-location',
            $ffmpeg,

            /*
            |--------------------------------------------------------------------------
            | Video + Audio
            |--------------------------------------------------------------------------
            */

            '-f',
            $formatSelector,

            '-o',
            $outputTemplate,

            $download->url,
        ];

        $process = new Process(
            $command,
            base_path(),
            $this->windowsEnvironment()
        );

        $process->setTimeout($this->timeout);

        $process->run(
            function ($type, $buffer) use ($download) {
                $this->updateProgress(
                    $download,
                    $buffer
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Download Failed
        |--------------------------------------------------------------------------
        */

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput());

            if ($error === '') {
                $error = trim($process->getOutput());
            }

            if ($error === '') {
                $error = 'Video download failed.';
            }

            $this->failDownload(
                $download,
                $error
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Find Downloaded File
        |--------------------------------------------------------------------------
        */

        $filePath = $this->findDownloadedFile(
            $downloadDir,
            $download->token
        );

        if (!$filePath) {
            $this->failDownload(
                $download,
                'Download completed but video file was not found.'
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Make Sure Video Exists
        |--------------------------------------------------------------------------
        */

        if (!$this->hasVideoStream($filePath)) {
            $this->failDownload(
                $download,
                'Downloaded file does not contain a video stream.'
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | File Size
        |--------------------------------------------------------------------------
        */

        $fileSize = @filesize($filePath);

        if ($fileSize === false) {
            $fileSize = null;
        }

        /*
        |--------------------------------------------------------------------------
        | Actual Downloaded File Details
        |--------------------------------------------------------------------------
        */

        $details = $this->getVideoDetails(
            $filePath,
            $download
        );

        $fileName = basename($filePath);

        $mimeType = 'video/mp4';

        if (function_exists('mime_content_type')) {
            $detectedMime = @mime_content_type($filePath);

            if ($detectedMime) {
                $mimeType = $detectedMime;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Ready
        |--------------------------------------------------------------------------
        */

        $download->update([
            'status' => 'ready',
            'progress' => 100,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'meta' => json_encode(
                $details,
                JSON_UNESCAPED_UNICODE
            ),
            'error' => null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Windows Environment
    |--------------------------------------------------------------------------
    */

    protected function windowsEnvironment(): array
    {
        $env = $_ENV;

        $tempPath = storage_path('app/downsync/temp');

        $env['TEMP'] = $tempPath;
        $env['TMP'] = $tempPath;
        $env['TMPDIR'] = $tempPath;

        $ffmpegDir = $this->findFfmpegDirectory();

        if ($ffmpegDir) {
            $oldPath = $env['PATH'] ?? '';

            $env['PATH'] =
                $ffmpegDir .
                ';' .
                $oldPath;
        }

        if (!isset($env['SystemRoot'])) {
            $env['SystemRoot'] = 'C:\\Windows';
        }

        if (!isset($env['PATH'])) {
            $env['PATH'] =
                'C:\\Windows\\System32;' .
                'C:\\Windows;' .
                'C:\\Windows\\System32\\Wbem';
        }

        return $env;
    }

    /*
    |--------------------------------------------------------------------------
    | Progress
    |--------------------------------------------------------------------------
    */

    protected function updateProgress(
        Download $download,
        string $output
    ): void {
        if (
            preg_match(
                '/(\d+(?:\.\d+)?)%/',
                $output,
                $matches
            )
        ) {
            $progress = (float) $matches[1];

            $progress = max(
                0,
                min(99, $progress)
            );

            $download->update([
                'progress' => $progress,
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Find Downloaded File
    |--------------------------------------------------------------------------
    */

    protected function findDownloadedFile(
        string $downloadDir,
        string $token
    ): ?string {
        $files = glob(
            $downloadDir .
            DIRECTORY_SEPARATOR .
            $token .
            '.*'
        );

        if (!$files) {
            return null;
        }

        $allowedExtensions = [
            'mp4',
            'mkv',
            'webm',
            'mov',
            'm4v',
            'avi',
        ];

        $videoFiles = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $extension = strtolower(
                pathinfo(
                    $file,
                    PATHINFO_EXTENSION
                )
            );

            if (
                in_array(
                    $extension,
                    $allowedExtensions,
                    true
                )
            ) {
                $videoFiles[] = $file;
            }
        }

        if (!$videoFiles) {
            return null;
        }

        usort(
            $videoFiles,
            function ($a, $b) {
                return filemtime($b) <=> filemtime($a);
            }
        );

        return $videoFiles[0] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | Check Video Stream
    |--------------------------------------------------------------------------
    */

    protected function hasVideoStream(
        string $filePath
    ): bool {
        $ffprobe = $this->findFfprobe();

        if (!$ffprobe) {
            return true;
        }

        try {
            $process = new Process([
                $ffprobe,
                '-v',
                'error',
                '-select_streams',
                'v:0',
                '-show_entries',
                'stream=codec_type',
                '-of',
                'default=noprint_wrappers=1:nokey=1',
                $filePath,
            ]);

            $process->setTimeout(60);

            $process->run();

            if (!$process->isSuccessful()) {
                return true;
            }

            return trim($process->getOutput()) !== '';
        } catch (\Throwable $e) {
            return true;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Actual Video Details
    |--------------------------------------------------------------------------
    */

    protected function getVideoDetails(
        string $filePath,
        Download $download
    ): array {
        $fileSize = @filesize($filePath);

        if ($fileSize === false) {
            $fileSize = null;
        }

        $extension = strtolower(
            pathinfo(
                $filePath,
                PATHINFO_EXTENSION
            )
        );

        $details = [
            'format_id' => $download->format_id,
            'ext' => $extension,
            'resolution' => null,
            'width' => null,
            'height' => null,
            'fps' => null,
            'vcodec' => null,
            'acodec' => null,
            'tbr' => null,
            'filesize' => $fileSize,
            'format_note' => null,
            'media' => 'Video',
            'duration' => null,
            'title' => $download->title,
            'uploader' => null,
            'subtitle' => null,
        ];

        /*
        |--------------------------------------------------------------------------
        | FFprobe Actual File Metadata
        |--------------------------------------------------------------------------
        */

        $ffprobe = $this->findFfprobe();

        if ($ffprobe) {
            try {
                $process = new Process([
                    $ffprobe,
                    '-v',
                    'error',
                    '-show_entries',
                    'format=duration,bit_rate',
                    '-show_entries',
                    'stream=codec_type,codec_name,width,height,r_frame_rate,bit_rate',
                    '-of',
                    'json',
                    $filePath,
                ]);

                $process->setTimeout(120);

                $process->run();

                if ($process->isSuccessful()) {
                    $data = json_decode(
                        $process->getOutput(),
                        true
                    );

                    if (is_array($data)) {
                        $streams = $data['streams'] ?? [];
                        $format = $data['format'] ?? [];

                        $video = null;
                        $audio = null;

                        foreach ($streams as $stream) {
                            if (
                                ($stream['codec_type'] ?? '') === 'video' &&
                                $video === null
                            ) {
                                $video = $stream;
                            }

                            if (
                                ($stream['codec_type'] ?? '') === 'audio' &&
                                $audio === null
                            ) {
                                $audio = $stream;
                            }
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Video
                        |--------------------------------------------------------------------------
                        */

                        if ($video) {
                            if (isset($video['width'])) {
                                $details['width'] =
                                    (int) $video['width'];
                            }

                            if (isset($video['height'])) {
                                $details['height'] =
                                    (int) $video['height'];
                            }

                            $details['vcodec'] =
                                $video['codec_name'] ?? null;

                            if (!empty($video['r_frame_rate'])) {
                                $details['fps'] =
                                    $this->parseFrameRate(
                                        $video['r_frame_rate']
                                    );
                            }

                            if (
                                isset($video['bit_rate']) &&
                                is_numeric($video['bit_rate'])
                            ) {
                                $details['tbr'] =
                                    round(
                                        ((float) $video['bit_rate']) / 1000,
                                        2
                                    );
                            }
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Audio
                        |--------------------------------------------------------------------------
                        */

                        if ($audio) {
                            $details['acodec'] =
                                $audio['codec_name'] ?? null;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Duration
                        |--------------------------------------------------------------------------
                        */

                        if (
                            isset($format['duration']) &&
                            is_numeric($format['duration'])
                        ) {
                            $details['duration'] =
                                round(
                                    (float) $format['duration'],
                                    3
                                );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Total Bitrate
                        |--------------------------------------------------------------------------
                        */

                        if (
                            empty($details['tbr']) &&
                            isset($format['bit_rate']) &&
                            is_numeric($format['bit_rate'])
                        ) {
                            $details['tbr'] =
                                round(
                                    ((float) $format['bit_rate']) / 1000,
                                    2
                                );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Resolution
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $details['width'] &&
                            $details['height']
                        ) {
                            $details['resolution'] =
                                $details['width'] .
                                ' × ' .
                                $details['height'];

                            /*
                            |--------------------------------------------------------------------------
                            | Actual format note
                            |--------------------------------------------------------------------------
                            |
                            | This comes from the downloaded file,
                            | NOT the selected frontend label.
                            |
                            */

                            $details['format_note'] =
                                $details['height'] . 'p';
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Media Type
                        |--------------------------------------------------------------------------
                        */

                        if ($video && $audio) {
                            $details['media'] =
                                'Video + Audio';
                        } elseif ($video) {
                            $details['media'] =
                                'Video';
                        } elseif ($audio) {
                            $details['media'] =
                                'Audio';
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Metadata failure does not fail download.
            }
        }

        /*
        |--------------------------------------------------------------------------
        | YouTube Metadata
        |--------------------------------------------------------------------------
        */

        $ytDlp = env(
            'DOWNSYNC_YTDLP',
            'C:/Users/Lenovo/Downloads/yt-dlp.exe'
        );

        if (file_exists($ytDlp)) {
            try {
                $process = new Process([
                    $ytDlp,
                    '--dump-single-json',
                    '--no-playlist',
                    '--no-warnings',
                    '--skip-download',
                    '--force-ipv4',
                    $download->url,
                ]);

                $process->setTimeout(180);

                $process->run();

                if ($process->isSuccessful()) {
                    $info = json_decode(
                        $process->getOutput(),
                        true
                    );

                    if (is_array($info)) {
                        if (!empty($info['title'])) {
                            $details['title'] =
                                $info['title'];
                        }

                        if (!empty($info['uploader'])) {
                            $details['uploader'] =
                                $info['uploader'];
                        }

                        if (!empty($info['channel'])) {
                            $details['subtitle'] =
                                $info['channel'];
                        } elseif (!empty($info['uploader'])) {
                            $details['subtitle'] =
                                $info['uploader'];
                        }

                        if (
                            empty($details['duration']) &&
                            isset($info['duration']) &&
                            is_numeric($info['duration'])
                        ) {
                            $details['duration'] =
                                round(
                                    (float) $info['duration'],
                                    3
                                );
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Ignore metadata error.
            }
        }

        $details['filesize'] = $fileSize;

        return $details;
    }

    /*
    |--------------------------------------------------------------------------
    | FPS Parser
    |--------------------------------------------------------------------------
    */

    protected function parseFrameRate(
        string $rate
    ): ?float {
        if (str_contains($rate, '/')) {
            $parts = explode(
                '/',
                $rate,
                2
            );

            $numerator = $parts[0] ?? null;
            $denominator = $parts[1] ?? null;

            if (
                is_numeric($numerator) &&
                is_numeric($denominator) &&
                (float) $denominator !== 0.0
            ) {
                return round(
                    (float) $numerator /
                    (float) $denominator,
                    2
                );
            }
        }

        if (is_numeric($rate)) {
            return round(
                (float) $rate,
                2
            );
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Find FFmpeg
    |--------------------------------------------------------------------------
    */

    protected function findFfmpeg(): ?string
    {
        $configured = env('DOWNSYNC_FFMPEG');

        if (
            $configured &&
            file_exists($configured)
        ) {
            return $configured;
        }

        $paths = [
            'C:/ffmpeg/bin/ffmpeg.exe',
            'C:/Program Files/ffmpeg/bin/ffmpeg.exe',
            'C:/Users/Lenovo/Downloads/ffmpeg/bin/ffmpeg.exe',
            'C:/Users/Lenovo/Downloads/ffmpeg.exe',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | FFmpeg Directory
    |--------------------------------------------------------------------------
    */

    protected function findFfmpegDirectory(): ?string
    {
        $ffmpeg = $this->findFfmpeg();

        if (!$ffmpeg) {
            return null;
        }

        return dirname($ffmpeg);
    }

    /*
    |--------------------------------------------------------------------------
    | Find FFprobe
    |--------------------------------------------------------------------------
    */

    protected function findFfprobe(): ?string
    {
        $configured = env('DOWNSYNC_FFPROBE');

        if (
            $configured &&
            file_exists($configured)
        ) {
            return $configured;
        }

        $paths = [
            'C:/ffmpeg/bin/ffprobe.exe',
            'C:/Program Files/ffmpeg/bin/ffprobe.exe',
            'C:/Users/Lenovo/Downloads/ffmpeg/bin/ffprobe.exe',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Maximum File Size
    |--------------------------------------------------------------------------
    */

    protected function maxBytes(): int
    {
        return 2048 * 1024 * 1024;
    }

    /*
    |--------------------------------------------------------------------------
    | Failed Download
    |--------------------------------------------------------------------------
    */

    protected function failDownload(
        Download $download,
        string $message
    ): void {
        $download->update([
            'status' => 'failed',
            'error' => $message,
        ]);
    }
}