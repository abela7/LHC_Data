<?php

namespace App\Services;

use App\Models\PhotoProcessingSetting;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackgroundRemovalService
{
    /**
     * @return array{available:bool,python:string|null,rembg:bool,message:string,details:string|null}
     */
    public function availability(?PhotoProcessingSetting $settings = null): array
    {
        $settings ??= PhotoProcessingSetting::current();
        $process = new Process(array_merge(
            $this->pythonCommandParts($settings->python_command),
            ['-c', 'import sys; import rembg; import PIL; print(sys.version.split()[0])']
        ));
        $process->setTimeout(20);
        $process->run();

        if ($process->isSuccessful()) {
            return [
                'available' => true,
                'python' => trim($process->getOutput()) ?: null,
                'rembg' => true,
                'message' => 'AI background removal is ready.',
                'details' => null,
            ];
        }

        return [
            'available' => false,
            'python' => null,
            'rembg' => false,
            'message' => 'AI background removal is not installed yet.',
            'details' => trim($process->getErrorOutput() ?: $process->getOutput()) ?: null,
        ];
    }

    /**
     * @return array{ok:bool,message:string,output_path:string|null,details:string|null}
     */
    public function removePublicImageToWhite(string $inputStoragePath, string $outputStoragePath, ?PhotoProcessingSetting $settings = null): array
    {
        $settings ??= PhotoProcessingSetting::current();
        $disk = Storage::disk('public');

        if (! $disk->exists($inputStoragePath)) {
            return [
                'ok' => false,
                'message' => 'Input photo file does not exist.',
                'output_path' => null,
                'details' => $inputStoragePath,
            ];
        }

        $script = base_path('scripts/remove_background.py');
        if (! is_file($script)) {
            return [
                'ok' => false,
                'message' => 'Background removal worker script is missing.',
                'output_path' => null,
                'details' => $script,
            ];
        }

        $outputDirectory = dirname($outputStoragePath);
        if (! $disk->exists($outputDirectory)) {
            $disk->makeDirectory($outputDirectory);
        }

        $process = new Process(array_merge(
            $this->pythonCommandParts($settings->python_command),
            [
                $script,
                $disk->path($inputStoragePath),
                $disk->path($outputStoragePath),
                '--background',
                $settings->background_color ?: '#ffffff',
            ]
        ));
        $process->setTimeout(max(30, (int) $settings->timeout_seconds));
        $process->run();

        if ($process->isSuccessful() && $disk->exists($outputStoragePath)) {
            return [
                'ok' => true,
                'message' => 'Background removed and white background applied.',
                'output_path' => $outputStoragePath,
                'details' => trim($process->getOutput()) ?: null,
            ];
        }

        $disk->delete($outputStoragePath);

        return [
            'ok' => false,
            'message' => 'AI background removal failed.',
            'output_path' => null,
            'details' => trim($process->getErrorOutput() ?: $process->getOutput()) ?: null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function pythonCommandParts(?string $command): array
    {
        $parts = str_getcsv(trim($command ?: 'py'), ' ', '"', '\\');
        $parts = array_values(array_filter($parts, fn (string $part): bool => trim($part) !== ''));

        return $parts ?: ['py'];
    }
}
