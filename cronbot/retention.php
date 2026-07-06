<?php
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';

/* =======================================================================
 *  RETENTION — automated renewal / win-back offers
 *  -----------------------------------------------------------------------
 *  Edit the settings below. Set 'dry_run' => true to see (in log.txt) what
 *  it WOULD send, without DMing users or creating discount codes.
 *
 *  Stage 1 (pre-expiry): an active user whose sub ends within `pre_days`
 *      gets a personal `pre_percent`% code and a renew button.
 *  Stage 2 (win-back): a user whose sub expired between `winback_min` and
 *      `winback_max` days ago AND who has NO active sub gets a bigger
 *      `winback_percent`% code to come back.
 *
 *  Each user gets each offer once per cycle (deduped in retention_log); a
 *  renewal starts a new cycle, so pre-expiry can fire again next month.
 * ===================================================================== */
$RET = [
    'enabled'         => true,
    'dry_run'         => false,
    'only_agent'      => 'f',   // target normal users (not resellers). null = everyone.
    'pre_days'        => 3,
    'pre_percent'     => 5,
    'winback_min'     => 3,
    'winback_max'     => 14,
    'winback_percent' => 20,
    'code_ttl_hours'  => 72,
    'batch'           => 40,    // max users handled per stage per run
];

/* ---- schema (self-bootstrapping) ------------------------------------- */
$pdo->exec("CREATE TABLE IF NOT EXISTS retention_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_invoice VARCHAR(100) NOT NULL,
    id_user VARCHAR(100) NOT NULL,
    stage VARCHAR(20) NOT NULL,
    cycle_key VARCHAR(50) NOT NULL,
    code VARCHAR(100) NULL,
    created_at INT NOT NULL,
    UNIQUE KEY uniq_offer (id_invoice, stage, cycle_key)
) DEFAULT CHARSET=utf8mb4");

function retentionLog($msg)
{
    file_put_contents('log.txt', "\n[retention] " . date('Y-m-d H:i:s') . " " . $msg, FILE_APPEND);
}

// Reserve an offer atomically: INSERT IGNORE on the unique key. Returns true only
// for the first caller — guarantees one offer per (invoice, stage, cycle).
function retentionClaim($id_invoice, $id_user, $stage, $cycleKey, $code)
{
    global $pdo;
    $stmt = $pdo->prepare("INSERT IGNORE INTO retention_log (id_invoice,id_user,stage,cycle_key,code,created_at) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$id_invoice, $id_user, $stage, $cycleKey, $code, time()]);
    return $stmt->rowCount() > 0;
}

// One-time, per-user discount code reusing the shop's DiscountSell mechanism.
// type/agent/product/panel = universal so it validates on renew AND purchase.
function retentionMakeCode($percent, $ttlHours)
{
    global $pdo;
    $code = 'RN' . strtoupper(bin2hex(random_bytes(3)));
    $stmt = $pdo->prepare("INSERT INTO DiscountSell (codeDiscount, usedDiscount, price, limitDiscount, agent, usefirst, useuser, code_panel, code_product, time, type) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$code, '0', (string) $percent, '1', 'allusers', '0', '1', '/all', 'all', (string) (time() + $ttlHours * 3600), 'all']);
    return $code;
}

function retentionSend($id_user, $bottype, $text, $callback)
{
    $kb = json_encode(['inline_keyboard' => [[
        ['text' => '🔁 تمدید سرویس', 'callback_data' => $callback],
    ]]]);
    sendmessage($id_user, $text, $kb, 'HTML', $bottype);
}

function retentionPreText($days, $percent, $code, $ttlHours)
{
    return "سرویس شما تا {$days} روز دیگر به پایان می‌رسد.\n\n" .
        "همین حالا با {$percent}٪ تخفیف تمدید کن و بدون قطعی ادامه بده.\n" .
        "کد تخفیف (تا {$ttlHours} ساعت معتبر):\n<code>{$code}</code>\n\n" .
        "برای تمدید دکمه زیر را بزن و هنگام پرداخت، کد را در بخش «کد تخفیف» وارد کن.";
}

function retentionWinbackText($percent, $code, $ttlHours)
{
    return "جای شما در پرکسو خالیه!\n\n" .
        "با {$percent}٪ تخفیف ویژه برگرد و دوباره اینترنت آزاد و پایدار داشته باش.\n" .
        "کد تخفیف (تا {$ttlHours} ساعت معتبر):\n<code>{$code}</code>\n\n" .
        "برای فعال‌سازی دوباره دکمه زیر را بزن و کد را هنگام پرداخت وارد کن.";
}

if (!$RET['enabled']) {
    return;
}

$panel = new ManagePanel();
$now = time();
$testName = languagechange()['Admin']['adminphp']['db_test_service_name'] ?? 'تست';

/* ---- Stage 1: pre-expiry ------------------------------------------- */
// agent lives on the user, not the invoice; only-agent is filtered in-loop.
function retentionAgentOk($inv, $onlyAgent)
{
    if ($onlyAgent === null) return true;
    $u = select("user", "agent", "id", $inv['id_user'], "select");
    return $u && ($u['agent'] ?? '') === $onlyAgent;
}

$stmt = $pdo->prepare("SELECT * FROM invoice WHERE Status = 'active' AND name_product != :t ORDER BY time_sell DESC LIMIT " . intval($RET['batch']) * 3);
$stmt->execute([':t' => $testName]);
$active = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent = 0;
foreach ($active as $inv) {
    if ($sent >= $RET['batch']) break;
    if (!retentionAgentOk($inv, $RET['only_agent'])) continue;
    $data = $panel->DataUser($inv['Service_location'], $inv['username']);
    if (!is_array($data) || ($data['status'] ?? '') !== 'active') continue;
    $expire = intval($data['expire'] ?? 0);
    if ($expire <= 0) continue; // never-expires plan: no pre-expiry offer
    $days = (int) ceil(($expire - $now) / 86400);
    if ($days <= 0 || $days > $RET['pre_days']) continue;

    $cycle = 'pre' . intval($expire / 86400); // expiry-day; a renewal → new cycle
    if ($RET['dry_run']) {
        retentionLog("DRY pre-expiry uid={$inv['id_user']} inv={$inv['id_invoice']} days={$days}");
        $sent++;
        continue;
    }
    if (!retentionClaim($inv['id_invoice'], $inv['id_user'], 'pre_expiry', $cycle, null)) continue;
    $code = retentionMakeCode($RET['pre_percent'], $RET['code_ttl_hours']);
    update("retention_log", "code", $code, "id_invoice", $inv['id_invoice']);
    retentionSend(
        $inv['id_user'],
        $inv['bottype'] ?? null,
        retentionPreText($days, $RET['pre_percent'], $code, $RET['code_ttl_hours']),
        'extend_' . $inv['id_invoice']
    );
    retentionLog("SENT pre-expiry uid={$inv['id_user']} inv={$inv['id_invoice']} days={$days} code={$code}");
    $sent++;
}

/* ---- Stage 2: win-back --------------------------------------------- */
// Lapsed subs land in various statuses here (mostly 'disabled'); the real gate
// is the expiry-window + no-active-sub checks below, not the status label.
$stmt = $pdo->prepare("SELECT * FROM invoice WHERE Status IN ('end_of_time','end_of_volume','sendedwarn','disabled') AND name_product != :t ORDER BY time_sell DESC LIMIT " . intval($RET['batch']) * 3);
$stmt->execute([':t' => $testName]);
$lapsed = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sentW = 0;
foreach ($lapsed as $inv) {
    if ($sentW >= $RET['batch']) break;
    if (!retentionAgentOk($inv, $RET['only_agent'])) continue;

    // Only lapsed users with NO active subscription.
    $hasActive = $pdo->prepare("SELECT COUNT(*) FROM invoice WHERE id_user = ? AND Status = 'active'");
    $hasActive->execute([$inv['id_user']]);
    if ($hasActive->fetchColumn() > 0) continue;

    $data = $panel->DataUser($inv['Service_location'], $inv['username']);
    $expire = is_array($data) ? intval($data['expire'] ?? 0) : 0;
    if ($expire <= 0) continue;
    $daysSince = (int) floor(($now - $expire) / 86400);
    if ($daysSince < $RET['winback_min'] || $daysSince > $RET['winback_max']) continue;

    $cycle = 'wb' . intval($expire / 86400);
    if ($RET['dry_run']) {
        retentionLog("DRY winback uid={$inv['id_user']} inv={$inv['id_invoice']} since={$daysSince}");
        $sentW++;
        continue;
    }
    if (!retentionClaim($inv['id_invoice'], $inv['id_user'], 'winback', $cycle, null)) continue;
    $code = retentionMakeCode($RET['winback_percent'], $RET['code_ttl_hours']);
    retentionSend(
        $inv['id_user'],
        $inv['bottype'] ?? null,
        retentionWinbackText($RET['winback_percent'], $code, $RET['code_ttl_hours']),
        'extend_' . $inv['id_invoice']
    );
    retentionLog("SENT winback uid={$inv['id_user']} inv={$inv['id_invoice']} since={$daysSince} code={$code}");
    $sentW++;
}

retentionLog("run done: pre={$sent} winback={$sentW} dry=" . ($RET['dry_run'] ? '1' : '0'));
