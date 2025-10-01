<?php
if (!defined('ABSPATH')) exit;

/* ------------------------------
 * Networking
 * ------------------------------ */
/**
 * SECURITY: Only trust proxy headers if explicitly allowed via filters.
 * By default returns REMOTE_ADDR to avoid spoofing.
 *
 * Filters:
 * - kkchat_trust_proxy_headers (bool): master switch (default false)
 * - kkchat_trusted_proxies (array<string>): list of proxy IPs you trust; if provided,
 *   REMOTE_ADDR must be in this list to honor forwarded headers.
 */
function kkchat_client_ip(): string {
  $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
  $remote = preg_replace('~[^0-9a-fA-F\:\.]~', '', $remote);

  $trust = (bool) apply_filters('kkchat_trust_proxy_headers', false);
  $trusted_list = (array) apply_filters('kkchat_trusted_proxies', []);

  if ($trust && $remote && (!empty($trusted_list) ? in_array($remote, $trusted_list, true) : true)) {
    // Prefer Cloudflare header if present
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
      $ip = preg_replace('~[^0-9a-fA-F\:\.]~', '', (string) $_SERVER['HTTP_CF_CONNECTING_IP']);
      if ($ip) return $ip;
    }
    // Then X-Forwarded-For (first hop)
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $xff = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
      $ip  = preg_replace('~[^0-9a-fA-F\:\.]~', '', trim($xff[0] ?? ''));
      if ($ip) return $ip;
    }
    // Or X-Real-IP
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
      $ip = preg_replace('~[^0-9a-fA-F\:\.]~', '', (string) $_SERVER['HTTP_X_REAL_IP']);
      if ($ip) return $ip;
    }
  }

  return $remote ?: '0.0.0.0';
}

/* ------------------------------
 * IP helpers for bans
 * ------------------------------ */
function kkchat_is_ipv4(string $ip): bool {
  return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
}
function kkchat_is_ipv6(string $ip): bool {
  return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
}
/** Return normalized IPv6 /64 (e.g., "2001:db8:abcd:1234::/64") or null on failure. */
function kkchat_ipv6_prefix64(string $ip): ?string {
  $bin = @inet_pton($ip);
  if ($bin === false || strlen($bin) !== 16) return null;
  // keep first 64 bits, zero the last 64 bits
  $masked = substr($bin, 0, 8) . str_repeat("\x00", 8);
  $net = @inet_ntop($masked);
  if ($net === false) return null;
  return strtolower($net) . '/64';
}
/** The value we store in blocks.target_ip for bans. */
function kkchat_ip_ban_key(?string $ip): ?string {
  if (!$ip) return null;
  $ip = trim($ip);
  if (kkchat_is_ipv4($ip)) return $ip;
  if (kkchat_is_ipv6($ip)) return kkchat_ipv6_prefix64($ip) ?: $ip;
  return $ip;
}
