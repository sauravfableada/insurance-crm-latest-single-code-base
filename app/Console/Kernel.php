<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Run marketing automations daily without overlapping in production.
        $schedule->command('marketing:send-automations')
            ->dailyAt('09:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Keep Baileys WhatsApp bridge alive without PM2 (shared host / cron only)
        $schedule->call(function () {
            $serverDir = base_path('whatsapp-session-server');
            if (! file_exists($serverDir . '/server.js')) {
                return;
            }

            $baseUrl = rtrim((string) env('WHATSAPP_SESSION_URL', 'http://127.0.0.1:3001'), '/');
            $isHealthy = false;
            try {
                $resp = \Illuminate\Support\Facades\Http::timeout(2)->get("{$baseUrl}/health");
                $isHealthy = $resp->successful() && (($resp->json('status') ?? '') === 'ok');
            } catch (\Throwable $e) {
                $isHealthy = false;
            }

            if (! $isHealthy) {
                \Illuminate\Support\Facades\Log::warning('[WhatsApp Session Bridge Monitor] Node server was down. Auto-restarting in background...');
                if (function_exists('exec')) {
                    if (PHP_OS_FAMILY === 'Windows') {
                        @pclose(@popen("start /B node \"{$serverDir}\\server.js\" >> \"{$serverDir}\\server.log\" 2>&1", 'r'));
                    } else {
                        @exec('pkill -9 -f "whatsapp-session-server/server.js" 2>/dev/null');
                        @exec('pkill -9 -f "whatsapp-session-server.*server.js" 2>/dev/null');
                        usleep(500000);
                        if (file_exists('/tmp/wa_session.sock')) {
                            @unlink('/tmp/wa_session.sock');
                        }

                        $nodeBin = 'node';
                        $which = function_exists('shell_exec') ? trim((string) @shell_exec('which node 2>/dev/null')) : '';
                        if (! empty($which) && @is_executable($which)) {
                            $nodeBin = $which;
                        } else {
                            $home = getenv('HOME') ?: ('/home/' . (get_current_user() ?: 'u378554361'));
                            $candidates = array_merge(
                                $home ? (@glob($home . '/.nvm/versions/node/*/bin/node') ?: []) : [],
                                ['/usr/local/bin/node', '/usr/bin/node', '/bin/node'],
                                @glob('/opt/alt/alt-nodejs*/root/usr/bin/node') ?: []
                            );
                            rsort($candidates);
                            foreach ($candidates as $cand) {
                                if (@file_exists($cand) && @is_executable($cand)) {
                                    $nodeBin = $cand;
                                    break;
                                }
                            }
                        }

                        $cmd = "cd {$serverDir} && nohup {$nodeBin} server.js >> {$serverDir}/server.log 2>&1 &";
                        @exec($cmd);
                    }
                }
            }
        })
            ->name('whatsapp:keep-session-server-alive')
            ->everyMinute()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
