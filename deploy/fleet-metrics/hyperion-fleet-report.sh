#!/usr/bin/env bash
#
# hyperion-fleet-report.sh — fleet-metrics heartbeat agent for hyperion.
#
# hyperion is the database+redis box: it runs NO Laravel app and NO Horizon,
# so it cannot run the PHP `ReportFleetMetricsJob` like every other box. This
# standalone script produces the SAME `kraite:fleet:<host>` Redis payload the
# PHP collector does, written with raw redis-cli on the kraite database.
#
# The key is UNPREFIXED on purpose — the PHP reader (admin dashboard) and the
# watchdog read it through the dedicated, unprefixed `fleet` Redis connection,
# so this raw key must match byte-for-byte: literally `kraite:fleet:hyperion`.
#
# Wired to fire every ~5 minutes by kraite-fleet-metrics.timer (systemd).
set -euo pipefail

HOSTNAME_VALUE="$(hostname -s)"
ROLE="database"
KEY_PREFIX="kraite:fleet:"
REDIS_DB="2"
TTL_SECONDS="604800"   # 7d GC horizon; liveness is judged by reported_at age

# --- redis auth: read requirepass straight from the hardened conf ----------
REDIS_PW="$(grep -h '^requirepass' /etc/redis/conf.d/*.conf /etc/redis/redis.conf 2>/dev/null | head -1 | awk '{print $2}')"

redis() {
    if [ -n "${REDIS_PW}" ]; then
        redis-cli -a "${REDIS_PW}" --no-auth-warning -n "${REDIS_DB}" "$@"
    else
        redis-cli -n "${REDIS_DB}" "$@"
    fi
}

# --- vitals ----------------------------------------------------------------
UPTIME_SECONDS="$(awk '{printf "%d", $1}' /proc/uptime)"
BOOT_ID="$(cat /proc/sys/kernel/random/boot_id 2>/dev/null || echo '')"

LOAD1="$(awk '{printf "%.2f", $1}' /proc/loadavg)"
CORES="$(nproc 2>/dev/null || grep -c '^processor' /proc/cpuinfo)"
CPU_PCT="$(awk -v l="${LOAD1}" -v c="${CORES}" 'BEGIN{ if (c>0){ p=(l/c)*100; if(p>100)p=100; printf "%.1f", p } else { printf "null" } }')"

read -r MEM_TOTAL_KB MEM_AVAIL_KB < <(awk '
    /^MemTotal:/ {t=$2}
    /^MemAvailable:/ {a=$2}
    END {print t, a}
' /proc/meminfo)
MEM_USED_MB="$(awk -v t="${MEM_TOTAL_KB}" -v a="${MEM_AVAIL_KB}" 'BEGIN{printf "%d", (t-a)/1024}')"
MEM_TOTAL_MB="$(awk -v t="${MEM_TOTAL_KB}" 'BEGIN{printf "%d", t/1024}')"
MEM_PCT="$(awk -v t="${MEM_TOTAL_KB}" -v a="${MEM_AVAIL_KB}" 'BEGIN{ if(t>0){printf "%.1f", ((t-a)/t)*100} else {printf "null"} }')"

# Root filesystem usage via df (1K blocks).
read -r DISK_USED_KB DISK_TOTAL_KB < <(df -kP / | awk 'NR==2{print $3, $2}')
DISK_USED_GB="$(awk -v u="${DISK_USED_KB}" 'BEGIN{printf "%.1f", u/1048576}')"
DISK_TOTAL_GB="$(awk -v t="${DISK_TOTAL_KB}" 'BEGIN{printf "%.1f", t/1048576}')"
DISK_PCT="$(awk -v u="${DISK_USED_KB}" -v t="${DISK_TOTAL_KB}" 'BEGIN{ if(t>0){printf "%.1f", (u/t)*100} else {printf "null"} }')"

# --- units: hyperion runs mysql + redis as systemd services (no supervisor).
# Report them in the same {name: STATE} shape, RUNNING when active.
unit_state() {
    local svc="$1"
    if systemctl is-active --quiet "${svc}" 2>/dev/null; then
        echo "RUNNING"
    else
        echo "STOPPED"
    fi
}
MYSQL_STATE="$(unit_state mysql 2>/dev/null || echo STOPPED)"
[ "${MYSQL_STATE}" = "STOPPED" ] && MYSQL_STATE="$(unit_state mariadb 2>/dev/null || echo STOPPED)"
REDIS_STATE="$(unit_state redis-server 2>/dev/null || echo STOPPED)"
[ "${REDIS_STATE}" = "STOPPED" ] && REDIS_STATE="$(unit_state redis 2>/dev/null || echo STOPPED)"

REPORTED_AT="$(date -u +%Y-%m-%dT%H:%M:%S+00:00)"

# --- assemble payload ------------------------------------------------------
# boot_id is emitted as a JSON string (or null when unreadable).
if [ -n "${BOOT_ID}" ]; then BOOT_ID_JSON="\"${BOOT_ID}\""; else BOOT_ID_JSON="null"; fi

PAYLOAD="$(cat <<JSON
{"hostname":"${HOSTNAME_VALUE}","role":"${ROLE}","reported_at":"${REPORTED_AT}","uptime_seconds":${UPTIME_SECONDS},"boot_id":${BOOT_ID_JSON},"cpu":{"load1":${LOAD1},"cores":${CORES},"percent":${CPU_PCT}},"ram":{"used_mb":${MEM_USED_MB},"total_mb":${MEM_TOTAL_MB},"percent":${MEM_PCT}},"disk":{"used_gb":${DISK_USED_GB},"total_gb":${DISK_TOTAL_GB},"percent":${DISK_PCT}},"units":{"mysql":"${MYSQL_STATE}","redis":"${REDIS_STATE}"}}
JSON
)"

redis SETEX "${KEY_PREFIX}${HOSTNAME_VALUE}" "${TTL_SECONDS}" "${PAYLOAD}" >/dev/null
