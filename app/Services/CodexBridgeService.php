<?php

namespace App\Services;

use App\Models\CodexBridgeTask;
use App\Models\IntakeSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CodexBridgeService
{
    public function dispatchHairIntakeMatch(IntakeSession $session): ?CodexBridgeTask
    {
        if (! config('codex_bridge.enabled')) {
            return null;
        }

        $threadId = trim((string) config('codex_bridge.thread_id'));
        if ($threadId === '') {
            throw new RuntimeException('CODEX_BRIDGE_THREAD_ID is missing.');
        }

        $session->loadMissing('brand');

        $task = CodexBridgeTask::query()->create([
            'task_uuid' => (string) Str::uuid(),
            'task_type' => 'hair_intake_match',
            'intake_session_id' => $session->id,
            'codex_thread_id' => $threadId,
            'status' => 'queued',
        ]);

        $promptPath = 'codex-bridge/tasks/'.$task->task_uuid.'/prompt.md';
        $outputPath = Storage::disk('local')->path('codex-bridge/tasks/'.$task->task_uuid.'/last-message.md');
        $scriptPath = Storage::disk('local')->path('codex-bridge/tasks/'.$task->task_uuid.'/run.cmd');

        Storage::disk('local')->put($promptPath, $this->hairIntakeMatchPrompt($session, $task));
        $this->writeRunScript($scriptPath, Storage::disk('local')->path($promptPath), $outputPath, $threadId);

        $task->update([
            'prompt_disk' => 'local',
            'prompt_path' => $promptPath,
            'script_path' => $scriptPath,
            'output_path' => $outputPath,
        ]);

        try {
            $processId = $this->startDetached($scriptPath);
            $task->update([
                'status' => 'started',
                'process_id' => $processId,
                'started_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $task->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            Log::error('Failed to start Codex bridge task.', [
                'task_uuid' => $task->task_uuid,
                'intake_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $task->fresh();
    }

    private function hairIntakeMatchPrompt(IntakeSession $session, CodexBridgeTask $task): string
    {
        $photoPath = $session->photo_path
            ? storage_path('app/public/'.$session->photo_path)
            : null;

        $observations = json_encode($session->observations_json ?: ['main' => [], 'sub' => [], 'common' => []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return trim(<<<PROMPT
Hair extension intake match request from the browser.

Task UUID: {$task->task_uuid}
Intake session UUID: {$session->session_uuid}
Intake session id: {$session->id}
Brand catalogue brand id: {$session->brand_catalogue_brand_id}
Brand name: {$session->brand?->name}
Style hint: {$session->style_name_hint}
Observations JSON: {$observations}
Photo path: {$photoPath}

Hard scope rules:
- Do not browse the internet.
- Do not scan the whole project.
- Use only this intake session and hair-extension catalogue data.
- Read at most these app areas if code context is needed:
  - app/Models/IntakeSession.php
  - app/Models/IntakeSessionAiCall.php
  - app/Services/HairIntakeCatalogueMatcher.php
  - app/Http/Controllers/HairExtensionIntakeWizardController.php
- Use only these database areas:
  - intake_sessions for the session above
  - intake_session_ai_calls for writing the match result
  - brand_catalogue_brands, brand_catalogue_lines, brand_catalogue_product_types, brand_catalogue_styles, brand_catalogue_skus, and related variant option/value tables, scoped to brand_catalogue_brand_id above

Required output action:
1. Inspect the submitted photo and observations.
2. Match only against the scoped brand catalogue records.
3. Write a Call 1 match JSON result into intake_session_ai_calls for this intake session.
4. Set intake_sessions.status = 'draft' and current_step = 2 after writing the result.
5. Set codex_bridge_tasks.status = 'finished' and finished_at = now() for Task UUID {$task->task_uuid}.
6. Do not accept the match automatically and do not create session variants. The user must click "Use this match".

Required match JSON shape:
{
  "match_status": "confirmed|needs_user_choice|not_found",
  "confidence": 0.0,
  "matched_family": {"family_id": null, "family_name": ""},
  "matched_type": {"type_id": null, "type_name": ""},
  "matched_style": {"style_id": 123, "style_name": ""},
  "variant_taxonomy": {"main_axis": "length", "sub_axis": "colour", "common_axis": "pack_count"},
  "variants": [
    {
      "variant_id": 1,
      "display_name": "",
      "main": "",
      "sub": "",
      "common": "",
      "matches_observation": false,
      "status": "pending_user_confirmation"
    }
  ],
  "candidates": [],
  "reasoning": "One line: what matched, what was inferred, what is uncertain."
}

If confidence is not safe, write needs_user_choice with 2-3 candidates or not_found. Do not invent data.
PROMPT);
    }

    private function writeRunScript(string $scriptPath, string $promptPath, string $outputPath, string $threadId): void
    {
        $dir = dirname($scriptPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $codex = (string) config('codex_bridge.binary', 'codex');
        $workspace = (string) config('codex_bridge.workspace', base_path());
        $logPath = dirname($scriptPath).DIRECTORY_SEPARATOR.'process.log';
        $command = sprintf(
            '%s -C %s exec resume --skip-git-repo-check --dangerously-bypass-approvals-and-sandbox --output-last-message %s %s - < %s > %s 2>&1',
            escapeshellarg($codex),
            escapeshellarg($workspace),
            escapeshellarg($outputPath),
            escapeshellarg($threadId),
            escapeshellarg($promptPath),
            escapeshellarg($logPath),
        );

        file_put_contents($scriptPath, "@echo off\r\n".$command."\r\n");
    }

    private function startDetached(string $scriptPath): ?int
    {
        $quoted = str_replace("'", "''", $scriptPath);
        $powershell = "\$p = Start-Process -FilePath 'cmd.exe' -ArgumentList @('/c', '{$quoted}') -WindowStyle Hidden -PassThru; Write-Output \$p.Id";
        $process = proc_open(
            ['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', $powershell],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            base_path(),
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start PowerShell for Codex bridge task.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('PowerShell failed to start Codex bridge task. '.$stderr);
        }

        $processId = (int) trim($stdout);

        return $processId > 0 ? $processId : null;
    }
}
