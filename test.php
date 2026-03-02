<?php

// =====================================================
// GitHub Copilot - Personal Usage Tracker
// User: JayenthiranP
// =====================================================

$GITHUB_TOKEN = ""; // <-- REPLACE with your GitHub Personal Access Token (PAT)
$USERNAME     = "JayenthiranP";

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
            "User-Agent: CopilotPersonalTracker/1.0"
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ["error" => "cURL Error: $curlError", "url" => $url];
    }
    if ($httpCode !== 200) {
        return ["error" => "HTTP $httpCode", "url" => $url, "response" => json_decode($response, true)];
    }
    return json_decode($response, true) ?? [];
}

// =====================================================
// HELPER: Pretty JSON
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
// HELPER: Acceptance Rate
// =====================================================
function acceptanceRate(int $accepted, int $suggested): string {
    if ($suggested === 0) return "N/A";
    return round(($accepted / $suggested) * 100, 2) . "%";
}

// =====================================================
// 1. My Profile Info
// =====================================================
function getMyProfile(string $token, string $username): array {
    $data = githubRequest("https://api.github.com/users/$username", $token);
    return [
        "login"        => $data["login"]        ?? null,
        "name"         => $data["name"]          ?? null,
        "email"        => $data["email"]         ?? null,
        "company"      => $data["company"]       ?? null,
        "public_repos" => $data["public_repos"]  ?? null,
        "followers"    => $data["followers"]     ?? null,
        "created_at"   => $data["created_at"]    ?? null,
    ];
}

// =====================================================
// 2. My Copilot Subscription / Seat Info
// =====================================================
function getMyCopilotSubscription(string $token): array {
    $data = githubRequest("https://api.github.com/copilot_internal/user", $token);
    if (isset($data["error"])) {
        // fallback to billing endpoint
        $data = githubRequest("https://api.github.com/user/copilot_internal/user", $token);
    }
    return $data;
}

// =====================================================
// 3. My Copilot Usage (via user-level endpoint)
// =====================================================
function getMyCopilotUsage(string $token): array {
    // Personal usage endpoint (Copilot Individual)
    return githubRequest("https://api.github.com/user/copilot/usage", $token);
}

// =====================================================
// 4. My Recent Activity - Events
// =====================================================
function getMyRecentEvents(string $token, string $username): array {
    $events = githubRequest("https://api.github.com/users/$username/events?per_page=100", $token);

    if (isset($events["error"])) return $events;

    $summary = [
        "total_events"  => count($events),
        "event_types"   => [],
        "recent_repos"  => [],
        "daily_activity"=> [],
    ];

    foreach ($events as $event) {
        $type    = $event["type"]                  ?? "unknown";
        $repo    = $event["repo"]["name"]           ?? "unknown";
        $date    = substr($event["created_at"] ?? "", 0, 10);

        // Count event types
        $summary["event_types"][$type] = ($summary["event_types"][$type] ?? 0) + 1;

        // Unique repos
        if (!in_array($repo, $summary["recent_repos"])) {
            $summary["recent_repos"][] = $repo;
        }

        // Daily activity
        $summary["daily_activity"][$date] = ($summary["daily_activity"][$date] ?? 0) + 1;
    }

    arsort($summary["event_types"]);
    krsort($summary["daily_activity"]);

    return $summary;
}

// =====================================================
// 5. My Repositories
// =====================================================
function getMyRepos(string $token): array {
    $repos = githubRequest("https://api.github.com/user/repos?per_page=100&sort=updated", $token);

    if (isset($repos["error"])) return $repos;

    $summary = [];
    foreach ($repos as $repo) {
        $summary[] = [
            "name"           => $repo["name"]             ?? null,
            "language"       => $repo["language"]         ?? "N/A",
            "visibility"     => $repo["visibility"]       ?? null,
            "stars"          => $repo["stargazers_count"] ?? 0,
            "forks"          => $repo["forks_count"]      ?? 0,
            "open_issues"    => $repo["open_issues_count"]?? 0,
            "last_updated"   => substr($repo["updated_at"] ?? "", 0, 10),
        ];
    }
    return $summary;
}

// =====================================================
// 6. My Orgs (to find which orgs I can query)
// =====================================================
function getMyOrgs(string $token): array {
    $orgs = githubRequest("https://api.github.com/user/orgs", $token);
    if (isset($orgs["error"])) return $orgs;

    $result = [];
    foreach ($orgs as $org) {
        $result[] = [
            "login"       => $org["login"]       ?? null,
            "description" => $org["description"] ?? null,
            "url"         => $org["url"]          ?? null,
        ];
    }
    return $result;
}

// =====================================================
// 7. My Copilot Org Seat (if part of org)
// =====================================================
function getMyCopilotOrgSeat(string $token, string $orgSlug): array {
    return githubRequest("https://api.github.com/orgs/$orgSlug/copilot/billing/seats?per_page=100", $token);
}

// =====================================================
// 8. Token Scopes (what my token can access)
// =====================================================
function getMyTokenScopes(string $token): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://api.github.com/user",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            "Accept: application/vnd.github+json",
            "User-Agent: CopilotPersonalTracker/1.0"
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    preg_match('/X-OAuth-Scopes:\s*(.+)/i', $response, $matches);
    $scopes = isset($matches[1]) ? array_map('trim', explode(',', $matches[1])) : [];
    return ["token_scopes" => $scopes];
}

// =====================================================
// MAIN - Collect Everything
// =====================================================

echo "\n🚀 Fetching all Copilot & GitHub data for: $USERNAME\n\n";

$fullReport = [];

// Profile
echo "📋 Fetching profile...\n";
$fullReport["1_profile"]              = getMyProfile($GITHUB_TOKEN, $USERNAME);

// Token scopes
echo "🔑 Fetching token scopes...\n";
$fullReport["2_token_scopes"]         = getMyTokenScopes($GITHUB_TOKEN);

// Copilot subscription
echo "🤖 Fetching Copilot subscription...\n";
$fullReport["3_copilot_subscription"] = getMyCopilotSubscription($GITHUB_TOKEN);

// Copilot usage
echo "📊 Fetching Copilot usage...\n";
$fullReport["4_copilot_usage"]        = getMyCopilotUsage($GITHUB_TOKEN);

// My orgs
echo "🏢 Fetching organizations...\n";
$myOrgs = getMyOrgs($GITHUB_TOKEN);
$fullReport["5_my_orgs"]             = $myOrgs;

// For each org — try to get my seat info
if (!empty($myOrgs) && !isset($myOrgs["error"])) {
    foreach ($myOrgs as $org) {
        $slug = $org["login"];
        echo "   └─ Fetching Copilot seat info for org: $slug\n";
        $fullReport["6_copilot_seat_in_orgs"][$slug] = getMyCopilotOrgSeat($GITHUB_TOKEN, $slug);
    }
}

// Recent events / activity
echo "⚡ Fetching recent GitHub events...\n";
$fullReport["7_recent_activity"]      = getMyRecentEvents($GITHUB_TOKEN, $USERNAME);

// My repos
echo "📁 Fetching repositories...\n";
$fullReport["8_my_repos"]            = getMyRepos($GITHUB_TOKEN);

// Print everything as JSON
printJson($fullReport, "✅ Full Copilot & GitHub Report — $USERNAME");