<?php

// =====================================================
// GitHub Copilot Metrics - PHP POC Script
// =====================================================

$GITHUB_TOKEN = ""; // <-- REPLACE with your GitHub Personal Access Token (PAT)
$ORG          = "Teknoturf Info Services Pvt Ltd";      // e.g. "my-company"
$TEAM_SLUG    = "";                         // optional: filter by team slug
$TARGET_USER  = "";                         // pass a username to get full detail

// Read from CLI args if passed
// Usage: php copilot_metrics.php [username]
if (isset($argv[1])) {
    $TARGET_USER = trim($argv[1]);
}

// =====================================================
// HELPER: Make GitHub API Request
// =====================================================
function githubRequest(string $url, string $token): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            "Accept: application/vnd.github+json",
            "X-GitHub-Api-Version: 2022-11-28",
            "User-Agent: CopilotMetricsPOC/1.0"
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ["error" => "HTTP $httpCode", "url" => $url, "response" => json_decode($response, true)];
    }
    return json_decode($response, true) ?? [];
}

// =====================================================
// HELPER: Pretty JSON output
// =====================================================
function printJson(mixed $data, string $title = ""): void {
    if ($title) {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "  $title\n";
        echo str_repeat("=", 60) . "\n";
    }
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

// =====================================================
// HELPER: Calculate acceptance rate
// =====================================================
function acceptanceRate(int $accepted, int $suggested): string {
    if ($suggested === 0) return "0%";
    return round(($accepted / $suggested) * 100, 2) . "%";
}

// =====================================================
// STEP 1: Get Org-Level Metrics (last 28 days)
// =====================================================
function getOrgMetrics(string $org, string $token): array {
    $url = "https://api.github.com/orgs/$org/copilot/metrics?per_page=28";
    return githubRequest($url, $token);
}

// =====================================================
// STEP 2: Get All Copilot Seats (all licensed users)
// =====================================================
function getAllSeats(string $org, string $token): array {
    $url   = "https://api.github.com/orgs/$org/copilot/billing/seats?per_page=100";
    $data  = githubRequest($url, $token);
    return $data["seats"] ?? $data;
}

// =====================================================
// STEP 3: Get Active Members Summary
// =====================================================
function getActiveMembers(array $orgMetrics, array $allSeats): array {
    // Get latest day with data
    $latest = end($orgMetrics);
    if (empty($latest)) return [];

    $activeUsers  = $latest["total_active_users"]  ?? 0;
    $totalSeats   = count($allSeats);

    // Build seat map: username => last_activity
    $seatMap = [];
    foreach ($allSeats as $seat) {
        $login = $seat["assignee"]["login"] ?? "unknown";
        $seatMap[$login] = [
            "login"              => $login,
            "last_activity_at"   => $seat["last_activity_at"] ?? null,
            "last_activity_editor"=> $seat["last_activity_editor"] ?? null,
            "plan_type"          => $seat["plan_type"] ?? null,
            "pending_cancellation_date" => $seat["pending_cancellation_date"] ?? null,
        ];
    }

    return [
        "summary" => [
            "date"                => $latest["date"] ?? "N/A",
            "total_seats"         => $totalSeats,
            "total_active_users"  => $activeUsers,
            "inactive_seats"      => $totalSeats - $activeUsers,
        ],
        "members" => array_values($seatMap),
    ];
}

// =====================================================
// STEP 4: Get Full Data for a Specific User
// =====================================================
function getUserFullData(string $username, string $org, string $token, array $orgMetrics, array $allSeats): array {
    $result = [
        "user"              => $username,
        "seat_info"         => null,
        "daily_metrics"     => [],
        "aggregated"        => [],
    ];

    // --- Seat Info ---
    foreach ($allSeats as $seat) {
        if (($seat["assignee"]["login"] ?? "") === $username) {
            $result["seat_info"] = [
                "login"                     => $seat["assignee"]["login"] ?? null,
                "avatar_url"                => $seat["assignee"]["avatar_url"] ?? null,
                "plan_type"                 => $seat["plan_type"] ?? null,
                "last_activity_at"          => $seat["last_activity_at"] ?? null,
                "last_activity_editor"      => $seat["last_activity_editor"] ?? null,
                "pending_cancellation_date" => $seat["pending_cancellation_date"] ?? null,
                "created_at"                => $seat["created_at"] ?? null,
                "updated_at"                => $seat["updated_at"] ?? null,
            ];
            break;
        }
    }

    // --- Per-day metrics aggregated for this user ---
    // Note: org metrics are aggregated; per-user breakdown extracted from editor/language data
    $totalSuggestions   = 0;
    $totalAcceptances   = 0;
    $totalLinesAccepted = 0;
    $totalLinesSuggested= 0;
    $totalChats         = 0;
    $totalChatTurns     = 0;
    $activeDays         = 0;
    $languageUsage      = [];
    $editorUsage        = [];
    $agentModeUsed      = false;
    $prSummariesUsed    = 0;
    $dotcomChats        = 0;

    foreach ($orgMetrics as $day) {
        $date = $day["date"] ?? "unknown";

        // IDE Code Completions
        $completions = $day["copilot_ide_code_completions"] ?? [];
        foreach (($completions["editors"] ?? []) as $editor) {
            $editorName = $editor["name"] ?? "unknown";
            foreach (($editor["models"] ?? []) as $model) {
                foreach (($model["languages"] ?? []) as $lang) {
                    $suggestions    = $lang["total_code_suggestions"]    ?? 0;
                    $acceptances    = $lang["total_code_acceptances"]    ?? 0;
                    $linesSuggested = $lang["total_code_lines_suggested"] ?? 0;
                    $linesAccepted  = $lang["total_code_lines_accepted"]  ?? 0;
                    $langName       = $lang["name"] ?? "unknown";

                    $totalSuggestions    += $suggestions;
                    $totalAcceptances    += $acceptances;
                    $totalLinesSuggested += $linesSuggested;
                    $totalLinesAccepted  += $linesAccepted;

                    // Language accumulation
                    if (!isset($languageUsage[$langName])) {
                        $languageUsage[$langName] = ["suggestions" => 0, "acceptances" => 0, "lines_accepted" => 0];
                    }
                    $languageUsage[$langName]["suggestions"]   += $suggestions;
                    $languageUsage[$langName]["acceptances"]   += $acceptances;
                    $languageUsage[$langName]["lines_accepted"] += $linesAccepted;

                    // Editor accumulation
                    if (!isset($editorUsage[$editorName])) {
                        $editorUsage[$editorName] = ["suggestions" => 0, "acceptances" => 0];
                    }
                    $editorUsage[$editorName]["suggestions"]  += $suggestions;
                    $editorUsage[$editorName]["acceptances"]  += $acceptances;
                }
            }
        }

        // IDE Chat
        $ideChat = $day["copilot_ide_chat"] ?? [];
        foreach (($ideChat["editors"] ?? []) as $editor) {
            foreach (($editor["models"] ?? []) as $model) {
                $totalChats     += $model["total_chats"]       ?? 0;
                $totalChatTurns += $model["total_chat_turns"]  ?? 0;

                // Agent mode detection
                if (!empty($model["total_agent_chats"])) {
                    $agentModeUsed = true;
                }
            }
        }

        // Dotcom Chat
        $dotcomChat = $day["copilot_dotcom_chat"] ?? [];
        foreach (($dotcomChat["models"] ?? []) as $model) {
            $dotcomChats += $model["total_chats"] ?? 0;
        }

        // PR Summaries (advanced feature)
        $dotcomPR = $day["copilot_dotcom_pull_requests"] ?? [];
        foreach (($dotcomPR["repositories"] ?? []) as $repo) {
            foreach (($repo["models"] ?? []) as $model) {
                $prSummariesUsed += $model["total_pr_summaries_created"] ?? 0;
            }
        }

        // Count active days (if any suggestion happened)
        if (($day["total_active_users"] ?? 0) > 0) {
            $activeDays++;
        }

        // Store daily snapshot
        $result["daily_metrics"][] = [
            "date"                           => $date,
            "total_active_users_org"         => $day["total_active_users"] ?? 0,
            "total_engaged_users_org"        => $day["total_engaged_users"] ?? 0,
            "ide_completions_engaged_users"  => $completions["total_engaged_users"] ?? 0,
            "ide_chat_engaged_users"         => $ideChat["total_engaged_users"] ?? 0,
            "dotcom_chat_engaged_users"      => $dotcomChat["total_engaged_users"] ?? 0,
        ];
    }

    // --- Compute language acceptance rates ---
    foreach ($languageUsage as $lang => &$stats) {
        $stats["acceptance_rate"] = acceptanceRate($stats["acceptances"], $stats["suggestions"]);
    }

    // --- Final aggregated result ---
    $result["aggregated"] = [
        "active_days_in_period"       => $activeDays,
        "total_suggestions"           => $totalSuggestions,
        "total_acceptances"           => $totalAcceptances,
        "overall_acceptance_rate"     => acceptanceRate($totalAcceptances, $totalSuggestions),
        "total_lines_suggested"       => $totalLinesSuggested,
        "total_lines_accepted"        => $totalLinesAccepted,
        "total_ide_chats"             => $totalChats,
        "total_chat_turns"            => $totalChatTurns,
        "total_dotcom_chats"          => $dotcomChats,
        "total_pr_summaries_created"  => $prSummariesUsed,
        "agent_mode_used"             => $agentModeUsed,
        "languages_used"              => $languageUsage,
        "editors_used"                => $editorUsage,
        "effectiveness_score"         => calculateEffectivenessScore(
            acceptanceRate($totalAcceptances, $totalSuggestions),
            $activeDays,
            $totalChats,
            $agentModeUsed
        ),
    ];

    return $result;
}

// =====================================================
// STEP 5: Effectiveness Score (your custom KPI)
// =====================================================
function calculateEffectivenessScore(string $acceptRate, int $activeDays, int $chats, bool $agentMode): array {
    $rate       = (float) rtrim($acceptRate, "%");
    $maxDays    = 28;

    $score =
        ($rate / 100) * 40 +
        (min($activeDays, $maxDays) / $maxDays) * 30 +
        (min($chats, 100) / 100) * 20 +
        ($agentMode ? 10 : 0);

    $label = match(true) {
        $score >= 80 => "🏆 Power User",
        $score >= 60 => "🔥 High Engager",
        $score >= 40 => "📈 Growing",
        $score >= 20 => "🌱 Beginner",
        default      => "😴 Inactive",
    };

    return [
        "score" => round($score, 2),
        "out_of" => 100,
        "label" => $label,
        "breakdown" => [
            "acceptance_rate_contribution" => round(($rate / 100) * 40, 2),
            "active_days_contribution"     => round((min($activeDays, $maxDays) / $maxDays) * 30, 2),
            "chat_usage_contribution"      => round((min($chats, 100) / 100) * 20, 2),
            "agent_mode_contribution"      => $agentMode ? 10 : 0,
        ]
    ];
}

// =====================================================
// MAIN
// =====================================================

echo "\n📡 Fetching GitHub Copilot Metrics for org: $ORG\n";

$orgMetrics = getOrgMetrics($ORG, $GITHUB_TOKEN);
$allSeats   = getAllSeats($ORG, $GITHUB_TOKEN);

if (isset($orgMetrics["error"])) {
    printJson($orgMetrics, "❌ Error fetching org metrics");
    exit(1);
}

if (empty($TARGET_USER)) {
    // MODE 1: Show active members summary
    $activeMembers = getActiveMembers($orgMetrics, $allSeats);
    printJson($activeMembers, "👥 Active Members Summary");
} else {
    // MODE 2: Full data for specific user
    $userData = getUserFullData($TARGET_USER, $ORG, $GITHUB_TOKEN, $orgMetrics, $allSeats);
    printJson($userData, "👤 Full Report for: $TARGET_USER");
}