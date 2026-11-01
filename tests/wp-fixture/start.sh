#!/usr/bin/env bash
# (Re)start the PHP built-in web server for the WordPress fixture on http://127.0.0.1:8081
# Idempotent: stops any previous instance on that port, starts a new one in the background
# (detached, nohup, log in server.log) and waits until WordPress answers.
set -euo pipefail

BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST=127.0.0.1
PORT=8081
URL="http://${HOST}:${PORT}"
LOG="${BASE}/server.log"
PIDFILE="${BASE}/server.pid"

if [ ! -f "${BASE}/site/wp-config.php" ]; then
	echo "No fixture found in ${BASE}/site - run ${BASE}/build.sh first." >&2
	exit 1
fi

# 1. Stop a previous instance (pid file first, then anything still running "php -S HOST:PORT").
if [ -f "$PIDFILE" ]; then
	kill "$(cat "$PIDFILE")" 2>/dev/null || true
	rm -f "$PIDFILE"
fi
pkill -f "^([^ ]*/)?php -S ${HOST//./\\.}:${PORT}( |\$)" 2>/dev/null || true
for _ in $(seq 1 50); do
	if ! (exec 3<>"/dev/tcp/${HOST}/${PORT}") 2>/dev/null; then break; fi
	sleep 0.1
done

# 2. Start the server in the background, detached from this shell.
#    PHP_CLI_SERVER_WORKERS lets several requests run in parallel (REST clients, image downloads).
echo "=== $(date -Is) starting php -S ${HOST}:${PORT} ===" >>"$LOG"
cd "${BASE}"
PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}" \
	setsid nohup php -S "${HOST}:${PORT}" -t "${BASE}/site" "${BASE}/router.php" >>"$LOG" 2>&1 </dev/null &
echo $! >"$PIDFILE"

# 3. Wait until WordPress answers (REST index returns 200).
for _ in $(seq 1 100); do
	code="$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "${URL}/wp-json/" || true)"
	if [ "$code" = "200" ]; then
		echo "WordPress fixture is up: ${URL}/  (pid $(cat "$PIDFILE"), log ${LOG})"
		exit 0
	fi
	sleep 0.2
done
echo "Server did not answer on ${URL} - last log lines:" >&2
tail -n 30 "$LOG" >&2
exit 1
