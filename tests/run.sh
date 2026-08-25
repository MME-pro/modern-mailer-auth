#!/usr/bin/env bash
# Integration suite.
#
# Every outbound HTTP call is stubbed via WordPress's pre_http_request filter,
# so this needs no API credentials and sends no mail. The suite restores the
# site to an unconfigured state when it finishes.
#
# Requires PHP 8.0+ and a WordPress install this plugin sits inside.
#
#   PHP        override the php binary          (default: php on PATH)
#   MYSQL_SOCK point at a non-default MySQL socket, e.g. for LocalWP / MAMP
#   MMOA_TEST_HOST  the dev site hostname       (default: localhost)
#
# LocalWP example:
#   PHP="~/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php" \
#   MYSQL_SOCK="~/Library/Application Support/Local/run/<site-id>/mysql/mysqld.sock" ./run.sh
set -uo pipefail

PHP="${PHP:-php}"
ARGS=()
if [ -n "${MYSQL_SOCK:-}" ]; then
	ARGS+=( -d "mysqli.default_socket=${MYSQL_SOCK}" -d "pdo_mysql.default_socket=${MYSQL_SOCK}" )
fi

cd "$( dirname "$0" )"
status=0

# Generated on demand rather than committed: a checked-in private key trips
# security scanners and teaches the wrong habit, even as a throwaway.
if [ ! -f test-sa-key.pem ]; then
	openssl genrsa -out test-sa-key.pem 2048 2>/dev/null
fi
trap 'rm -f test-sa-key.pem' EXIT

for test in test-graph.php test-failures.php test-gmail.php test-resilience.php test-google-consent.php test-smtp.php test-routing.php test-regression-wpms.php test-final.php; do
	echo "--- ${test} ---"
	"$PHP" "${ARGS[@]}" "$test" || status=1
done

exit "$status"
