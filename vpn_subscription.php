<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/request.php';

/**
 * vpn-subscription provider (e.g. https://sub.prexo.site).
 *
 * Unlike the panel providers, vpn-subscription is our own orchestrator API: it
 * talks to the upstream xray panels itself, so the bot only speaks one HTTP
 * contract. The bot's account username is sent as `customId` on create and
 * becomes the subscription endpoint id, so every later call keys on that same
 * value — identical to how the other providers key on the username they chose.
 *
 * marzban_panel row mapping:
 *   url_panel       -> API base, e.g. https://sub.prexo.site (no trailing slash)
 *   password_panel  -> SECRET_TOKEN, sent RAW as the Authorization header (no "Bearer")
 *   username_panel  -> unused ("null")
 *
 * Conventions shared with the dispatcher (panels.php):
 *   - data_limit is in bytes (0 = unlimited); the API speaks GB.
 *   - expire is a unix timestamp (0 = never); the API speaks duration-in-days
 *     on create/renew and an ISO-8601 date on update.
 *   - statuses (active/disabled/limited/expired/on_hold) map 1:1, no translation.
 */

const VS_ONE_GB = 1073741824;

function vs_panel($location)
{
    return select("marzban_panel", "*", "name_panel", $location, "select");
}

function vs_base($panel)
{
    return rtrim($panel['url_panel'], "/");
}

/**
 * Authenticated request against the vpn-subscription admin API.
 * Returns the CurlRequest array: ['status'=>int,'body'=>string] or ['error'=>string].
 */
function vs_request($panel, $method, $path, $body = null)
{
    $req = new CurlRequest(vs_base($panel) . $path);
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    // Admin API compares the Authorization header directly to SECRET_TOKEN — no "Bearer".
    $headers[] = 'Authorization: ' . $panel['password_panel'];
    $req->setHeaders($headers);

    $payload = $body === null ? null : json_encode($body);
    switch (strtoupper($method)) {
        case 'POST':
            return $req->post($payload);
        case 'PATCH':
            return $req->PATCH($payload);
        case 'DELETE':
            return $req->delete($payload);
        default:
            return $req->get();
    }
}

/**
 * Unwrap the {status, data} envelope into ['ok'=>bool, 'data'=>mixed, 'msg'=>string].
 * Centralises transport errors, non-JSON bodies, and the API's fail/error shape.
 */
function vs_decode($resp)
{
    if (!empty($resp['error'])) {
        return array('ok' => false, 'msg' => $resp['error']);
    }
    $json = json_decode($resp['body'] ?? '', true);
    if (!is_array($json)) {
        return array('ok' => false, 'msg' => 'invalid response (http ' . ($resp['status'] ?? '?') . ')');
    }
    if (($json['status'] ?? '') === 'success') {
        return array('ok' => true, 'data' => $json['data'] ?? null);
    }
    $data = $json['data'] ?? null;
    if (is_array($data)) {
        $msg = $data['message'] ?? json_encode($data, JSON_UNESCAPED_UNICODE);
    } else {
        $msg = $data ?? 'error';
    }
    return array('ok' => false, 'msg' => $msg);
}

/**
 * Convert an expire unix timestamp (0 = never) into whole days from now,
 * the unit create/renew expect. Always >= 1 for a bounded expiry.
 */
function vs_days_from_expire($expire)
{
    if (intval($expire) === 0) {
        return 0; // never expires
    }
    $days = (int) ceil(($expire - time()) / 86400);
    return $days < 1 ? 1 : $days;
}

function vs_limit_gb($data_limit_bytes)
{
    return intval($data_limit_bytes) === 0 ? 0 : round($data_limit_bytes / VS_ONE_GB, 2);
}

/**
 * Create a subscription. $username becomes the endpoint id (customId).
 * $data_limit is bytes (0 = unlimited), $expire is a unix timestamp (0 = never).
 */
function adduser_vs($location, $username, $data_limit, $expire, $telegramId = "")
{
    $body = array(
        'customId' => $username,
        'duration' => vs_days_from_expire($expire),
        'limit' => vs_limit_gb($data_limit),
        'telegramId' => (string) $telegramId,
    );
    return vs_request(vs_panel($location), 'POST', '/api/v1/subscription/create', $body);
}

function getuser_vs($location, $username)
{
    return vs_request(vs_panel($location), 'GET', '/api/v1/subscription/get/' . rawurlencode($username));
}

function update_vs($location, $username, array $body)
{
    return vs_request(vs_panel($location), 'PATCH', '/api/v1/subscription/update/' . rawurlencode($username), $body);
}

function reset_vs($location, $username)
{
    return vs_request(vs_panel($location), 'PATCH', '/api/v1/subscription/reset/' . rawurlencode($username));
}

function removeuser_vs($location, $username)
{
    return vs_request(vs_panel($location), 'DELETE', '/api/v1/subscription/delete/' . rawurlencode($username));
}

/** Unauthenticated liveness probe used by the admin connection test. */
function healthcheck_vs($location)
{
    return vs_request(vs_panel($location), 'GET', '/api/v1/server/health-check');
}
